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
        $this->assertNoExternalIncomingForeignKeys($databaseSchema, $desiredSchema);
        $this->assertExistingExternalTargets($databaseSchema, $desiredSchema);
        $scoped = $this->scope->restrict($databaseSchema, $desiredSchema, $modules[$slug]);

        return [
            'diff' => $schemaManager->createComparator()->compareSchemas($scoped['before'], $scoped['after']),
            'tables' => $scoped['tables'],
        ];
    }

    /**
     * Scoping away a referencing table could conceal a dependency on a module
     * table. Fail closed until incoming constraints can be preserved safely.
     */
    private function assertNoExternalIncomingForeignKeys(object $database, object $desired): void
    {
        $targets = array_fill_keys($this->tableNames($desired), true);
        foreach ($database->getTables() as $table) {
            if (isset($targets[$table->getName()])) {
                continue;
            }
            foreach ($table->getForeignKeys() as $foreignKey) {
                if (isset($targets[$foreignKey->getForeignTableName()])) {
                    throw new \DomainException(sprintf(
                        'Clé étrangère entrante depuis une table externe : %s -> %s.',
                        $table->getName(),
                        $foreignKey->getForeignTableName(),
                    ));
                }
            }
        }
    }

    /** A declared target in another module must already exist physically. */
    private function assertExistingExternalTargets(object $database, object $desired): void
    {
        $available = array_fill_keys($this->tableNames($database), true);
        $local = array_fill_keys($this->tableNames($desired), true);
        foreach ($desired->getTables() as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                $target = $foreignKey->getForeignTableName();
                if (!isset($local[$target]) && !isset($available[$target])) {
                    throw new \DomainException('Table référencée absente de la base : '.$target);
                }
            }
        }
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
