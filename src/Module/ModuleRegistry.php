<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Single source of truth for locally available modules.
 * No Composer mutations or runtime activation happen during discovery.
 */
final class ModuleRegistry
{
    public function __construct(private readonly ModuleManifest $reader = new ModuleManifest())
    {
    }

    /** @return array<string, array{path: string, manifest: array<string, mixed>}> */
    public function discover(string $workspace): array
    {
        $root = rtrim($workspace, '/').'/modules';
        if (!is_dir($root)) {
            return [];
        }
        $modules = [];
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $slug = $entry->getFilename();
            if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $slug)) {
                throw new \InvalidArgumentException('Répertoire de module invalide : '.$slug);
            }
            $path = $entry->getPathname();
            $manifest = $this->reader->read($path);
            if (strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $manifest['name'])) !== $slug) {
                throw new \InvalidArgumentException('Nom du manifeste incohérent : '.$slug);
            }
            $modules[$slug] = ['path' => $path, 'manifest' => $manifest];
        }
        ksort($modules);

        return $modules;
    }
}
