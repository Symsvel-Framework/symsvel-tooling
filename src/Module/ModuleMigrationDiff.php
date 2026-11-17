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
        $versions = [];
        foreach ($sources as $source) {
            $key = $source['module'].'/'.$source['name'];
            // All modules compile into one Doctrine namespace and one migrations directory.
            // Reject conflicting version names before writing any compiled file.
            if (preg_match('/^Version[0-9]{14}\\.php$/D', $source['name']) === 1) {
                $name = $source['name'];
                if (isset($versions[$name]) && $versions[$name] !== $key) {
                    throw new \DomainException('Version de migration utilisée par plusieurs modules : '
                        .$name.' ('.$versions[$name].' et '.$key.').');
                }
                $versions[$name] = $key;
            }
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
