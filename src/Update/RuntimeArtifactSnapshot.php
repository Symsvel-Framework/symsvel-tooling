<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Transaction checkpoint for npm dependencies and generated Svelte outputs.
 * Kept separate from source snapshots because node_modules and build are excluded there.
 */
final class RuntimeArtifactSnapshot
{
    /** @var array<string, bool> */
    private array $present = [];

    /** @var array<string, string> */
    private array $fingerprints = [];

    public function __construct(
        private readonly string $component,
        private readonly string $transaction,
        private readonly array $artifacts = ['node_modules', 'build', 'dist', '.svelte-kit'],
    ) {
    }

    public function capture(): void
    {
        foreach ($this->artifacts as $name) {
            $source = $this->component.'/'.$name;
            if (is_link($source)) {
                throw new \RuntimeException('Snapshot runtime refusé : lien symbolique : '.$source);
            }
            $exists = is_dir($source);
            $this->present[$name] = $exists;
            if ($exists) {
                $this->copyTree($source, $this->transaction.'/runtime/'.$name);
                $this->fingerprints[$name] = $this->fingerprint($source);
                if ($this->fingerprint($this->transaction.'/runtime/'.$name) !== $this->fingerprints[$name]) {
                    throw new \RuntimeException('Snapshot runtime incohérent : '.$name);
                }
            }
        }
    }

    public function restore(): void
    {
        foreach ($this->present as $name => $exists) {
            if ($exists && !is_dir($this->transaction.'/runtime/'.$name)) {
                throw new \RuntimeException('Rollback runtime impossible : sauvegarde absente pour '.$name);
            }
            if ($exists && $this->fingerprint($this->transaction.'/runtime/'.$name) !== $this->fingerprints[$name]) {
                throw new \RuntimeException('Rollback runtime impossible : sauvegarde altérée pour '.$name);
            }
        }
        foreach ($this->present as $name => $exists) {
            $destination = $this->component.'/'.$name;
            $this->removeTree($destination);
            if ($exists) {
                $this->copyTree($this->transaction.'/runtime/'.$name, $destination);
                if ($this->fingerprint($destination) !== $this->fingerprints[$name]) {
                    throw new \RuntimeException('Rollback runtime incomplet : intégrité non vérifiée pour '.$name);
                }
            }
        }
    }

    public function discard(): void
    {
        $this->removeTree($this->transaction.'/runtime');
        if (is_dir($this->transaction) && !rmdir($this->transaction)) {
            throw new \RuntimeException('Nettoyage du snapshot runtime impossible : '.$this->transaction);
        }
    }

    private function fingerprint(string $directory): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = substr($entry->getPathname(), strlen($directory) + 1);
            if ($entry->isLink()) {
                $entries[$path] = 'link:'.readlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                $entries[$path] = 'dir';
            } else {
                $hash = hash_file('sha256', $entry->getPathname());
                if ($hash === false) {
                    throw new \RuntimeException('Empreinte runtime impossible : '.$path);
                }
                $entries[$path] = 'file:'.$hash.':'.($entry->getPerms() & 0777);
            }
        }
        ksort($entries);
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }

    private function copyTree(string $source, string $destination): void
    {
        if (is_link($destination)) {
            throw new \RuntimeException('Destination runtime symbolique non fiable : '.$destination);
        }
        if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new \RuntimeException('Création snapshot runtime impossible : '.$destination);
        }
        foreach (new \FilesystemIterator($source) as $entry) {
            $target = $destination.'/'.$entry->getFilename();
            if ($entry->isLink()) {
                if (!symlink((string) readlink($entry->getPathname()), $target)) {
                    throw new \RuntimeException('Copie lien runtime impossible : '.$target);
                }
            } elseif ($entry->isDir()) {
                $this->copyTree($entry->getPathname(), $target);
            } elseif (!copy($entry->getPathname(), $target) || !chmod($target, $entry->getPerms() & 0777)) {
                throw new \RuntimeException('Copie fichier runtime impossible : '.$target);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException('Suppression runtime impossible : '.$path);
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->removeTree($entry->getPathname());
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Suppression runtime impossible : '.$path);
        }
    }
}
