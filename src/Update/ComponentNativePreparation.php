<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Prepares an upstream native update without changing project files.
 * Transaction ownership belongs to the calling updater.
 */
final class ComponentNativePreparation
{
    public function __construct(
        private readonly ComponentNativeSource $native = new ComponentNativeSource(),
        private readonly ComponentUpdatePreflight $preflight = new ComponentUpdatePreflight(),
        private readonly LegacyComponentBaseline $legacy = new LegacyComponentBaseline(),
    ) {
    }

    /**
     * @return array{revision: string, incoming: array<string, string>, baseline: array<string, string>, plan: array{add: list<string>, replace: list<string>, delete: list<string>, conflicts: list<string>}}
     */
    public function prepare(string $backend, string $component, string $upstream): array
    {
        if (!in_array($component, ['bo', 'app', 'front'], true)) {
            throw new \InvalidArgumentException('Composant inconnu : '.$component);
        }
        $directory = dirname($backend).'/'.$component;
        if (is_link($directory) || !is_dir($directory)) {
            throw new \RuntimeException('Composant absent ou symbolique : '.$component);
        }
        $baseline = (new ComponentManifest($backend))->get($component)
            ?? $this->legacy->recover($directory, $component);
        $revision = $this->native->fetch($component, $upstream);
        $incoming = $this->native->files($upstream);
        $local = $this->native->files($directory);
        $plan = $this->preflight->inspect($baseline['files'], $incoming, $local);
        $this->preflight->assertSafe($plan);
        return [
            'revision' => $revision,
            'incoming' => $incoming,
            'baseline' => $baseline['files'],
            'plan' => $plan,
        ];
    }
}
