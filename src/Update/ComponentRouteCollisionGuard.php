<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/** Detect SvelteKit route collisions even when route groups differ on disk. */
final class ComponentRouteCollisionGuard
{
    /**
     * @param array<string, string> $baseline
     * @param array<string, string> $incoming
     * @param array<string, string> $local
     * @return list<string>
     */
    public function conflicts(array $baseline, array $incoming, array $local): array
    {
        $existing = [];
        foreach (array_keys($local) as $path) {
            $route = $this->route($path);
            if ($route !== null) {
                $existing[$route][] = $path;
            }
        }
        $conflicts = [];
        foreach (array_keys($incoming) as $path) {
            if (isset($baseline[$path])) {
                continue;
            }
            $route = $this->route($path);
            if ($route === null) {
                continue;
            }
            foreach ($existing[$route] ?? [] as $other) {
                if ($other !== $path && !isset($baseline[$other])) {
                    $conflicts[] = $path.' <=> '.$other;
                }
            }
        }
        sort($conflicts);
        return $conflicts;
    }

    private function route(string $path): ?string
    {
        if (!preg_match('~^src/routes/(.*)\\+(page|server)(?:\\.[^/]+)?$~', $path, $matches)) {
            return null;
        }
        $segments = array_filter(explode('/', trim($matches[1], '/')), static fn (string $segment): bool => !preg_match('/^\\([^)]*\\)$/D', $segment));
        $segments = array_map(static function (string $segment): string {
            if (preg_match('/^\\[\\[?\\.\\.\\./', $segment)) {
                return '[parameter]';
            }
            if (preg_match('/^\\[[^]]+\\]$/D', $segment)) {
                return '[parameter]';
            }
            return $segment;
        }, $segments);
        return '/'.implode('/', $segments).'/'.$matches[2];
    }
}
