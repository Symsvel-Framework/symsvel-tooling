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
        $transaction = $this->snapshots->begin($backend);
        try {
            $this->execute(array_merge(['composer'], $arguments), $backend, $progress);
            $this->snapshots->finish($backend, $transaction);
        } catch (\Throwable $failure) {
            try {
                $this->snapshots->restoreManifests($backend, $transaction);
                // The original lock must exist for deterministic vendor recovery.
                if (!is_file($backend.'/composer.lock')) {
                    throw new \RuntimeException('Aucun composer.lock initial : restauration vendor automatique impossible.');
                }
                $this->execute(['composer', 'install', '--no-scripts', '--no-plugins', '--no-interaction'], $backend, $progress);
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
