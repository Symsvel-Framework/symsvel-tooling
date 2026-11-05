<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

/** Opt-in POSIX shared-group access for CLI and PHP-FPM. */
final class SetupGroupAccess
{
    public function configure(string $workspace, string $group): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/D', $group)) {
            throw new \InvalidArgumentException('Groupe setup invalide.');
        }
        if (!function_exists('posix_getgrnam') || posix_getgrnam($group) === false) {
            throw new \RuntimeException('Groupe setup inexistant : '.$group);
        }
        $root = realpath($workspace);
        if ($root === false) {
            throw new \RuntimeException('Workspace setup introuvable.');
        }
        $state = $root.'/.symsvel';
        $backend = $root.'/backend';
        $token = $state.'/setup-token';
        foreach ([$state, $backend] as $directory) {
            if (is_link($directory) || !is_dir($directory)) {
                throw new \RuntimeException('Répertoire setup invalide : '.$directory);
            }
        }
        if (is_link($token) || !is_file($token)) {
            throw new \RuntimeException('Jeton setup invalide.');
        }
        $pending = $state.'/pending-admin.json';
        if (is_link($pending) || (file_exists($pending) && !is_file($pending))) {
            throw new \RuntimeException('Données administrateur temporaires invalides.');
        }
        foreach ([$state, $backend] as $directory) {
            if (!chgrp($directory, $group) || !chmod($directory, 02770)) {
                throw new \RuntimeException('Partage du répertoire setup impossible : '.$directory);
            }
        }
        if (!chgrp($token, $group) || !chmod($token, 0640)) {
            throw new \RuntimeException('Partage du jeton setup impossible.');
        }
        if (is_file($pending) && (!chgrp($pending, $group) || !chmod($pending, 0640))) {
            throw new \RuntimeException('Partage des données administrateur impossible.');
        }
    }
}
