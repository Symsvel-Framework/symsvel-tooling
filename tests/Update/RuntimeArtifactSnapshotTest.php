<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\RuntimeArtifactSnapshot;

final class RuntimeArtifactSnapshotTest extends TestCase
{
    public function testRestoresNpmDependenciesAndBuildAfterFailure(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules/pkg', 0775, true);
        mkdir($component.'/build', 0775, true);
        file_put_contents($component.'/node_modules/pkg/index.js', 'original');
        file_put_contents($component.'/build/index.html', 'old build');
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction);
        try {
            $snapshot->capture();
            file_put_contents($component.'/node_modules/pkg/index.js', 'broken');
            unlink($component.'/build/index.html');
            file_put_contents($component.'/build/extra.html', 'partial');
            mkdir($component.'/dist');
            file_put_contents($component.'/dist/new.js', 'partial');
            $snapshot->restore();
            self::assertSame('original', file_get_contents($component.'/node_modules/pkg/index.js'));
            self::assertSame('old build', file_get_contents($component.'/build/index.html'));
            self::assertFileDoesNotExist($component.'/build/extra.html');
            self::assertDirectoryDoesNotExist($component.'/dist');
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testMissingBackupNeverDeletesExistingDependencies(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-missing-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules', 0775, true);
        file_put_contents($component.'/node_modules/original.js', 'keep');
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction);
        try {
            $snapshot->capture();
            $this->deleteTree($transaction.'/runtime/node_modules');
            try {
                $snapshot->restore();
                self::fail('Missing runtime backup must abort restoration.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('sauvegarde absente', $error->getMessage());
                self::assertSame('keep', file_get_contents($component.'/node_modules/original.js'));
            }
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testVendorSnapshotRestoresExactFilesAndRemovesNewDependencies(): void
    {
        $root = sys_get_temp_dir().'/symsvel-vendor-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        mkdir($backend.'/vendor/package', 0775, true);
        file_put_contents($backend.'/vendor/package/original.php', 'original bytes');
        chmod($backend.'/vendor/package/original.php', 0755);
        symlink('original.php', $backend.'/vendor/package/alias.php');
        $snapshot = new RuntimeArtifactSnapshot($backend, $root.'/transaction/backend', ['vendor']);
        try {
            $snapshot->capture();
            file_put_contents($backend.'/vendor/package/original.php', 'modified');
            unlink($backend.'/vendor/package/alias.php');
            file_put_contents($backend.'/vendor/package/extra.php', 'new dependency');
            $snapshot->restore();
            self::assertSame('original bytes', file_get_contents($backend.'/vendor/package/original.php'));
            self::assertSame(0755, fileperms($backend.'/vendor/package/original.php') & 0777);
            self::assertTrue(is_link($backend.'/vendor/package/alias.php'));
            self::assertSame('original.php', readlink($backend.'/vendor/package/alias.php'));
            self::assertFileDoesNotExist($backend.'/vendor/package/extra.php');
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testMissingVendorBackupDoesNotDeleteMutatedVendor(): void
    {
        $root = sys_get_temp_dir().'/symsvel-vendor-missing-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        mkdir($backend.'/vendor', 0775, true);
        file_put_contents($backend.'/vendor/original.php', 'before');
        $snapshot = new RuntimeArtifactSnapshot($backend, $root.'/transaction/backend', ['vendor']);
        try {
            $snapshot->capture();
            file_put_contents($backend.'/vendor/original.php', 'after');
            $this->deleteTree($root.'/transaction/backend/runtime/vendor');
            try {
                $snapshot->restore();
                self::fail('Expected missing vendor backup failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('sauvegarde absente', $error->getMessage());
                self::assertSame('after', file_get_contents($backend.'/vendor/original.php'));
            }
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testCorruptedCheckpointFileNeverDestroysCurrentRuntime(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-corrupt-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules/pkg', 0775, true);
        file_put_contents($component.'/node_modules/pkg/index.js', 'original');
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction);
        try {
            $snapshot->capture();
            file_put_contents($component.'/node_modules/pkg/index.js', 'current mutated state');
            file_put_contents($transaction.'/runtime/node_modules/pkg/index.js', 'corrupted checkpoint');
            try {
                $snapshot->restore();
                self::fail('Corrupted checkpoint must abort before deleting current files.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('sauvegarde altérée', $error->getMessage());
                self::assertSame('current mutated state', file_get_contents($component.'/node_modules/pkg/index.js'));
                self::assertSame('corrupted checkpoint', file_get_contents($transaction.'/runtime/node_modules/pkg/index.js'));
            }
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testMissingFileInsideCheckpointNeverDestroysCurrentRuntime(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-file-missing-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/build', 0775, true);
        file_put_contents($component.'/build/index.html', 'original');
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction);
        try {
            $snapshot->capture();
            file_put_contents($component.'/build/index.html', 'current mutated state');
            unlink($transaction.'/runtime/build/index.html');
            try {
                $snapshot->restore();
                self::fail('Missing checkpoint file must abort before deleting current files.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('sauvegarde altérée', $error->getMessage());
                self::assertSame('current mutated state', file_get_contents($component.'/build/index.html'));
            }
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->deleteTree($entry->getPathname());
        }
        rmdir($path);
    }
}
