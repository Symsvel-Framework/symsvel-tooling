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

    public function testCoreUpdatesComposerAndExistingInterfaces(): void
    {
        $root = sys_get_temp_dir().'/symsvel-update-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        file_put_contents($root.'/backend/composer.json', '{}');
        mkdir($root.'/bo');
        mkdir($root.'/bo/.git');
        $calls = [];
        $messages = [];
        try {
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): void {
                $calls[] = [$command, $cwd];
            });
            $updater->update($root.'/backend', 'core', static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
            self::assertSame(['git', 'status', '--porcelain', '--untracked-files=all'], $calls[0][0]);
            self::assertSame('composer', $calls[1][0][0]);
            self::assertContains('--no-scripts', $calls[1][0]);
            self::assertContains([['git', 'merge', '--ff-only', 'origin/main'], $root.'/bo'], $calls);
            self::assertContains([['npm', 'run', 'build'], $root.'/bo'], $calls);
            self::assertCount(6, $calls);
            self::assertContains('Backend : mise à jour Composer (Core + Tooling)…', $messages);
            self::assertContains('BO : build…', $messages);
            self::assertContains('APP : absent, ignoré.', $messages);
            self::assertContains('FRONT : absent, ignoré.', $messages);
            self::assertSame('Tous les composants présents ont été traités.', end($messages));
        } finally {
            rmdir($root.'/bo/.git');
            rmdir($root.'/bo');
            unlink($root.'/backend/composer.json');
            unlink($root.'/backend/scripts/symsvel-module-sync.php');
            unlink($root.'/backend/scripts/symsvel-composer-transaction.php');
            rmdir($root.'/backend/scripts');
            rmdir($root.'/backend');
            rmdir($root);
        }
    }
    public function testDevSelectsLatestStableTag(): void
    {
        $root = sys_get_temp_dir().'/symsvel-dev-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        file_put_contents($root.'/backend/composer.json', '{}');
        file_put_contents($root.'/backend/composer.json', json_encode(['require' => ['symsvel-framework/core' => '^1.0', 'symsvel-framework/tooling' => '^1.0']]));
        mkdir($root.'/bo');
        mkdir($root.'/bo/.git');
        $calls = [];
        try {
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): ?string {
                $calls[] = [$command, $cwd];
                if ($command === ['git', 'tag', '--list', 'v[0-9]*']) {
                    return implode(PHP_EOL, ['v1.2.0', 'v1.10.0', 'v2.0.0-beta', 'v1.9.5', '']);
                }
                return null;
            });
            $updater->update($root.'/backend', 'dev');
            self::assertContains([['git', 'checkout', '--detach', 'v1.10.0'], $root.'/bo'], $calls);
            self::assertNotContains([['git', 'merge', '--ff-only', 'origin/main'], $root.'/bo'], $calls);
        } finally {
            rmdir($root.'/bo/.git');
            rmdir($root.'/bo');
            unlink($root.'/backend/composer.json');
            unlink($root.'/backend/scripts/symsvel-module-sync.php');
            unlink($root.'/backend/scripts/symsvel-composer-transaction.php');
            rmdir($root.'/backend/scripts');
            rmdir($root.'/backend');
            rmdir($root);
        }
    }

    public function testBuildFailureIdentifiesComponentAndStage(): void
    {
        $root = sys_get_temp_dir().'/symsvel-failing-build-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        mkdir($root.'/bo/.git', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        try {
            $updater = new WorkspaceUpdater(static function (array $command): void {
                if ($command === ['npm', 'run', 'build']) {
                    throw new \RuntimeException('Svelte compilation failed');
                }
            });
            try {
                $updater->update($root.'/backend', 'core');
                self::fail('Expected BO build failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[BO / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('Svelte compilation failed', $error->getMessage());
            }
        } finally {
            @unlink($root.'/backend/scripts/symsvel-composer-transaction.php');
            @unlink($root.'/backend/scripts/symsvel-module-sync.php');
            @rmdir($root.'/backend/scripts');
            unlink($root.'/backend/composer.json');
            rmdir($root.'/backend');
            rmdir($root.'/bo/.git');
            rmdir($root.'/bo');
            rmdir($root);
        }
    }

    public function testDirtyInterfaceStopsBeforeComposer(): void
    {
        $root = sys_get_temp_dir().'/symsvel-dirty-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        file_put_contents($root.'/backend/composer.json', '{}');
        mkdir($root.'/bo');
        mkdir($root.'/bo/.git');
        $calls = [];
        try {
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): ?string {
                $calls[] = [$command, $cwd];
                return $command === ['git', 'status', '--porcelain', '--untracked-files=all'] ? ' M package.json' : null;
            });
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('modifications locales');
            $updater->update($root.'/backend', 'core');
        } finally {
            self::assertCount(1, $calls);
            self::assertSame('git', $calls[0][0][0]);
            rmdir($root.'/bo/.git');
            rmdir($root.'/bo');
            unlink($root.'/backend/composer.json');
            rmdir($root.'/backend');
            rmdir($root);
        }
    }

    public function testRegisteredGeneratedFileDoesNotBlockUpdate(): void
    {
        $root = sys_get_temp_dir().'/symsvel-managed-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend/.symsvel', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        mkdir($root.'/bo/.git', 0775, true);
        mkdir($root.'/bo/src/lib/vendor/test-back', 0775, true);
        $file = $root.'/bo/src/lib/vendor/test-back/Badge.svelte';
        file_put_contents($file, '<p>OK</p>');
        file_put_contents($root.'/backend/.symsvel/svelte-files.json', json_encode([$file => hash_file('sha256', $file)], JSON_THROW_ON_ERROR));
        $calls = [];
        try {
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): ?string {
                $calls[] = [$command, $cwd];
                return $command === ['git', 'status', '--porcelain', '--untracked-files=all']
                    ? '?? src/lib/vendor/test-back/Badge.svelte'
                    : null;
            });
            $updater->update($root.'/backend', 'core');
            self::assertContains([['npm', 'run', 'build'], $root.'/bo'], $calls);
            file_put_contents($file, '<p>Edited</p>');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('modifications locales');
            $updater->update($root.'/backend', 'core');
        } finally {
            unlink($file);
            rmdir(dirname($file));
            rmdir($root.'/bo/src/lib/vendor');
            rmdir($root.'/bo/src/lib');
            rmdir($root.'/bo/src');
            rmdir($root.'/bo/.git');
            rmdir($root.'/bo');
            unlink($root.'/backend/.symsvel/svelte-files.json');
            rmdir($root.'/backend/.symsvel');
            unlink($root.'/backend/composer.json');
            unlink($root.'/backend/scripts/symsvel-module-sync.php');
            unlink($root.'/backend/scripts/symsvel-composer-transaction.php');
            rmdir($root.'/backend/scripts');
            rmdir($root.'/backend');
            rmdir($root);
        }
    }

}
