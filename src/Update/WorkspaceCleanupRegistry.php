<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Deferred cleanup for successfully committed workspace updates only.
 * Failed rollback checkpoints must never be queued here.
 */
final class WorkspaceCleanupRegistry
{
    public function __construct(private readonly string $backend)
    {
    }

    /** @param list<string> $paths */
    public function enqueue(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
        if ($paths === []) {
            return;
        }
        $this->withLock(function (array &$entries) use ($paths): void {
            foreach ($paths as $path) {
                $this->validatePath($path);
            }
            $entries[] = ['id' => bin2hex(random_bytes(12)), 'created_at' => gmdate('c'), 'paths' => $paths];
        });
    }

    /** @return array{removed: int, remaining: int, errors: list<string>} */
    public function cleanup(): array
    {
        $removed = 0;
        $errors = [];
        $remaining = $this->withLock(function (array &$entries) use (&$removed, &$errors): int {
            $pending = [];
            foreach ($entries as $entry) {
                $left = [];
                foreach ($entry['paths'] as $path) {
                    try {
                        $this->validatePath($path);
                        if (PHP_OS_FAMILY === 'Windows') {
                            \SymSvel\Tooling\Filesystem\NativeTreeRemoval::remove($path);
                        } else {
                            $this->remove($path);
                        }
                        ++$removed;
                    } catch (\Throwable $failure) {
                        $left[] = $path;
                        $errors[] = $path.' : '.$failure->getMessage();
                    }
                }
                if ($left !== []) {
                    $entry['paths'] = $left;
                    $pending[] = $entry;
                }
            }
            $entries = $pending;
            return count($pending);
        });
        return ['removed' => $removed, 'remaining' => $remaining, 'errors' => $errors];
    }

    /** @template T @param callable(array<int, array<string, mixed>>&):T $callback @return T */
    private function withLock(callable $callback): mixed
    {
        $directory = $this->backend.'/.symsvel';
        if (is_link($directory)) {
            throw new \RuntimeException('Lien symbolique interdit pour le registre cleanup.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Registre cleanup inaccessible : '.$directory);
        }
        if (is_link($directory.'/cleanup.lock') || is_link($directory.'/cleanup-pending.json')) {
            throw new \RuntimeException('Lien symbolique interdit pour les fichiers du registre cleanup.');
        }
        $lock = fopen($directory.'/cleanup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Verrou cleanup indisponible.');
        }
        try {
            $file = $directory.'/cleanup-pending.json';
            $entries = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
            if (!is_array($entries) || !array_is_list($entries)) {
                throw new \RuntimeException('Registre cleanup invalide.');
            }
            $result = $callback($entries);
            $temp = tempnam($directory, '.cleanup-');
            if ($temp === false) {
                throw new \RuntimeException('Impossible de préparer le registre cleanup.');
            }
            try {
                if (file_put_contents($temp, json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false
                    || (is_dir($file) && !is_link($file)) || !rename($temp, $file)) {
                    throw new \RuntimeException('Écriture atomique du registre cleanup impossible.');
                }
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function validatePath(string $path): void
    {
        $backend = realpath($this->backend);
        if ($backend === false) {
            throw new \RuntimeException('Backend introuvable.');
        }
        $base = $backend.'/.symsvel/transactions';
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $base = str_replace(DIRECTORY_SEPARATOR, '/', $base);
        if (!str_starts_with($path, $base.'/') || str_contains($path, '/../') || str_contains($path, '/./')) {
            throw new \RuntimeException('Chemin de nettoyage hors registre : '.$path);
        }
        $relative = substr($path, strlen($base) + 1);
        if ($relative === '' || str_contains($relative, '/') || !preg_match('/^(?:update-[a-f0-9]{24}|[a-f0-9]{32}|\.database-[A-Za-z0-9._-]+)$/D', $relative)) {
            throw new \RuntimeException('Cible de nettoyage non reconnue : '.$path);
        }
        if (is_link($path) || is_link($base) || is_link($backend.'/.symsvel')) {
            throw new \RuntimeException('Lien symbolique interdit dans le registre cleanup.');
        }
        // The transaction root must be a real directory, not a missing or
        // redirected path. Never recurse into an unverified cleanup root.
        if (!is_dir($base) || str_replace(DIRECTORY_SEPARATOR, '/', realpath($base) ?: '') !== $base) {
            throw new \RuntimeException('Répertoire de transactions invalide : '.$base);
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('Lien symbolique interdit.');
        }
        if (is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException('Suppression impossible.');
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $target = $entry->getPathname();
            if ($entry->isLink()) {
                if (!unlink($target)) {
                    throw new \RuntimeException('Suppression du lien impossible : '.$target);
                }
            } else {
                $this->remove($target);
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Suppression du répertoire impossible : '.$path);
        }
    }
}
