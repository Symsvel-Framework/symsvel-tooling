<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ComposerModuleRegistry;
use SymSvel\Tooling\Module\ModuleMaker;

final class ComposerModuleRegistryTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-composer-'.bin2hex(random_bytes(6));
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

    public function testDiscoversComposerPathSymlinkAndIgnoresUnrelatedPackages(): void
    {
        $source = (new ModuleMaker())->create($this->workspace, 'my-catalog');
        symlink($source, $this->workspace.'/vendor/symsvel-framework/module-my-catalog');
        $this->installed([
            ['name' => 'symfony/console'],
            ['name' => 'symsvel-framework/module-my-catalog', 'install-path' => '../symsvel-framework/module-my-catalog'],
        ]);
        $modules = (new ComposerModuleRegistry())->discover($this->workspace);
        self::assertSame(['my-catalog'], array_keys($modules));
        self::assertSame(realpath($source), $modules['my-catalog']['path']);
        self::assertSame('MyCatalog', $modules['my-catalog']['manifest']['name']);
    }

    public function testRejectsInstalledPackageWithoutMatchingVendorEntry(): void
    {
        (new ModuleMaker())->create($this->workspace, 'my-catalog');
        $this->installed([['name' => 'symsvel-framework/module-my-catalog', 'install-path' => '../../modules/my-catalog']]);
        $this->expectException(\InvalidArgumentException::class);
        (new ComposerModuleRegistry())->discover($this->workspace);
    }

    public function testRejectsMissingComposerMetadata(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ComposerModuleRegistry())->discover($this->workspace);
    }

    /** @param list<array<string, string>> $packages */
    private function installed(array $packages): void
    {
        file_put_contents(
            $this->workspace.'/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR),
        );
    }
}
