<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleRemovalTransaction;
use Symfony\Component\Process\Process;

final class ModuleRemovalSqliteIntegrationTest extends TestCase
{
    private string $root;
    private string $database;
    private string $url;

    protected function setUp(): void
    {
        $probe = new Process(['sqlite3', '--version']);
        try {
            $probe->run();
        } catch (\Throwable) {
            self::markTestSkipped('sqlite3 executable unavailable.');
        }
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('sqlite3 executable unavailable.');
        }
        $this->root = sys_get_temp_dir().'/symsvel-sqlite-removal-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->database = $this->root.'/database.sqlite';
        $this->url = 'sqlite://'.$this->database;
        $this->sql('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users (name) VALUES ("original");');
    }

    protected function tearDown(): void
    {
        if (!isset($this->root)) {
            return;
        }
        foreach (glob($this->root.'/.symsvel/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->root.'/.symsvel')) {
            rmdir($this->root.'/.symsvel');
        }
        if (is_file($this->database)) {
            unlink($this->database);
        }
        rmdir($this->root);
    }

    public function testSuccessfulRemovalCommitsAndCleansBackup(): void
    {
        (new ModuleRemovalTransaction())->run($this->root, $this->url, ['users'], function (): void {
            $this->sql('DROP TABLE users;');
        });
        self::assertSame('0', trim($this->sql("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='users';")));
        self::assertSame([], glob($this->root.'/.symsvel/*') ?: []);
        self::assertSame([], glob($this->root.'/.symsvel/.module-backup-*') ?: []);
    }

    public function testFailureRestoresSqlAndKeepsRecoveryArtifacts(): void
    {
        try {
            (new ModuleRemovalTransaction())->run($this->root, $this->url, ['users'], function (): void {
                $this->sql('DROP TABLE users;');
                throw new \RuntimeException('simulated removal failure');
            });
            self::fail('Expected removal failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('SQL restauré', $exception->getMessage());
        }
        self::assertSame('original', trim($this->sql('SELECT name FROM users LIMIT 1;')));
        $journal = $this->root.'/.symsvel/module-removal-recovery.json';
        self::assertFileExists($journal);
        $state = json_decode((string) file_get_contents($journal), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sql_restored', $state['state']);
        self::assertFileExists($state['backup']['path']);
    }

    public function testPendingRecoveryPreventsSecondRemoval(): void
    {
        try {
            (new ModuleRemovalTransaction())->run($this->root, $this->url, ['users'], function (): void {
                $this->sql('DROP TABLE users;');
                throw new \RuntimeException('simulated failure');
            });
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('SQL restauré', $failure->getMessage());
        }

        $called = false;
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Récupération en attente');
            (new ModuleRemovalTransaction())->run($this->root, $this->url, ['users'], static function () use (&$called): void {
                $called = true;
            });
        } finally {
            self::assertFalse($called);
            self::assertSame('original', trim($this->sql('SELECT name FROM users LIMIT 1;')));
        }
    }

    public function testFailedRemovalRestoresOtherTablesAsWell(): void
    {
        $this->sql('CREATE TABLE settings (value TEXT); INSERT INTO settings VALUES ("keep");');
        try {
            (new ModuleRemovalTransaction())->run($this->root, $this->url, ['users'], function (): void {
                $this->sql('DROP TABLE users; UPDATE settings SET value = "changed";');
                throw new \RuntimeException('simulated failure');
            });
            self::fail('Expected removal failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('SQL restauré', $exception->getMessage());
        }

        self::assertSame('original', trim($this->sql('SELECT name FROM users LIMIT 1;')));
        self::assertSame('keep', trim($this->sql('SELECT value FROM settings LIMIT 1;')));
    }

    private function sql(string $statement): string
    {
        $process = new Process(['sqlite3', $this->database, $statement]);
        $process->mustRun();
        return $process->getOutput();
    }
}
