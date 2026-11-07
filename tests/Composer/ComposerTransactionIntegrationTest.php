<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Composer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Composer\ComposerTransactionRunner;

final class ComposerTransactionIntegrationTest extends TestCase
{
    public function testRealComposerFailureRestoresOriginalLockAndVendor(): void
    {
        $probe = new Process(['composer', '--version']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('Composer executable unavailable.');
        }

        $root = sys_get_temp_dir().'/symsvel-composer-integration-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        $package = $root.'/broken-module';
        mkdir($backend, 0700, true);
        mkdir($package, 0700, true);
        mkdir($backend.'/scripts', 0700, true);

        file_put_contents($package.'/composer.json', json_encode([
            'name' => 'symsvel-test/broken-module',
            'version' => '1.0.0',
            'type' => 'library',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($backend.'/scripts/fail.php', '<?php exit(17);');
        file_put_contents($backend.'/composer.json', json_encode([
            'name' => 'symsvel-test/consumer',
            'repositories' => [['type' => 'path', 'url' => '../broken-module', 'options' => ['symlink' => false]]],
            'scripts' => ['post-update-cmd' => ['@php scripts/fail.php']],
            'config' => ['allow-plugins' => false],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        try {
            $initial = new Process(['composer', 'update', '--no-scripts', '--no-plugins', '--no-interaction'], $backend);
            $initial->setTimeout(120);
            $initial->run();
            self::assertTrue($initial->isSuccessful(), $initial->getErrorOutput().$initial->getOutput());
            $originalJson = file_get_contents($backend.'/composer.json');
            $originalLock = file_get_contents($backend.'/composer.lock');

            try {
                (new ComposerTransactionRunner(commandRunner: static function (array $command, string $cwd, ?callable $progress): void {
                    $process = new Process($command, $cwd);
                    $process->setTimeout(120);
                    $process->mustRun();
                    throw new \RuntimeException('Injected failure after successful Composer mutation');
                }))->run($backend, ['require', 'symsvel-test/broken-module:1.0.0', '--no-interaction']);
                self::fail('Expected injected Composer failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            }

            self::assertSame($originalJson, file_get_contents($backend.'/composer.json'));
            self::assertSame($originalLock, file_get_contents($backend.'/composer.lock'));
            self::assertDirectoryDoesNotExist($backend.'/vendor/symsvel-test/broken-module');
            self::assertSame([], glob($backend.'/.symsvel/transactions/*'));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testRealComposerFailedUpdateRestoresPreviousPackageVersion(): void
    {
        $probe = new Process(['composer', '--version']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('Composer executable unavailable.');
        }

        $root = sys_get_temp_dir().'/symsvel-composer-update-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        $package = $root.'/module';
        mkdir($backend, 0700, true);
        mkdir($package, 0700, true);
        mkdir($backend.'/scripts', 0700, true);
        file_put_contents($package.'/composer.json', json_encode([
            'name' => 'symsvel-test/versioned-module',
            'version' => '1.0.0',
            'type' => 'library',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($backend.'/scripts/fail.php', '<?php exit(17);');
        file_put_contents($backend.'/composer.json', json_encode([
            'name' => 'symsvel-test/consumer',
            'repositories' => [['type' => 'path', 'url' => '../module', 'options' => ['symlink' => false]]],
            'require' => ['symsvel-test/versioned-module' => '^1.0'],
            'scripts' => ['post-update-cmd' => ['@php scripts/fail.php']],
            'config' => ['allow-plugins' => false],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        try {
            $initial = new Process(['composer', 'update', '--no-scripts', '--no-plugins', '--no-interaction'], $backend);
            $initial->setTimeout(120);
            $initial->run();
            self::assertTrue($initial->isSuccessful(), $initial->getErrorOutput().$initial->getOutput());
            $originalLock = file_get_contents($backend.'/composer.lock');
            file_put_contents($package.'/composer.json', json_encode([
                'name' => 'symsvel-test/versioned-module',
                'version' => '1.1.0',
                'type' => 'library',
            ], JSON_THROW_ON_ERROR));

            try {
                (new ComposerTransactionRunner(commandRunner: static function (array $command, string $cwd, ?callable $progress): void {
                    $process = new Process($command, $cwd);
                    $process->setTimeout(120);
                    $process->mustRun();
                    throw new \RuntimeException('Injected failure after successful Composer mutation');
                }))->run($backend, ['update', 'symsvel-test/versioned-module', '--no-interaction']);
                self::fail('Expected injected Composer failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            }
            self::assertSame($originalLock, file_get_contents($backend.'/composer.lock'));
            $installed = json_decode((string) file_get_contents($backend.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
            $packages = $installed['packages'] ?? $installed;
            $matching = array_values(array_filter($packages, static fn (array $p): bool => ($p['name'] ?? '') === 'symsvel-test/versioned-module'));
            self::assertCount(1, $matching);
            self::assertSame('1.0.0', $matching[0]['version']);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testRealComposerFailedRemoveRestoresRemovedPackage(): void
    {
        $probe = new Process(['composer', '--version']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('Composer executable unavailable.');
        }

        $root = sys_get_temp_dir().'/symsvel-composer-remove-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        $package = $root.'/module';
        mkdir($backend, 0700, true);
        mkdir($package, 0700, true);
        mkdir($backend.'/scripts', 0700, true);
        file_put_contents($package.'/composer.json', json_encode([
            'name' => 'symsvel-test/removable-module',
            'version' => '1.0.0',
            'type' => 'library',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($backend.'/scripts/fail.php', '<?php exit(17);');
        file_put_contents($backend.'/composer.json', json_encode([
            'name' => 'symsvel-test/consumer',
            'repositories' => [['type' => 'path', 'url' => '../module', 'options' => ['symlink' => false]]],
            'require' => ['symsvel-test/removable-module' => '^1.0'],
            'scripts' => ['post-update-cmd' => ['@php scripts/fail.php']],
            'config' => ['allow-plugins' => false],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        try {
            $initial = new Process(['composer', 'update', '--no-scripts', '--no-plugins', '--no-interaction'], $backend);
            $initial->setTimeout(120);
            $initial->run();
            self::assertTrue($initial->isSuccessful(), $initial->getErrorOutput().$initial->getOutput());
            $originalJson = file_get_contents($backend.'/composer.json');
            $originalLock = file_get_contents($backend.'/composer.lock');

            try {
                (new ComposerTransactionRunner(commandRunner: static function (array $command, string $cwd, ?callable $progress): void {
                    $process = new Process($command, $cwd);
                    $process->setTimeout(120);
                    $process->mustRun();
                    throw new \RuntimeException('Injected failure after successful Composer mutation');
                }))->run($backend, ['remove', 'symsvel-test/removable-module', '--no-interaction']);
                self::fail('Expected injected Composer failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            }
            self::assertSame($originalJson, file_get_contents($backend.'/composer.json'));
            self::assertSame($originalLock, file_get_contents($backend.'/composer.lock'));
            self::assertDirectoryExists($backend.'/vendor/symsvel-test/removable-module');
        } finally {
            $this->removeTree($root);
        }
    }

    public function testRealGitModuleVcsRequireSucceedsInDevelopmentChannel(): void
    {
        foreach (['composer', 'git'] as $executable) {
            $probe = new Process([$executable, '--version']);
            $probe->run();
            if (!$probe->isSuccessful()) {
                self::markTestSkipped($executable.' executable unavailable.');
            }
        }

        $root = sys_get_temp_dir().'/symsvel-composer-vcs-success-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        $module = $root.'/module';
        mkdir($backend, 0700, true);
        mkdir($module, 0700, true);
        file_put_contents($module.'/composer.json', json_encode([
            'name' => 'symsvel-framework/module-demo',
            'type' => 'library',
        ], JSON_THROW_ON_ERROR));
        foreach ([
            ['git', 'init', '-b', 'main'],
            ['git', 'config', 'user.email', 'ci@example.invalid'],
            ['git', 'config', 'user.name', 'CI'],
            ['git', 'add', 'composer.json'],
            ['git', 'commit', '-m', 'fixture'],
        ] as $command) {
            (new Process($command, $module))->mustRun();
        }
        file_put_contents($backend.'/composer.json', json_encode([
            'name' => 'symsvel-test/vcs-consumer',
            'config' => ['allow-plugins' => false],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        try {
            (new Process(['composer', 'update', '--no-scripts', '--no-plugins', '--no-interaction'], $backend))->mustRun();
            \SymSvel\Tooling\Update\ProjectChannel::write($backend, 'dev');
            $commands = [];
            $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $cwd, ?callable $progress) use (&$commands, $module): void {
                $commands[] = $command;
                if (($command[0] ?? '') === 'composer' && ($command[1] ?? '') === 'config') {
                    $command[4] = $module;
                }
                $process = new Process($command, $cwd);
                $process->setTimeout(120);
                $process->mustRun();
            });
            $runner->run($backend, ['require', 'symsvel-framework/module-demo:dev-main', '--no-interaction']);
            $composer = json_decode((string) file_get_contents($backend.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('dev-main', $composer['require']['symsvel-framework/module-demo'] ?? null);
            self::assertDirectoryExists($backend.'/vendor/symsvel-framework/module-demo');
            self::assertContains(['composer', 'config', 'repositories.module-demo-dev', 'vcs', 'https://github.com/Symsvel-Framework/symsvel-module-demo.git'], $commands);
            self::assertSame('dev', \SymSvel\Tooling\Update\ProjectChannel::read($backend));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testRealGitModuleVcsFailureRestoresRepositoryConfiguration(): void
    {
        foreach (['composer', 'git'] as $executable) {
            $probe = new Process([$executable, '--version']);
            $probe->run();
            if (!$probe->isSuccessful()) {
                self::markTestSkipped($executable.' executable unavailable.');
            }
        }

        $root = sys_get_temp_dir().'/symsvel-composer-vcs-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        $module = $root.'/module';
        mkdir($backend, 0700, true);
        mkdir($module, 0700, true);
        file_put_contents($module.'/composer.json', json_encode([
            'name' => 'symsvel-framework/module-demo',
            'type' => 'library',
        ], JSON_THROW_ON_ERROR));
        foreach ([
            ['git', 'init', '-b', 'main'],
            ['git', 'config', 'user.email', 'ci@example.invalid'],
            ['git', 'config', 'user.name', 'CI'],
            ['git', 'add', 'composer.json'],
            ['git', 'commit', '-m', 'fixture'],
        ] as $command) {
            (new Process($command, $module))->mustRun();
        }
        file_put_contents($backend.'/composer.json', json_encode([
            'name' => 'symsvel-test/vcs-consumer',
            'config' => ['allow-plugins' => false],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        try {
            (new Process(['composer', 'update', '--no-scripts', '--no-plugins', '--no-interaction'], $backend))->mustRun();
            \SymSvel\Tooling\Update\ProjectChannel::write($backend, 'dev');
            $originalJson = (string) file_get_contents($backend.'/composer.json');
            $originalLock = (string) file_get_contents($backend.'/composer.lock');
            $commands = [];
            $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $cwd, ?callable $progress) use (&$commands, $module): void {
                $commands[] = $command;
                if (($command[0] ?? '') === 'composer' && ($command[1] ?? '') === 'config') {
                    $command[4] = $module;
                    (new Process($command, $cwd))->mustRun();
                    return;
                }
                if (($command[0] ?? '') === 'composer' && ($command[1] ?? '') === 'require') {
                    (new Process($command, $cwd))->mustRun();
                    throw new \RuntimeException('Injected failure after successful VCS require');
                }
                (new Process($command, $cwd))->mustRun();
            });
            try {
                $runner->run($backend, ['require', 'symsvel-framework/module-demo:dev-main', '--no-interaction']);
                self::fail('Expected injected failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            }
            self::assertSame($originalJson, file_get_contents($backend.'/composer.json'));
            self::assertSame($originalLock, file_get_contents($backend.'/composer.lock'));
            self::assertDirectoryDoesNotExist($backend.'/vendor/symsvel-framework/module-demo');
            self::assertContains(['composer', 'config', 'repositories.module-demo-dev', 'vcs', 'https://github.com/Symsvel-Framework/symsvel-module-demo.git'], $commands);
        } finally {
            $this->removeTree($root);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->removeTree($entry->getPathname());
        }
        @rmdir($path);
    }
}
