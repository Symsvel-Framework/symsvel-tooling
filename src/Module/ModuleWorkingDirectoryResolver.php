<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Resolves exactly one workspace module for development commands.
 * Reuses ModuleRegistry rather than interpreting arbitrary module.json files.
 */
final class ModuleWorkingDirectoryResolver
{
    public function __construct(private readonly ModuleRegistry $registry = new ModuleRegistry())
    {
    }

    /** @return array{slug: string, path: string, manifest: array<string, mixed>} */
    public function resolve(string $workspace, ?string $module, string $workingDirectory): array
    {
        $modules = $this->registry->discover($workspace);
        if ($module !== null && $module !== '') {
            foreach ($modules as $slug => $entry) {
                if ($module === $slug || $module === $entry['manifest']['name']) {
                    return ['slug' => $slug, ...$entry];
                }
            }
            throw new \InvalidArgumentException('Module SymSvel introuvable : '.$module);
        }

        $current = realpath($workingDirectory);
        if ($current === false || !is_dir($current)) {
            throw new \InvalidArgumentException('Répertoire courant invalide.');
        }
        foreach ($modules as $slug => $entry) {
            $root = realpath($entry['path']);
            if ($root !== false && ($current === $root || str_starts_with($current, $root.DIRECTORY_SEPARATOR))) {
                return ['slug' => $slug, ...$entry];
            }
        }

        throw new \InvalidArgumentException('Module non identifié : indiquez son nom ou lancez la commande depuis son dossier.');
    }
}
