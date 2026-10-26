<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Plans safe module removal, including transitive dependants.
 *
 * This planner never changes the database or filesystem. The caller must
 * explicitly confirm the full cascade before running any down migrations.
 */
final class ModuleRemovalPlanner
{
    public function __construct(
        private readonly ModuleRegistry $registry = new ModuleRegistry(),
        private readonly ModulePlanner $planner = new ModulePlanner(),
    ) {
    }

    /**
     * @return array{requested: string, dependants: list<string>, removal: list<string>}
     */
    public function plan(string $workspace, string $slug, bool $cascade = false): array
    {
        $modules = $this->registry->discover($workspace);
        if (!isset($modules[$slug])) {
            throw new \InvalidArgumentException('Module introuvable : '.$slug);
        }

        // Installation order is a topological sort; its reverse is the only
        // safe removal order for foreign keys between module-owned tables.
        $order = $this->planner->plan($workspace);
        $selected = [$slug => true];
        foreach ($order as $candidate) {
            foreach (array_keys($modules[$candidate]['manifest']['dependencies']) as $dependency) {
                if (isset($selected[$dependency])) {
                    $selected[$candidate] = true;
                    break;
                }
            }
        }

        $dependants = array_values(array_filter(
            $order,
            static fn (string $candidate): bool => $candidate !== $slug && isset($selected[$candidate]),
        ));

        if ($dependants !== [] && !$cascade) {
            throw new \DomainException(sprintf(
                'Suppression de %s bloquée par : %s. Confirmation de la cascade requise.',
                $slug,
                implode(', ', $dependants),
            ));
        }

        $removal = array_values(array_filter(
            array_reverse($order),
            static fn (string $candidate): bool => isset($selected[$candidate]),
        ));

        return ['requested' => $slug, 'dependants' => $dependants, 'removal' => $removal];
    }
}
