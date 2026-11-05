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
        if (!function_exists('posix_getgrnam') || !function_exists('posix_getgroups') || !function_exists('posix_getegid')) {
            throw new \RuntimeException('Extension POSIX requise pour le partage de groupe.');
        }
        $groupInfo = posix_getgrnam($group);
        if ($groupInfo === false) {
            throw new \RuntimeException('Groupe setup inexistant : '.$group);
        }
        if (!in_array($groupInfo['gid'], array_merge([posix_getegid()], posix_getgroups()), true)) {
            throw new \RuntimeException('Le processus CLI ne fait pas partie du groupe setup : '.$group);
        }
        $root = realpath($workspace);
        if ($root === false) {
            throw new \RuntimeException('Workspace setup introuvable.');
        }
        if (!is_executable($root)) {
            throw new \RuntimeException('Workspace non traversable par le processus CLI.');
        }
        // The web process must be able to traverse the workspace and its parents.
        // Do not chmod these shared paths implicitly.
        for ($parent = $root; ; $parent = dirname($parent)) {
            $stat = @stat($parent);
            if ($stat === false || (($stat['mode'] & 0001) === 0
                && !($stat['gid'] === $groupInfo['gid'] && ($stat['mode'] & 0010) !== 0))) {
                throw new \RuntimeException('Le groupe setup ne peut pas traverser '.$parent.'. Configurez les droits du parent ou une ACL avant installation.');
            }
            if ($parent === dirname($parent)) {
                break;
            }
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
        // A group-writable backend is not sufficient if its existing .env.local
        // belongs to another UID. Refuse an unsafe resume before changing modes.
        $env = $backend.'/.env.local';
        if (is_link($env) || (file_exists($env) && !is_file($env))) {
            throw new \RuntimeException('Configuration .env.local existante invalide.');
        }
        if (is_file($env) && !is_writable($env)) {
            throw new \RuntimeException('Configuration .env.local existante non modifiable par la CLI ; vérifiez son propriétaire.');
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
