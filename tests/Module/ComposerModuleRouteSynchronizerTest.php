<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ComposerModuleRouteSynchronizer;
use SymSvel\Tooling\Module\ModuleMaker;

final class ComposerModuleRouteSynchronizerTest extends TestCase
{
    private string $workspace;
    private string $backend;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-composer-routes-'.bin2hex(random_bytes(6));
        $this->backend = $this->workspace.'/backend';
        mkdir($this->backend.'/vendor/composer', 0775, true);
        mkdir($this->backend.'/vendor/symsvel-framework', 0775, true);
        mkdir($this->backend.'/config/routes', 0775, true);
        $this->metadata([]);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($this->workspace);
    }

    public function testLocalComposerSymlinkInstallsIdempotentRoutesAndRemovesOnlyOwnedImport(): void
    {
        $this->install('sample', true);
        $sync = new ComposerModuleRouteSynchronizer();
        self::assertSame(['written' => 1, 'removed' => 0, 'external' => 0], $sync->sync($this->backend));
        $route = $this->backend.'/config/routes/sample.yaml';
        self::assertFileExists($route);
        self::assertStringContainsString(
            "../../vendor/symsvel-framework/module-sample/back/src/Controller/",
            (string) file_get_contents($route),
        );
        self::assertStringContainsString('namespace: SymSvel\\Module\\Sample\\Controller', (string) file_get_contents($route));
        self::assertSame(['written' => 0, 'removed' => 0, 'external' => 0], $sync->sync($this->backend));

        unlink($this->backend.'/vendor/symsvel-framework/module-sample');
        $this->metadata([]);
        self::assertSame(['written' => 0, 'removed' => 1, 'external' => 0], $sync->sync($this->backend));
        self::assertFileDoesNotExist($route);
        self::assertFileExists($this->backend.'/.symsvel/composer-routes.json');
    }

    public function testAControllerFreeModuleHasNoRouteAndDoesNotInventOne(): void
    {
        $this->install('empty', false);
        $result = (new ComposerModuleRouteSynchronizer())->sync($this->backend);
        self::assertSame(0, $result['written']);
        self::assertFileDoesNotExist($this->backend.'/config/routes/empty.yaml');
    }

    public function testPreExistingFlexOrApplicationRouteRemainsExternallyOwned(): void
    {
        $this->install('sample', true);
        $path = $this->backend.'/config/routes/sample.yaml';
        file_put_contents($path, "# Application-owned import\nsample_local: { path: /test, controller: sample }\n");
        $sync = new ComposerModuleRouteSynchronizer();
        self::assertSame(['written' => 0, 'removed' => 0, 'external' => 1], $sync->sync($this->backend));
        self::assertSame(['written' => 0, 'removed' => 0, 'external' => 1], $sync->sync($this->backend));
        unlink($this->backend.'/vendor/symsvel-framework/module-sample');
        $this->metadata([]);
        self::assertSame(0, $sync->sync($this->backend)['removed']);
        self::assertStringContainsString('Application-owned', (string) file_get_contents($path));
    }

    public function testAClassicComposerDistributionIsLeftToFlex(): void
    {
        $source = $this->install('sample', true);
        unlink($this->backend.'/vendor/symsvel-framework/module-sample');
        rename($source, $this->backend.'/vendor/symsvel-framework/module-sample');
        self::assertSame(0, (new ComposerModuleRouteSynchronizer())->sync($this->backend)['written']);
        self::assertFileDoesNotExist($this->backend.'/config/routes/sample.yaml');
    }

    public function testLocallyModifiedManagedImportBlocksTheSyncAndRemoval(): void
    {
        $this->install('sample', true);
        $sync = new ComposerModuleRouteSynchronizer();
        $sync->sync($this->backend);
        $path = $this->backend.'/config/routes/sample.yaml';
        file_put_contents($path, "# local change\n", FILE_APPEND);
        unlink($this->backend.'/vendor/symsvel-framework/module-sample');
        $this->metadata([]);
        try {
            $sync->sync($this->backend);
            self::fail('A locally modified route must fail closed.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('modifié', $e->getMessage());
        }
        self::assertStringContainsString('local change', (string) file_get_contents($path));
    }

    public function testUntrustedLedgerPathAndSymlinkCannotDeleteApplicationFiles(): void
    {
        mkdir($this->backend.'/.symsvel');
        $victim = $this->backend.'/config/routes/private.yaml';
        file_put_contents($victim, 'keep');
        file_put_contents($this->backend.'/.symsvel/composer-routes.json',
            json_encode(['schema' => 1, 'files' => ['../config/routes/private.yaml' => hash('sha256', 'keep')]], JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        try {
            (new ComposerModuleRouteSynchronizer())->sync($this->backend);
        } finally {
            self::assertSame('keep', file_get_contents($victim));
        }
    }

    public function testInvalidExplicitRecipeDoesNotSilentlyGenerateRoutes(): void
    {
        $source = $this->install('sample', true);
        $recipe = $source.'/recipe/recipe.json';
        $data = json_decode((string) file_get_contents($recipe), true, 32, JSON_THROW_ON_ERROR);
        $data['manifests']['symsvel-framework/module-sample']['files']['config/routes/sample.yaml']['contents'][3] = '        namespace: Other\\Controller';
        file_put_contents($recipe, json_encode($data, JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        (new ComposerModuleRouteSynchronizer())->sync($this->backend);
    }

    private function install(string $slug, bool $controller): string
    {
        $source = (new ModuleMaker())->create($this->workspace, $slug);
        if ($controller) {
            file_put_contents(
                $source.'/back/src/Controller/PingController.php',
                "<?php\nnamespace SymSvel\\Module\\".ucfirst($slug)."\\Controller;\nfinal class PingController {}\n",
            );
        }
        symlink($source, $this->backend.'/vendor/symsvel-framework/module-'.$slug);
        $this->metadata([$slug]);
        return $source;
    }

    /** @param list<string> $slugs */
    private function metadata(array $slugs): void
    {
        $packages = [];
        foreach ($slugs as $slug) {
            $packages[] = [
                'name' => 'symsvel-framework/module-'.$slug,
                'install-path' => '../symsvel-framework/module-'.$slug,
            ];
        }
        file_put_contents($this->backend.'/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    }
}
