<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Builds the same immutable lifecycle plan for initial installation and updates.
 * Applying filesystem, Composer or Doctrine changes belongs to a separate executor.
 */
final class ModuleSynchronizer
{
    public function __construct(
        private readonly ModuleRegistry $registry = new ModuleRegistry(),
        private readonly ModulePlanner $planner = new ModulePlanner(),
    ) {
    }

    /**
     * @return list<array{module: string, path: string, components: array<string, string>, documentation: array<string, string>}>
     */
    public function prepare(string $workspace): array
    {
        $modules = $this->registry->discover($workspace);
        $steps = [];
        foreach ($this->planner->plan($workspace) as $slug) {
            $manifest = $modules[$slug]['manifest'];
            $steps[] = [
                'module' => $slug,
                'path' => $modules[$slug]['path'],
                'components' => $manifest['components'],
                'documentation' => $manifest['documentation'],
            ];
        }

        return $steps;
    }
}
