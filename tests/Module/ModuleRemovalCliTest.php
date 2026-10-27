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
}
