<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleRegistry;

final class ModuleRegistryTest extends TestCase
{
    public function testEmptyWorkspaceAndGeneratedModule(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-registry-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $registry = new ModuleRegistry();
            self::assertSame([], $registry->discover($workspace));
            (new ModuleMaker())->create($workspace, 'my-module');
            $modules = $registry->discover($workspace);
            self::assertSame(['my-module'], array_keys($modules));
            self::assertSame('MyModule', $modules['my-module']['manifest']['name']);
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
