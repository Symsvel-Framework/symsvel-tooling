<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/** Read-only bridge from installed Composer packages to SymSvel lifecycle plans. */
final class ComposerModuleSynchronizer
{
    public function __construct(
        private readonly ComposerModuleRegistry $registry = new ComposerModuleRegistry(),
        private readonly ComposerModulePlanner $planner = new ComposerModulePlanner(),
    ) {
    }

    /** @return list<array{module: string, path: string, components: array<string, string>, documentation: array<string, string>}> */
    public function prepare(string $backend): array
    {
        $modules = $this->registry->discover($backend);
        $steps = [];
        foreach ($this->planner->plan($backend) as $slug) {
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
