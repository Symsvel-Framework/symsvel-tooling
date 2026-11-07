<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Applies workspace-wide native plans inside a transaction owned by WorkspaceUpdater.
 * No snapshots, Composer calls or cleanup belong to this class.
 */
final class WorkspaceNativeApply
{
    public function __construct(private readonly ComponentNativeFileApplier $applier = new ComponentNativeFileApplier())
    {
    }

    /**
     * @param array<string, array{revision: string, incoming: array<string, string>, baseline: array<string, string>, plan: array{add: list<string>, replace: list<string>, delete: list<string>, conflicts: list<string>}, upstream: string}> $prepared
     */
    public function apply(string $backend, array $prepared): void
    {
        $manifest = new ComponentManifest($backend);
        foreach ($prepared as $component => $entry) {
            if (!in_array($component, ['bo', 'app', 'front'], true)) {
                throw new \InvalidArgumentException('Composant inconnu : '.$component);
            }
            if ($entry['plan']['conflicts'] !== []) {
                throw new \RuntimeException('Plan natif non validé : '.$component);
            }
        }
        foreach ($prepared as $component => $entry) {
            $directory = dirname($backend).'/'.$component;
            if (is_link($directory) || !is_dir($directory)) {
                throw new \RuntimeException('Composant absent ou symbolique : '.$component);
            }
            $this->applier->apply($directory, $entry['upstream'], $entry['plan'], $entry['baseline']);
        }
        // Commit provenance only after all native file applications succeed.
        // The caller must restore sources and manifest if this stage fails.
        foreach ($prepared as $component => $entry) {
            $manifest->set($component, $entry['revision'], $entry['incoming']);
        }
    }
}
