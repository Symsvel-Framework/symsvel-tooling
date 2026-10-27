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

    public function testSymlinkedRecoveryDirectoryBlocksRestoration(): void
    {
        $root = sys_get_temp_dir().'/symsvel-restore-link-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        mkdir($root.'/outside', 0700);
        symlink($root.'/outside', $root.'/.symsvel');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('symbolique interdit');
            (new ModuleRemovalTransaction())->restore($root, 'postgresql://localhost/example');
        } finally {
            unlink($root.'/.symsvel');
            rmdir($root.'/outside');
            rmdir($root);
        }
    }

    public function testRecoveryRejectsBackupOutsideWorkspace(): void
    {
        $root = sys_get_temp_dir().'/symsvel-restore-path-'.bin2hex(random_bytes(6));
        mkdir($root.'/.symsvel', 0700, true);
        $external = $root.'/external.sql';
        file_put_contents($external, 'do not restore');
        $journal = $root.'/.symsvel/module-removal-recovery.json';
        file_put_contents($journal, json_encode([
            'schema' => 1,
            'state' => 'restore_required',
            'backup' => ['path' => $external, 'driver' => 'mysql', 'tables' => ['users']],
            'sha256' => hash_file('sha256', $external),
        ], JSON_THROW_ON_ERROR));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('hors du répertoire');
            (new ModuleRemovalTransaction())->restore($root, 'mysql://localhost/example');
        } finally {
            unlink($journal);
            unlink($external);
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }

}
