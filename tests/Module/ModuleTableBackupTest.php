<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleTableBackup;

final class ModuleTableBackupTest extends TestCase
{
    public function testRejectsEmptyTableSelectionWithoutCreatingBackup(): void
    {
        $backup = new ModuleTableBackup();
        $this->expectException(\InvalidArgumentException::class);
        $backup->create(sys_get_temp_dir(), 'postgresql://localhost/example', []);
    }

    public function testRejectsUnsafeTableNameWithoutRunningSql(): void
    {
        $backup = new ModuleTableBackup();
        $this->expectException(\InvalidArgumentException::class);
        $backup->create(sys_get_temp_dir(), 'mysql://localhost/example', ['users; DROP TABLE users']);
    }

    public function testRejectsDuplicateTableNames(): void
    {
        $backup = new ModuleTableBackup();
        $this->expectException(\InvalidArgumentException::class);
        $backup->create(sys_get_temp_dir(), 'sqlite:///tmp/example.db', ['users', 'users']);
    }

    public function testRestoreRefusesMissingBackupBeforeTouchingDatabase(): void
    {
        $backup = new ModuleTableBackup();
        $this->expectException(\RuntimeException::class);
        $backup->restore('postgresql://localhost/example', [
            'driver' => 'postgresql',
            'path' => sys_get_temp_dir().'/symsvel-nonexistent-'.bin2hex(random_bytes(8)),
            'tables' => ['users'],
        ]);
    }
}
