<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ComposerModulePlanner;
use SymSvel\Tooling\Module\ComposerModuleRegistry;
use SymSvel\Tooling\Module\ComposerModuleSynchronizer;
use SymSvel\Tooling\Module\ModuleMaker;

final class ComposerModulePlannerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-composer-plan-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/vendor/composer', 0777, true);
        mkdir($this->workspace.'/vendor/symsvel-framework', 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($this->workspace);
    }

    public function testOrdersDependenciesAndBuildsReadOnlyPlan(): void
    {
        $this->module('my-base');
        $this->module('my-feature', ['my-base' => '^1.0']);
        $this->installed(['my-feature', 'my-base']);
        $planner = new ComposerModulePlanner();
        self::assertSame(['my-base', 'my-feature'], $planner->plan($this->workspace));
        $steps = (new ComposerModuleSynchronizer())->prepare($this->workspace);
        self::assertSame(['my-base', 'my-feature'], array_column($steps, 'module'));
        self::assertSame(['back'], array_keys($steps[0]['components']));
        self::assertSame(['dev'], array_keys($steps[0]['documentation']));
    }

    public function testRejectsMissingDependency(): void
    {
        $this->module('my-feature', ['my-base' => '^1.0']);
        $this->installed(['my-feature']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Module Composer dépendant introuvable');
        (new ComposerModulePlanner())->plan($this->workspace);
    }

    public function testRejectsCircularDependencies(): void
    {
        $this->module('my-base', ['my-feature' => '^1.0']);
        $this->module('my-feature', ['my-base' => '^1.0']);
        $this->installed(['my-base', 'my-feature']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dépendance circulaire');
        (new ComposerModulePlanner())->plan($this->workspace);
    }

    /** @param array<string, string> $dependencies */
    private function module(string $slug, array $dependencies = []): void
    {
        $source = (new ModuleMaker())->create($this->workspace, $slug);
        $manifestPath = $source.'/module.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['dependencies'] = $dependencies;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        symlink($source, $this->workspace.'/vendor/symsvel-framework/module-'.$slug);
    }

    /** @param list<string> $slugs */
    private function installed(array $slugs): void
    {
        $packages = [];
        foreach ($slugs as $slug) {
            $packages[] = ['name' => 'symsvel-framework/module-'.$slug, 'install-path' => '../symsvel-framework/module-'.$slug];
        }
        file_put_contents(
            $this->workspace.'/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR),
        );
    }
}
