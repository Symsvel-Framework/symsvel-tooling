<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Read-only preflight of native files before a component update.
 * This deliberately never consults the project's Git branch or remotes.
 */
final class ComponentUpdatePreflight
{
    public function __construct(
        private readonly ComponentFilePlanner $planner = new ComponentFilePlanner(),
        private readonly ComponentRouteCollisionGuard $routes = new ComponentRouteCollisionGuard(),
    ) {
    }

    /**
     * @param array<string, string> $baseline
     * @param array<string, string> $incoming
     * @param array<string, string> $local
     * @return array{add: list<string>, replace: list<string>, delete: list<string>, conflicts: list<string>}
     */
    public function inspect(array $baseline, array $incoming, array $local): array
    {
        $plan = $this->planner->plan($baseline, $incoming, $local);
        $plan['conflicts'] = array_values(array_unique([
            ...$plan['conflicts'],
            ...$this->routes->conflicts($baseline, $incoming, $local),
        ]));
        sort($plan['conflicts'], SORT_STRING);
        return $plan;
    }

    /**
     * @param array{conflicts: list<string>} $plan
     */
    public function assertSafe(array $plan): void
    {
        if ($plan['conflicts'] !== []) {
            throw new \RuntimeException(
                "Mise à jour native refusée : conflits détectés avant toute modification :\n - "
                .implode("\n - ", $plan['conflicts']),
            );
        }
    }
}
