<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;

final class ModuleMakerTest extends TestCase
{
    public function testGeneratesManifestAndComposerBundle(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-maker-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $path = (new ModuleMaker())->create($workspace, 'my-documents');
            self::assertSame($workspace.'/modules/my-documents', $path);
            $manifest = json_decode((string) file_get_contents($path.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
            $composer = json_decode((string) file_get_contents($path.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $manifest['schema']);
            self::assertSame('MyDocuments', $manifest['name']);
            self::assertSame('symsvel-framework/module-my-documents', $composer['name']);
            self::assertFileExists($path.'/back/src/MyDocumentsBundle.php');
            $this->expectException(\RuntimeException::class);
            (new ModuleMaker())->create($workspace, 'my-documents');
        } finally {
            foreach (['composer.json', 'module.json', 'back/src/MyDocumentsBundle.php', 'README.md', 'docs/dev/index.md', '.gitignore'] as $file) {
                @unlink($workspace.'/modules/my-documents/'.$file);
            }
            foreach (['back/src', 'back', 'docs/dev', 'docs'] as $dir) {
                @rmdir($workspace.'/modules/my-documents/'.$dir);
            }
            @rmdir($workspace.'/modules/my-documents');
            @rmdir($workspace.'/modules');
            @rmdir($workspace);
        }
    }

    public function testRejectsUnsafeNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModuleMaker())->create(sys_get_temp_dir(), '../escape');
    }
}
