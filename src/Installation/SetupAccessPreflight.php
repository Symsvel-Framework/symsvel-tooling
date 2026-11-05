<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

/**
 * Checks the effective filesystem rights of the process running the wizard.
 * Does not attempt to relax permissions or disclose the setup credential.
 */
final class SetupAccessPreflight
{
    /** @return list<string> */
    public function check(string $workspace): array
    {
        $root = realpath($workspace);
        if ($root === false) {
            return ['Workspace introuvable.'];
        }

        $stateDirectory = $root.'/.symsvel';
        $backend = $root.'/backend';
        $errors = [];

        if (is_link($stateDirectory) || !is_dir($stateDirectory)) {
            $errors[] = 'Répertoire .symsvel absent ou symbolique.';
        } elseif (!is_readable($stateDirectory) || !is_executable($stateDirectory) || !is_writable($stateDirectory)) {
            $errors[] = 'Le processus PHP doit pouvoir lire, traverser et écrire dans .symsvel.';
        }

        foreach (['setup.json', 'setup-token'] as $filename) {
            $path = $stateDirectory.'/'.$filename;
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                $errors[] = $filename.' doit être un fichier ordinaire lisible par le processus PHP.';
            }
        }

        if (is_link($backend) || !is_dir($backend) || !is_writable($backend) || !is_executable($backend)) {
            $errors[] = 'Le processus PHP doit pouvoir écrire dans backend pour publier .env.local.';
        }

        $env = $backend.'/.env.local';
        if (is_link($env) || (file_exists($env) && (!is_file($env) || !is_readable($env)))) {
            $errors[] = '.env.local existant invalide, symbolique ou illisible.';
        }

        $pendingAdmin = $stateDirectory.'/pending-admin.json';
        if (is_link($pendingAdmin) || (file_exists($pendingAdmin) && (!is_file($pendingAdmin) || !is_readable($pendingAdmin)))) {
            $errors[] = 'pending-admin.json existant invalide, symbolique ou illisible.';
        }

        return $errors;
    }
}
