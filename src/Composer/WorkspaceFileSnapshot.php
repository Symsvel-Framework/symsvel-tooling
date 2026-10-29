<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Composer;

/**
 * Restore source files across the workspace after a failed Composer operation.
 * Runtime dependencies and database state are deliberately outside this scope.
 */
final class WorkspaceFileSnapshot
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var list<string> */
    private array $roots = [];

    public function __construct(private readonly string $backend, private readonly string $snapshot)
    {
    }

    public function capture(): void
    {
        $workspace = dirname($this->backend);
        foreach (['backend', 'bo', 'app', 'front'] as $component) {
            $root = $component === 'backend' ? $this->backend : $workspace.'/'.$component;
            if (!is_dir($root)) {
                continue;
            }
            $this->roots[] = $root;
            foreach ($this->scan($root) as $relative => $path) {
                $key = $component.'/'.$relative;
                $target = $this->snapshot.'/workspace/'.$key;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                    throw new \RuntimeException('Impossible de préparer le snapshot : '.$key);
                }
                if (is_link($path)) {
                    if (!symlink((string) readlink($path), $target)) {
                        throw new \RuntimeException('Sauvegarde du lien impossible : '.$key);
                    }
                } elseif (!copy($path, $target)) {
                    throw new \RuntimeException('Sauvegarde impossible : '.$key);
                }
                $this->files[$key] = $target;
            }
        }
    }

    public function restore(): void
    {
        $workspace = dirname($this->backend);
        foreach ($this->roots as $root) {
            $component = $root === $this->backend ? 'backend' : basename($root);
            foreach ($this->scan($root) as $relative => $path) {
                if (!isset($this->files[$component.'/'.$relative]) && !unlink($path)) {
                    throw new \RuntimeException('Suppression impossible : '.$path);
                }
            }
        }
        foreach ($this->files as $key => $source) {
            [$component, $relative] = explode('/', $key, 2);
            $root = $component === 'backend' ? $this->backend : $workspace.'/'.$component;
            $target = $root.'/'.$relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                throw new \RuntimeException('Répertoire impossible à restaurer : '.$key);
            }
            if (is_link($target) || is_file($target)) {
                if (!unlink($target)) {
                    throw new \RuntimeException('Remplacement impossible : '.$key);
                }
            }
            if (is_link($source)) {
                if (!symlink((string) readlink($source), $target)) {
                    throw new \RuntimeException('Lien impossible à restaurer : '.$key);
                }
            } elseif (!copy($source, $target)) {
                throw new \RuntimeException('Fichier impossible à restaurer : '.$key);
            }
        }
    }

    /** @return array<string, string> */
    private function scan(string $root): array
    {
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator($directory, static function (\SplFileInfo $entry): bool {
            if ($entry->isDir() && !$entry->isLink()) {
                return !in_array($entry->getFilename(), ['vendor', 'node_modules', '.git', '.symsvel', '.svelte-kit', '.next', 'build', 'dist', '.cache'], true);
            }

            return true;
        });
        $files = [];
        foreach (new \RecursiveIteratorIterator($filtered) as $entry) {
            if (!$entry->isFile() && !$entry->isLink()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if ($root === $this->backend && in_array($relative, ['composer.json', 'composer.lock', 'symfony.lock'], true)) {
                continue;
            }
            $files[$relative] = $entry->getPathname();
        }

        return $files;
    }
}
