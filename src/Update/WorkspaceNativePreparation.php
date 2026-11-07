<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Read-only native preflight for every installed Svelte interface.
 * The caller owns the checkpoint directory and the global transaction.
 */
final class WorkspaceNativePreparation
{
    public function __construct(private readonly ComponentNativePreparation $preparation = new ComponentNativePreparation())
    {
    }

    /**
     * @return array<string, array{revision: string, incoming: array<string, string>, baseline: array<string, string>, plan: array{add: list<string>, replace: list<string>, delete: list<string>, conflicts: list<string>}, upstream: string}>
     */
    public function prepare(string $backend, string $checkpoint): array
    {
        $root = dirname($backend);
        $prepared = [];
        foreach (['bo', 'app', 'front'] as $component) {
            $directory = $root.'/'.$component;
            if (is_link($directory)) {
                throw new \RuntimeException('Composant symbolique refusé : '.$component);
            }
            if (!is_dir($directory)) {
                continue;
            }
            $upstream = $checkpoint.'/upstream-'.$component;
            $result = $this->preparation->prepare($backend, $component, $upstream);
            $prepared[$component] = [...$result, 'upstream' => $upstream];
        }
        return $prepared;
    }
}
