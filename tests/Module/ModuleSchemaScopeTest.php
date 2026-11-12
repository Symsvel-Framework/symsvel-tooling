<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleSchemaScope;

final class ModuleSchemaScopeTest extends TestCase
{
    public function testExcludesNeighborTablesFromBothSides(): void
    {
        $current = new FakeSchema(['catalogue_items', 'users', 'legacy_table']);
        $desired = new FakeSchema(['catalogue_items']);
        $result = (new ModuleSchemaScope())->restrict($current, $desired, [
            'manifest' => ['tables' => ['catalogue_items']],
        ]);

        self::assertSame(['catalogue_items'], $result['before']->names());
        self::assertSame(['catalogue_items'], $result['after']->names());
        self::assertSame(['catalogue_items', 'legacy_table', 'users'], $current->names(), 'Original database schema must remain unchanged');
    }

    public function testPreservesExistingPostgresNamespaceInTarget(): void
    {
        $current = new FakeNamespacedSchema(['other_table'], ['public']);
        $desired = new FakeNamespacedSchema(['catalogue_items'], []);
        $result = (new ModuleSchemaScope())->restrict($current, $desired, [
            'manifest' => ['tables' => ['catalogue_items']],
        ]);
        self::assertSame(['public'], $result['after']->getNamespaces());
        self::assertSame([], $desired->getNamespaces(), 'Original desired schema must remain unchanged');
        self::assertSame([], $result['before']->names());
        self::assertSame(['catalogue_items'], $result['after']->names());
    }

    public function testRefusesOrmTableNotOwnedByModule(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Table ORM non déclarée');
        (new ModuleSchemaScope())->restrict(
            new FakeSchema(['users']),
            new FakeSchema(['catalogue_items', 'users']),
            ['manifest' => ['tables' => ['catalogue_items']]],
        );
    }

    public function testRefusesMissingOwnershipDeclaration(): void
    {
        $this->expectException(\DomainException::class);
        (new ModuleSchemaScope())->restrict(new FakeSchema([]), new FakeSchema([]), ['manifest' => []]);
    }

    public function testMissingOrmTableRemainsInScopeForDrop(): void
    {
        $result = (new ModuleSchemaScope())->restrict(
            new FakeSchema(['catalogue_items', 'catalogue_tags']),
            new FakeSchema(['catalogue_items']),
            ['manifest' => ['tables' => ['catalogue_items', 'catalogue_tags']]],
        );
        self::assertSame(['catalogue_items', 'catalogue_tags'], $result['before']->names());
        self::assertSame(['catalogue_items'], $result['after']->names());
    }
}

final class FakeSchema
{
    /** @param list<string> $names */
    public function __construct(private array $names)
    {
    }

    /** @return list<object> */
    public function getTables(): array
    {
        return array_map(static fn (string $name): object => new class($name) {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }
        }, $this->names);
    }

    public function dropTable(string $name): void
    {
        $this->names = array_values(array_filter($this->names, static fn (string $entry): bool => $entry !== $name));
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = $this->names;
        sort($names, SORT_STRING);
        return $names;
    }
}

final class FakeNamespacedSchema
{
    /** @param list<string> $tables @param list<string> $namespaces */
    public function __construct(private array $tables, private array $namespaces) {}
    public function getTables(): array
    {
        return array_map(static fn (string $name): object => new class($name) {
            public function __construct(private readonly string $name) {}
            public function getName(): string { return $this->name; }
        }, $this->tables);
    }
    public function dropTable(string $name): void
    {
        $this->tables = array_values(array_filter($this->tables, static fn (string $table): bool => $table !== $name));
    }
    public function getNamespaces(): array { return $this->namespaces; }
    public function hasNamespace(string $name): bool { return in_array($name, $this->namespaces, true); }
    public function createNamespace(string $name): void { $this->namespaces[] = $name; }
    public function names(): array { return $this->tables; }
}
