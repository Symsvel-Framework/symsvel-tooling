<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Recomputes the removal scope at execution time. A preview is never trusted
 * as an executable instruction.
 */
final class ModuleRemovalExecutionGate
{
    public function __construct(
        private readonly ModuleMigrationRemovalPlan $planner = new ModuleMigrationRemovalPlan(),
        private readonly ModuleTableOwnership $ownership = new ModuleTableOwnership(),
        private readonly ModuleMigrationRegistry $registry = new ModuleMigrationRegistry(),
        private readonly ModuleRemovalConfirmation $confirmation = new ModuleRemovalConfirmation(),
    ) {
    }

    /**
     * @return array{modules: list<string>, versions: list<string>, sources: list<string>, tables: list<string>, remaining: array<string, array{sha256: string, doctrine: string}>}
     */
    public function authorize(string $workspace, string $backend, string $module, bool $cascade, string $fingerprint): array
    {
        $plan = $this->planner->plan($workspace, $module, $cascade);
        $migrationDirectory = rtrim($backend, '/').'/migrations';
        if (is_link($migrationDirectory)) {
            throw new \DomainException('Répertoire Doctrine compilé symbolique interdit.');
        }
        foreach ($plan['versions'] as $version) {
            if (!preg_match('/^DoctrineMigrations\\\\(Version[0-9]+)$/D', $version, $matches)) {
                throw new \DomainException('Version Doctrine compilée invalide : '.$version);
            }
            $compiled = $migrationDirectory.'/'.$matches[1].'.php';
            if (!is_file($compiled) || is_link($compiled)) {
                throw new \DomainException('Migration Doctrine compilée absente ou invalide : '.$version);
            }
        }
        $plan['tables'] = $this->ownership->tables($workspace, $plan['modules']);
        if ($plan['versions'] !== [] && $plan['tables'] === []) {
            throw new \DomainException('Migrations intégrées sans tables déclarées : désinstallation bloquée.');
        }
        $remaining = $this->registry->withoutSources($workspace, $plan['sources']);
        $this->confirmation->assertMatches($workspace, $plan, $fingerprint);

        return [
            'modules' => $plan['modules'],
            'versions' => $plan['versions'],
            'sources' => $plan['sources'],
            'tables' => $plan['tables'],
            'remaining' => $remaining,
        ];
    }
}
