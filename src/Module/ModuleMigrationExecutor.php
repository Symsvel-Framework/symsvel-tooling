<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

use Symfony\Component\Process\Process;

/**
 * Runs Doctrine migrations in dependency order and compensates completed steps
 * in reverse order if a later step fails.
 */
final class ModuleMigrationExecutor
{
    /**
     * @param list<array{version: string, path: string, entries: array<string, array{sha256: string, doctrine: string}>}> $compiled
     * @param array<string, array{sha256: string, doctrine: string}> $registered
     */
    public function install(string $backend, string $workspace, array $compiled, array $registered, ModuleMigrationRegistry $registry): void
    {
        $completed = [];
        $inFlight = null;
        $journal = rtrim($workspace, '/').'/.symsvel/migration-recovery.json';
        if (is_file($journal) || is_link($journal)) {
            throw new \RuntimeException('Récupération de migration en attente : '.$journal);
        }
        $directory = dirname($journal);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire de récupération inaccessible.');
        }
        $this->journal($journal, 'running', $compiled, $completed, null);
        try {
            foreach ($compiled as $migration) {
                $inFlight = $migration['version'];
                $this->journal($journal, 'running', $compiled, $completed, $inFlight);
                $this->execute($backend, $migration['version'], 'up');
                $completed[] = $migration;
                $inFlight = null;
                $this->journal($journal, 'running', $compiled, $completed, null);
            }
            $next = $registered;
            foreach ($completed as $migration) {
                $next = array_merge($next, $migration['entries']);
            }
            $registry->write($workspace, $next);
            unlink($journal);
        } catch (\Throwable $failure) {
            $this->journal($journal, 'recovering', $compiled, $completed, $inFlight);
            $errors = [];
            foreach (array_reverse($completed) as $migration) {
                try {
                    $this->execute($backend, $migration['version'], 'down');
                } catch (\Throwable $rollbackFailure) {
                    $errors[] = $migration['version'].': '.$rollbackFailure->getMessage();
                }
            }
            if ($inFlight !== null) {
                $errors[] = 'Migration interrompue '.$inFlight.' : état SQL à vérifier (DDL potentiellement non transactionnel).';
            }
            if ($errors !== []) {
                throw new \RuntimeException('Installation échouée : '.$failure->getMessage().'. Récupération manuelle requise, rollback incomplet : '.implode('; ', $errors), 0, $failure);
            }
            foreach ($compiled as $migration) {
                if (is_file($migration['path'])) {
                    unlink($migration['path']);
                }
            }
            unlink($journal);
            throw new \RuntimeException('Installation annulée et migrations exécutées compensées : '.$failure->getMessage(), 0, $failure);
        }
    }

    /**
     * @param list<array{version: string, path: string, entries: array<string, array{sha256: string, doctrine: string}>}> $compiled
     * @param list<array{version: string, path: string, entries: array<string, array{sha256: string, doctrine: string}>}> $completed
     */
    private function journal(string $path, string $state, array $compiled, array $completed, ?string $inFlight): void
    {
        $payload = json_encode([
            'schema' => 1,
            'state' => $state,
            'planned' => array_column($compiled, 'version'),
            'completed' => array_column($completed, 'version'),
            'in_flight' => $inFlight,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = tempnam(dirname($path), '.recovery-');
        if ($temporary === false) {
            throw new \RuntimeException('Impossible de créer le journal de récupération.');
        }
        try {
            if (file_put_contents($temporary, $payload."\n") === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Impossible de publier le journal de récupération.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function execute(string $backend, string $version, string $direction): void
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new \InvalidArgumentException('Direction Doctrine invalide.');
        }
        $process = new Process(
            [PHP_BINARY, 'bin/console', 'doctrine:migrations:execute', $version, '--'.$direction, '--no-interaction'],
            $backend,
        );
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Doctrine '.$direction.' '.$version.' : '.trim($process->getErrorOutput().' '.$process->getOutput()));
        }
    }
}
