<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Prepare a module-only schema comparison. Never compare the entire database
 * against a schema generated from a subset of ORM entities.
 */
final class ModuleSchemaScope
{
    /**
     * @param array{manifest: array<string, mixed>} $module
     * @return array{before: object, after: object, tables: list<string>}
     */
    public function restrict(object $databaseSchema, object $desiredSchema, array $module): array
    {
        $owned = $module['manifest']['tables'] ?? null;
        if (!is_array($owned) || !array_is_list($owned) || $owned === []) {
            throw new \DomainException('Déclarez explicitement les tables du module dans module.json avant toute génération.');
        }
        $lookup = [];
        foreach ($owned as $name) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || isset($lookup[$name])) {
                throw new \DomainException('Déclaration de tables invalide ou dupliquée.');
            }
            $lookup[$name] = true;
        }
        foreach ($desiredSchema->getTables() as $table) {
            if (!isset($lookup[$table->getName()])) {
                throw new \DomainException('Table ORM non déclarée par ce module : '.$table->getName());
            }
        }

        // A declaration is not a license to delete an existing table. Only
        // tables represented by ORM metadata may be considered in this diff.
        $targetNames = [];
        foreach ($desiredSchema->getTables() as $table) {
            $targetNames[$table->getName()] = true;
        }
        $before = clone $databaseSchema;
        foreach ($before->getTables() as $table) {
            if (!isset($targetNames[$table->getName()])) {
                $before->dropTable($table->getName());
            }
        }
        $after = clone $desiredSchema;
        $tables = array_keys($targetNames);
        sort($tables, SORT_STRING);

        return ['before' => $before, 'after' => $after, 'tables' => $tables];
    }
}
