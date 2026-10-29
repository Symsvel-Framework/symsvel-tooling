<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Composer;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;

final class WorkspaceFileSnapshotTest extends TestCase
{
    public function testCaptureFailureCannotProduceAUsableSnapshot(): void
    {
        $root = sys_get_temp_dir().'/symsvel-snapshot-capture-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        $transaction = $backend.'/.symsvel/transactions/test';
        mkdir($transaction, 0700, true);
        file_put_contents($backend.'/source.txt', 'must remain untouched');
        // Simulate an inaccessible snapshot destination without relying on chmod
        // (which behaves differently when tests run as root).
        file_put_contents($transaction.'/workspace', 'blocked');
        $snapshot = new WorkspaceFileSnapshot($backend, $transaction);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Impossible de préparer le snapshot');
            $snapshot->capture();
        } finally {
            self::assertSame('must remain untouched', file_get_contents($backend.'/source.txt'));
            unlink($transaction.'/workspace');
            unlink($backend.'/source.txt');
            rmdir($transaction);
            rmdir(dirname($transaction));
            rmdir(dirname(dirname($transaction)));
            rmdir($backend);
            rmdir($root);
        }
    }

    public function testRollbackDoesNotFollowSymlinkIntoExternalDirectory(): void
    {
        $root = sys_get_temp_dir().'/symsvel-snapshot-symlink-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        $bo = $root.'/bo';
        $outside = $root.'/outside';
        $transaction = $backend.'/.symsvel/transactions/test';
        mkdir($transaction, 0700, true);
        mkdir($bo.'/src', 0775, true);
        mkdir($outside);
        file_put_contents($bo.'/src/component.js', 'original');
        file_put_contents($outside.'/component.js', 'external');
        $snapshot = new WorkspaceFileSnapshot($backend, $transaction);
        try {
            $snapshot->capture();
            unlink($bo.'/src/component.js');
            rmdir($bo.'/src');
            symlink($outside, $bo.'/src');
            $snapshot->restore();
            self::assertSame('original', file_get_contents($bo.'/src/component.js'));
            self::assertSame('external', file_get_contents($outside.'/component.js'));
            self::assertFalse(is_link($bo.'/src'));
        } finally {
            $snapshot->discard();
            @unlink($bo.'/src/component.js');
            @unlink($bo.'/src');
            @rmdir($bo.'/src');
            @unlink($outside.'/component.js');
            @rmdir($outside);
            @rmdir($bo);
            @rmdir($transaction);
            @rmdir(dirname($transaction));
            @rmdir(dirname(dirname($transaction)));
            @rmdir($backend);
            @rmdir($root);
        }
    }
}
