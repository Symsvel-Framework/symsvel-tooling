<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleRemovalTransaction;

final class ModuleRemovalTransactionTest extends TestCase
{
    public function testEmptyTableSetCannotExecuteDestructiveCallback(): void
    {
        $called = false;
        try {
            (new ModuleRemovalTransaction())->run(
                sys_get_temp_dir(),
                'postgresql://localhost/example',
                [],
                static function () use (&$called): void { $called = true; },
            );
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException) {
            self::assertFalse($called);
        }
    }

    public function testPendingJournalBlocksNewRemovalBeforeBackup(): void
    {
        $root = sys_get_temp_dir().'/symsvel-removal-guard-'.bin2hex(random_bytes(6));
        mkdir($root.'/.symsvel', 0700, true);
        file_put_contents($root.'/.symsvel/module-removal-recovery.json', '{}');
        $called = false;
        try {
            $this->expectException(\RuntimeException::class);
            (new ModuleRemovalTransaction())->run(
                $root,
                'postgresql://localhost/example',
                ['users'],
                static function () use (&$called): void { $called = true; },
            );
        } finally {
            self::assertFalse($called);
            unlink($root.'/.symsvel/module-removal-recovery.json');
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }
    public function testSymlinkedRecoveryDirectoryBlocksRemoval(): void
    {
        $root = sys_get_temp_dir().'/symsvel-removal-link-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $target = $root.'/outside';
        mkdir($target, 0700);
        if (!symlink($target, $root.'/.symsvel')) {
            rmdir($target);
            rmdir($root);
            self::markTestSkipped('Symlinks unavailable.');
        }
        $called = false;
        try {
            $this->expectException(\RuntimeException::class);
            (new ModuleRemovalTransaction())->run(
                $root,
                'postgresql://localhost/example',
                ['users'],
                static function () use (&$called): void { $called = true; },
            );
        } finally {
            self::assertFalse($called);
            unlink($root.'/.symsvel');
            rmdir($target);
            rmdir($root);
        }
    }

}
