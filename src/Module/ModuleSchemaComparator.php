<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Produces a DBAL schema diff without SQL generation or database mutation.
 * The caller must validate the diff before rendering a migration.
 */
final class ModuleSchemaComparator
{
    public function __construct(
        private readonly ModuleSchemaScope $scope = new ModuleSchemaScope(),
        private readonly ModuleTableOwnershipGuard $ownership = new ModuleTableOwnershipGuard(),
        private readonly ModuleForeignKeyGuard $foreignKeys = new ModuleForeignKeyGuard(),
    ) {
    }

    /**
     * @param array<string, array{manifest: array<string, mixed>}> $modules
     * @return array{diff: object, tables: list<string>}
     */
    public function compare(object $connection, object $desiredSchema, array $modules, string $slug): array
    {
        if (!isset($modules[$slug])) {
            throw new \DomainException('Module absent du registre : '.$slug);
        }
        $schemaManager = $connection->createSchemaManager();
        $databaseSchema = $schemaManager->introspectSchema();

        // All validations precede the actual DBAL comparison.
        $this->ownership->assertExclusive($modules, $slug, $this->tableNames($desiredSchema));
        $this->foreignKeys->assertDeclaredTargets($desiredSchema, $modules, $slug);
        $scoped = $this->scope->restrict($databaseSchema, $desiredSchema, $modules[$slug]);

        return [
            'diff' => $schemaManager->createComparator()->compareSchemas($scoped['before'], $scoped['after']),
            'tables' => $scoped['tables'],
        ];
    }

    /** @return list<string> */
    private function tableNames(object $schema): array
    {
        $names = [];
        foreach ($schema->getTables() as $table) {
            $names[] = $table->getName();
        }
        return $names;
    }
}
