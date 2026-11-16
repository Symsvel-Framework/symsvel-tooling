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

    /** @var array<string, true> Runtime directories moved into the checkpoint. */
    private array $moved = [];

    /** @var array<string, true> Active worktrees recreated from stable moved originals. */
    private array $workingCopies = [];

    public function __construct(
        private readonly string $component,
        private readonly string $transaction,
        private readonly array $artifacts = ['node_modules', 'build', 'dist', '.svelte-kit'],
        private readonly bool $moveOnCapture = false,
        private readonly bool $keepWorkingCopy = false,
    ) {
    }

    public function capture(): void
    {
        if ($this->moveOnCapture) {
            // A move is atomic only within one filesystem. Never accept PHP's
            // cross-device rename fallback, which could copy then delete data.
            $origin = @stat($this->component);
            $checkpoint = @stat($this->transaction);
            if ($origin === false || $checkpoint === false || $origin['dev'] !== $checkpoint['dev']) {
                throw new \RuntimeException('Snapshot runtime : déplacement impossible entre volumes.');
            }
            // Validate every root before moving the first one.
            foreach ($this->artifacts as $name) {
                if (is_link($this->component.'/'.$name)) {
                    throw new \RuntimeException('Snapshot runtime refusé : lien symbolique : '.$this->component.'/'.$name);
                }
            }
        }

        try {
            foreach ($this->artifacts as $name) {
                $source = $this->component.'/'.$name;
                if (is_link($source)) {
                    throw new \RuntimeException('Snapshot runtime refusé : lien symbolique : '.$source);
                }
                $exists = is_dir($source);
                $this->present[$name] = $exists;
                if (!$exists) {
                    continue;
                }
                $destination = $this->transaction.'/runtime/'.$name;
                if ($this->moveOnCapture) {
                    $checkpointRoot = dirname($destination);
                    if (is_link($checkpointRoot)
                        || (!is_dir($checkpointRoot) && !@mkdir($checkpointRoot, 0700, true) && !is_dir($checkpointRoot))) {
                        throw new \RuntimeException('Répertoire snapshot runtime non fiable : '.$checkpointRoot);
                    }
                    if (file_exists($destination) || is_link($destination) || !@rename($source, $destination)) {
                        throw new \RuntimeException('Déplacement snapshot runtime impossible : '.$name);
                    }
                    $this->moved[$name] = true;
                    // Hash the *moved original*, not an enormous mutable copy.
                    $this->fingerprints[$name] = $this->fingerprint($destination);
                    if ($this->keepWorkingCopy) {
                        // Composer's build hooks still require the original npm tree.
                        // Copy from the checkpoint, which stays stable during capture.
                        $this->workingCopies[$name] = true;
                        $this->copyTree($destination, $source);
                        if ($this->fingerprint($source) !== $this->fingerprints[$name]) {
                            throw new \RuntimeException('Snapshot runtime : copie de travail incohérente : '.$name);
                        }
                    }
                } else {
                    $this->copyTree($source, $destination);
                    $this->fingerprints[$name] = $this->fingerprint($source);
                    if ($this->fingerprint($destination) !== $this->fingerprints[$name]) {
                        throw new \RuntimeException('Snapshot runtime incohérent : '.$name);
                    }
                }
            }
        } catch (\Throwable $failure) {
            if ($this->moveOnCapture) {
                $errors = [];
                foreach (array_reverse(array_keys($this->moved)) as $name) {
                    $source = $this->component.'/'.$name;
                    $destination = $this->transaction.'/runtime/'.$name;
                    if (isset($this->workingCopies[$name])) {
                        try {
                            $this->removeTree($source);
                            unset($this->workingCopies[$name]);
                        } catch (\Throwable) {
                            $errors[] = $name;
                            continue;
                        }
                    }
                    if (file_exists($source) || is_link($source) || !is_dir($destination) || !@rename($destination, $source)) {
                        $errors[] = $name;
                    } else {
                        unset($this->moved[$name]);
                    }
                }
                if ($errors !== []) {
                    throw new \RuntimeException(
                        'Capture runtime interrompue ; dossiers non restaurés : '.implode(', ', $errors),
                        0,
                        $failure,
                    );
                }
            }
            throw $failure;
        }
    }

    /** A failed moved capture must keep its checkpoint if recovery was incomplete. */
    public function hasPendingMoves(): bool
    {
        return $this->moved !== [];
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
                if ($this->moveOnCapture) {
                    if (!@rename($this->transaction.'/runtime/'.$name, $destination)) {
                        throw new \RuntimeException('Rollback runtime impossible : déplacement refusé pour '.$name);
                    }
                    unset($this->moved[$name]);
                } else {
                    $this->copyTree($this->transaction.'/runtime/'.$name, $destination);
                }
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
        $entries = ['.' => 'dir:'.(fileperms($directory) & 0777)];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = substr($entry->getPathname(), strlen($directory) + 1);
            if ($entry->isLink()) {
                $entries[$path] = 'link:'.readlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                $entries[$path] = 'dir:'.($entry->getPerms() & 0777);
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
        if (!chmod($destination, fileperms($source) & 0777)) {
            throw new \RuntimeException('Permissions répertoire runtime impossibles : '.$destination);
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
