<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\WorkspaceDatabaseBackup;

final class WorkspaceDatabaseBackupTest extends TestCase
{
    public function testRejectsUnsupportedDatabaseBeforeCreatingBackup(): void
    {
        $backup = new WorkspaceDatabaseBackup();
        $this->expectException(\DomainException::class);
        $backup->capture(sys_get_temp_dir(), 'oracle://localhost/example');
    }

    public function testMysqlAndMariaDbRejectSystemDatabaseBeforeDump(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            try {
                (new WorkspaceDatabaseBackup())->capture(sys_get_temp_dir(), $driver.'://localhost/mysql');
                self::fail('System database checkpoint must be refused.');
            } catch (\DomainException $error) {
                self::assertStringContainsString('base système interdite', $error->getMessage());
            }
        }
    }

    public function testMysqlAndMariaDbRejectMismatchedArchiveBeforeRestore(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'symsvel-mysql-checkpoint-');
        self::assertNotFalse($path);
        file_put_contents($path, '-- Current Database: `another_database`');
        try {
            foreach (['mysql', 'mariadb'] as $driver) {
                try {
                    (new WorkspaceDatabaseBackup())->restore($driver.'://localhost/example', [
                        'driver' => $driver, 'path' => $path,
                    ]);
                    self::fail('Mismatched database archive must be refused.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('Archive MySQL/MariaDB invalide', $error->getMessage());
                }
            }
            self::assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    public function testMysqlAndMariaDbRestoreOriginalDataAndDropNewTables(): void
    {
        foreach (['mysql' => 'SYMSVEL_TEST_MYSQL_URL', 'mariadb' => 'SYMSVEL_TEST_MARIADB_URL'] as $driver => $variable) {
            $url = getenv($variable);
            if (!is_string($url) || $url === '') {
                continue;
            }
            $parts = parse_url($url);
            if ($parts === false || ($parts['path'] ?? '') !== '/symsvel_rollback_ci'
                || ($parts['scheme'] ?? '') !== $driver) {
                self::fail('Destructive MySQL/MariaDB tests require a dedicated symsvel_rollback_ci database.');
            }
            $args = ['mysql'];
            foreach (['host' => '--host=', 'port' => '--port=', 'user' => '--user='] as $key => $prefix) {
                if (isset($parts[$key])) {
                    $args[] = $prefix.rawurldecode((string) $parts[$key]);
                }
            }
            $args[] = '--database=symsvel_rollback_ci';
            $env = isset($parts['pass']) ? ['MYSQL_PWD' => rawurldecode((string) $parts['pass'])] : [];
            $sql = static function (string $query) use ($args, $env): string {
                $process = new Process([...$args, '--execute='.$query], null, $env);
                $process->mustRun();
                return trim($process->getOutput());
            };
            $root = sys_get_temp_dir().'/symsvel-'.$driver.'-rollback-'.bin2hex(random_bytes(8));
            mkdir($root);
            $backup = new WorkspaceDatabaseBackup();
            $checkpoint = null;
            try {
                $sql('DROP TABLE IF EXISTS symsvel_new_table; DROP TABLE IF EXISTS symsvel_initial_table; CREATE TABLE symsvel_initial_table (id INTEGER PRIMARY KEY, label VARCHAR(64)); INSERT INTO symsvel_initial_table VALUES (1, \'original\');');
                $checkpoint = $backup->capture($root, $url);
                $sql('UPDATE symsvel_initial_table SET label = \'changed\'; CREATE TABLE symsvel_new_table (id INTEGER); INSERT INTO symsvel_new_table VALUES (2);');
                $backup->restore($url, $checkpoint);
                self::assertSame('original', $sql('SELECT label FROM symsvel_initial_table WHERE id=1'));
                self::assertSame('0', $sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='symsvel_rollback_ci' AND table_name='symsvel_new_table'"));
            } finally {
                if ($checkpoint !== null) {
                    $backup->discard($checkpoint);
                }
                @rmdir($root.'/.symsvel/transactions');
                @rmdir($root.'/.symsvel');
                @rmdir($root);
            }
        }
        if (!getenv('SYMSVEL_TEST_MYSQL_URL') && !getenv('SYMSVEL_TEST_MARIADB_URL')) {
            self::markTestSkipped('Dedicated MySQL/MariaDB integration databases not configured.');
        }
    }

    public function testRejectsMissingCheckpointWithoutModifyingDatabase(): void
    {
        $backup = new WorkspaceDatabaseBackup();
        $this->expectException(\RuntimeException::class);
        $backup->restore('sqlite:///tmp/test.db', [
            'driver' => 'sqlite',
            'path' => sys_get_temp_dir().'/.missing-symsvel-'.bin2hex(random_bytes(8)),
        ]);
    }

    public function testPostgresRestoreRejectsInvalidArchiveAndRetainsCheckpoint(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'symsvel-pg-checkpoint-');
        self::assertNotFalse($path);
        file_put_contents($path, 'archive placeholder');
        try {
            $backup = new WorkspaceDatabaseBackup();
            try {
                $backup->restore('postgresql://localhost/example', ['driver' => 'postgresql', 'path' => $path]);
                self::fail('Unsafe PostgreSQL restore must be refused.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Archive PostgreSQL illisible ou nom de base absent', $error->getMessage());
                self::assertFileExists($path);
            }
        } finally {
            @unlink($path);
        }
    }

    public function testPostgresRestoreRefusesMaintenanceDatabase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'symsvel-pg-checkpoint-');
        self::assertNotFalse($path);
        file_put_contents($path, 'archive placeholder');
        try {
            $backup = new WorkspaceDatabaseBackup();
            $this->expectException(\DomainException::class);
            $backup->restore('postgresql://localhost/postgres', ['driver' => 'postgresql', 'path' => $path]);
        } finally {
            @unlink($path);
        }
    }

    public function testPostgresRestoresOriginalDataAndDropsTablesCreatedAfterCheckpoint(): void
    {
        $url = getenv('SYMSVEL_TEST_POSTGRES_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Dedicated PostgreSQL integration database not configured.');
        }
        // Destructive integration test: never run against a non-CI database.
        $parts = parse_url($url);
        if ($parts === false || ($parts['path'] ?? '') !== '/symsvel_rollback_ci') {
            self::fail('PostgreSQL integration test requires the dedicated symsvel_rollback_ci database.');
        }
        $env = ['PGPASSWORD' => rawurldecode((string) ($parts['pass'] ?? ''))];
        $psql = static function (string $sql) use ($parts, $env): string {
            $command = [
                'psql', '-X', '-v', 'ON_ERROR_STOP=1',
                '--host='.($parts['host'] ?? '127.0.0.1'),
                '--port='.($parts['port'] ?? 5432),
                '--username='.rawurldecode((string) ($parts['user'] ?? '')),
                '--dbname=symsvel_rollback_ci', '-Atc', $sql,
            ];
            $process = new Process($command, null, $env);
            $process->mustRun();
            return trim($process->getOutput());
        };
        $root = sys_get_temp_dir().'/symsvel-pg-rollback-'.bin2hex(random_bytes(8));
        mkdir($root);
        $backup = new WorkspaceDatabaseBackup();
        $checkpoint = null;
        try {
            $psql('DROP TABLE IF EXISTS symsvel_new_table; DROP TABLE IF EXISTS symsvel_initial_table; CREATE TABLE symsvel_initial_table (id INTEGER PRIMARY KEY, label TEXT); INSERT INTO symsvel_initial_table VALUES (1, \'original\');');
            $checkpoint = $backup->capture($root, $url);
            $psql('UPDATE symsvel_initial_table SET label = \'changed\'; CREATE TABLE symsvel_new_table (id INTEGER); INSERT INTO symsvel_new_table VALUES (2);');
            $backup->restore($url, $checkpoint);
            self::assertSame('original', $psql('SELECT label FROM symsvel_initial_table WHERE id=1'));
            self::assertSame('0', $psql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='symsvel_new_table'"));
        } finally {
            if ($checkpoint !== null) {
                $backup->discard($checkpoint);
            }
            @rmdir($root.'/.symsvel/transactions');
            @rmdir($root.'/.symsvel');
            @rmdir($root);
        }
    }

    public function testSqliteCheckpointRestoresDataAndSchemaAfterMutation(): void
    {
        $probe = new Process(['sqlite3', '-version']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('sqlite3 executable unavailable.');
        }
        $root = sys_get_temp_dir().'/symsvel-db-checkpoint-'.bin2hex(random_bytes(8));
        mkdir($root);
        $database = $root.'/database.sqlite';
        $url = 'sqlite://'.$database;
        $this->sql($database, "CREATE TABLE example (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO example VALUES (1, 'initial');");
        $backup = new WorkspaceDatabaseBackup();
        $checkpoint = null;
        try {
            $checkpoint = $backup->capture($root, $url);
            $this->sql($database, "UPDATE example SET name = 'modified'; INSERT INTO example VALUES (2, 'new'); CREATE TABLE unexpected (id INTEGER); INSERT INTO unexpected VALUES (7);");
            $backup->restore($url, $checkpoint);
            self::assertSame('initial', trim($this->sql($database, 'SELECT name FROM example;')));
            self::assertSame('1', trim($this->sql($database, 'SELECT COUNT(*) FROM example;')));
            self::assertSame('0', trim($this->sql($database, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='unexpected';")));
        } finally {
            if ($checkpoint !== null) {
                $backup->discard($checkpoint);
            }
            @unlink($database);
            @rmdir($root.'/.symsvel/transactions');
            @rmdir($root.'/.symsvel');
            @rmdir($root);
        }
    }

    private function sql(string $database, string $query): string
    {
        $process = new Process(['sqlite3', $database, $query]);
        $process->mustRun();
        return $process->getOutput();
    }
}
