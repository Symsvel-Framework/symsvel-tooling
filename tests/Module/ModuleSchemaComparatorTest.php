<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleSchemaComparator;

final class ModuleSchemaComparatorTest extends TestCase
{
    public function testOnlyComparesOwnedTablesAndDoesNotMutateInput(): void
    {
        $database = new ComparatorFakeSchema(['orders', 'users']);
        $desired = new ComparatorFakeSchema(['orders']);
        $manager = new ComparatorFakeManager($database);
        $connection = new ComparatorFakeConnection($manager);

        $result = (new ModuleSchemaComparator())->compare($connection, $desired, [
            'orders' => ['manifest' => ['tables' => ['orders']]],
            'users' => ['manifest' => ['tables' => ['users']]],
        ], 'orders');

        self::assertSame(['orders'], $result['tables']);
        self::assertSame(['orders'], $result['diff']->before);
        self::assertSame(['orders'], $result['diff']->after);
        self::assertSame(['orders', 'users'], $database->names());
        self::assertSame(1, $manager->comparisons);
    }

    public function testRefusesConflictBeforeComparing(): void
    {
        $manager = new ComparatorFakeManager(new ComparatorFakeSchema(['orders']));
        try {
            (new ModuleSchemaComparator())->compare(new ComparatorFakeConnection($manager), new ComparatorFakeSchema(['orders']), [
                'orders' => ['manifest' => ['tables' => ['orders']]],
                'users' => ['manifest' => ['tables' => ['orders']]],
            ], 'orders');
            self::fail('Expected ownership conflict.');
        } catch (\DomainException $e) {
            self::assertSame(0, $manager->comparisons);
        }
    }
}

final class ComparatorFakeConnection
{
    public function __construct(private readonly ComparatorFakeManager $manager) {}
    public function createSchemaManager(): ComparatorFakeManager { return $this->manager; }
}

final class ComparatorFakeManager
{
    public int $comparisons = 0;
    public function __construct(private readonly ComparatorFakeSchema $database) {}
    public function introspectSchema(): ComparatorFakeSchema { return $this->database; }
    public function createComparator(): self { return $this; }
    public function compareSchemas(object $before, object $after): object
    {
        ++$this->comparisons;
        return (object) ['before' => $before->names(), 'after' => $after->names()];
    }
}

final class ComparatorFakeSchema
{
    /** @param list<string> $names */
    public function __construct(private array $names) {}
    public function getTables(): array
    {
        return array_map(static fn (string $name): object => new class($name) {
            public function __construct(private readonly string $name) {}
            public function getName(): string { return $this->name; }
            public function getForeignKeys(): array { return []; }
        }, $this->names);
    }
    public function dropTable(string $name): void
    {
        $this->names = array_values(array_filter($this->names, static fn (string $item): bool => $item !== $name));
    }
    public function names(): array
    {
        $names = $this->names;
        sort($names, SORT_STRING);
        return $names;
    }
}
