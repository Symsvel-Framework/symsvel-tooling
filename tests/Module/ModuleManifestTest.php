<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleManifest;

final class ModuleManifestTest extends TestCase
{
    public function testReadsGeneratedModule(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-manifest-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $module = (new ModuleMaker())->create($workspace, 'documents', 'symsvel-framework', 'app');
            $manifest = (new ModuleManifest())->read($module);
            self::assertSame(['back', 'bo', 'app'], array_keys($manifest['components']));
            self::assertSame(['dev', 'admin', 'user'], array_keys($manifest['documentation']));
            self::assertSame([], $manifest['tables']);
            $manifest['components']['front'] = 'front';
            file_put_contents($module.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->expectException(\InvalidArgumentException::class);
            (new ModuleManifest())->read($module);
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
    public function testRejectsDuplicateTableOwnership(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-manifest-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $module = (new ModuleMaker())->create($workspace, 'documents');
            $manifest = (new ModuleManifest())->read($module);
            $manifest['tables'] = ['documents', 'documents'];
            file_put_contents($module.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->expectException(\InvalidArgumentException::class);
            (new ModuleManifest())->read($module);
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
