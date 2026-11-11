<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleWorkingDirectoryResolver;

final class ModuleWorkingDirectoryResolverTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-module-resolver-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0775, true);
        (new ModuleMaker())->create($this->workspace, 'catalogue');
        (new ModuleMaker())->create($this->workspace, 'other-module');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->workspace);
    }

    public function testDetectsModuleFromNestedDirectory(): void
    {
        $result = (new ModuleWorkingDirectoryResolver())->resolve(
            $this->workspace,
            null,
            $this->workspace.'/modules/catalogue/back/src/Entity',
        );
        self::assertSame('catalogue', $result['slug']);
    }

    public function testExplicitNameWinsOverCurrentDirectory(): void
    {
        $result = (new ModuleWorkingDirectoryResolver())->resolve(
            $this->workspace,
            'OtherModule',
            $this->workspace.'/modules/catalogue',
        );
        self::assertSame('other-module', $result['slug']);
    }

    public function testRefusesWorkspaceRootWithoutModuleName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModuleWorkingDirectoryResolver())->resolve($this->workspace, null, $this->workspace);
    }

    public function testRefusesUnknownExplicitModule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModuleWorkingDirectoryResolver())->resolve($this->workspace, 'Unknown', $this->workspace);
    }
}
