<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\InitialSetupMigrationResolver;
use SymSvel\Tooling\Module\ModuleMigrationCompiler;

final class InitialSetupMigrationResolverTest extends TestCase
{
    private string $root;
    private array $source;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-initial-migration-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $path = $this->root.'/source.php';
        file_put_contents($path, <<<'PHP'
<?php
final class Source
{
    public function up(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $schema->createTable('initial_setup_test');
    }

    public function down(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $schema->dropTable('initial_setup_test');
    }
}
PHP);
        $this->source = [
            'module' => 'example',
            'name' => 'Version20261123000000.php',
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/migrations/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->root.'/migrations')) {
            rmdir($this->root.'/migrations');
        }
        @unlink($this->root.'/source.php');
        @rmdir($this->root);
    }

    public function testMissingFileIsCompiledAndMarkedCreated(): void
    {
        $migration = (new InitialSetupMigrationResolver())->resolve($this->root, $this->source, new ModuleMigrationCompiler());
        self::assertTrue($migration['created']);
        self::assertFileExists($migration['path']);
        self::assertSame('DoctrineMigrations\\Version20261123000000', $migration['version']);
    }

    public function testExistingCompiledFileIsReusedWithoutModification(): void
    {
        $resolver = new InitialSetupMigrationResolver();
        $compiler = new ModuleMigrationCompiler();
        $first = $resolver->resolve($this->root, $this->source, $compiler);
        $original = file_get_contents($first['path']);
        $second = $resolver->resolve($this->root, $this->source, $compiler);
        self::assertFalse($second['created']);
        self::assertSame($original, file_get_contents($second['path']));
        self::assertSame($first['entries'], $second['entries']);
    }

    public function testForeignDoctrineMigrationCollisionIsRejected(): void
    {
        mkdir($this->root.'/migrations');
        $path = $this->root.'/migrations/'.$this->source['name'];
        file_put_contents($path, '<?php // unrelated migration');
        try {
            (new InitialSetupMigrationResolver())->resolve($this->root, $this->source, new ModuleMigrationCompiler());
            self::fail('Collision must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Collision', $exception->getMessage());
            self::assertSame('<?php // unrelated migration', file_get_contents($path));
        }
    }

    public function testCompiledMigrationFromAnotherModuleIsRejected(): void
    {
        $resolver = new InitialSetupMigrationResolver();
        $migration = $resolver->resolve($this->root, $this->source, new ModuleMigrationCompiler());
        $other = $this->source;
        $other['module'] = 'another';
        try {
            $resolver->resolve($this->root, $other, new ModuleMigrationCompiler());
            self::fail('Foreign module collision must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Collision', $exception->getMessage());
            self::assertFileExists($migration['path']);
        }
    }

    public function testSymlinkedDoctrineDirectoryIsRejected(): void
    {
        if (!symlink(sys_get_temp_dir(), $this->root.'/migrations')) {
            self::markTestSkipped('Symlinks unavailable.');
        }
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('symbolique interdit');
            (new InitialSetupMigrationResolver())->resolve($this->root, $this->source, new ModuleMigrationCompiler());
        } finally {
            unlink($this->root.'/migrations');
        }
    }
}
