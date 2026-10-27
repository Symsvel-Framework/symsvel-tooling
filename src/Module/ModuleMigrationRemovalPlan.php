<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Builds a read-only down-migration plan from recorded Doctrine receipts.
 * The caller must confirm destructive operations before executing it.
 */
final class ModuleMigrationRemovalPlan
{
    public function __construct(
        private readonly ModuleRemovalPlanner $planner = new ModuleRemovalPlanner(),
        private readonly ModuleMigrationRegistry $registry = new ModuleMigrationRegistry(),
        private readonly ModuleMigrationCatalog $catalog = new ModuleMigrationCatalog(),
    ) {
    }

    /**
     * @return array{modules: list<string>, versions: list<string>, sources: list<string>}
     */
    public function plan(string $workspace, string $slug, bool $cascade = false): array
    {
        $stateDirectory = rtrim($workspace, '/').'/.symsvel';
        if (is_link($stateDirectory)) {
            throw new \RuntimeException('Répertoire de récupération symbolique interdit.');
        }
        $removalRecovery = $stateDirectory.'/module-removal-recovery.json';
        if (file_exists($removalRecovery) || is_link($removalRecovery)) {
            throw new \RuntimeException('Désinstallation précédente à récupérer : '.$removalRecovery);
        }
        $recovery = rtrim($workspace, '/').'/.symsvel/migration-recovery.json';
        if (file_exists($recovery) || is_link($recovery)) {
            throw new \RuntimeException('Récupération de migration en attente : '.$recovery);
        }
        $removal = $this->planner->plan($workspace, $slug, $cascade)['removal'];
        $selected = array_fill_keys($removal, true);
        $receipts = $this->registry->read($workspace);
        $sources = [];
        foreach ($this->catalog->discover($workspace) as $source) {
            if (!isset($selected[$source['module']])) {
                continue;
            }
            $key = $source['module'].'/'.$source['name'];
            if (!isset($receipts[$key])) {
                continue;
            }
            if (!hash_equals($receipts[$key]['sha256'], $source['sha256'])) {
                throw new \DomainException('Migration modifiée depuis son intégration : '.$key);
            }
            $sources[] = $key;
        }
        $expected = array_keys(array_filter($receipts, static function (array $entry, string $key) use ($selected): bool {
            return isset($selected[explode('/', $key, 2)[0]]);
        }, ARRAY_FILTER_USE_BOTH));
        if (array_diff($expected, $sources) !== []) {
            throw new \DomainException('Sources de migration intégrées manquantes : '.implode(', ', array_diff($expected, $sources)));
        }
        // A compiled Doctrine version must never be shared with a module
        // that survives the removal. Otherwise a down() would remove both.
        $ownership = [];
        foreach ($receipts as $key => $entry) {
            $ownership[$entry['doctrine']][] = explode('/', $key, 2)[0];
        }
        foreach ($sources as $key) {
            $version = $receipts[$key]['doctrine'];
            foreach ($ownership[$version] as $owner) {
                if (!isset($selected[$owner])) {
                    throw new \DomainException('Migration Doctrine partagée avec un module conservé : '.$version.' ('.$owner.').');
                }
            }
        }
        $versions = [];
        foreach (array_reverse($sources) as $key) {
            $version = $receipts[$key]['doctrine'];
            if (!in_array($version, $versions, true)) {
                $versions[] = $version;
            }
        }

        // A compiled migration is executable only when its Doctrine class is
        // still present in the backend. Source receipts alone are insufficient.
        return ['modules' => $removal, 'versions' => $versions, 'sources' => array_reverse($sources)];
    }
}
