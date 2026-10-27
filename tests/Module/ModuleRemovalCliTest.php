<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Command\SymSvelModuleRemovalMigrationsExecuteCommand;
use SymSvel\Tooling\Command\SymSvelModuleRemovalMigrationsPlanCommand;
use SymSvel\Tooling\Module\ModuleMaker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ModuleRemovalCliTest extends TestCase
{
    public function testExecuteRejectsMissingConfirmationBeforeDatabaseAccess(): void
    {
        $tester = new CommandTester(new SymSvelModuleRemovalMigrationsExecuteCommand(sys_get_temp_dir()));
        self::assertSame(Command::INVALID, $tester->execute(['module' => 'users']));
        self::assertStringContainsString('Confirmation obligatoire', $tester->getDisplay());
    }

    public function testExecuteRejectsMalformedConfirmationBeforeDatabaseAccess(): void
    {
        $tester = new CommandTester(new SymSvelModuleRemovalMigrationsExecuteCommand(sys_get_temp_dir()));
        self::assertSame(Command::INVALID, $tester->execute(['module' => 'users', '--confirm' => 'yes']));
        self::assertStringContainsString('Confirmation obligatoire', $tester->getDisplay());
    }
    public function testPreviewProducesConfirmationAcceptedByExecutionGate(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-cli-'.bin2hex(random_bytes(6));
        mkdir($workspace, 0700);
        try {
            (new ModuleMaker())->create($workspace, 'users');
            $manifestPath = $workspace.'/modules/users/module.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['tables'] = ['users'];
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

            $preview = new CommandTester(new SymSvelModuleRemovalMigrationsPlanCommand($workspace));
            self::assertSame(Command::SUCCESS, $preview->execute([
                'module' => 'users',
                '--workspace' => $workspace,
                '--json' => true,
            ]));
            $plan = json_decode($preview->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['users'], $plan['modules']);
            self::assertSame(['users'], $plan['tables']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $plan['confirmation']);

            // No DATABASE_URL: the CLI must refuse execution before touching SQL.
            // The preview itself must not create a removal recovery journal.
            self::assertFileDoesNotExist($workspace.'/.symsvel/module-removal-recovery.json');
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($workspace);
        }
    }
    public function testPreviewConfirmationExecutesSqliteRemovalEndToEnd(): void
    {
        $probe = new \Symfony\Component\Process\Process(['sqlite3', '--version']);
        try {
            $probe->run();
        } catch (\Throwable) {
            self::markTestSkipped('sqlite3 executable unavailable.');
        }
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('sqlite3 executable unavailable.');
        }

        $workspace = sys_get_temp_dir().'/symsvel-cli-e2e-'.bin2hex(random_bytes(6));
        mkdir($workspace, 0700);
        try {
            (new ModuleMaker())->create($workspace, 'users');
            $manifestPath = $workspace.'/modules/users/module.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['tables'] = ['users'];
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

            $source = $workspace.'/modules/users/Migration/001.php';
            file_put_contents($source, '<?php // users migration');
            $version = 'DoctrineMigrations\\Version202610270001';
            (new \SymSvel\Tooling\Module\ModuleMigrationRegistry())->write($workspace, [
                'users/001.php' => ['sha256' => hash_file('sha256', $source), 'doctrine' => $version],
            ]);
            mkdir($workspace.'/migrations');
            file_put_contents($workspace.'/migrations/Version202610270001.php', '<?php // compiled migration');
            mkdir($workspace.'/bin');
            $script = <<<'PHP'
<?php
$database = dirname(__DIR__).'/database.sqlite';
passthru('sqlite3 '.escapeshellarg($database).' '.escapeshellarg('DROP TABLE users;'), $status);
exit($status);
PHP;
            file_put_contents($workspace.'/bin/console', $script);

            $db = $workspace.'/database.sqlite';
            (new \Symfony\Component\Process\Process([
                'sqlite3', $db, 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users (name) VALUES ("original");',
            ]))->mustRun();

            $preview = new CommandTester(new SymSvelModuleRemovalMigrationsPlanCommand($workspace));
            self::assertSame(Command::SUCCESS, $preview->execute([
                'module' => 'users', '--workspace' => $workspace, '--json' => true,
            ]));
            $plan = json_decode($preview->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['users'], $plan['tables']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $plan['confirmation']);

            // The execution command reads DATABASE_URL from the Symfony environment.
            $oldServer = $_SERVER['DATABASE_URL'] ?? null;
            $_SERVER['DATABASE_URL'] = 'sqlite://'.$db;
            try {
                $execute = new CommandTester(new SymSvelModuleRemovalMigrationsExecuteCommand($workspace));
                self::assertSame(Command::SUCCESS, $execute->execute([
                    'module' => 'users',
                    '--workspace' => $workspace,
                    '--confirm' => $plan['confirmation'],
                ]), $execute->getDisplay());
            } finally {
                if ($oldServer === null) {
                    unset($_SERVER['DATABASE_URL']);
                } else {
                    $_SERVER['DATABASE_URL'] = $oldServer;
                }
            }

            $check = new \Symfony\Component\Process\Process([
                'sqlite3', $db, "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='users';",
            ]);
            $check->mustRun();
            self::assertSame('0', trim($check->getOutput()));
            self::assertSame([], (new \SymSvel\Tooling\Module\ModuleMigrationRegistry())->read($workspace));
            self::assertFileDoesNotExist($workspace.'/.symsvel/module-removal-recovery.json');
            self::assertSame([], glob($workspace.'/.symsvel/.module-backup-*'));
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($workspace);
        }
    }
}
