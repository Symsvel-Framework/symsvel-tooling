<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Composer;

use Symfony\Component\Process\Process;

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
        $initialFiles = $this->workspaceFiles($backend);
        $transaction = $this->snapshots->begin($backend);
        $workspaceSnapshot = new WorkspaceFileSnapshot($backend, $transaction);
        try {
            $workspaceSnapshot->capture();
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
            $this->execute(array_merge(['composer'], $arguments), $backend, $progress);
        } catch (\Throwable $failure) {
            try {
                $this->snapshots->restoreManifests($backend, $transaction);
                // The original lock must exist for deterministic vendor recovery.
                if (!is_file($backend.'/composer.lock')) {
                    throw new \RuntimeException('Aucun composer.lock initial : restauration vendor automatique impossible.');
                }
                $this->execute(['composer', 'install', '--no-scripts', '--no-plugins', '--no-interaction'], $backend, $progress);
                $workspaceSnapshot->restore();
                $restoredFiles = $this->workspaceFiles($backend);
                $changedFiles = array_keys(array_diff_assoc($restoredFiles, $initialFiles));
                $changedFiles = array_unique(array_merge($changedFiles, array_keys(array_diff_key($initialFiles, $restoredFiles))));
                if ($changedFiles !== []) {
                    throw new \RuntimeException('Fichiers hors Composer modifiés : '.implode(', ', array_slice($changedFiles, 0, 10)));
                }
                $workspaceSnapshot->discard();
                $this->snapshots->finish($backend, $transaction);
            } catch (\Throwable $recovery) {
                throw new \RuntimeException(
                    'Échec Composer : '.$failure->getMessage().'; récupération incomplète : '.$recovery->getMessage().
                    '; snapshot conservé : '.$transaction,
                    0,
                    $failure,
                );
            }
            throw new \RuntimeException('Opération Composer annulée et dépendances restaurées : '.$failure->getMessage(), 0, $failure);
        }
        // Cleanup failure after a successful Composer command must never trigger a rollback.
        $workspaceSnapshot->discard();
        $this->snapshots->finish($backend, $transaction);
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
                    return !in_array($entry->getFilename(), ['vendor', 'node_modules', '.git', '.symsvel', '.svelte-kit', '.next', 'build', 'dist', '.cache'], true);
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
            if (preg_match('~^(?:vendor|node_modules|\\.git|\\.symsvel)/~', $relative)
                || in_array($relative, ['composer.json', 'composer.lock', 'symfony.lock'], true)) {
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
