<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;
use SymSvel\Tooling\Module\ModuleMigrationRemovalExecutor;
use SymSvel\Tooling\Module\ModuleRemovalConfirmation;
use Symfony\Component\Process\Process;

final class ModuleMigrationRemovalExecutorIntegrationTest extends TestCase
{
    public function testDoctrineFailureRestoresSqlAndPreservesReceipts(): void
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

        $root = sys_get_temp_dir().'/symsvel-executor-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        try {
            (new ModuleMaker())->create($root, 'users');
            $manifestPath = $root.'/modules/users/module.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['tables'] = ['users'];
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $migration = $root.'/modules/users/Migration/001.php';
            file_put_contents($migration, '<?php // test migration');
            $version = 'DoctrineMigrations\\Version202610270001';
            $receipts = ['users/001.php' => ['sha256' => hash_file('sha256', $migration), 'doctrine' => $version]];
            $registry = new ModuleMigrationRegistry();
            $registry->write($root, $receipts);

            mkdir($root.'/migrations');
            file_put_contents($root.'/migrations/Version202610270001.php', '<?php // compiled migration');
            mkdir($root.'/bin');
            file_put_contents($root.'/bin/console', "<?php fwrite(STDERR, 'simulated Doctrine failure'); exit(1);\n");

            $db = $root.'/database.sqlite';
            $url = 'sqlite://'.$db;
            (new Process(['sqlite3', $db, 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users (name) VALUES ("original");']))->mustRun();

            $plan = [
                'modules' => ['users'],
                'versions' => [$version],
                'sources' => ['users/001.php'],
                'tables' => ['users'],
            ];
            $fingerprint = (new ModuleRemovalConfirmation())->fingerprint($root, $plan);
            try {
                (new ModuleMigrationRemovalExecutor())->run($root, $root, $url, 'users', false, $fingerprint);
                self::fail('Expected Doctrine failure.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('SQL restauré', $failure->getMessage());
            }

            $query = new Process(['sqlite3', $db, 'SELECT name FROM users LIMIT 1;']);
            $query->mustRun();
            self::assertSame('original', trim($query->getOutput()));
            self::assertSame($receipts, $registry->read($root));
            $journal = json_decode((string) file_get_contents($root.'/.symsvel/module-removal-recovery.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('sql_restored', $journal['state']);
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }
    public function testSuccessfulRemovalCommitsSqlAndReceipts(): void
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

        $root = sys_get_temp_dir().'/symsvel-executor-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        try {
            (new ModuleMaker())->create($root, 'users');
            $manifestPath = $root.'/modules/users/module.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['tables'] = ['users'];
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $migration = $root.'/modules/users/Migration/001.php';
            file_put_contents($migration, '<?php // test migration');
            $version = 'DoctrineMigrations\\Version202610270001';
            $receipts = ['users/001.php' => ['sha256' => hash_file('sha256', $migration), 'doctrine' => $version]];
            $registry = new ModuleMigrationRegistry();
            $registry->write($root, $receipts);

            mkdir($root.'/migrations');
            file_put_contents($root.'/migrations/Version202610270001.php', '<?php // compiled migration');
            mkdir($root.'/bin');
            file_put_contents($root.'/bin/console', "<?php passthru('sqlite3 '.escapeshellarg(__DIR__.'/../database.sqlite').' '.escapeshellarg('DROP TABLE users;'), \$status); exit(\$status);\n");

            $db = $root.'/database.sqlite';
            $url = 'sqlite://'.$db;
            (new Process(['sqlite3', $db, 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users (name) VALUES ("original");']))->mustRun();

            $plan = [
                'modules' => ['users'],
                'versions' => [$version],
                'sources' => ['users/001.php'],
                'tables' => ['users'],
            ];
            $fingerprint = (new ModuleRemovalConfirmation())->fingerprint($root, $plan);
            (new ModuleMigrationRemovalExecutor())->run($root, $root, $url, 'users', false, $fingerprint);

            $query = new Process(['sqlite3', $db, "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='users';"]);
            $query->mustRun();
            self::assertSame('0', trim($query->getOutput()));
            self::assertSame([], $registry->read($root));
            self::assertFileDoesNotExist($root.'/.symsvel/module-removal-recovery.json');
            self::assertSame([], glob($root.'/.symsvel/.module-backup-*'));
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }
}
