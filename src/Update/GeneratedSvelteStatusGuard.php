<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Allows only intact, registered, untracked module-generated files.
 * Tracked changes and unknown files always block a Git update.
 */
final class GeneratedSvelteStatusGuard
{
    public function assertSafe(string $backend, string $component, string $status): void
    {
        if ($status === '') {
            return;
        }
        $registry = $backend.'/.symsvel/svelte-files.json';
        if (is_link($registry) || !is_file($registry)) {
            throw new \RuntimeException('Modifications locales présentes dans '.basename($component).' : mise à jour refusée.');
        }
        $entries = json_decode((string) file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($entries)) {
            throw new \RuntimeException('Registre Svelte invalide.');
        }
        foreach (explode("\0", rtrim($status, "\0")) as $record) {
            if ($record === '') {
                continue;
            }
            if (!str_starts_with($record, '?? ')) {
                throw new \RuntimeException('Modifications locales présentes dans '.basename($component).' : mise à jour refusée.');
            }
            $relative = substr($record, 3);
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')
                || !str_starts_with($relative, 'src/')) {
                throw new \RuntimeException('Fichier non géré dans '.basename($component).' : '.$relative);
            }
            $path = $component.'/'.$relative;
            $hash = $entries[$path] ?? null;
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                || is_link($path) || !is_file($path) || hash_file('sha256', $path) !== $hash) {
                throw new \RuntimeException('Fichier local non géré ou modifié : '.$relative);
            }
        }
    }
}
