<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Discovers Composer module migration sources in dependency order.
 * This is read-only: compilation and Doctrine execution remain separate steps.
 */
final class ComposerModuleMigrationCatalog
{
    public function __construct(
        private readonly ComposerModuleSynchronizer $synchronizer = new ComposerModuleSynchronizer(),
    ) {
    }

    /** @return list<array{module: string, name: string, path: string, sha256: string}> */
    public function discover(string $backend): array
    {
        $sources = [];
        foreach ($this->synchronizer->prepare($backend) as $module) {
            $slug = $module['module'];
            $directory = $module['path'].'/Migration';
            if (!file_exists($directory) && !is_link($directory)) {
                continue;
            }
            if (!is_dir($directory) || is_link($directory)) {
                throw new \InvalidArgumentException('Répertoire Migration invalide : '.$slug);
            }
            $files = [];
            foreach (new \DirectoryIterator($directory) as $entry) {
                if ($entry->isDot() || $entry->getFilename() === '.gitkeep') {
                    continue;
                }
                if ($entry->isLink() || !$entry->isFile()
                    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\\.php$/D', $entry->getFilename())) {
                    throw new \InvalidArgumentException('Source de migration invalide : '.$slug.'/'.$entry->getFilename());
                }
                $files[$entry->getFilename()] = $entry->getPathname();
            }
            ksort($files, SORT_STRING);
            foreach ($files as $name => $path) {
                $sha256 = hash_file('sha256', $path);
                if ($sha256 === false) {
                    throw new \RuntimeException('Lecture de migration impossible : '.$path);
                }
                $sources[] = ['module' => $slug, 'name' => $name, 'path' => $path, 'sha256' => $sha256];
            }
        }
        usort($sources, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $sources;
    }
}
