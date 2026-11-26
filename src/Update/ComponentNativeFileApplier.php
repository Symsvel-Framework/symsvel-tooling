<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Applies an already validated native three-way plan without touching project Git.
 *
 * The caller owns preflight, snapshots, commit and rollback. This deliberately
 * has no transaction lifecycle so global and single-component updates can
 * share the same file mutation rules.
 */
final class ComponentNativeFileApplier
{
    /**
     * @param array{add: list<string>, replace: list<string>, delete: list<string>} $plan
     * @param array<string, string> $baseline
     */
    public function apply(string $directory, string $upstream, array $plan, array $baseline): void
    {
        foreach (['add', 'replace'] as $action) {
            foreach ($plan[$action] as $path) {
                $source = $upstream.'/'.$path;
                $target = $directory.'/'.$path;
                $parent = dirname($target);
                if (!$this->safeParent($directory, $parent)) {
                    throw new \RuntimeException('Chemin local non fiable : '.$path);
                }
                if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                    throw new \RuntimeException('Création impossible : '.$path);
                }
                // Recheck the local target immediately before writing: project files
                // may change after the read-only three-way preflight.
                if ($action === 'add') {
                    if (file_exists($target) || is_link($target)) {
                        throw new \RuntimeException('Ajout natif refusé : fichier local apparu depuis le préflight : '.$path);
                    }
                } elseif (!isset($baseline[$path]) || !is_file($target) || is_link($target)
                    || hash_file('sha256', $target) !== $baseline[$path]) {
                    throw new \RuntimeException('Remplacement natif refusé : fichier local modifié depuis le préflight : '.$path);
                }
                // An upstream file can disappear after preflight. Report a controlled
                // failure rather than leaking a PHP warning into PHPUnit/CI.
                if (!is_file($source) || is_link($source)) {
                    throw new \RuntimeException('Source native absente ou non fiable : '.$path);
                }
                if (is_link($target) || is_dir($target) || !@copy($source, $target)) {
                    throw new \RuntimeException('Copie native impossible : '.$path);
                }
                $permissions = @fileperms($source);
                if ($permissions === false || !@chmod($target, $permissions & 0777)) {
                    throw new \RuntimeException('Permissions natives impossibles : '.$path);
                }
            }
        }
        foreach ($plan['delete'] as $path) {
            $target = $directory.'/'.$path;
            if (!$this->safeParent($directory, dirname($target)) || is_link($target) || !is_file($target)
                || hash_file('sha256', $target) !== $baseline[$path]) {
                throw new \RuntimeException('Fichier natif modifié ou chemin non fiable avant suppression : '.$path);
            }
            if (!unlink($target)) {
                throw new \RuntimeException('Suppression native impossible : '.$path);
            }
        }
    }

    private function safeParent(string $root, string $parent): bool
    {
        $root = str_replace(DIRECTORY_SEPARATOR, '/', $root);
        $parent = str_replace(DIRECTORY_SEPARATOR, '/', $parent);
        while ($parent !== $root) {
            if (is_link($parent) || is_file($parent) || !str_starts_with($parent, $root.'/')) {
                return false;
            }
            $parent = str_replace(DIRECTORY_SEPARATOR, '/', dirname($parent));
        }
        return true;
    }
}
