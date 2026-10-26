<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleBundleMapWriter;
use SymSvel\Tooling\Module\ModuleMaker;

final class ModuleBundleMapWriterTest extends TestCase
{
    public function testWritesStableBundleMap(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-map-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            (new ModuleMaker())->create($workspace, 'catalog');
            $writer = new ModuleBundleMapWriter();
            $file = $writer->write($workspace);
            self::assertSame(['SymSvel\\Module\\Catalog\\CatalogBundle'], require $file);
            $before = file_get_contents($file);
            self::assertSame($file, $writer->write($workspace));
            self::assertSame($before, file_get_contents($file));
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
