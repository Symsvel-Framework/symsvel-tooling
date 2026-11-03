<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;
use SymSvel\Tooling\Module\SvelteModuleSynchronizer;
use SymSvel\Tooling\Update\WorkspaceCleanupRegistry;
use Symfony\Component\Process\Process;

/** Installs a new interface and its module files as a single operation. */
final class ComponentLifecycleInstaller
{
    public function __construct(
        private readonly ComponentInstaller $installer = new ComponentInstaller(),
        private readonly SvelteModuleSynchronizer $synchronizer = new SvelteModuleSynchronizer(),
    ) {
    }

    public function install(string $backend, string $component): void
    {
        if (!in_array($component, ['bo', 'app', 'front'], true)) {
            throw new \InvalidArgumentException('Composant inconnu : '.$component);
        }
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if (basename($backend) !== 'backend' || !is_file($backend.'/composer.json')) {
            throw new \RuntimeException('Arborescence backend invalide.');
        }
        $workspace = dirname($backend);
        $target = $workspace.'/'.$component;
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException('Composant déjà présent : '.$component);
        }
        $transaction = $backend.'/.symsvel/transactions/update-'.bin2hex(random_bytes(12));
        if (!is_dir(dirname($transaction)) && !mkdir(dirname($transaction), 0700, true) && !is_dir(dirname($transaction))) {
            throw new \RuntimeException('Répertoire de transactions indisponible.');
        }
        if (!mkdir($transaction, 0700)) {
            throw new \RuntimeException('Création du checkpoint impossible.');
        }
        $sources = new WorkspaceFileSnapshot($backend, $transaction);
        $sources->capture();
        try {
            $this->installer->install($workspace, [$component]);
            $this->synchronizer->sync($backend);
            $build = new Process(['npm', 'run', 'build'], $target);
            $build->setTimeout(600);
            $build->run();
            if (!$build->isSuccessful()) {
                throw new \RuntimeException('Build après synchronisation impossible : '.trim($build->getErrorOutput() ?: $build->getOutput()));
            }
        } catch (\Throwable $failure) {
            $errors = [];
            if (is_dir($target) && !is_link($target)) {
                $remove = new Process(['rm', '-rf', '--', $target]);
                $remove->run();
                if (!$remove->isSuccessful()) {
                    $errors[] = 'Suppression du composant : '.trim($remove->getErrorOutput());
                }
            }
            try {
                $sources->restore();
            } catch (\Throwable $error) {
                $errors[] = 'Restauration des sources : '.$error->getMessage();
            }
            if ($errors !== []) {
                throw new \RuntimeException('Installation échouée ; rollback incomplet : '.implode(' ; ', $errors).'; snapshot : '.$transaction, 0, $failure);
            }
            throw new \RuntimeException('Installation annulée, état précédent restauré : '.$failure->getMessage(), 0, $failure);
        }
        $cleanup = new WorkspaceCleanupRegistry($backend);
        $cleanup->enqueue([$transaction]);
        $cleanup->cleanup();
    }
}
