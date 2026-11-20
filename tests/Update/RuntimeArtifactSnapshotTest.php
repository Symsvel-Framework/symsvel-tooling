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

    public function testCorruptedLaterArtifactDoesNotPartiallyRestoreEarlierArtifact(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-atomic-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules', 0775, true);
        mkdir($component.'/build', 0775, true);
        file_put_contents($component.'/node_modules/current.js', 'original dependency');
        file_put_contents($component.'/build/index.html', 'original build');
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction);
        try {
            $snapshot->capture();
            file_put_contents($component.'/node_modules/current.js', 'mutated dependency');
            file_put_contents($component.'/build/index.html', 'mutated build');
            file_put_contents($transaction.'/runtime/build/index.html', 'corrupted backup');
            try {
                $snapshot->restore();
                self::fail('A later corrupted artifact must block all artifact restoration.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('sauvegarde altérée pour build', $error->getMessage());
                self::assertSame('mutated dependency', file_get_contents($component.'/node_modules/current.js'));
                self::assertSame('mutated build', file_get_contents($component.'/build/index.html'));
            }
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testRestoresDirectoryPermissionsAndRejectsTamperedDirectoryMode(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-directory-mode-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/build/nested', 0755, true);
        file_put_contents($component.'/build/nested/index.html', 'original');
        chmod($component.'/build', 0750);
        chmod($component.'/build/nested', 0755);
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction);
        try {
            $snapshot->capture();
            self::assertSame(0750, fileperms($transaction.'/runtime/build') & 0777);
            chmod($component.'/build', 0777);
            chmod($component.'/build/nested', 0777);
            $snapshot->restore();
            self::assertSame(0750, fileperms($component.'/build') & 0777);
            self::assertSame(0755, fileperms($component.'/build/nested') & 0777);

            chmod($transaction.'/runtime/build/nested', 0700);
            chmod($component.'/build', 0777);
            try {
                $snapshot->restore();
                self::fail('Modified checkpoint directory mode must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('sauvegarde altérée pour build', $error->getMessage());
                self::assertSame(0777, fileperms($component.'/build') & 0777);
            }
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testMoveCapturePreservesNpmFilesAndRestoresPreviousRuntime(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-move-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules/pkg', 0775, true);
        mkdir($component.'/build', 0775, true);
        file_put_contents($component.'/node_modules/pkg/index.js', 'old dependency');
        symlink('index.js', $component.'/node_modules/pkg/alias.js');
        file_put_contents($component.'/build/index.html', 'old build');
        $inode = fileinode($component.'/node_modules/pkg/index.js');
        mkdir($transaction, 0700, true);
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction, moveOnCapture: true);

        try {
            $snapshot->capture();
            self::assertDirectoryDoesNotExist($component.'/node_modules');
            self::assertDirectoryDoesNotExist($component.'/build');
            self::assertSame($inode, fileinode($transaction.'/runtime/node_modules/pkg/index.js'));
            mkdir($component.'/node_modules', 0775, true);
            file_put_contents($component.'/node_modules/new.js', 'new dependency');
            mkdir($component.'/build');
            file_put_contents($component.'/build/index.html', 'new build');
            $snapshot->restore();
            self::assertSame('old dependency', file_get_contents($component.'/node_modules/pkg/index.js'));
            self::assertTrue(is_link($component.'/node_modules/pkg/alias.js'));
            self::assertSame('index.js', readlink($component.'/node_modules/pkg/alias.js'));
            self::assertSame($inode, fileinode($component.'/node_modules/pkg/index.js'));
            self::assertSame('old build', file_get_contents($component.'/build/index.html'));
            self::assertFileDoesNotExist($component.'/node_modules/new.js');
            self::assertFalse($snapshot->hasPendingMoves());
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testMoveCaptureKeepsStableWorkingCopyForComposerAndRestoresOriginal(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-working-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules/pkg', 0775, true);
        mkdir($component.'/build', 0775, true);
        mkdir($transaction, 0700, true);
        file_put_contents($component.'/node_modules/pkg/index.js', 'original');
        file_put_contents($component.'/build/index.html', 'original build');
        $inode = fileinode($component.'/node_modules/pkg/index.js');
        $snapshot = new RuntimeArtifactSnapshot(
            $component,
            $transaction,
            moveOnCapture: true,
            keepWorkingCopy: true,
        );
        try {
            $snapshot->capture();
            self::assertSame('original', file_get_contents($component.'/node_modules/pkg/index.js'));
            self::assertSame('original', file_get_contents($transaction.'/runtime/node_modules/pkg/index.js'));
            self::assertSame($inode, fileinode($transaction.'/runtime/node_modules/pkg/index.js'));
            file_put_contents($component.'/node_modules/pkg/index.js', 'modified by hook');
            file_put_contents($component.'/build/index.html', 'new build');
            $snapshot->restore();
            self::assertSame('original', file_get_contents($component.'/node_modules/pkg/index.js'));
            self::assertSame('original build', file_get_contents($component.'/build/index.html'));
            self::assertSame($inode, fileinode($component.'/node_modules/pkg/index.js'));
            self::assertFalse($snapshot->hasPendingMoves());
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testMoveCaptureFailureRestoresAlreadyMovedDirectories(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-move-fail-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules', 0775, true);
        mkdir($component.'/build');
        file_put_contents($component.'/node_modules/keep.js', 'keep');
        file_put_contents($component.'/build/index.html', 'keep build');
        // Force the second rename to fail after node_modules was moved.
        mkdir($transaction.'/runtime/build', 0700, true);
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction, moveOnCapture: true);

        try {
            try {
                $snapshot->capture();
                self::fail('Existing checkpoint path must be refused.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Déplacement snapshot runtime impossible : build', $error->getMessage());
            }
            self::assertSame('keep', file_get_contents($component.'/node_modules/keep.js'));
            self::assertSame('keep build', file_get_contents($component.'/build/index.html'));
            self::assertFalse($snapshot->hasPendingMoves());
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testMovedCheckpointIsVerifiedBeforeReplacingCurrentRuntime(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-move-tamper-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules', 0775, true);
        file_put_contents($component.'/node_modules/original.js', 'original');
        mkdir($transaction, 0700, true);
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction, moveOnCapture: true);

        try {
            $snapshot->capture();
            mkdir($component.'/node_modules');
            file_put_contents($component.'/node_modules/current.js', 'current');
            file_put_contents($transaction.'/runtime/node_modules/original.js', 'tampered');
            try {
                $snapshot->restore();
                self::fail('A tampered moved checkpoint must block rollback.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('sauvegarde altérée', $error->getMessage());
                self::assertSame('current', file_get_contents($component.'/node_modules/current.js'));
            }
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    public function testMoveCaptureRejectsSymbolicRootBeforeMovingDependencies(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-move-symlink-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules', 0775, true);
        mkdir($root.'/external');
        file_put_contents($component.'/node_modules/keep.js', 'keep');
        symlink($root.'/external', $component.'/build');
        mkdir($transaction, 0700, true);
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction, moveOnCapture: true);

        try {
            try {
                $snapshot->capture();
                self::fail('A later symbolic artifact must be checked before moving anything.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Snapshot runtime refusé', $error->getMessage());
            }
            self::assertSame('keep', file_get_contents($component.'/node_modules/keep.js'));
            self::assertFalse($snapshot->hasPendingMoves());
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
