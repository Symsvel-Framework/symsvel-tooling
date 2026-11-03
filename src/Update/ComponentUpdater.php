<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;
use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;

/**
 * Transactional update of one already installed Svelte interface.
 * Never changes Composer dependencies or accesses the database.
 */
final class ComponentUpdater
{
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
        if (is_link($directory) || !is_dir($directory) || !is_dir($directory.'/.git')) {
            throw new \RuntimeException('Composant Git absent ou invalide : '.$component);
        }
        $status = trim($this->run(['git', 'status', '--porcelain', '--untracked-files=all'], $directory));
        if ($status !== '') {
            throw new \RuntimeException('Modifications locales présentes dans '.$component.' : mise à jour refusée.');
        }
        $checkpoint = new GitComponentCheckpoint($directory);
        $checkpoint->capture();
        $transaction = $backend.'/.symsvel/transactions/update-'.bin2hex(random_bytes(12));
        if (!is_dir(dirname($transaction)) && !mkdir(dirname($transaction), 0700, true) && !is_dir(dirname($transaction))) {
            throw new \RuntimeException('Répertoire de transactions indisponible.');
        }
        if (!mkdir($transaction, 0700)) {
            throw new \RuntimeException('Création du checkpoint impossible.');
        }
        $sources = new WorkspaceFileSnapshot($backend, $transaction);
        $runtime = new RuntimeArtifactSnapshot($directory, $transaction);
        // Snapshot failures occur before any mutation and must retain evidence.
        $sources->capture();
        $runtime->capture();
        try {
            $this->run(['git', 'fetch', 'origin', '--tags'], $directory);
            $branch = trim($this->run(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'], $directory));
            if ($branch === '') {
                throw new \RuntimeException('HEAD détachée : mise à jour automatique refusée.');
            }
            $this->run(['git', 'merge', '--ff-only', 'origin/'.$branch], $directory);
            $this->run(['npm', 'ci'], $directory);
            $this->run(['npm', 'run', 'build'], $directory);
        } catch (\Throwable $failure) {
            $errors = [];
            foreach ([
                'Git' => static fn () => $checkpoint->restore(),
                'sources' => static fn () => $sources->restore(),
                'npm/build' => static fn () => $runtime->restore(),
            ] as $name => $restore) {
                try {
                    $restore();
                } catch (\Throwable $error) {
                    $errors[] = $name.' : '.$error->getMessage();
                }
            }
            if ($errors !== []) {
                throw new \RuntimeException('Mise à jour échouée ; rollback incomplet : '.implode(' ; ', $errors).'; snapshot : '.$transaction, 0, $failure);
            }
            throw new \RuntimeException('Mise à jour annulée, état précédent restauré : '.$failure->getMessage(), 0, $failure);
        }
        // Clean up only after the update has committed successfully.
        (new WorkspaceCleanupRegistry($backend))->enqueue([$transaction]);
        (new WorkspaceCleanupRegistry($backend))->cleanup();
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(600);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(implode(' ', $command).' : '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
        return $process->getOutput();
    }
}
