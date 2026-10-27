<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use SymSvel\Tooling\Command\SymSvelModulePlanCommand;
use SymSvel\Tooling\Module\ModuleMaker;

final class SymSvelModulePlanCommandTest extends TestCase
{
    public function testPrintsReadOnlyPlanAsJson(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-plan-cli-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            (new ModuleMaker())->create($workspace, 'catalog');
            $tester = new CommandTester(new SymSvelModulePlanCommand($workspace.'/backend'));
            self::assertSame(Command::SUCCESS, $tester->execute(['--workspace' => $workspace, '--json' => true]));
            $plan = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('catalog', $plan[0]['module']);
            self::assertSame(['back'], array_keys($plan[0]['components']));
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($workspace);
        }
    }
    public function testComposerSourceDisplaysInstalledModulesAsJson(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-composer-cli-'.bin2hex(random_bytes(6));
        mkdir($workspace.'/backend/vendor/composer', 0777, true);
        mkdir($workspace.'/backend/vendor/symsvel-framework', 0777, true);
        try {
            $source = (new ModuleMaker())->create($workspace, 'catalog');
            symlink($source, $workspace.'/backend/vendor/symsvel-framework/module-catalog');
            file_put_contents(
                $workspace.'/backend/vendor/composer/installed.json',
                json_encode(['packages' => [[
                    'name' => 'symsvel-framework/module-catalog',
                    'install-path' => '../symsvel-framework/module-catalog',
                ]]], JSON_THROW_ON_ERROR),
            );
            $tester = new CommandTester(new SymSvelModulePlanCommand($workspace.'/backend'));
            self::assertSame(Command::SUCCESS, $tester->execute([
                '--workspace' => $workspace,
                '--source' => 'composer',
                '--json' => true,
            ]));
            $plan = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('catalog', $plan[0]['module']);
            self::assertSame(realpath($source), $plan[0]['path']);
            self::assertFileDoesNotExist($workspace.'/.symsvel/modules.json');
        } finally {
            $iterator = new \\RecursiveIteratorIterator(
                new \\RecursiveDirectoryIterator($workspace, \\FilesystemIterator::SKIP_DOTS),
                \\RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isLink() || $item->isFile()) {
                    unlink($item->getPathname());
                } else {
                    rmdir($item->getPathname());
                }
            }
            rmdir($workspace);
        }
    }

    public function testRejectsUnknownSource(): void
    {
        $tester = new CommandTester(new SymSvelModulePlanCommand(sys_get_temp_dir().'/backend'));
        self::assertSame(Command::INVALID, $tester->execute([
            '--workspace' => sys_get_temp_dir(),
            '--source' => 'unknown',
        ]));
    }

}
