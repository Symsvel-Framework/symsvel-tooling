<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleSchemaMigrationRenderer;

final class ModuleSchemaMigrationRendererTest extends TestCase
{
    public function testRendersCreateAndReverseDrop(): void
    {
        if (!class_exists(\Doctrine\DBAL\Schema\Table::class)) {
            self::markTestSkipped('Doctrine DBAL fourni par le projet hôte.');
        }
        $table = new \Doctrine\DBAL\Schema\Table('demo_items');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('title', 'string', ['length' => 100]);
        $table->setPrimaryKey(['id']);

        $php = (new ModuleSchemaMigrationRenderer())->render($this->diff([$table]), 'Version20261111150000');
        self::assertStringContainsString('final class Version20261111150000', $php);
        self::assertStringContainsString('$schema->createTable(', $php);
        self::assertStringContainsString('$table->setPrimaryKey(', $php);
        self::assertStringContainsString('$schema->dropTable(', $php);
        self::assertStringNotContainsString('addSql(', $php);
    }

    public function testRendersDropTableAndRestoresItsStructureOnDown(): void
    {
        if (!class_exists(\Doctrine\DBAL\Schema\Table::class)) {
            self::markTestSkipped('Doctrine DBAL fourni par le projet hôte.');
        }
        $table = new \Doctrine\DBAL\Schema\Table('demo_legacy');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
        $php = (new ModuleSchemaMigrationRenderer())->renderDropTable($table, 'Version20261112143000');
        self::assertMatchesRegularExpression('~function up.*?dropTable.*?function down.*?createTable~s', $php);
        self::assertStringContainsString("'demo_legacy'", $php);
    }

    public function testRendersColumnDropAndTypeChangeFromRealDbalDiff(): void
    {
        if (!class_exists(\Doctrine\DBAL\Schema\Comparator::class)) {
            self::markTestSkipped('Doctrine DBAL fourni par le projet hôte.');
        }
        $before = new \Doctrine\DBAL\Schema\Schema();
        $old = $before->createTable('demo_items');
        $old->addColumn('id', 'integer');
        $old->addColumn('legacy', 'string', ['length' => 40]);
        $old->addColumn('description', 'string', ['length' => 100]);
        $after = clone $before;
        $new = $after->getTable('demo_items');
        $new->dropColumn('legacy');
        $new->modifyColumn('description', ['type' => \Doctrine\DBAL\Types\Type::getType('text'), 'length' => null]);
        $platform = new \Doctrine\DBAL\Platforms\PostgreSQLPlatform();
        $diff = (new \Doctrine\DBAL\Schema\Comparator($platform))->compareSchemas($before, $after);
        $alterations = $diff->getAlteredTables();
        self::assertCount(1, $alterations);
        $php = (new ModuleSchemaMigrationRenderer())->renderAlteration(array_values($alterations)[0], 'Version20261112143100');
        self::assertStringContainsString('$table->dropColumn(', $php);
        self::assertStringContainsString('$table->modifyColumn(', $php);
        self::assertMatchesRegularExpression('~modifyColumn\\([^,]+,\\s*array \\(~', $php);
        self::assertStringContainsString("'type' => \\Doctrine\\DBAL\\Types\\Type::getType('text')", $php);
        self::assertStringContainsString("'type' => \\Doctrine\\DBAL\\Types\\Type::getType('string')", $php);
        self::assertStringContainsString("'text'", $php);
        self::assertStringContainsString("'string'", $php);
    }

    public function testRendersSimpleIndexes(): void
    {
        if (!class_exists(\Doctrine\DBAL\Schema\Table::class)) {
            self::markTestSkipped('Doctrine DBAL fourni par le projet hôte.');
        }
        $table = new \Doctrine\DBAL\Schema\Table('demo_items');
        $table->addColumn('id', 'integer');
        $table->addColumn('email', 'string', ['length' => 150]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['email'], 'uniq_demo_email');
        $table->addIndex(['id', 'email'], 'idx_demo_lookup');

        $php = (new ModuleSchemaMigrationRenderer())->render($this->diff([$table]), 'Version20261111150100');
        self::assertStringContainsString('$table->addUniqueIndex(', $php);
        self::assertStringContainsString('uniq_demo_email', $php);
        self::assertStringContainsString('$table->addIndex(', $php);
        self::assertStringContainsString('idx_demo_lookup', $php);
    }

    public function testRejectsIndexFlags(): void
    {
        if (!class_exists(\Doctrine\DBAL\Schema\Table::class)) {
            self::markTestSkipped('Doctrine DBAL fourni par le projet hôte.');
        }
        $table = new \Doctrine\DBAL\Schema\Table('demo_items');
        $table->addColumn('email', 'string');
        $table->addIndex(['email'], 'idx_special', [], ['where' => 'email IS NOT NULL']);

        $this->expectException(\DomainException::class);
        (new ModuleSchemaMigrationRenderer())->render($this->diff([$table]), 'Version20261111150200');
    }

    public function testGeneratedMigrationIsValidPhp(): void
    {
        if (!class_exists(\Doctrine\DBAL\Schema\Table::class)) {
            self::markTestSkipped('Doctrine DBAL fourni par le projet hôte.');
        }
        $table = new \Doctrine\DBAL\Schema\Table('demo_items');
        $table->addColumn('id', 'integer');
        $php = (new ModuleSchemaMigrationRenderer())->render($this->diff([$table]), 'Version20261111150300');
        $path = tempnam(sys_get_temp_dir(), 'symsvel-php-');
        self::assertNotFalse($path);
        try {
            self::assertNotFalse(file_put_contents($path, $php));
            $output = [];
            $status = 0;
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $status);
            self::assertSame(0, $status, implode("\n", $output));
        } finally {
            unlink($path);
        }
    }

    public function testRejectsEmptyDiff(): void
    {
        $this->expectException(\DomainException::class);
        (new ModuleSchemaMigrationRenderer())->render($this->diff([]), 'Version20261111150000');
    }

    public function testRejectsInvalidVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModuleSchemaMigrationRenderer())->render($this->diff([]), '../Version');
    }

    private function diff(array $created): object
    {
        return new class($created) {
            public function __construct(private array $created) {}
            public function getCreatedTables(): array { return $this->created; }
            public function getDroppedTables(): array { return []; }
            public function getAlteredTables(): array { return []; }
            public function getCreatedSchemas(): array { return []; }
            public function getDroppedSchemas(): array { return []; }
            public function getCreatedSequences(): array { return []; }
            public function getDroppedSequences(): array { return []; }
            public function getAlteredSequences(): array { return []; }
        };
    }
}
