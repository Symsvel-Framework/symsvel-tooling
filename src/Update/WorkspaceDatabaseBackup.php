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
            $this->run([
                'pg_restore', '--exit-on-error', '--create', '--clean', '--if-exists',
                '--no-owner', '--no-acl', ...$maintenance, $checkpoint['path'],
            ], $env);
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

    /** @param list<string> $command
     *  @param array<string, string> $env
     */
    private function run(array $command, array $env = []): void
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(900);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Commande de checkpoint BDD échouée (code '.$process->getExitCode().').');
        }
    }
}
