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
                (new ComposerTransactionRunner())->run($backend, ['require', 'symsvel-test/broken-module:1.0.0', '--no-interaction']);
                self::fail('Expected Composer post-update script to fail.');
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
                (new ComposerTransactionRunner())->run($backend, ['update', 'symsvel-test/versioned-module', '--no-interaction']);
                self::fail('Expected Composer update failure.');
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
                (new ComposerTransactionRunner())->run($backend, ['remove', 'symsvel-test/removable-module', '--no-interaction']);
                self::fail('Expected Composer removal failure.');
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
