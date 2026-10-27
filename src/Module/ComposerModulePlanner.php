<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Validates the Composer-installed module graph independently from local module sources.
 * Composer resolves package versions; this checks SymSvel's manifest-level dependencies.
 */
final class ComposerModulePlanner
{
    public function __construct(private readonly ComposerModuleRegistry $registry = new ComposerModuleRegistry())
    {
    }

    /** @return list<string> */
    public function plan(string $backend): array
    {
        $modules = $this->registry->discover($backend);
        $states = [];
        $ordered = [];
        $visit = function (string $slug) use (&$visit, &$states, &$ordered, $modules): void {
            if (($states[$slug] ?? 0) === 2) {
                return;
            }
            if (($states[$slug] ?? 0) === 1) {
                throw new \InvalidArgumentException('Dépendance circulaire : '.$slug);
            }
            if (!isset($modules[$slug])) {
                throw new \InvalidArgumentException('Module Composer dépendant introuvable : '.$slug);
            }
            $states[$slug] = 1;
            foreach ($modules[$slug]['manifest']['dependencies'] as $dependency => $constraint) {
                if (!is_string($dependency)
                    || !preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $dependency)
                    || !is_string($constraint) || trim($constraint) === '') {
                    throw new \InvalidArgumentException('Dépendance invalide dans '.$slug);
                }
                $visit($dependency);
            }
            $states[$slug] = 2;
            $ordered[] = $slug;
        };
        foreach (array_keys($modules) as $slug) {
            $visit($slug);
        }

        return $ordered;
    }
}
