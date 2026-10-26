<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModulePlanner;

final class ModulePlannerTest extends TestCase
{
    public function testOrdersDependenciesAndRejectsCycles(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-plan-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $maker = new ModuleMaker();
            $maker->create($workspace, 'alpha');
            $maker->create($workspace, 'beta');
            $this->dependencies($workspace, 'alpha', ['beta' => '*']);
            self::assertSame(['beta', 'alpha'], (new ModulePlanner())->plan($workspace));
            $this->dependencies($workspace, 'beta', ['alpha' => '*']);
            $this->expectException(\InvalidArgumentException::class);
            (new ModulePlanner())->plan($workspace);
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

    private function dependencies(string $workspace, string $name, array $dependencies): void
    {
        $file = $workspace.'/modules/'.$name.'/module.json';
        $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $manifest['dependencies'] = $dependencies;
        file_put_contents($file, json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
