<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;
use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;
use SymSvel\Tooling\Module\SvelteModuleSynchronizer;

/** Transactional native-file updates; project Git is never modified. */
final class ComponentUpdater
{
    public function __construct(
        private readonly SvelteModuleSynchronizer $synchronizer = new SvelteModuleSynchronizer(),
        private readonly ComponentNativeSource $native = new ComponentNativeSource(),
        private readonly ComponentUpdatePreflight $preflight = new ComponentUpdatePreflight(),
        private readonly LegacyComponentBaseline $legacy = new LegacyComponentBaseline(),
        private readonly ComponentNativeFileApplier $applier = new ComponentNativeFileApplier(),
    ) {
    }

    public function update(string $backend, string $component): void
    {
        if (!in_array($component, ['bo', 'app', 'front'], true)) {
            throw new \InvalidArgumentException('Composant inconnu : '.$component);
        }
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if (basename($backend) !== 'backend' || !is_file($backend.'/composer.json')) {
            throw new \RuntimeException('Arborescence backend invalide.');
        }
        $directory = dirname($backend).'/'.$component;
        if (is_link($directory) || !is_dir($directory)) {
            throw new \RuntimeException('Composant absent ou symbolique : '.$component);
        }
        $manifest = new ComponentManifest($backend);
        // Keep provenance validation before creating a checkpoint.
        $baseline = $manifest->get($component);
        if ($baseline === null) {
            $baseline = $this->legacy->recover($directory, $component);
        }

        $transactionRoot = $backend.'/.symsvel/transactions';
        // A checkpoint must never escape the backend through a redirected parent.
        foreach ([$backend.'/.symsvel', $transactionRoot] as $parent) {
            if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) {
                throw new \RuntimeException('Répertoire de transactions non fiable : '.$parent);
            }
        }
        $transaction = $transactionRoot.'/update-'.bin2hex(random_bytes(12));
        if (!is_dir(dirname($transaction)) && !mkdir(dirname($transaction), 0700, true) && !is_dir(dirname($transaction))) {
            throw new \RuntimeException('Répertoire de transactions indisponible.');
        }
        if (!mkdir($transaction, 0700)) {
            throw new \RuntimeException('Création du checkpoint impossible.');
        }
        try {
            $upstream = $transaction.'/upstream';
            $prepared = (new ComponentNativePreparation($this->native, $this->preflight, $this->legacy))
                ->prepare($backend, $component, $upstream);
            $revision = $prepared['revision'];
            $incoming = $prepared['incoming'];
            $plan = $prepared['plan'];
        } catch (\Throwable $failure) {
            // Preflight has not modified the workspace. Discard only its checkpoint.
            // Keep the original failure if cleanup succeeds; report both otherwise.
            try {
                $cleanup = new WorkspaceCleanupRegistry($backend);
                $cleanup->enqueue([$transaction]);
                $result = $cleanup->cleanup();
                if ($result['remaining'] > 0) {
                    throw new \RuntimeException(implode(' ; ', $result['errors']));
                }
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    'Préflight échoué ; nettoyage du checkpoint impossible : '.$cleanupFailure->getMessage().'; snapshot : '.$transaction,
                    0,
                    $failure,
                );
            }
            throw $failure;
        }

        $sources = new WorkspaceFileSnapshot($backend, $transaction);
        $runtime = new RuntimeArtifactSnapshot($directory, $transaction);
        try {
            $sources->capture();
            $runtime->capture();
        } catch (\Throwable $failure) {
            // Capturing checkpoints never changes project sources or runtime.
            // A partially written snapshot must not be treated as a valid rollback.
            try {
                $cleanup = new WorkspaceCleanupRegistry($backend);
                $cleanup->enqueue([$transaction]);
                $result = $cleanup->cleanup();
                if ($result['remaining'] > 0) {
                    throw new \RuntimeException(implode(' ; ', $result['errors']));
                }
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    'Snapshot échoué ; nettoyage du checkpoint impossible : '.$cleanupFailure->getMessage().'; snapshot : '.$transaction,
                    0,
                    $failure,
                );
            }
            throw $failure;
        }
        try {
            $this->applier->apply($directory, $upstream, $plan, $baseline['files']);
            $this->run(['npm', 'ci'], $directory);
            $this->synchronizer->sync($backend);
            $this->run(['npm', 'run', 'build'], $directory);
            $manifest->set($component, $revision, $incoming);
        } catch (\Throwable $failure) {
            $errors = [];
            foreach (['sources' => static fn () => $sources->restore(), 'npm/build' => static fn () => $runtime->restore()] as $name => $restore) {
                try {
                    $restore();
                } catch (\Throwable $error) {
                    $errors[] = $name.' : '.$error->getMessage();
                }
            }
            if ($errors !== []) {
                throw new \RuntimeException('Rollback incomplet : '.implode(' ; ', $errors).'; snapshot : '.$transaction, 0, $failure);
            }
            // A complete rollback no longer needs its checkpoint. An incomplete
            // rollback retains it above for manual recovery.
            try {
                $cleanup = new WorkspaceCleanupRegistry($backend);
                $cleanup->enqueue([$transaction]);
                $result = $cleanup->cleanup();
                if ($result['remaining'] > 0) {
                    throw new \RuntimeException(implode(' ; ', $result['errors']));
                }
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    'Rollback restauré mais nettoyage du checkpoint impossible : '.$cleanupFailure->getMessage().'; snapshot : '.$transaction,
                    0,
                    $failure,
                );
            }
            throw new \RuntimeException('Mise à jour annulée, état précédent restauré : '.$failure->getMessage(), 0, $failure);
        }
        $cleanup = new WorkspaceCleanupRegistry($backend);
        $cleanup->enqueue([$transaction]);
        $cleanup->cleanup();
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(600);
        $process->mustRun();
    }
}
