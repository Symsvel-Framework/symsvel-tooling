<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleSynchronizer;

final class ModuleSynchronizerTest extends TestCase
{
    public function testPreparationIsDeterministicAndDoesNotModifyModules(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-sync-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $path = (new ModuleMaker())->create($workspace, 'catalog', 'symsvel-framework', 'front');
            $before = file_get_contents($path.'/module.json');
            $sync = new ModuleSynchronizer();
            $plan = $sync->prepare($workspace);
            self::assertSame($plan, $sync->prepare($workspace));
            self::assertCount(1, $plan);
            self::assertSame('catalog', $plan[0]['module']);
            self::assertSame(['back', 'bo', 'front'], array_keys($plan[0]['components']));
            self::assertSame(['dev', 'admin', 'user'], array_keys($plan[0]['documentation']));
            self::assertSame($before, file_get_contents($path.'/module.json'));
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
}
