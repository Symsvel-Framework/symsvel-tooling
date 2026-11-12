<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Discovers immutable module migration sources in dependency order.
 * Only migration sources are discovered; they are never executed directly.
 */
final class ModuleMigrationCatalog
{
    public function __construct(
        private readonly ModuleRegistry $registry = new ModuleRegistry(),
        private readonly ModulePlanner $planner = new ModulePlanner(),
    ) {
    }

    /** @return list<array{module: string, name: string, path: string, sha256: string}> */
    public function discover(string $workspace): array
    {
        $modules = $this->registry->discover($workspace);
        $result = [];
        foreach ($this->planner->plan($workspace) as $slug) {
            $directory = $modules[$slug]['path'].'/Migration';
            if (!file_exists($directory)) {
                continue;
            }
            if (!is_dir($directory) || is_link($directory)) {
                throw new \InvalidArgumentException('Répertoire Migration invalide : '.$slug);
            }
            $files = [];
            foreach (new \DirectoryIterator($directory) as $entry) {
                if ($entry->isDot() || in_array($entry->getFilename(), ['.gitkeep', '.DS_Store'], true)) {
                    continue;
                }
                if ($entry->isLink() || !$entry->isFile() || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.php$/', $entry->getFilename())) {
                    throw new \InvalidArgumentException('Source de migration invalide : '.$slug.'/'.$entry->getFilename());
                }
                $files[$entry->getFilename()] = $entry->getPathname();
            }
            ksort($files, SORT_STRING);
            foreach ($files as $name => $path) {
                $hash = hash_file('sha256', $path);
                if ($hash === false) {
                    throw new \RuntimeException('Lecture de migration impossible : '.$path);
                }
                $result[] = ['module' => $slug, 'name' => $name, 'path' => $path, 'sha256' => $hash];
            }
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $result;
    }
}
