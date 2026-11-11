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
