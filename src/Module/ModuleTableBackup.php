<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

use Symfony\Component\Process\Process;

/**
 * Temporary, database-native backup for destructive module removal.
 * The backup is retained until the entire removal transaction commits.
 */
final class ModuleTableBackup
{
    /**
     * @param list<string> $tables Explicitly owned tables, verified by the caller.
     * @return array{driver: string, path: string, tables: list<string>}
     */
    public function create(string $workspace, string $databaseUrl, array $tables): array
    {
        if ($tables === []) {
            throw new \InvalidArgumentException('Aucune table à sauvegarder.');
        }
        if (count($tables) !== count(array_unique($tables))) {
            throw new \InvalidArgumentException('Tables dupliquées.');
        }
        foreach ($tables as $table) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) {
                throw new \InvalidArgumentException('Nom de table invalide : '.$table);
            }
        }
        $url = $this->connection($databaseUrl);
        $driver = strtolower($url['scheme']);
        $directory = rtrim($workspace, '/').'/.symsvel';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new \RuntimeException('Répertoire de sauvegarde inaccessible.');
        }
        $path = tempnam($directory, '.module-backup-');
        if ($path === false) {
            throw new \RuntimeException('Impossible de créer la sauvegarde.');
        }
        chmod($path, 0600);
        try {
            if (in_array($driver, ['postgresql', 'postgres', 'pgsql'], true)) {
                $this->postgresBackup($databaseUrl, $tables, $path);
                $driver = 'postgresql';
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $this->mysqlBackup($databaseUrl, $tables, $path);
                $driver = 'mysql';
            } elseif ($driver === 'sqlite') {
                $this->sqliteBackup($url, $path);
            } else {
                throw new \DomainException('Moteur SQL non pris en charge : '.$driver);
            }
            if (!is_file($path) || filesize($path) === 0) {
                throw new \RuntimeException('Sauvegarde vide.');
            }
            return ['driver' => $driver, 'path' => $path, 'tables' => $tables];
        } catch (\Throwable $failure) {
            @unlink($path);
            throw $failure;
        }
    }

    /**
     * Restore a previously created backup. Never delete the backup here:
     * only the transaction coordinator may discard it after verification.
     *
     * @param array{driver: string, path: string, tables: list<string>} $backup
     */
    public function restore(string $databaseUrl, array $backup): void
    {
        if (!is_file($backup['path']) || is_link($backup['path']) || filesize($backup['path']) === 0) {
            throw new \RuntimeException('Sauvegarde absente ou invalide : restauration bloquée.');
        }
        $parts = $this->connection($databaseUrl);
        $driver = strtolower($parts['scheme']);
        if (in_array($driver, ['postgres', 'pgsql'], true)) {
            $driver = 'postgresql';
        } elseif ($driver === 'mariadb') {
            $driver = 'mysql';
        }
        if ($driver !== $backup['driver']) {
            throw new \DomainException('Moteur SQL différent de celui de la sauvegarde.');
        }
        if ($driver === 'postgresql') {
            [$args, $env] = $this->postgresConnection($parts);
            $restore = ['pg_restore', '--exit-on-error', '--clean', '--if-exists', '--no-owner', '--no-acl'];
            // The archive already contains only selected tables. Restore all
            // archive objects, including associated sequences and constraints.
            $this->run(array_merge($restore, $args, [$backup['path']]), $env);
        } elseif ($driver === 'mysql') {
            $this->mysqlCommand($parts, $backup['path'], true);
        } elseif ($driver === 'sqlite') {
            $database = $this->sqlitePath($parts);
            $this->run(['sqlite3', $database, '.restore '. $backup['path']]);
        } else {
            throw new \DomainException('Moteur SQL non pris en charge.');
        }
    }

    /** @param list<string> $tables */
    private function postgresBackup(string $url, array $tables, string $path): void
    {
        [$args, $env] = $this->postgresConnection($this->connection($url));
        $command = ['pg_dump', '--format=custom', '--no-owner', '--no-acl', '--file='.$path];
        foreach ($tables as $table) {
            $command[] = '--table='.$table;
        }
        $this->run(array_merge($command, $args), $env);
    }

    /** @param array<string, mixed> $parts
     * @return array{list<string>, array<string, string>}
     */
    private function postgresConnection(array $parts): array
    {
        $args = [];
        $env = [];
        foreach (['host' => '--host=', 'port' => '--port=', 'user' => '--username='] as $key => $flag) {
            if (isset($parts[$key])) {
                $args[] = $flag.rawurldecode((string) $parts[$key]);
            }
        }
        $database = ltrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        if ($database === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $database)) {
            throw new \InvalidArgumentException('Nom de base PostgreSQL invalide.');
        }
        $args[] = '--dbname='.$database;
        if (isset($parts['pass'])) {
            $env['PGPASSWORD'] = rawurldecode((string) $parts['pass']);
        }
        return [$args, $env];
    }

    /** @param list<string> $tables */
    private function mysqlBackup(string $url, array $tables, string $path): void
    {
        $this->mysqlCommand($this->connection($url), $path, false, $tables);
    }

    /**
     * @param array<string, mixed> $parts
     * @param list<string> $tables
     */
    private function mysqlCommand(array $parts, string $path, bool $restore, array $tables = []): void
    {
        $database = ltrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new \InvalidArgumentException('Nom de base MySQL invalide.');
        }
        $defaults = null;
        try {
            $args = [$restore ? 'mysql' : 'mysqldump'];
            if (isset($parts['pass'])) {
                $defaults = tempnam(dirname($path), '.mysql-auth-');
                if ($defaults === false) {
                    throw new \RuntimeException('Fichier authentification indisponible.');
                }
                chmod($defaults, 0600);
                $password = rawurldecode((string) $parts['pass']);
                if (str_contains($password, "\n") || str_contains($password, "\r")) {
                    throw new \InvalidArgumentException('Mot de passe MySQL incompatible avec le fichier de configuration.');
                }
                file_put_contents($defaults, "[client]\npassword=\"".addcslashes($password, "\\\"")."\"\n");
                $args[] = '--defaults-extra-file='.$defaults;
            }
            if (!$restore) {
                array_push($args, '--single-transaction', '--routines', '--triggers', '--add-drop-table', '--result-file='.$path);
            }
            foreach (['host' => '--host=', 'port' => '--port=', 'user' => '--user='] as $key => $flag) {
                if (isset($parts[$key])) {
                    $args[] = $flag.rawurldecode((string) $parts[$key]);
                }
            }
            $args[] = $database;
            if (!$restore) {
                array_push($args, ...$tables);
            }
            $input = null;
            if ($restore) {
                $input = fopen($path, 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Impossible de lire le dump MySQL.');
                }
            }
            try {
                $this->run($args, [], $input);
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
            }
        } finally {
            if ($defaults !== null) {
                @unlink($defaults);
            }
        }
    }

    /** @param array<string, mixed> $url */
    private function sqliteBackup(array $url, string $path): void
    {
        $this->run(['sqlite3', $this->sqlitePath($url), '.backup '. $path]);
    }

    /** @param array<string, mixed> $url */
    private function sqlitePath(array $url): string
    {
        $database = rawurldecode((string) ($url['path'] ?? ''));
        if ($database === '' || $database === '/:memory:' || $database === ':memory:') {
            throw new \DomainException('SQLite en mémoire non sauvegardable.');
        }
        return $database;
    }

    /** @return array<string, mixed> */
    private function connection(string $url): array
    {
        // PHP parse_url() rejects the valid Doctrine sqlite:///absolute/path form.
        if (str_starts_with($url, 'sqlite:///')) {
            return ['scheme' => 'sqlite', 'path' => substr($url, strlen('sqlite://'))];
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException('DATABASE_URL invalide.');
        }
        return $parts;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments, array $env = [], mixed $input = null): void
    {
        $process = new Process($arguments, null, $env);
        if ($input !== null) {
            $process->setInput($input);
        }
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Sauvegarde SQL échouée (code '.$process->getExitCode().').');
        }
    }
}
