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

        // All declared tables belong to this module, including tables whose
        // ORM entity was intentionally removed. Their removal is a real diff.
        $targetNames = $lookup;
        $before = clone $databaseSchema;
        foreach ($before->getTables() as $table) {
            if (!isset($targetNames[$table->getName()])) {
                $before->dropTable($table->getName());
            }
        }
        $after = clone $desiredSchema;
        // PostgreSQL namespaces are infrastructure, not module-owned objects.
        // Preserve existing namespaces in the scoped target so the comparator
        // cannot interpret a module-only diff as DROP SCHEMA public.
        if (method_exists($before, 'getNamespaces') && method_exists($after, 'getNamespaces')
            && method_exists($after, 'hasNamespace') && method_exists($after, 'createNamespace')) {
            foreach ($before->getNamespaces() as $namespace) {
                if (!$after->hasNamespace($namespace)) {
                    $after->createNamespace($namespace);
                }
            }
        }
        $tables = array_keys($lookup);
        sort($tables, SORT_STRING);

        return ['before' => $before, 'after' => $after, 'tables' => $tables];
    }
}
