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
            self::assertSame('composer', $calls[0][0][0]);
            self::assertContains([['git', 'merge', '--ff-only', 'origin/main'], $root.'/bo'], $calls);
            self::assertContains([['npm', 'run', 'build'], $root.'/bo'], $calls);
            self::assertCount(7, $calls);
            self::assertContains('Backend : mise à jour Composer (Core + Tooling)…', $messages);
            self::assertContains('BO : build…', $messages);
            self::assertContains('APP : absent, ignoré.', $messages);
            self::assertContains('FRONT : absent, ignoré.', $messages);
            self::assertSame('Tous les composants présents ont été traités.', end($messages));
        } finally {
            rmdir($root.'/bo/.git');
            rmdir($root.'/bo');
            unlink($root.'/backend/composer.json');
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
        mkdir($root.'/bo');
        mkdir($root.'/bo/.git');
        $calls = [];
        try {
            $updater = new WorkspaceUpdater(static function (array $command, string $cwd) use (&$calls): ?string {
                $calls[] = [$command, $cwd];
                if ($command === ['git', 'tag', '--list', 'v[0-9]*']) {
                    return "v1.2.0\\nv1.10.0\\nv2.0.0-beta\\nv1.9.5\\n";
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
            rmdir($root.'/backend');
            rmdir($root);
        }
    }

}
