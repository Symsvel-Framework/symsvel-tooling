<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Pure three-way planner for framework-owned files.
 *
 * B = installed framework baseline, N = new framework release,
 * L = current project file (null means absent).
 */
final class ComponentFilePlanner
{
    /**
     * @param array<string, string> $baseline SHA-256 hashes of previously installed native files
     * @param array<string, string> $incoming SHA-256 hashes of files in the new native release
     * @param array<string, string> $local SHA-256 hashes of current project files
     * @return array{add: list<string>, replace: list<string>, delete: list<string>, conflicts: list<string>}
     */
    public function plan(array $baseline, array $incoming, array $local): array
    {
        $result = ['add' => [], 'replace' => [], 'delete' => [], 'conflicts' => []];
        foreach (array_unique([...array_keys($baseline), ...array_keys($incoming)]) as $path) {
            $old = $baseline[$path] ?? null;
            $new = $incoming[$path] ?? null;
            $current = $local[$path] ?? null;

            if ($old === null) {
                if ($new !== null && $current === null) {
                    $result['add'][] = $path;
                } elseif ($new !== null && $current !== $new) {
                    $result['conflicts'][] = $path;
                }
                continue;
            }

            if ($new === $old) {
                continue;
            }
            if ($current !== $old) {
                // A developer removed or changed a native file: do not overwrite it.
                // An already-applied upstream change is safe and idempotent.
                if ($current !== $new) {
                    $result['conflicts'][] = $path;
                }
                continue;
            }
            $result[$new === null ? 'delete' : 'replace'][] = $path;
        }
        foreach ($result as &$paths) {
            sort($paths, SORT_STRING);
        }
        unset($paths);

        return $result;
    }
}
