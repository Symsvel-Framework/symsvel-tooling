<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleSchemaDiffGuard;

final class ModuleSchemaDiffGuardTest extends TestCase
{
    public function testAllowsCreationOnly(): void
    {
        (new ModuleSchemaDiffGuard())->assertSupported($this->diff([], []));
        self::assertFalse((new ModuleSchemaDiffGuard())->hasChanges($this->diff([], [])));
        self::assertTrue((new ModuleSchemaDiffGuard())->hasChanges($this->diff([], [], ['new_table'])));
        self::assertTrue((new ModuleSchemaDiffGuard())->hasChanges($this->diff([], [$this->additiveChange()])));
    }

    public function testRejectsDroppedTables(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Suppression de table');
        (new ModuleSchemaDiffGuard())->assertNonDestructive($this->diff(['legacy'], []));
    }

    public function testRejectsChangedTables(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Format de modification DBAL');
        (new ModuleSchemaDiffGuard())->assertNonDestructive($this->diff([], ['orders']));
    }

    public function testRejectsSequenceCreationEvenWithNewTable(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('getCreatedSequences');
        (new ModuleSchemaDiffGuard())->assertNonDestructive($this->diff([], [], ['orders'], ['sequence']));
    }

    public function testRejectsSchemaCreation(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('getCreatedSchemas');
        (new ModuleSchemaDiffGuard())->assertNonDestructive($this->diff([], [], [], [], ['schema']));
    }

    public function testRejectsUnknownDiffShape(): void
    {
        $this->expectException(\DomainException::class);
        (new ModuleSchemaDiffGuard())->assertNonDestructive(new \stdClass());
    }

    public function testRejectsDestructiveColumnChange(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('getDroppedColumns');
        (new ModuleSchemaDiffGuard())->assertNonDestructive($this->diff([], [$this->additiveChange(true)]));
    }

    private function additiveChange(bool $destructive = false): object
    {
        return new class($destructive) {
            public function __construct(private bool $destructive) {}
            public function getAddedColumns(): array { return []; }
            public function getAddedIndexes(): array { return []; }
            public function getAddedForeignKeys(): array { return []; }
            public function getChangedColumns(): array { return []; }
            public function getDroppedColumns(): array { return $this->destructive ? ['legacy'] : []; }
            public function getModifiedIndexes(): array { return []; }
            public function getDroppedIndexes(): array { return []; }
            public function getRenamedIndexes(): array { return []; }
            public function getModifiedForeignKeys(): array { return []; }
            public function getDroppedForeignKeys(): array { return []; }
        };
    }

    private function diff(array $dropped, array $changed, array $created = [], array $sequences = [], array $schemas = []): object
    {
        return new class($dropped, $changed, $created, $sequences, $schemas) {
            public function __construct(private array $dropped, private array $changed, private array $created, private array $sequences, private array $schemas) {}
            public function getCreatedSchemas(): array { return $this->schemas; }
            public function getDroppedSchemas(): array { return []; }
            public function getCreatedSequences(): array { return $this->sequences; }
            public function getDroppedSequences(): array { return []; }
            public function getAlteredSequences(): array { return []; }
            public function getCreatedTables(): array { return $this->created; }
            public function getDroppedTables(): array { return $this->dropped; }
            public function getAlteredTables(): array { return $this->changed; }
        };
    }
}
