<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleInventoryWriter;
use SymSvel\Tooling\Module\ModuleMaker;

final class ModuleInventoryWriterTest extends TestCase
{
    public function testPublishesAndReusesInventory(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-inventory-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            (new ModuleMaker())->create($workspace, 'catalog', 'symsvel-framework', 'bo');
            $writer = new ModuleInventoryWriter();
            $path = $writer->write($workspace);
            $contents = (string) file_get_contents($path);
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $data['schema']);
            self::assertSame('catalog', $data['modules'][0]['module']);
            self::assertSame($path, $writer->write($workspace));
            self::assertSame($contents, file_get_contents($path));
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
