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
        // Revalidate every target across every component before the first write.
        // This catches local edits made after the workspace-wide preflight.
        foreach ($prepared as $component => $entry) {
            $directory = dirname($backend).'/'.$component;
            if (is_link($directory) || !is_dir($directory)) {
                throw new \RuntimeException('Composant absent ou symbolique : '.$component);
            }
            foreach (['add', 'replace', 'delete'] as $action) {
                foreach ($entry['plan'][$action] as $path) {
                    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
                        || in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)
                        || in_array('', explode('/', $path), true)) {
                        throw new \RuntimeException('Chemin natif invalide : '.$component.'/'.$path);
                    }
                    $target = $directory.'/'.$path;
                    $parent = dirname($target);
                    while ($parent !== $directory) {
                        if (is_link($parent) || is_file($parent) || !str_starts_with($parent, $directory.'/')) {
                            throw new \RuntimeException('Chemin natif non fiable : '.$component.'/'.$path);
                        }
                        $parent = dirname($parent);
                    }
                    if ($action === 'add') {
                        if (file_exists($target) || is_link($target)) {
                            throw new \RuntimeException('Ajout local concurrent : '.$component.'/'.$path);
                        }
                    } elseif (!isset($entry['baseline'][$path]) || !is_file($target) || is_link($target)
                        || hash_file('sha256', $target) !== $entry['baseline'][$path]) {
                        throw new \RuntimeException('Modification locale concurrente : '.$component.'/'.$path);
                    }
                }
            }
        }
        foreach ($prepared as $component => $entry) {
            $directory = dirname($backend).'/'.$component;
            $this->applier->apply($directory, $entry['upstream'], $entry['plan'], $entry['baseline']);
        }
        // Commit provenance only after all native file applications succeed.
        // The caller must restore sources and manifest if this stage fails.
        foreach ($prepared as $component => $entry) {
            $manifest->set($component, $entry['revision'], $entry['incoming']);
        }
    }
}
