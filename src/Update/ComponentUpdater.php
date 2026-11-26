<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use SymSvel\Tooling\Process\NativeCommand;
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

    /** @param null|callable(string):void $progress */
    public function update(string $backend, string $component, ?callable $progress = null): void
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
                ->prepare($backend, $component, $upstream, ProjectChannel::read($backend));
            $revision = $prepared['revision'];
            $incoming = $prepared['incoming'];
            $plan = $prepared['plan'];
            $baselineFiles = $prepared['baseline'];
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
        // npm ci reconstructs these directories. Moving the originals into
        // the same-filesystem checkpoint avoids copying a changing npm tree.
        $runtime = new RuntimeArtifactSnapshot($directory, $transaction, moveOnCapture: true);
        try {
            $sources->capture();
            $runtime->capture();
        } catch (\Throwable $failure) {
            // A partially moved runtime must never be discarded if its return
            // to the workspace failed: keep the checkpoint for recovery.
            if ($runtime->hasPendingMoves()) {
                throw new \RuntimeException(
                    'Capture runtime interrompue, restauration incomplète ; sauvegarde conservée : '.$transaction,
                    0,
                    $failure,
                );
            }
            // No moved runtime remains; sources were read-only snapshots.
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
            $this->applier->apply($directory, $upstream, $plan, $baselineFiles);
            $this->run(['npm', 'ci'], $directory, $progress);
            if ($progress !== null) {
                $progress('['.$component.'] Synchronisation des modules'.PHP_EOL);
            }
            $this->synchronizer->sync($backend);
            $this->run(['npm', 'run', 'build'], $directory, $progress);
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
    private function run(array $command, string $cwd, ?callable $progress): void
    {
        if ($progress !== null) {
            $progress('['.basename($cwd).'] '.implode(' ', $command).PHP_EOL);
        }
        $process = new Process(NativeCommand::resolve($command), $cwd);
        $process->setTimeout(600);
        $process->mustRun($progress === null ? null : static function (string $type, string $buffer) use ($progress): void {
            $progress($buffer);
        });
        if ($progress !== null) {
            $progress('['.basename($cwd).'] '.implode(' ', $command).' : OK'.PHP_EOL);
        }
    }
}
