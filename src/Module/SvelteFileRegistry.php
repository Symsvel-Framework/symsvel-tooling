<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Reads Svelte ownership hashes independently from the checkout location.
 *
 * New records are relative to the project workspace, e.g.:
 * bo/src/lib/modules/example/index.ts.
 * Absolute-path records from existing projects are accepted only for a
 * hash-verified migration by SvelteModuleSynchronizer.
 */
final class SvelteFileRegistry
{
    /** @return array<string, string> */
    public function read(string $backend): array
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        $registry = $backend.'/.symsvel/svelte-files.json';
        if (is_link($registry)) {
            throw new \RuntimeException('Registre Svelte symbolique interdit.');
        }
        if (!is_file($registry)) {
            return [];
        }
        $entries = json_decode((string) file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($entries) || ($entries !== [] && array_is_list($entries))) {
            throw new \RuntimeException('Registre Svelte invalide.');
        }

        $workspace = dirname($backend);
        $normalized = [];
        $legacyRoot = null;
        foreach ($entries as $key => $hash) {
            if (!is_string($key) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \RuntimeException('Entrée de registre Svelte invalide.');
            }
            $relative = $key;
            if (str_starts_with($key, '/')) {
                if (str_starts_with($key, $workspace.'/')) {
                    $relative = substr($key, strlen($workspace) + 1);
                } else {
                    // Legacy absolute paths from another checkout. Never open
                    // the original path; rebase only a recognized managed suffix.
                    if (!preg_match('~^(.+)/(bo|app|front)/src/(lib|routes)/(.+)$~D', $key, $matches)) {
                        throw new \RuntimeException('Entrée de registre Svelte invalide.');
                    }
                    $root = $matches[1];
                    if (str_contains($root, '\\') || str_contains($root, "\0")
                        || preg_match('~(?:^|/)\.\.?(?:/|$)~', $root)
                        || ($legacyRoot !== null && $legacyRoot !== $root)) {
                        throw new \RuntimeException('Origine de registre Svelte incohérente.');
                    }
                    $legacyRoot = $root;
                    $relative = $matches[2].'/src/'.$matches[3].'/'.$matches[4];
                }
            }
            if (!self::isManagedRelativePath($relative) || isset($normalized[$relative])) {
                throw new \RuntimeException('Entrée de registre Svelte invalide.');
            }
            $normalized[$relative] = $hash;
        }

        return $normalized;
    }

    public static function isManagedRelativePath(string $relative): bool
    {
        if (str_contains($relative, '\\') || str_contains($relative, "\0")
            || !preg_match('~^(?:bo|app|front)/src/(?:lib|routes)/.+$~D', $relative)) {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }
}
