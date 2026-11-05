<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\SetupGroupAccess;

final class SetupGroupAccessTest extends TestCase
{
    public function testSharesOnlyDedicatedPathsWithExistingGroup(): void
    {
        if (!function_exists('posix_getgrgid') || !function_exists('posix_getegid')) {
            self::markTestSkipped('POSIX extension required.');
        }
        $group = posix_getgrgid(posix_getegid())['name'] ?? null;
        if (!is_string($group)) {
            self::markTestSkipped('No effective POSIX group.');
        }
        $root = sys_get_temp_dir().'/symsvel-group-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/.symsvel');
        mkdir($root.'/backend');
        file_put_contents($root.'/.symsvel/setup-token', 'secret');
        chmod($root.'/.symsvel/setup-token', 0600);
        try {
            (new SetupGroupAccess())->configure($root, $group);
            clearstatcache(true);
            self::assertSame(0640, fileperms($root.'/.symsvel/setup-token') & 0777);
            self::assertSame(02770, fileperms($root.'/.symsvel') & 07777);
            self::assertSame(02770, fileperms($root.'/backend') & 07777);
        } finally {
            @unlink($root.'/.symsvel/setup-token');
            @rmdir($root.'/.symsvel');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testRejectsUnknownGroupWithoutChangingToken(): void
    {
        $root = sys_get_temp_dir().'/symsvel-group-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/.symsvel');
        mkdir($root.'/backend');
        file_put_contents($root.'/.symsvel/setup-token', 'secret');
        chmod($root.'/.symsvel/setup-token', 0600);
        try {
            $this->expectException(\RuntimeException::class);
            (new SetupGroupAccess())->configure($root, 'symsvel_nonexistent_group_99999');
        } finally {
            clearstatcache(true, $root.'/.symsvel/setup-token');
            self::assertSame(0600, fileperms($root.'/.symsvel/setup-token') & 0777);
            @unlink($root.'/.symsvel/setup-token');
            @rmdir($root.'/.symsvel');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }
    public function testRejectsInvalidGroupNameBeforeFilesystemChanges(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SetupGroupAccess())->configure(sys_get_temp_dir(), '../unexpected');
    }

}
