<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleBundleResolver;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleRegistry;

final class ModuleBundleResolverTest extends TestCase
{
    public function testResolvesGeneratedBundleAndRejectsMissingBundle(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-bundles-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $path = (new ModuleMaker())->create($workspace, 'my-catalog');
            $registry = new ModuleRegistry();
            $resolver = new ModuleBundleResolver();
            $bundles = $resolver->resolve($registry->discover($workspace));
            self::assertSame('SymSvel\\Module\\MyCatalog\\MyCatalogBundle', $bundles[0]['class']);
            unlink($path.'/back/src/MyCatalogBundle.php');
            $this->expectException(\InvalidArgumentException::class);
            $resolver->resolve($registry->discover($workspace));
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
