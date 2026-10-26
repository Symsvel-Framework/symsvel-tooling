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
        $url = parse_url($databaseUrl);
        if ($url === false || !isset($url['scheme'])) {
            throw new \InvalidArgumentException('DATABASE_URL invalide.');
        }
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

    /** @param list<string> $tables */
    private function postgresBackup(string $url, array $tables, string $path): void
    {
        $args = ['pg_dump', '--format=custom', '--no-owner', '--no-acl', '--file='.$path];
        foreach ($tables as $table) {
            $args[] = '--table='.$table;
        }
        $args[] = $url;
        $this->run($args);
    }

    /** @param list<string> $tables */
    private function mysqlBackup(string $url, array $tables, string $path): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['path'])) {
            throw new \InvalidArgumentException('Base MySQL manquante.');
        }
        $database = ltrim($parts['path'], '/');
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new \InvalidArgumentException('Nom de base invalide.');
        }
        $args = ['mysqldump', '--single-transaction', '--routines', '--triggers', '--result-file='.$path];
        if (isset($parts['host'])) {
            $args[] = '--host='.$parts['host'];
        }
        if (isset($parts['port'])) {
            $args[] = '--port='.$parts['port'];
        }
        if (isset($parts['user'])) {
            $args[] = '--user='.rawurldecode($parts['user']);
        }
        // Never put credentials in process arguments or diagnostic output.
        $defaults = null;
        try {
            if (isset($parts['pass'])) {
                $defaults = tempnam(dirname($path), '.mysql-auth-');
                if ($defaults === false) {
                    throw new \RuntimeException('Fichier authentification indisponible.');
                }
                chmod($defaults, 0600);
                file_put_contents($defaults, "[client]\npassword=\"".addcslashes(rawurldecode($parts['pass']), "\\\"")."\"\n");
                array_splice($args, 1, 0, ['--defaults-extra-file='.$defaults]);
            }
            $args[] = $database;
            array_push($args, ...$tables);
            $this->run($args);
        } finally {
            if ($defaults !== null) {
                @unlink($defaults);
            }
        }
    }

    /** @param array<string, mixed> $url */
    private function sqliteBackup(array $url, string $path): void
    {
        $database = rawurldecode($url['path'] ?? '');
        if ($database === '' || $database === '/:memory:') {
            throw new \DomainException('SQLite en mémoire non sauvegardable.');
        }
        // SQLite uses a consistent full-file backup, not a partial table dump.
        $this->run(['sqlite3', $database, '.backup '. $path]);
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): void
    {
        $process = new Process($arguments);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Sauvegarde SQL échouée (code '.$process->getExitCode().').');
        }
    }
}
