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
        $snapshot = new ComposerTransactionSnapshot();
        try {
            $transaction = $snapshot->begin($backend);
            file_put_contents($backend.'/composer.json', '{"require":{"broken":"1"}}');
            file_put_contents($backend.'/composer.lock', '{"packages":[{"name":"broken"}]}');
            $snapshot->restoreManifests($backend, $transaction);
            self::assertSame('{"require":{}}', file_get_contents($backend.'/composer.json'));
            self::assertSame('{"packages":[]}', file_get_contents($backend.'/composer.lock'));
            $snapshot->finish($backend, $transaction);
            self::assertDirectoryDoesNotExist($transaction);
        } finally {
            @unlink($backend.'/composer.json');
            @unlink($backend.'/composer.lock');
            @rmdir($backend.'/.symsvel/transactions');
            @rmdir($backend.'/.symsvel');
            @rmdir($backend);
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
            $snapshot->restoreManifests($backend, $transaction);
            self::assertFileDoesNotExist($backend.'/composer.lock');
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
