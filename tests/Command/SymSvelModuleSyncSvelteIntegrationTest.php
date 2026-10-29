<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Command\SymSvelModuleSyncCommand;
use SymSvel\Tooling\Module\ModuleMaker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SymSvelModuleSyncSvelteIntegrationTest extends TestCase
{
    public function testComposerSyncPublishesAndRemovesSveltePages(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-sync-cli-'.bin2hex(random_bytes(6));
        $backend = $workspace.'/backend';
        mkdir($backend.'/vendor/composer', 0775, true);
        mkdir($backend.'/vendor/symsvel-framework', 0775, true);
        mkdir($workspace.'/bo/src/lib', 0775, true);
        mkdir($workspace.'/bo/src/routes', 0775, true);
        try {
            $module = (new ModuleMaker())->create($workspace, 'catalog', 'symsvel-framework', 'bo');
            mkdir($module.'/bo/src/routes/catalog', 0775, true);
            file_put_contents($module.'/bo/src/routes/catalog/+page.svelte', '<h1>Catalog</h1>');
            symlink($module, $backend.'/vendor/symsvel-framework/module-catalog');
            $installed = $backend.'/vendor/composer/installed.json';
            file_put_contents($installed, json_encode(['packages' => [['name' => 'symsvel-framework/module-catalog', 'install-path' => '../symsvel-framework/module-catalog']]], JSON_THROW_ON_ERROR));
            $tester = new CommandTester(new SymSvelModuleSyncCommand($backend));
            self::assertSame(Command::SUCCESS, $tester->execute(['--source' => 'composer']));
            self::assertFileExists($workspace.'/bo/src/routes/catalog/+page.svelte');
            self::assertFileExists($backend.'/.symsvel/modules.json');
            self::assertSame(Command::SUCCESS, $tester->execute(['--source' => 'composer']));
            file_put_contents($installed, json_encode(['packages' => []], JSON_THROW_ON_ERROR));
            self::assertSame(Command::SUCCESS, $tester->execute(['--source' => 'composer']));
            self::assertFileDoesNotExist($workspace.'/bo/src/routes/catalog/+page.svelte');
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
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
}
