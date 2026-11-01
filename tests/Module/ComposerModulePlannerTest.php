<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ComposerModulePlanner;
use SymSvel\Tooling\Module\ComposerModuleRegistry;
use SymSvel\Tooling\Module\ComposerModuleSynchronizer;
use SymSvel\Tooling\Module\ComposerModuleInventoryWriter;
use SymSvel\Tooling\Module\ComposerModuleBundleMapWriter;
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

    public function testComposerInventoryIsPublishedAtomicallyAndIdempotently(): void
    {
        $this->module('my-base');
        $this->module('my-feature', ['my-base' => '^1.0']);
        $this->installed(['my-feature', 'my-base']);

        $writer = new ComposerModuleInventoryWriter();
        $target = $writer->write($this->workspace);
        self::assertSame($this->workspace.'/.symsvel/modules.json', $target);
        $content = (string) file_get_contents($target);
        $inventory = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $inventory['schema']);
        self::assertSame(['my-base', 'my-feature'], array_column($inventory['modules'], 'module'));
        self::assertSame($target, $writer->write($this->workspace));
        self::assertSame($content, file_get_contents($target));
    }

    public function testInvalidComposerDependenciesDoNotOverwritePublishedInventory(): void
    {
        $this->module('my-base');
        $this->installed(['my-base']);
        $writer = new ComposerModuleInventoryWriter();
        $target = $writer->write($this->workspace);
        $before = (string) file_get_contents($target);
        $this->module('my-feature', ['missing-module' => '^1.0']);
        $this->installed(['my-base', 'my-feature']);

        try {
            $writer->write($this->workspace);
            self::fail('Missing Composer dependency should reject publication.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('introuvable', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($target));
    }

    public function testComposerBundleMapContainsInstalledModuleClasses(): void
    {
        $this->module('my-base');
        $this->module('my-feature', ['my-base' => '^1.0']);
        $this->installed(['my-feature', 'my-base']);

        $writer = new ComposerModuleBundleMapWriter();
        $target = $writer->write($this->workspace);
        self::assertSame($this->workspace.'/.symsvel/bundles.php', $target);
        $classes = require $target;
        self::assertSame([
            'SymSvel\\Module\\MyBase\\MyBaseBundle',
            'SymSvel\\Module\\MyFeature\\MyFeatureBundle',
        ], $classes);
        $before = file_get_contents($target);
        self::assertSame($target, $writer->write($this->workspace));
        self::assertSame($before, file_get_contents($target));
    }

    public function testMissingBundleDoesNotOverwritePreviouslyPublishedFiles(): void
    {
        $this->module('my-base');
        $this->installed(['my-base']);
        $inventoryWriter = new ComposerModuleInventoryWriter();
        $bundleWriter = new ComposerModuleBundleMapWriter();
        $inventory = $inventoryWriter->write($this->workspace);
        $bundleMap = $bundleWriter->write($this->workspace);
        $previousInventory = (string) file_get_contents($inventory);
        $previousMap = (string) file_get_contents($bundleMap);

        unlink($this->workspace.'/modules/my-base/back/src/MyBaseBundle.php');

        try {
            $bundleWriter->write($this->workspace);
            self::fail('Missing bundle must prevent publication.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Bundle manquant', $exception->getMessage());
        }
        self::assertSame($previousInventory, file_get_contents($inventory));
        self::assertSame($previousMap, file_get_contents($bundleMap));
    }


    public function testComposerMigrationCatalogDiscoversSourcesInDependencyOrder(): void
    {
        $this->module('my-base');
        $this->module('my-feature', ['my-base' => '^1.0']);
        $this->installed(['my-feature', 'my-base']);
        $base = $this->workspace.'/modules/my-base/Migration';
        $feature = $this->workspace.'/modules/my-feature/Migration';
        mkdir($base, 0775, true);
        mkdir($feature, 0775, true);
        file_put_contents($base.'/001_create.php', '<?php // base');
        file_put_contents($feature.'/002_create.php', '<?php // feature');

        $sources = (new \\SymSvel\\Tooling\\Module\\ComposerModuleMigrationCatalog())->discover($this->workspace);
        self::assertSame(['my-base', 'my-feature'], array_column($sources, 'module'));
        self::assertSame(['001_create.php', '002_create.php'], array_column($sources, 'name'));
        self::assertSame(hash_file('sha256', $base.'/001_create.php'), $sources[0]['sha256']);
        self::assertSame(hash_file('sha256', $feature.'/002_create.php'), $sources[1]['sha256']);
    }

    public function testComposerMigrationCatalogRejectsSymlinkedMigrationDirectory(): void
    {
        $this->module('my-base');
        $this->installed(['my-base']);
        symlink($this->workspace.'/vendor/composer', $this->workspace.'/modules/my-base/Migration');
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('Répertoire Migration invalide');
        (new \\SymSvel\\Tooling\\Module\\ComposerModuleMigrationCatalog())->discover($this->workspace);
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
