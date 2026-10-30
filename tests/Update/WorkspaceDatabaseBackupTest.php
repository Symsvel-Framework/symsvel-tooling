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
        $backup->capture(sys_get_temp_dir(), 'mysql://localhost/example');
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
