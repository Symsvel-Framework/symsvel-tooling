<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationSourceWriter;

final class ModuleMigrationSourceWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-source-writer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0775, true));
    }

    protected function tearDown(): void
    {
        $migrationDirectory = $this->root.'/Migration';
        // Never traverse a symlink: the rejection test points it at /tmp.
        if (is_link($migrationDirectory)) {
            unlink($migrationDirectory);
        } elseif (is_dir($migrationDirectory)) {
            foreach (glob($migrationDirectory.'/Version*.php') ?: [] as $file) {
                if (is_file($file) && !is_link($file)) {
                    unlink($file);
                }
            }
            rmdir($migrationDirectory);
        }
        rmdir($this->root);
    }

    public function testCreatesVersionedSourceWithoutOverwriting(): void
    {
        $writer = new ModuleMigrationSourceWriter();
        $time = new \DateTimeImmutable('2026-11-11 10:15:30');
        $path = $writer->write($this->root, "<?php\n// source\n", $time);
        self::assertSame($this->root.'/Migration/Version20261111101530.php', $path);
        self::assertSame("<?php\n// source\n", file_get_contents($path));

        try {
            $writer->write($this->root, 'replacement', $time);
            self::fail('A timestamp collision must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('déjà présente', $exception->getMessage());
        }
        self::assertSame("<?php\n// source\n", file_get_contents($path));
    }

    public function testRejectsSymlinkedMigrationDirectory(): void
    {
        $external = sys_get_temp_dir();
        if (!function_exists('symlink') || !@symlink($external, $this->root.'/Migration')) {
            self::markTestSkipped('Symbolic links unavailable.');
        }
        $this->expectException(\DomainException::class);
        (new ModuleMigrationSourceWriter())->write($this->root, '<?php');
    }
}
