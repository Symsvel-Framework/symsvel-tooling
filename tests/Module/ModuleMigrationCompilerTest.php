<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationCompiler;

final class ModuleMigrationCompilerTest extends TestCase
{
    public function testCompilesUpAndReverseDownWithoutExecutingSources(): void
    {
        $root = sys_get_temp_dir().'/symsvel-compiler-'.bin2hex(random_bytes(6));
        mkdir($root);
        $first = $root.'/first.php';
        $second = $root.'/second.php';
        $template = <<<'PHP'
<?php
final class Source
{
    public function up(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $this->addSql('CREATE TABLE %s (id INT)');
    }

    public function down(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $this->addSql('DROP TABLE %s');
    }
}
PHP;
        file_put_contents($first, sprintf($template, 'first', 'first'));
        file_put_contents($second, sprintf($template, 'second', 'second'));
        try {
            $sources = [
                ['module' => 'first', 'name' => '001.php', 'path' => $first, 'sha256' => hash_file('sha256', $first)],
                ['module' => 'second', 'name' => '001.php', 'path' => $second, 'sha256' => hash_file('sha256', $second)],
            ];
            $compiled = (new ModuleMigrationCompiler())->compile($root, $sources);
            self::assertNotNull($compiled);
            $output = (string) file_get_contents($compiled['path']);
            self::assertLessThan(strpos($output, 'CREATE TABLE second'), strpos($output, 'CREATE TABLE first'));
            self::assertLessThan(strpos($output, 'DROP TABLE first'), strpos($output, 'DROP TABLE second'));
            self::assertCount(2, $compiled['entries']);
            self::assertStringContainsString('DoctrineMigrations', $output);
        } finally {
            foreach (glob($root.'/migrations/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($root.'/migrations')) {
                rmdir($root.'/migrations');
            }
            unlink($first);
            unlink($second);
            rmdir($root);
        }
    }
}
