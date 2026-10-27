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
    public function authorize(string $workspace, string $module, bool $cascade, string $fingerprint): array
    {
        $plan = $this->planner->plan($workspace, $module, $cascade);
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
