<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleCompiledMigrationGuard;

final class ModuleCompiledMigrationGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-compiled-guard-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/migrations', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/migrations/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root.'/migrations');
        rmdir($this->root);
    }

    public function testExistingCompiledMigrationIsAccepted(): void
    {
        file_put_contents($this->root.'/migrations/Version202610270001.php', '<?php');
        (new ModuleCompiledMigrationGuard())->assertAvailable($this->root, ['DoctrineMigrations\\Version202610270001']);
        self::assertTrue(true);
    }

    public function testMissingCompiledMigrationIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('absente ou invalide');
        (new ModuleCompiledMigrationGuard())->assertAvailable($this->root, ['DoctrineMigrations\\Version202610270001']);
    }

    public function testInvalidVersionIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalide');
        (new ModuleCompiledMigrationGuard())->assertAvailable($this->root, ['Other\\Version202610270001']);
    }

    public function testSymlinkedCompiledMigrationIsRejected(): void
    {
        $target = $this->root.'/original.php';
        file_put_contents($target, '<?php');
        symlink($target, $this->root.'/migrations/Version202610270001.php');
        try {
            $this->expectException(\DomainException::class);
            (new ModuleCompiledMigrationGuard())->assertAvailable($this->root, ['DoctrineMigrations\\Version202610270001']);
        } finally {
            unlink($target);
        }
    }

    public function testSymlinkedMigrationsDirectoryIsRejected(): void
    {
        rmdir($this->root.'/migrations');
        mkdir($this->root.'/real', 0700);
        symlink($this->root.'/real', $this->root.'/migrations');
        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('symbolique');
            (new ModuleCompiledMigrationGuard())->assertAvailable($this->root, []);
        } finally {
            unlink($this->root.'/migrations');
            rmdir($this->root.'/real');
            mkdir($this->root.'/migrations', 0700);
        }
    }
}
