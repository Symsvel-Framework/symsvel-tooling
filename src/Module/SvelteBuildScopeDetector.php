<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/** Determines which Svelte applications changed between two managed-file inventories. */
final class SvelteBuildScopeDetector
{
    /** @param array<string, string> $before
     *  @param array<string, string> $after
     *  @return list<string>
     */
    public function changedScopes(string $workspace, array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $path) {
            if (($before[$path] ?? null) === ($after[$path] ?? null)) {
                continue;
            }
            foreach (['bo', 'app', 'front'] as $scope) {
                if (str_starts_with($path, $scope.'/src/')
                    || str_starts_with($path, rtrim($workspace, '/').'/'.$scope.'/src/')) {
                    $changed[$scope] = true;
                }
            }
        }
        return array_keys($changed);
    }

    /** @return array<string, string> */
    public function readInventory(string $backend): array
    {
        return (new SvelteFileRegistry())->read($backend);
    }
}
