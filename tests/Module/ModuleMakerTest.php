<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
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
            self::assertFileExists($path.'/back/phpunit.xml.dist');
            $phpunitConfiguration = (string) file_get_contents($path.'/back/phpunit.xml.dist');
            self::assertStringContainsString('bootstrap="../vendor/autoload.php"', $phpunitConfiguration);
            self::assertStringContainsString('<directory>tests/Unit</directory>', $phpunitConfiguration);
            self::assertStringContainsString('<directory>tests/Integration</directory>', $phpunitConfiguration);
            self::assertStringContainsString('<directory>tests/Functional</directory>', $phpunitConfiguration);
            $bundleTest = (string) file_get_contents($path.'/back/tests/Unit/MyDocumentsBundleTest.php');
            self::assertStringContainsString('namespace SymSvel\\Module\\MyDocuments\\Tests\\Unit;', $bundleTest);
            self::assertStringContainsString('use SymSvel\\Module\\MyDocuments\\MyDocumentsBundle;', $bundleTest);
            self::assertFileExists($path.'/Migration/.gitkeep');
            self::assertFileExists($path.'/back/tests/Unit/MyDocumentsBundleTest.php');
            foreach ([
                $path.'/back/src/MyDocumentsBundle.php',
                $path.'/back/tests/Unit/MyDocumentsBundleTest.php',
            ] as $phpFile) {
                $lint = new Process([PHP_BINARY, '-l', $phpFile]);
                $lint->run();
                self::assertTrue($lint->isSuccessful(), $phpFile.': '.$lint->getErrorOutput().$lint->getOutput());
            }
            $recipe = json_decode((string) file_get_contents($path.'/recipe/recipe.json'), true, 512, JSON_THROW_ON_ERROR);
            $entry = $recipe['manifests']['symsvel-framework/module-my-documents'];
            self::assertSame(['all'], $entry['manifest']['bundles']['SymSvel\\Module\\MyDocuments\\MyDocumentsBundle']);
            self::assertArrayHasKey('config/routes/my-documents.yaml', $entry['files']);
            self::assertSame('1.0.x-dev', $composer['extra']['branch-alias']['dev-main']);
            self::assertFileExists($path.'/.github/workflows/qa.yml');
            $this->expectException(\RuntimeException::class);
            (new ModuleMaker())->create($workspace, 'my-documents');
        } finally {
            foreach (['back/phpunit.xml.dist', 'Migration/.gitkeep', 'back/tests/Unit/MyDocumentsBundleTest.php', 'recipe/recipe.json', '.github/workflows/qa.yml', 'back/tests/Unit/.gitkeep', 'back/tests/Integration/.gitkeep', 'back/tests/Functional/.gitkeep', 'back/src/Controller/.gitkeep', 'back/src/Entity/.gitkeep', 'back/src/Repository/.gitkeep', 'back/config/.gitkeep', 'Migration/.gitkeep'] as $file) {
                @unlink($workspace.'/modules/my-documents/'.$file);
            }
            foreach (['composer.json', 'module.json', 'back/src/MyDocumentsBundle.php', 'README.md', 'docs/dev/index.md', '.gitignore'] as $file) {
                @unlink($workspace.'/modules/my-documents/'.$file);
            }
            foreach (['recipe', 'Migration', 'back/tests/Unit', 'back/tests/Integration', 'back/tests/Functional', 'back/tests', 'back/src/Controller', 'back/src/Entity', 'back/src/Repository', 'back/config', 'Migration', '.github/workflows', '.github', 'back/src', 'back', 'docs/dev', 'docs'] as $dir) {
                @rmdir($workspace.'/modules/my-documents/'.$dir);
            }
            @rmdir($workspace.'/modules/my-documents');
            @rmdir($workspace.'/modules');
            @rmdir($workspace);
        }
    }

    public function testProfilesAndDocumentation(): void
    {
        foreach ([
            'back' => [['back'], ['dev']],
            'bo' => [['back', 'bo'], ['dev', 'admin']],
            'app' => [['back', 'bo', 'app'], ['dev', 'admin', 'user']],
            'front' => [['back', 'bo', 'front'], ['dev', 'admin', 'user']],
            'all' => [['back', 'bo', 'app', 'front'], ['dev', 'admin', 'user']],
            'back,front' => [['back', 'front'], ['dev', 'user']],
            'bo,front' => [['back', 'bo', 'front'], ['dev', 'admin', 'user']],
        ] as $profile => [$components, $docs]) {
            $workspace = sys_get_temp_dir().'/symsvel-profiles-'.bin2hex(random_bytes(6));
            mkdir($workspace);
            $path = (new ModuleMaker())->create($workspace, 'test-profile', 'symsvel-framework', $profile);
            $manifest = json_decode((string) file_get_contents($path.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($components, array_keys($manifest['components']));
            self::assertSame($docs, array_keys($manifest['documentation']));
            foreach (['bo', 'app', 'front'] as $scope) {
                if (in_array($scope, $components, true)) {
                    self::assertFileExists($path.'/'.$scope.'/tsconfig.json');
                    self::assertFileExists($path.'/'.$scope.'/src/lib/modules/test-profile/index.ts');
                    self::assertFileExists($path.'/'.$scope.'/tests/test-profile.test.ts');
                    $tsconfig = json_decode((string) file_get_contents($path.'/'.$scope.'/tsconfig.json'), true, 512, JSON_THROW_ON_ERROR);
                    self::assertTrue($tsconfig['compilerOptions']['strict']);
                    self::assertSame('Bundler', $tsconfig['compilerOptions']['moduleResolution']);
                } else {
                    self::assertDirectoryDoesNotExist($path.'/'.$scope);
                }
            }
            foreach ($docs as $doc) {
                self::assertFileExists($path.'/docs/'.$doc.'/index.md');
            }
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

    public function testNormalizesRepositoryStyleModuleName(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-prefixed-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $path = (new ModuleMaker())->create($workspace, 'symsvel-module-languages');
            self::assertSame($workspace.'/modules/languages', $path);
            $composer = json_decode((string) file_get_contents($path.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $manifest = json_decode((string) file_get_contents($path.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('symsvel-framework/module-languages', $composer['name']);
            self::assertSame('Languages', $manifest['name']);
            self::assertFileExists($path.'/back/src/LanguagesBundle.php');
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

    public function testFrameworkWorkspaceUsesSiblingRepositoryName(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-framework-maker-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        mkdir($workspace.'/symsvel-cli');
        mkdir($workspace.'/symsvel-tooling');
        try {
            $path = (new ModuleMaker())->create($workspace, 'symsvel-module-mail', 'symsvel-framework', 'bo');
            self::assertSame($workspace.'/symsvel-module-mail', $path);
            self::assertDirectoryDoesNotExist($workspace.'/modules');
            self::assertFileExists($path.'/back/src/MailBundle.php');
            self::assertFileExists($path.'/bo/tsconfig.json');
            $composer = json_decode((string) file_get_contents($path.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('symsvel-framework/module-mail', $composer['name']);
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

    public function testRejectsUnknownProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModuleMaker())->create(sys_get_temp_dir(), 'test-profile', 'symsvel-framework', 'back,unknown');
    }

    public function testRejectsUnsafeNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModuleMaker())->create(sys_get_temp_dir(), '../escape');
    }
}
