<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Composer;

use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\RuntimeArtifactSnapshot;
use SymSvel\Tooling\Update\WorkspaceDatabaseBackup;
use SymSvel\Tooling\Update\WorkspaceCleanupRegistry;

/**
 * Opt-in Composer transaction runner.
 *
 * A subprocess can be recovered after it exits; native composer require/update/remove
 * cannot be made transactional by a post-update script.
 */
final class ComposerTransactionRunner
{
    public function __construct(
        private readonly ComposerTransactionSnapshot $snapshots = new ComposerTransactionSnapshot(),
        /** @var null|callable(list<string>, string, ?callable):void */
        private readonly mixed $commandRunner = null,
    ) {
    }

    /**
     * @param list<string> $arguments Composer arguments, starting with require/update/remove
     * @param null|callable(string):void $progress
     */
    public function run(string $backend, array $arguments, ?callable $progress = null): void
    {
        if (!in_array($arguments[0] ?? '', ['require', 'update', 'remove'], true)) {
            throw new \InvalidArgumentException('Opération Composer non prise en charge.');
        }
        foreach ($arguments as $argument) {
            if (in_array($argument, ['--no-scripts', '--no-plugins', '--working-dir', '-d'], true)
                || str_starts_with($argument, '--working-dir=')) {
                throw new \InvalidArgumentException('Option Composer incompatible avec la transaction : '.$argument);
            }
        }
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if (!is_file($backend.'/composer.lock')) {
            throw new \RuntimeException('composer.lock initial obligatoire pour garantir la restauration des dépendances.');
        }
        $databaseUrl = $this->databaseUrl($backend);
        $databaseBackup = new WorkspaceDatabaseBackup();
        $databaseCheckpoint = null;
        $initialFiles = $this->workspaceFiles($backend);
        $transaction = $this->snapshots->begin($backend);
        $workspaceSnapshot = new WorkspaceFileSnapshot($backend, $transaction);
        $vendorSnapshot = new RuntimeArtifactSnapshot($backend, $transaction.'/backend', ['vendor']);
        /** @var list<RuntimeArtifactSnapshot> $runtimeSnapshots */
        $runtimeSnapshots = [];
        try {
            if ($databaseUrl !== null) {
                $databaseCheckpoint = $databaseBackup->capture($backend, $databaseUrl);
            }
            $workspaceSnapshot->capture();
            $vendorSnapshot->capture();
            foreach (['bo', 'app', 'front'] as $component) {
                $directory = dirname($backend).'/'.$component;
                if (!is_dir($directory)) {
                    continue;
                }
                $runtime = new RuntimeArtifactSnapshot($directory, $transaction.'/'.$component);
                $runtime->capture();
                $runtimeSnapshots[$component] = $runtime;
            }
        } catch (\Throwable $failure) {
            // No Composer command has started. Keep the snapshot for diagnosis;
            // do not report a successful transaction or attempt a partial restore.
            throw new \RuntimeException(
                'BACK / Snapshot initial incomplet : opération Composer annulée avant exécution ; snapshot conservé : '.$transaction,
                0,
                $failure,
            );
        }
        try {
            $this->execute(array_merge(['composer'], $arguments, []), $backend, $progress);
        } catch (\Throwable $failure) {
            $recoveryErrors = [];
            if ($databaseCheckpoint !== null) {
                try {
                    $databaseBackup->restore($databaseUrl, $databaseCheckpoint);
                } catch (\Throwable $recovery) {
                    $recoveryErrors[] = 'BDD / PostgreSQL : '.$recovery->getMessage();
                }
            }
            // Every recovery step is independent: a failed manifest restore
            // must not prevent vendor, sources or frontend artifacts recovery.
            try {
                $this->snapshots->restoreManifests($backend, $transaction);
            } catch (\Throwable $recovery) {
                $recoveryErrors[] = 'BACK / manifests : '.$recovery->getMessage();
            }
            try {
                $vendorSnapshot->restore();
            } catch (\Throwable $recovery) {
                $recoveryErrors[] = 'BACK / vendor : '.$recovery->getMessage();
            }
            try {
                $workspaceSnapshot->restore();
            } catch (\Throwable $recovery) {
                $recoveryErrors[] = 'Workspace / sources : '.$recovery->getMessage();
            }
            foreach ($runtimeSnapshots as $component => $runtime) {
                try {
                    $runtime->restore();
                } catch (\Throwable $recovery) {
                    $recoveryErrors[] = strtoupper($component).' / npm-build : '.$recovery->getMessage();
                }
            }
            try {
                if (!is_file($backend.'/composer.lock')) {
                    throw new \RuntimeException('composer.lock initial absent après restauration.');
                }
                $restoredFiles = $this->workspaceFiles($backend);
                $changedFiles = array_keys(array_diff_assoc($restoredFiles, $initialFiles));
                $changedFiles = array_unique(array_merge($changedFiles, array_keys(array_diff_key($initialFiles, $restoredFiles))));
                if ($changedFiles !== []) {
                    throw new \RuntimeException('Fichiers hors Composer modifiés : '.implode(', ', array_slice($changedFiles, 0, 10)));
                }
            } catch (\Throwable $verification) {
                $recoveryErrors[] = 'Vérification : '.$verification->getMessage();
            }
            if ($recoveryErrors !== []) {
                throw new \RuntimeException(
                    'Échec Composer : '.$failure->getMessage().'; récupération incomplète : '.implode(' ; ', $recoveryErrors).
                    '; snapshot conservé : '.$transaction,
                    0,
                    $failure,
                );
            }
            if ($databaseCheckpoint !== null) {
                $databaseBackup->discard($databaseCheckpoint);
            }
            // Recovery has been verified. Cleanup is separate housekeeping.
            try {
                $registry = new WorkspaceCleanupRegistry($backend);
                $registry->enqueue([$transaction]);
                $cleanup = $registry->cleanup();
                if ($cleanup['errors'] !== [] && $progress !== null) {
                    $progress('AVERTISSEMENT : rollback Composer restauré, nettoyage différé (symsvel:cleanup) : '.implode(' ; ', $cleanup['errors']));
                }
            } catch (\Throwable $cleanupFailure) {
                if ($progress !== null) {
                    $progress('AVERTISSEMENT : rollback Composer restauré, nettoyage non enregistré : '.$cleanupFailure->getMessage().'; checkpoint conservé si présent : '.$transaction);
                }
            }
            throw new \RuntimeException('Opération Composer annulée et dépendances restaurées : '.$failure->getMessage(), 0, $failure);
        }
        if ($databaseCheckpoint !== null) {
            $databaseBackup->discard($databaseCheckpoint);
        }
        // Composer has succeeded. Register the checkpoint before deleting anything:
        // an interrupted cleanup can be retried without turning success into failure.
        try {
            $registry = new WorkspaceCleanupRegistry($backend);
            $registry->enqueue([$transaction]);
            $result = $registry->cleanup();
            if ($result['errors'] !== [] && $progress !== null) {
                $progress('AVERTISSEMENT : opération Composer réussie, nettoyage différé (symsvel:cleanup) : '.implode(' ; ', $result['errors']));
            }
        } catch (\Throwable $cleanupFailure) {
            if ($progress !== null) {
                $progress('AVERTISSEMENT : opération Composer réussie, nettoyage non enregistré ou interrompu : '.$cleanupFailure->getMessage().'; checkpoint conservé si présent : '.$transaction);
            }
        }
    }

    /**
     * Only enable database checkpoints when a concrete local DATABASE_URL is set.
     * Never attempt a destructive restore against a default/example .env.
     */
    private function databaseUrl(string $backend): ?string
    {
        $file = $backend.'/.env.local';
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $contents = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contents === false) {
            throw new \RuntimeException('Lecture de .env.local impossible.');
        }
        foreach ($contents as $line) {
            if (preg_match('/^\\s*DATABASE_URL\\s*=\\s*(.*?)\\s*$/', $line, $matches)) {
                $value = trim($matches[1], " \\t\\n\\r\\0\\x0B\\\"'");
                if ($value === '') {
                    throw new \RuntimeException('DATABASE_URL vide dans .env.local.');
                }
                return $value;
            }
        }
        return null;
    }

    /**
     * Fingerprint application source in all workspace components. This detects
     * unrolled-back changes without falsely claiming they have been restored.
     *
     * @return array<string, string>
     */
    private function workspaceFiles(string $backend): array
    {
        $files = [];
        foreach (['backend' => $backend, 'bo' => dirname($backend).'/bo', 'app' => dirname($backend).'/app', 'front' => dirname($backend).'/front'] as $component => $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach ($this->projectFiles($directory) as $path => $fingerprint) {
                $files[$component.'/'.$path] = $fingerprint;
            }
        }

        return $files;
    }

    /** @return array<string, string> */
    private function projectFiles(string $backend): array
    {
        $files = [];
        // Prune dependency and transaction directories before traversal, not after.
        // A post-filter still walks every file in vendor/, which can be enormous.
        $directory = new \RecursiveDirectoryIterator($backend, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $entry): bool {
                if ($entry->isDir() && !$entry->isLink()) {
                    return !in_array($entry->getFilename(), ['vendor', 'node_modules', '.git', '.svelte-kit', '.next', 'build', 'dist', '.cache'], true)
                        && !($entry->getFilename() === 'transactions' && basename(dirname($entry->getPathname())) === '.symsvel');
                }

                return true;
            },
        );
        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (!$file->isFile() && !$file->isLink()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($backend) + 1);
            if (preg_match('~^(?:vendor|node_modules|\\.git|\\.symsvel/transactions)/~', $relative)
                || in_array($relative, ['composer.json', 'composer.lock', 'symfony.lock', '.symsvel/cleanup-pending.json'], true)) {
                continue;
            }
            $files[$relative] = $file->isLink()
                ? 'symlink:'.readlink($file->getPathname())
                : 'file:'.hash_file('sha256', $file->getPathname()).':mode:'.decoct(fileperms($file->getPathname()) & 0777);
        }
        return $files;
    }

    /** @param list<string> $command */
    private function execute(array $command, string $backend, ?callable $progress): void
    {
        if ($this->commandRunner !== null) {
            ($this->commandRunner)($command, $backend, $progress);
            return;
        }
        $process = new Process($command, $backend);
        $process->setTimeout(900);
        $process->run(static function (string $type, string $output) use ($progress): void {
            if ($progress !== null) {
                $progress($output);
            }
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Commande échouée (code '.$process->getExitCode().') : '.implode(' ', $command));
        }
    }
}
