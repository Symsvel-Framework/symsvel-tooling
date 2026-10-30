<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Composer;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Composer\ComposerTransactionSnapshot;

final class ComposerTransactionSnapshotTest extends TestCase
{
    public function testRestoresExistingComposerFiles(): void
    {
        $backend = sys_get_temp_dir().'/symsvel-composer-'.bin2hex(random_bytes(8));
        mkdir($backend);
        file_put_contents($backend.'/composer.json', '{"require":{}}');
        file_put_contents($backend.'/composer.lock', '{"packages":[]}');
        file_put_contents($backend.'/symfony.lock', '{"recipes":{}}');
        $snapshot = new ComposerTransactionSnapshot();
        try {
            $transaction = $snapshot->begin($backend);
            file_put_contents($backend.'/composer.json', '{"require":{"broken":"1"}}');
            file_put_contents($backend.'/composer.lock', '{"packages":[{"name":"broken"}]}');
            file_put_contents($backend.'/symfony.lock', '{"recipes":{"broken":{}}}');
            $snapshot->restoreManifests($backend, $transaction);
            self::assertSame('{"require":{}}', file_get_contents($backend.'/composer.json'));
            self::assertSame('{"packages":[]}', file_get_contents($backend.'/composer.lock'));
            self::assertSame('{"recipes":{}}', file_get_contents($backend.'/symfony.lock'));
            $snapshot->finish($backend, $transaction);
            self::assertDirectoryDoesNotExist($transaction);
        } finally {
            @unlink($backend.'/composer.json');
            @unlink($backend.'/composer.lock');
            @unlink($backend.'/symfony.lock');
            @rmdir($backend.'/.symsvel/transactions');
            @rmdir($backend.'/.symsvel');
            @rmdir($backend);
        }
    }

    public function testRejectsSymlinkedTransactionManifestWithoutReadingExternalFile(): void
    {
        $backend = sys_get_temp_dir().'/symsvel-composer-link-'.bin2hex(random_bytes(8));
        mkdir($backend);
        file_put_contents($backend.'/composer.json', '{}');
        $snapshot = new ComposerTransactionSnapshot();
        $transaction = $snapshot->begin($backend);
        $outside = $backend.'/outside.json';
        file_put_contents($outside, '{"external":true}');
        unlink($transaction.'/manifest.json');
        symlink($outside, $transaction.'/manifest.json');
        try {
            $snapshot->restoreManifests($backend, $transaction);
            self::fail('Symlinked transaction metadata must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('absent ou symbolique', $error->getMessage());
            self::assertSame('{"external":true}', file_get_contents($outside));
        } finally {
            unlink($transaction.'/manifest.json');
            foreach (['composer.json', 'composer.lock', 'symfony.lock'] as $name) {
                @unlink($transaction.'/'.$name);
            }
            @rmdir($transaction);
            @rmdir($backend.'/.symsvel/transactions');
            @rmdir($backend.'/.symsvel');
            @unlink($outside);
            @unlink($backend.'/composer.json');
            @rmdir($backend);
        }
    }

    public function testRejectsSymbolicComposerManifestBeforeCreatingCheckpoint(): void
    {
        $backend = sys_get_temp_dir().'/symsvel-composer-preflight-'.bin2hex(random_bytes(8));
        mkdir($backend);
        file_put_contents($backend.'/original.json', '{}');
        symlink($backend.'/original.json', $backend.'/composer.json');
        try {
            try {
                (new ComposerTransactionSnapshot())->begin($backend);
                self::fail('Symbolic Composer manifest must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('symbolique refusé', $error->getMessage());
            }
            self::assertDirectoryDoesNotExist($backend.'/.symsvel');
        } finally {
            unlink($backend.'/composer.json');
            unlink($backend.'/original.json');
            rmdir($backend);
        }
    }

    public function testRejectsSymbolicTransactionRootBeforeCreatingCheckpoint(): void
    {
        $root = sys_get_temp_dir().'/symsvel-composer-root-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        mkdir($backend.'/.symsvel', 0700, true);
        mkdir($root.'/other');
        file_put_contents($backend.'/composer.json', '{}');
        symlink($root.'/other', $backend.'/.symsvel/transactions');
        try {
            try {
                (new ComposerTransactionSnapshot())->begin($backend);
                self::fail('Symbolic checkpoint root must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('symbolique refusé', $error->getMessage());
            }
            self::assertSame([], array_diff(scandir($root.'/other'), ['.', '..']));
        } finally {
            unlink($backend.'/.symsvel/transactions');
            unlink($backend.'/composer.json');
            rmdir($backend.'/.symsvel');
            rmdir($backend);
            rmdir($root.'/other');
            rmdir($root);
        }
    }

    public function testRestoresAbsenceOfOriginalLockFile(): void
    {
        $backend = sys_get_temp_dir().'/symsvel-composer-'.bin2hex(random_bytes(8));
        mkdir($backend);
        file_put_contents($backend.'/composer.json', '{}');
        $snapshot = new ComposerTransactionSnapshot();
        try {
            $transaction = $snapshot->begin($backend);
            file_put_contents($backend.'/composer.lock', '{}');
            file_put_contents($backend.'/symfony.lock', '{}');
            $snapshot->restoreManifests($backend, $transaction);
            self::assertFileDoesNotExist($backend.'/composer.lock');
            self::assertFileDoesNotExist($backend.'/symfony.lock');
            $snapshot->finish($backend, $transaction);
        } finally {
            @unlink($backend.'/composer.json');
            @unlink($backend.'/composer.lock');
            @rmdir($backend.'/.symsvel/transactions');
            @rmdir($backend.'/.symsvel');
            @rmdir($backend);
        }
    }
}
