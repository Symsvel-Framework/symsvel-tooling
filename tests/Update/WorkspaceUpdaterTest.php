<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\WorkspaceUpdater;

final class WorkspaceUpdaterTest extends TestCase
{
    public function testProductionCannotUpdate(): void
    {
        $updater = new WorkspaceUpdater(static function (): void {
            self::fail('Aucune commande ne doit être exécutée.');
        });
        foreach (['prod'] as $env) {
            try {
                $updater->update('/tmp/backend', $env);
                self::fail('Mise à jour interdite attendue.');
            } catch (\RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testWorkspaceUpdatesComposerAndExistingInterfaces(): void
    {
        $root = sys_get_temp_dir().'/symsvel-update-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        $fixture = new NativeWorkspaceFixture($root);
        $fixture->component('bo', ['version.txt' => 'before'], ['version.txt' => 'after']);
        $calls = [];
        $messages = [];
        try {
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): void {
                $calls[] = [$command, $cwd];
            }, nativePreparation: $fixture->preparation());
            $updater->update($root.'/backend', 'test', static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
            self::assertSame('composer', $calls[0][0][0]);
            self::assertContains('--no-scripts', $calls[0][0]);
            self::assertContains('--no-plugins', $calls[0][0]);
            self::assertContains([['npm', 'run', 'build'], $root.'/bo'], $calls);
            self::assertCount(3, $calls);
            self::assertSame('after', file_get_contents($root.'/bo/version.txt'));
            self::assertContains('BO : build…', $messages);
            self::assertContains('APP : absent, ignoré.', $messages);
            self::assertContains('FRONT : absent, ignoré.', $messages);
            self::assertSame('Tous les composants présents ont été traités.', end($messages));
        } finally {
            $this->deleteFixtureTree($root);
        }
    }

    public function testDevRequiresStableComposerConstraintsBeforeNativePreparation(): void
    {
        $root = sys_get_temp_dir().'/symsvel-dev-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', json_encode([
            'require' => ['symsvel-framework/core' => 'dev-main', 'symsvel-framework/tooling' => '^1.0'],
        ], JSON_THROW_ON_ERROR));
        $calls = [];
        try {
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): void {
                $calls[] = [$command, $cwd];
            });
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('doit utiliser une contrainte de release stable');
            $updater->update($root.'/backend', 'dev');
        } finally {
            self::assertSame([], $calls);
            $this->deleteFixtureTree($root);
        }
    }

    public function testBuildFailureIdentifiesComponentAndStage(): void
    {
        $root = sys_get_temp_dir().'/symsvel-failing-build-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        $fixture = new NativeWorkspaceFixture($root);
        $fixture->component('bo', ['version.txt' => 'before'], ['version.txt' => 'after']);
        try {
            $updater = new WorkspaceUpdater(static function (array $command): void {
                if ($command === ['npm', 'run', 'build']) {
                    throw new \RuntimeException('Svelte compilation failed');
                }
            }, nativePreparation: $fixture->preparation());
            try {
                $updater->update($root.'/backend', 'test');
                self::fail('Expected BO build failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[BO / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('Svelte compilation failed', $error->getMessage());
            }
        } finally {
            $this->deleteFixtureTree($root);
        }
    }

    public function testConflictingNativeFileStopsBeforeComposer(): void
    {
        $root = sys_get_temp_dir().'/symsvel-dirty-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        $fixture = new NativeWorkspaceFixture($root);
        $fixture->component('bo', ['package.json' => 'original'], ['package.json' => 'upstream']);
        file_put_contents($root.'/bo/package.json', 'local customization');
        $calls = [];
        try {
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): void {
                $calls[] = [$command, $cwd];
            }, nativePreparation: $fixture->preparation());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Préflight natif global échoué');
            $updater->update($root.'/backend', 'test');
        } finally {
            self::assertSame([], $calls, 'Native conflicts must stop before Composer or npm.');
            self::assertSame('local customization', file_get_contents($root.'/bo/package.json'));
            $this->deleteFixtureTree($root);
        }
    }

    public function testLocalUntrackedFilesSurviveNativeUpdate(): void
    {
        $root = sys_get_temp_dir().'/symsvel-managed-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        $fixture = new NativeWorkspaceFixture($root);
        $fixture->component('bo', ['version.txt' => 'before'], ['version.txt' => 'after']);
        $local = $root.'/bo/src/lib/vendor/test-back/Badge.svelte';
        mkdir(dirname($local), 0775, true);
        file_put_contents($local, '<p>Local</p>');
        try {
            $calls = [];
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): void {
                $calls[] = [$command, $cwd];
            }, nativePreparation: $fixture->preparation());
            $updater->update($root.'/backend', 'test');
            self::assertSame('after', file_get_contents($root.'/bo/version.txt'));
            self::assertSame('<p>Local</p>', file_get_contents($local));
            self::assertContains([['npm', 'run', 'build'], $root.'/bo'], $calls);
        } finally {
            $this->deleteFixtureTree($root);
        }
    }

    private function deleteFixtureTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }

}
