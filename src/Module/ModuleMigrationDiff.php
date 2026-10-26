<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Compares immutable module sources against the recorded Doctrine migration map.
 * The registry is a receipt, not a substitute for doctrine_migration_versions.
 */
final class ModuleMigrationDiff
{
    /**
     * @param list<array{module: string, name: string, path: string, sha256: string}> $sources
     * @param array<string, array{sha256: string, doctrine: string}> $registered
     * @return array{pending: list<array{module: string, name: string, path: string, sha256: string}>, removed: list<string>}
     */
    public function compare(array $sources, array $registered): array
    {
        $pending = [];
        $seen = [];
        foreach ($sources as $source) {
            $key = $source['module'].'/'.$source['name'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Migration dupliquée : '.$key);
            }
            $seen[$key] = true;
            if (!isset($registered[$key])) {
                $pending[] = $source;
                continue;
            }
            if (!hash_equals($registered[$key]['sha256'], $source['sha256'])) {
                throw new \DomainException('Migration source déjà intégrée modifiée : '.$key);
            }
        }
        $removed = array_values(array_diff(array_keys($registered), array_keys($seen)));
        sort($removed, SORT_STRING);

        return ['pending' => $pending, 'removed' => $removed];
    }
}
