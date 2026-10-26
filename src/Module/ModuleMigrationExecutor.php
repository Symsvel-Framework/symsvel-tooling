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
        try {
            foreach ($compiled as $migration) {
                $this->execute($backend, $migration['version'], 'up');
                $completed[] = $migration;
            }
            $next = $registered;
            foreach ($completed as $migration) {
                $next = array_merge($next, $migration['entries']);
            }
            $registry->write($workspace, $next);
        } catch (\Throwable $failure) {
            $errors = [];
            foreach (array_reverse($completed) as $migration) {
                try {
                    $this->execute($backend, $migration['version'], 'down');
                } catch (\Throwable $rollbackFailure) {
                    $errors[] = $migration['version'].': '.$rollbackFailure->getMessage();
                }
            }
            if ($errors !== []) {
                throw new \RuntimeException('Installation échouée : '.$failure->getMessage().'. Récupération manuelle requise, rollback incomplet : '.implode('; ', $errors), 0, $failure);
            }
            foreach ($compiled as $migration) {
                if (is_file($migration['path'])) {
                    unlink($migration['path']);
                }
            }
            throw new \RuntimeException('Installation annulée et migrations exécutées compensées : '.$failure->getMessage(), 0, $failure);
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
