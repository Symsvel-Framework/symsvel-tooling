<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Check ownership declarations across all discovered modules before generating
 * any schema operation. Ownership is explicit, never inferred from ORM names.
 */
final class ModuleTableOwnershipGuard
{
    /**
     * @param array<string, array{manifest: array<string, mixed>}> $modules
     * @param list<string> $targetTables
     */
    public function assertExclusive(array $modules, string $slug, array $targetTables): void
    {
        if (!isset($modules[$slug])) {
            throw new \DomainException('Module absent du registre : '.$slug);
        }

        $owners = [];
        foreach ($modules as $moduleSlug => $module) {
            $tables = $module['manifest']['tables'] ?? [];
            if (!is_array($tables) || !array_is_list($tables)) {
                throw new \DomainException('Déclaration des tables invalide : '.$moduleSlug);
            }
            foreach ($tables as $table) {
                if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) {
                    throw new \DomainException('Nom de table invalide : '.$moduleSlug);
                }
                if (isset($owners[$table])) {
                    throw new \DomainException(sprintf(
                        'Table %s déclarée par plusieurs modules : %s et %s.',
                        $table,
                        $owners[$table],
                        $moduleSlug,
                    ));
                }
                $owners[$table] = $moduleSlug;
            }
        }

        foreach ($targetTables as $table) {
            if (($owners[$table] ?? null) !== $slug) {
                throw new \DomainException('Table ORM non attribuée exclusivement au module '.$slug.' : '.$table);
            }
        }
    }
}
