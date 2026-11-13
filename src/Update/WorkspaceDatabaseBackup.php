<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;

/**
 * Whole-database checkpoint for workspace updates.
 * Unlike module table backups, this covers all tables and schema objects.
 * Backups are retained until the transaction coordinator discards them.
 */
final class WorkspaceDatabaseBackup
{
    /** @return array{driver: string, path: string} */
    public function capture(string $backend, string $databaseUrl): array
    {
        $parts = $this->parse($databaseUrl);
        $driver = $this->driver($parts);
        $directory = rtrim($backend, '/').'/.symsvel/transactions';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new \RuntimeException('Répertoire de transaction BDD inaccessible.');
        }
        $path = tempnam($directory, '.database-');
        if ($path === false) {
            throw new \RuntimeException('Création du checkpoint BDD impossible.');
        }
        chmod($path, 0600);
        try {
            if ($driver === 'sqlite') {
                $this->run(['sqlite3', $this->sqlitePath($parts), '.backup '.$path]);
            } elseif ($driver === 'postgresql') {
                [$connection, $env] = $this->postgresConnection($parts);
                $this->run(['pg_dump', '--format=custom', '--no-owner', '--no-acl', '--file='.$path, ...$connection], $env);
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                [$connection, $env, $database] = $this->mysqlConnection($parts);
                $this->assertRestorableMysqlDatabase($database);
                $this->run([
                    'mysqldump', '--single-transaction', '--routines', '--events',
                    '--triggers', '--hex-blob', '--no-tablespaces',
                    '--add-drop-database', '--databases', $database,
                    '--result-file='.$path, ...$connection,
                ], $env);
            } else {
                throw new \DomainException('Sauvegarde globale non prise en charge pour '.$driver.'.');
            }
            if (!is_file($path) || filesize($path) === 0) {
                throw new \RuntimeException('Checkpoint BDD vide.');
            }
            return ['driver' => $driver, 'path' => $path];
        } catch (\Throwable $failure) {
            @unlink($path);
            throw $failure;
        }
    }

    /** @param array{driver: string, path: string} $checkpoint */
    public function restore(string $databaseUrl, array $checkpoint): void
    {
        if (is_link($checkpoint['path']) || !is_file($checkpoint['path']) || filesize($checkpoint['path']) === 0) {
            throw new \RuntimeException('Checkpoint BDD manquant : restauration refusée.');
        }
        $parts = $this->parse($databaseUrl);
        $driver = $this->driver($parts);
        if ($driver !== $checkpoint['driver']) {
            throw new \RuntimeException('Moteur BDD différent du checkpoint.');
        }
        if ($driver === 'sqlite') {
            $this->run(['sqlite3', $this->sqlitePath($parts), '.restore '.$checkpoint['path']]);
        } elseif ($driver === 'postgresql') {
            // Restore at DATABASE level, not object level: --create --clean
            // drops the target database, including objects absent from the dump.
            // The maintenance connection must point to another database.
            [$connection, $env] = $this->postgresConnection($parts);
            $database = substr((string) end($connection), strlen('--dbname='));
            if (in_array($database, ['postgres', 'template0', 'template1'], true)) {
                throw new \DomainException('Restauration destructive de la base de maintenance interdite.');
            }
            $archive = new Process(['pg_restore', '--list', $checkpoint['path']]);
            $archive->setTimeout(60);
            $archive->run();
            if (!$archive->isSuccessful() || !preg_match('/^;\\s*dbname:\\s*(.+)\\s*$/m', $archive->getOutput(), $matches)) {
                throw new \RuntimeException('Archive PostgreSQL illisible ou nom de base absent.');
            }
            if (trim($matches[1]) !== $database) {
                throw new \RuntimeException('La base cible ne correspond pas à la base sauvegardée.');
            }
            $maintenance = array_map(
                static fn (string $arg): string => str_starts_with($arg, '--dbname=') ? '--dbname=postgres' : $arg,
                $connection,
            );
            try {
                $this->run([
                    'pg_restore', '--exit-on-error', '--create', '--clean', '--if-exists',
                    '--no-owner', '--no-acl', ...$maintenance, $checkpoint['path'],
                ], $env);
            } catch (\RuntimeException $failure) {
                if (str_contains($failure->getMessage(), 'is being accessed by other users')) {
                    throw new \RuntimeException(
                        'Restauration PostgreSQL bloquée : une application utilise encore la base '.
                        $database.'. Fermez ses connexions (par exemple DbGate) avant de retenter '.
                        'la récupération ; le checkpoint reste conservé.',
                        0,
                        $failure,
                    );
                }
                throw $failure;
            }
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            [$connection, $env, $database] = $this->mysqlConnection($parts);
            $this->assertRestorableMysqlDatabase($database);
            // A full-database dump is required: object-level imports would leave
            // tables created after the checkpoint behind.
            $dump = file_get_contents($checkpoint['path'], false, null, 0, 65536);
            if ($dump === false || !str_contains($dump, 'Current Database: `'.$database.'`')
                || !str_contains($dump, 'DROP DATABASE IF EXISTS `'.$database.'`')
                || !str_contains($dump, 'USE `'.$database.'`')) {
                throw new \RuntimeException('Archive MySQL/MariaDB invalide ou base cible différente.');
            }
            $this->runWithInput(['mysql', ...$connection], $env, $checkpoint['path']);
        } else {
            throw new \DomainException('Restauration globale non prise en charge pour '.$driver.'.');
        }
    }

    /** @param array{driver: string, path: string} $checkpoint */
    public function discard(array $checkpoint): void
    {
        if (is_file($checkpoint['path']) && !unlink($checkpoint['path'])) {
            throw new \RuntimeException('Nettoyage du checkpoint BDD impossible.');
        }
    }

    /** @return array<string, mixed> */
    private function parse(string $url): array
    {
        if (str_starts_with($url, 'sqlite:///')) {
            return ['scheme' => 'sqlite', 'path' => substr($url, strlen('sqlite://'))];
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException('DATABASE_URL invalide.');
        }
        return $parts;
    }

    /** @param array<string, mixed> $parts */
    private function driver(array $parts): string
    {
        return match (strtolower((string) $parts['scheme'])) {
            'postgres', 'pgsql', 'postgresql' => 'postgresql',
            'sqlite' => 'sqlite',
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            default => throw new \DomainException('Moteur BDD non pris en charge : '.$parts['scheme']),
        };
    }

    /** @param array<string, mixed> $parts */
    private function sqlitePath(array $parts): string
    {
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if ($path === '' || $path === ':memory:' || $path === '/:memory:') {
            throw new \DomainException('SQLite mémoire non sauvegardable.');
        }
        return $path;
    }

    /**
     * @param array<string, mixed> $parts
     * @return array{list<string>, array<string, string>}
     */
    private function postgresConnection(array $parts): array
    {
        $arguments = [];
        foreach (['host' => '--host=', 'port' => '--port=', 'user' => '--username='] as $key => $flag) {
            if (isset($parts[$key])) {
                $arguments[] = $flag.rawurldecode((string) $parts[$key]);
            }
        }
        $database = ltrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        if ($database === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $database)) {
            throw new \InvalidArgumentException('Nom de base PostgreSQL invalide.');
        }
        $arguments[] = '--dbname='.$database;
        $env = isset($parts['pass']) ? ['PGPASSWORD' => rawurldecode((string) $parts['pass'])] : [];
        return [$arguments, $env];
    }

    /**
     * @param array<string, mixed> $parts
     * @return array{list<string>, array<string, string>, string}
     */
    private function mysqlConnection(array $parts): array
    {
        $arguments = [];
        foreach (['host' => '--host=', 'port' => '--port=', 'user' => '--user='] as $key => $flag) {
            if (isset($parts[$key])) {
                $arguments[] = $flag.rawurldecode((string) $parts[$key]);
            }
        }
        $database = ltrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        if ($database === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $database)) {
            throw new \InvalidArgumentException('Nom de base MySQL/MariaDB invalide.');
        }
        $env = isset($parts['pass']) ? ['MYSQL_PWD' => rawurldecode((string) $parts['pass'])] : [];

        return [$arguments, $env, $database];
    }

    private function assertRestorableMysqlDatabase(string $database): void
    {
        if (in_array(strtolower($database), ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            throw new \DomainException('Restauration destructive de la base système interdite.');
        }
    }

    /** @param list<string> $command
     *  @param array<string, string> $env
     */
    private function runWithInput(array $command, array $env, string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Lecture du checkpoint BDD impossible.');
        }
        try {
            $process = new Process($command, null, $env);
            $process->setInput($stream);
            $process->setTimeout(900);
            $process->run();
            if (!$process->isSuccessful()) {
                $diagnostic = trim($process->getErrorOutput());
                $diagnostic = preg_replace('/(?i)(password|passphrase)\\s*[:=]\\s*\\S+/', '$1=[REDACTED]', $diagnostic);
                throw new \RuntimeException(
                    'Restauration MySQL/MariaDB échouée (code '.$process->getExitCode().')'.
                    ($diagnostic !== '' ? ' : '.substr($diagnostic, 0, 1200) : '.'),
                );
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param list<string> $command
     *  @param array<string, string> $env
     */
    private function run(array $command, array $env = []): void
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(900);
        $process->run();
        if (!$process->isSuccessful()) {
            // PostgreSQL diagnostics are essential for diagnosing version,
            // privilege and connection failures. Never include command args:
            // they can contain connection information.
            $diagnostic = trim($process->getErrorOutput());
            $diagnostic = preg_replace('/(?i)(password|passphrase)\\s*[:=]\\s*\\S+/', '$1=[REDACTED]', $diagnostic);
            throw new \RuntimeException(
                'Commande de checkpoint BDD échouée (code '.$process->getExitCode().')'.
                ($diagnostic !== '' ? ' : '.substr($diagnostic, 0, 1200) : '.'),
            );
        }
    }
}
