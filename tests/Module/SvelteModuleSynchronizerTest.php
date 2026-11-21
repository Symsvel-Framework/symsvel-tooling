<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\SvelteModuleSynchronizer;

final class SvelteModuleSynchronizerTest extends TestCase
{
    private string $workspace;
    private string $backend;
    private string $module;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-svelte-'.bin2hex(random_bytes(6));
        $this->backend = $this->workspace.'/backend';
        mkdir($this->backend.'/vendor/composer', 0775, true);
        mkdir($this->backend.'/vendor/symsvel-framework', 0775, true);
        foreach (['bo', 'app', 'front'] as $scope) {
            mkdir($this->workspace.'/'.$scope.'/src/lib', 0775, true);
            mkdir($this->workspace.'/'.$scope.'/src/routes', 0775, true);
        }
        $this->module = (new ModuleMaker())->create($this->workspace, 'catalog', 'symsvel-framework', 'all');
        symlink($this->module, $this->backend.'/vendor/symsvel-framework/module-catalog');
        $this->installed(true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isFile() || $item->isLink() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($this->workspace);
    }

    public function testInstallIdempotentUpdateAndUninstallAcrossScopes(): void
    {
        foreach (['bo', 'app', 'front'] as $scope) {
            mkdir($this->module.'/'.$scope.'/src/routes/catalog', 0775, true);
            file_put_contents($this->module.'/'.$scope.'/src/routes/catalog/+page.svelte', '<h1>'.$scope.'</h1>');
            file_put_contents($this->module.'/'.$scope.'/src/lib/widget.svelte', '<p>Widget</p>');
        }
        $sync = new SvelteModuleSynchronizer();
        self::assertSame(['written' => 15, 'removed' => 0], $sync->sync($this->backend));
        self::assertSame(['written' => 0, 'removed' => 0], $sync->sync($this->backend));
        foreach (['bo', 'app', 'front'] as $scope) {
            self::assertFileExists($this->workspace.'/'.$scope.'/src/lib/modules/catalog/index.ts');
            self::assertFileDoesNotExist($this->workspace.'/'.$scope.'/tsconfig.json');
            self::assertFileDoesNotExist($this->workspace.'/'.$scope.'/src/lib/tests/catalog.test.ts');
            self::assertStringContainsString(
                "export const moduleId = 'catalog';",
                (string) file_get_contents($this->workspace.'/'.$scope.'/src/lib/modules/catalog/index.ts'),
            );
        }
        self::assertStringContainsString('DO NOT EDIT', (string) file_get_contents($this->workspace.'/bo/src/routes/catalog/+page.svelte'));
        self::assertStringContainsString('#lib/modules/catalog/routes/catalog/+page.svelte', (string) file_get_contents($this->workspace.'/bo/src/routes/catalog/+page.svelte'));
        file_put_contents($this->module.'/bo/src/lib/widget.svelte', '<p>Updated</p>');
        self::assertSame(['written' => 1, 'removed' => 0], $sync->sync($this->backend));
        self::assertSame('<p>Updated</p>', file_get_contents($this->workspace.'/bo/src/lib/widget.svelte'));
        $this->installed(false);
        self::assertSame(['written' => 0, 'removed' => 15], $sync->sync($this->backend));
        self::assertDirectoryDoesNotExist($this->workspace.'/bo/src/routes/catalog');
        self::assertDirectoryDoesNotExist($this->workspace.'/bo/src/lib/modules/catalog');
        self::assertDirectoryExists($this->workspace.'/bo/src/routes');
    }

    public function testWritesPortableOwnershipKeysAndMigratesLegacyAbsolutePaths(): void
    {
        $sync = new SvelteModuleSynchronizer();
        $sync->sync($this->backend);
        $registry = $this->backend.'/.symsvel/svelte-files.json';
        $portable = json_decode((string) file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($portable);
        foreach (array_keys($portable) as $relative) {
            self::assertMatchesRegularExpression('~^(?:bo|app|front)/src/(?:lib|routes)/~', $relative);
            self::assertStringNotContainsString($this->workspace, $relative);
        }

        $legacy = [];
        foreach ($portable as $relative => $hash) {
            $legacy['/Volumes/Workspace/www.choralys.com/'.$relative] = $hash;
        }
        file_put_contents($registry, json_encode($legacy, JSON_THROW_ON_ERROR));

        self::assertSame(['written' => 0, 'removed' => 0], $sync->sync($this->backend));
        self::assertSame($portable, json_decode((string) file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['written' => 0, 'removed' => 0], $sync->sync($this->backend));
    }

    public function testLegacyRegistryNeverAdoptsModifiedGeneratedFile(): void
    {
        $sync = new SvelteModuleSynchronizer();
        $sync->sync($this->backend);
        $registry = $this->backend.'/.symsvel/svelte-files.json';
        $portable = json_decode((string) file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR);
        $legacy = [];
        foreach ($portable as $relative => $hash) {
            $legacy['/Volumes/Workspace/www.choralys.com/'.$relative] = $hash;
        }
        file_put_contents($registry, json_encode($legacy, JSON_THROW_ON_ERROR));
        $modified = $this->workspace.'/bo/src/lib/modules/catalog/index.ts';
        file_put_contents($modified, 'Personalized');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fichier généré modifié ou absent');
        try {
            $sync->sync($this->backend);
        } finally {
            self::assertSame('Personalized', file_get_contents($modified));
            self::assertSame($legacy, json_decode((string) file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testRejectsLegacyRegistryWithInconsistentOrigins(): void
    {
        $sync = new SvelteModuleSynchronizer();
        $sync->sync($this->backend);
        $registry = $this->backend.'/.symsvel/svelte-files.json';
        $portable = json_decode((string) file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(2, count($portable));
        $legacy = [];
        $index = 0;
        foreach ($portable as $relative => $hash) {
            $root = $index++ % 2 === 0 ? '/Volumes/Workspace/project-a' : '/Volumes/Workspace/project-b';
            $legacy[$root.'/'.$relative] = $hash;
        }
        file_put_contents($registry, json_encode($legacy, JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Origine de registre Svelte incohérente');
        $sync->sync($this->backend);
    }

    public function testIndependentModuleDictionariesCanShareLanguageNamesAndUninstallSeparately(): void
    {
        $other = (new ModuleMaker())->create($this->workspace, 'reports', 'symsvel-framework', 'bo');
        symlink($other, $this->backend.'/vendor/symsvel-framework/module-reports');
        file_put_contents($this->module.'/bo/src/lib/translations/fr.ts',
            'export default {"title":"Catalogue"} as const;');
        file_put_contents($other.'/bo/src/lib/translations/fr.ts',
            'export default {"title":"Rapports"} as const;');
        file_put_contents($this->backend.'/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'symsvel-framework/module-catalog', 'install-path' => '../symsvel-framework/module-catalog'],
            ['name' => 'symsvel-framework/module-reports', 'install-path' => '../symsvel-framework/module-reports'],
        ]], JSON_THROW_ON_ERROR));

        $catalog = $this->workspace.'/bo/src/lib/translations/catalog/fr.ts';
        $reports = $this->workspace.'/bo/src/lib/translations/reports/fr.ts';
        $sync = new SvelteModuleSynchronizer();
        $sync->sync($this->backend);
        self::assertSame('export default {"title":"Catalogue"} as const;', file_get_contents($catalog));
        self::assertSame('export default {"title":"Rapports"} as const;', file_get_contents($reports));
        self::assertFileDoesNotExist($this->workspace.'/bo/src/lib/translations/fr.ts');
        self::assertSame(['written' => 0, 'removed' => 0], $sync->sync($this->backend));

        file_put_contents($this->module.'/bo/src/lib/translations/fr.ts',
            'export default {"title":"Nouveau catalogue"} as const;');
        self::assertSame(['written' => 1, 'removed' => 0], $sync->sync($this->backend));
        self::assertStringContainsString('Nouveau catalogue', (string) file_get_contents($catalog));
        self::assertStringContainsString('Rapports', (string) file_get_contents($reports));

        file_put_contents($this->backend.'/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'symsvel-framework/module-catalog', 'install-path' => '../symsvel-framework/module-catalog'],
        ]], JSON_THROW_ON_ERROR));
        $sync->sync($this->backend);
        self::assertFileDoesNotExist($reports);
        self::assertFileExists($catalog);
    }

    public function testBoOnlyProjectSkipsOptionalAppAndFrontScopes(): void
    {
        // The module supports BO + App + Front, but the installation only
        // contains BO. Installing such a module must never force App/Front.
        foreach (['app', 'front'] as $scope) {
            rmdir($this->workspace.'/'.$scope.'/src/lib');
            rmdir($this->workspace.'/'.$scope.'/src/routes');
            rmdir($this->workspace.'/'.$scope.'/src');
            rmdir($this->workspace.'/'.$scope);
        }
        $sync = new SvelteModuleSynchronizer();
        self::assertSame(['written' => 2, 'removed' => 0], $sync->sync($this->backend));
        self::assertFileExists($this->workspace.'/bo/src/lib/modules/catalog/index.ts');
        self::assertDirectoryDoesNotExist($this->workspace.'/app');
        self::assertDirectoryDoesNotExist($this->workspace.'/front');
        self::assertSame(['written' => 0, 'removed' => 0], $sync->sync($this->backend));
    }

    public function testRefusesToOverwriteUserRoute(): void
    {
        mkdir($this->module.'/bo/src/routes/catalog', 0775, true);
        file_put_contents($this->module.'/bo/src/routes/catalog/+page.svelte', '<h1>Module</h1>');
        mkdir($this->workspace.'/bo/src/routes/catalog', 0775, true);
        $route = $this->workspace.'/bo/src/routes/catalog/+page.svelte';
        file_put_contents($route, '<h1>User</h1>');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fichier non géré');
        try {
            (new SvelteModuleSynchronizer())->sync($this->backend);
        } finally {
            self::assertSame('<h1>User</h1>', file_get_contents($route));
        }
    }

    public function testRefusesToRemoveEditedGeneratedFile(): void
    {
        file_put_contents($this->module.'/bo/src/lib/widget.svelte', 'Original');
        $sync = new SvelteModuleSynchronizer();
        $sync->sync($this->backend);
        $generated = $this->workspace.'/bo/src/lib/widget.svelte';
        file_put_contents($generated, 'User edit');
        $this->installed(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modifié ou absent');
        $sync->sync($this->backend);
    }

    public function testRejectsRegistryEntryOutsideManagedDirectories(): void
    {
        mkdir($this->backend.'/.symsvel', 0775, true);
        $protected = $this->backend.'/important.txt';
        file_put_contents($protected, 'Keep me');
        file_put_contents(
            $this->backend.'/.symsvel/svelte-files.json',
            json_encode([$protected => hash('sha256', 'Keep me')], JSON_THROW_ON_ERROR),
        );
        try {
            (new SvelteModuleSynchronizer())->sync($this->backend);
            self::fail('Expected an invalid registry exception.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('registre invalide', $exception->getMessage());
            self::assertSame('Keep me', file_get_contents($protected));
        }
    }

    public function testRejectsTraversalInRegistry(): void
    {
        mkdir($this->backend.'/.symsvel', 0775, true);
        $path = $this->workspace.'/bo/src/routes/../../../backend/important.txt';
        file_put_contents($this->backend.'/important.txt', 'Keep me');
        file_put_contents(
            $this->backend.'/.symsvel/svelte-files.json',
            json_encode([$path => hash('sha256', 'Keep me')], JSON_THROW_ON_ERROR),
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registre invalide');
        (new SvelteModuleSynchronizer())->sync($this->backend);
    }

    private function installed(bool $enabled): void
    {
        $packages = $enabled ? [['name' => 'symsvel-framework/module-catalog', 'install-path' => '../symsvel-framework/module-catalog']] : [];
        file_put_contents($this->backend.'/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    }
}
