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
        self::assertSame(['written' => 9, 'removed' => 0], $sync->sync($this->backend));
        self::assertSame(['written' => 0, 'removed' => 0], $sync->sync($this->backend));
        self::assertStringContainsString('DO NOT EDIT', (string) file_get_contents($this->workspace.'/bo/src/routes/catalog/+page.svelte'));
        self::assertStringContainsString('$lib/vendor/catalog/routes/catalog/+page.svelte', (string) file_get_contents($this->workspace.'/bo/src/routes/catalog/+page.svelte'));
        file_put_contents($this->module.'/bo/src/lib/widget.svelte', '<p>Updated</p>');
        self::assertSame(['written' => 1, 'removed' => 0], $sync->sync($this->backend));
        self::assertSame('<p>Updated</p>', file_get_contents($this->workspace.'/bo/src/lib/vendor/catalog/widget.svelte'));
        $this->installed(false);
        self::assertSame(['written' => 0, 'removed' => 9], $sync->sync($this->backend));
        self::assertFileDoesNotExist($this->workspace.'/bo/src/routes/catalog/+page.svelte');
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
        $generated = $this->workspace.'/bo/src/lib/vendor/catalog/widget.svelte';
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
