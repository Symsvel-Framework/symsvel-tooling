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

    /** @var array<string, string> */
    private array $fingerprints = [];

    public function __construct(private readonly string $backend, private readonly string $snapshot)
    {
    }

    public function capture(): void
    {
        $workspace = dirname($this->backend);
        foreach (['backend', 'bo', 'app', 'front'] as $component) {
            $root = $component === 'backend' ? $this->backend : $workspace.'/'.$component;
            if (is_link($root)) {
                throw new \RuntimeException('Snapshot refusé : composant symbolique non fiable : '.$root);
            }
            if (!is_dir($root)) {
                continue;
            }
            $this->roots[] = $root;
            foreach ($this->scan($root) as $relative => $path) {
                $key = $component.'/'.$relative;
                $target = $this->snapshot.'/workspace/'.$key;
                $parent = dirname($target);
                while ($parent !== $this->snapshot && $parent !== dirname($parent)) {
                    if (is_link($parent)) {
                        throw new \RuntimeException('Snapshot refusé : répertoire symbolique non fiable : '.$parent);
                    }
                    $parent = dirname($parent);
                }
                if (!is_dir(dirname($target))) {
                    // A regular file in the parent chain is an expected failure
                    // scenario in crash tests: never let mkdir emit a PHP warning.
                    $parent = dirname($target);
                    while (!file_exists($parent) && !is_link($parent) && $parent !== dirname($parent)) {
                        $parent = dirname($parent);
                    }
                    if (!is_dir($parent) || !@mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                        throw new \RuntimeException('Impossible de préparer le snapshot : '.$key);
                    }
                }
                if (is_link($path)) {
                    if (!symlink((string) readlink($path), $target)) {
                        throw new \RuntimeException('Sauvegarde du lien impossible : '.$key);
                    }
                } else {
                    if (!copy($path, $target)) {
                        throw new \RuntimeException('Sauvegarde impossible : '.$key);
                    }
                    if (!chmod($target, fileperms($path) & 0777)) {
                        throw new \RuntimeException('Permissions impossibles à sauvegarder : '.$key);
                    }
                }
                $fingerprint = $this->fingerprint($path);
                if ($this->fingerprint($target) !== $fingerprint) {
                    throw new \RuntimeException('Snapshot source incohérent : '.$key);
                }
                $this->fingerprints[$key] = $fingerprint;
                $this->files[$key] = $target;
            }
        }
    }

    public function restore(): void
    {
        // Reject redirected checkpoint directories before reading any saved file.
        $snapshotRoot = $this->snapshot.'/workspace';
        if (is_link($snapshotRoot) || ($this->files !== [] && !is_dir($snapshotRoot))) {
            throw new \RuntimeException('Rollback sources impossible : répertoire de sauvegarde absent ou symbolique.');
        }
        foreach ($this->files as $source) {
            $parent = dirname($source);
            while ($parent !== $this->snapshot && $parent !== dirname($parent)) {
                if (is_link($parent)) {
                    throw new \RuntimeException('Rollback sources impossible : répertoire de sauvegarde symbolique : '.$parent);
                }
                $parent = dirname($parent);
            }
        }
        // Validate every saved source before removing any current workspace file.
        foreach ($this->files as $key => $source) {
            if ((!is_file($source) && !is_link($source)) || $this->fingerprint($source) !== $this->fingerprints[$key]) {
                throw new \RuntimeException('Rollback sources impossible : sauvegarde altérée pour '.$key);
            }
        }
        $workspace = dirname($this->backend);
        foreach ($this->roots as $root) {
            if (is_link($root) || !is_dir($root)) {
                throw new \RuntimeException('Rollback bloqué : composant absent ou symbolique : '.$root);
            }
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
            // Never follow a directory symlink created by the failed operation.
            // Doing so could overwrite files outside the application workspace.
            $parent = dirname($target);
            while ($parent !== $root && $parent !== dirname($parent)) {
                if (is_link($parent)) {
                    throw new \RuntimeException('Rollback bloqué : répertoire symbolique non fiable : '.$parent);
                }
                $parent = dirname($parent);
            }
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
            } else {
                if (!copy($source, $target)) {
                    throw new \RuntimeException('Fichier impossible à restaurer : '.$key);
                }
                if (!chmod($target, fileperms($source) & 0777)) {
                    throw new \RuntimeException('Permissions impossibles à restaurer : '.$key);
                }
            }
        }
        $this->verifyRestoration();
    }

    /** Verify every restored source and reject files left behind by rollback. */
    private function verifyRestoration(): void
    {
        foreach ($this->roots as $root) {
            $component = $root === $this->backend ? 'backend' : basename($root);
            foreach ($this->scan($root) as $relative => $path) {
                $key = $component.'/'.$relative;
                if (!isset($this->fingerprints[$key]) || $this->fingerprint($path) !== $this->fingerprints[$key]) {
                    throw new \RuntimeException('Rollback sources non vérifié : '.$key);
                }
            }
        }
        foreach ($this->files as $key => $source) {
            [$component, $relative] = explode('/', $key, 2);
            $root = $component === 'backend' ? $this->backend : dirname($this->backend).'/'.$component;
            $target = $root.'/'.$relative;
            if ((!is_file($target) && !is_link($target)) || $this->fingerprint($target) !== $this->fingerprints[$key]) {
                throw new \RuntimeException('Rollback sources non vérifié : '.$key);
            }
        }
    }

    public function discard(): void
    {
        $root = $this->snapshot.'/workspace';
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                if (!rmdir($entry->getPathname())) {
                    throw new \RuntimeException('Nettoyage du snapshot impossible : '.$entry->getPathname());
                }
            } elseif (!unlink($entry->getPathname())) {
                throw new \RuntimeException('Nettoyage du snapshot impossible : '.$entry->getPathname());
            }
        }
        if (!rmdir($root)) {
            throw new \RuntimeException('Nettoyage du snapshot impossible : '.$root);
        }
    }

    private function fingerprint(string $path): string
    {
        if (is_link($path)) {
            return 'link:'.readlink($path);
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException('Empreinte source impossible : '.$path);
        }
        return 'file:'.$hash.':'.(fileperms($path) & 0777);
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
