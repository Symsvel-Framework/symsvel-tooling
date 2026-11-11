<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationCompiler;

final class ModuleMigrationCompilerTest extends TestCase
{
    public function testPreservesSourceVersionForSqlMigration(): void
    {
        $root = sys_get_temp_dir().'/symsvel-compiler-'.bin2hex(random_bytes(6));
        mkdir($root);
        $source = $root.'/Version20200101000000.php';
        file_put_contents($source, <<<'PHP'
<?php
final class Source
{
    public function up(\Doctrine\\DBAL\\Schema\\Schema $schema): void
    {
        $this->addSql('INSERT INTO reference_table (id) VALUES (1)');
    }

    public function down(\Doctrine\\DBAL\\Schema\\Schema $schema): void
    {
        $this->addSql('DELETE FROM reference_table WHERE id = 1');
    }
}
PHP);
        try {
            $entry = ['module' => 'reference', 'name' => basename($source), 'path' => $source, 'sha256' => hash_file('sha256', $source)];
            $compiler = new ModuleMigrationCompiler();
            $compiled = $compiler->compile($root, [$entry]);
            self::assertSame('DoctrineMigrations\\Version20200101000000', $compiled['version']);
            self::assertSame($root.'/migrations/Version20200101000000.php', $compiled['path']);
            self::assertStringContainsString('INSERT INTO reference_table', file_get_contents($compiled['path']));
            self::assertStringContainsString('createComparator()->compareSchemas', file_get_contents($compiled['path']));
            self::assertStringContainsString('getAlterSchemaSQL', file_get_contents($compiled['path']));
            $lint = new \Symfony\Component\Process\Process([PHP_BINARY, '-l', $compiled['path']]);
            $lint->run();
            self::assertTrue($lint->isSuccessful(), $lint->getOutput().$lint->getErrorOutput());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Version Doctrine déjà présente');
            $compiler->compile($root, [$entry]);
        } finally {
            foreach (glob($root.'/migrations/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($root.'/migrations')) {
                rmdir($root.'/migrations');
            }
            unlink($source);
            rmdir($root);
        }
    }

    public function testRejectsLegacySourceNames(): void
    {
        $root = sys_get_temp_dir().'/symsvel-compiler-'.bin2hex(random_bytes(6));
        mkdir($root);
        $source = $root.'/001.php';
        file_put_contents($source, '<?php');
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new ModuleMigrationCompiler())->compile($root, [
                ['module' => 'legacy', 'name' => '001.php', 'path' => $source, 'sha256' => hash_file('sha256', $source)],
            ]);
        } finally {
            if (is_dir($root.'/migrations')) {
                rmdir($root.'/migrations');
            }
            unlink($source);
            rmdir($root);
        }
    }
}
