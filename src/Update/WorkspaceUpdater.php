<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;

final class WorkspaceUpdater
{
    /** @param null|callable(list<string>, string):void $runner */
    public function __construct(private readonly mixed $runner = null)
    {
    }

    /** @param null|callable(string):void $progress */
    public function update(string $backendDir, string $environment, ?callable $progress = null): void
    {
        if (!in_array($environment, ['core', 'dev', 'prod'], true)) {
            throw new \InvalidArgumentException('Environnement non pris en charge : '.$environment);
        }
        if ($environment === 'prod') {
            throw new \RuntimeException('Mise à jour interdite en production.');
        }
        if ($environment === 'dev') {
            throw new \RuntimeException('Mode dev : mise à jour par releases non encore implémentée.');
        }
        if (basename($backendDir) !== 'backend' || !is_file($backendDir.'/composer.json')) {
            throw new \RuntimeException('Le backend Symfony doit être dans backend/ avec composer.json.');
        }
        $root = dirname($backendDir);
        if ($progress !== null) {
            $progress('Backend : mise à jour Composer (Core + Tooling)…');
        }
        $this->run(['composer', 'update', 'symsvel-framework/core', 'symsvel-framework/tooling', '--with-all-dependencies', '--no-interaction'], $backendDir);
        foreach (['bo', 'app', 'front'] as $component) {
            $directory = $root.'/'.$component;
            if (!is_dir($directory)) {
                if ($progress !== null) {
                    $progress(strtoupper($component).' : absent, ignoré.');
                }
                continue;
            }
            if (!is_dir($directory.'/.git')) {
                throw new \RuntimeException('Le composant '.$component.' n’est pas un dépôt Git.');
            }
            if ($progress !== null) {
                $progress(strtoupper($component).' : vérification Git…');
            }
            $this->run(['git', 'diff', '--quiet'], $directory);
            $this->run(['git', 'diff', '--cached', '--quiet'], $directory);
            if ($progress !== null) {
                $progress(strtoupper($component).' : récupération des commits…');
            }
            $this->run(['git', 'fetch', 'origin', '--tags'], $directory);
            if ($progress !== null) {
                $progress(strtoupper($component).' : synchronisation main…');
            }
            $this->run(['git', 'merge', '--ff-only', 'origin/main'], $directory);
            if ($progress !== null) {
                $progress(strtoupper($component).' : installation npm…');
            }
            $this->run(['npm', 'ci'], $directory);
            if ($progress !== null) {
                $progress(strtoupper($component).' : build…');
            }
            $this->run(['npm', 'run', 'build'], $directory);
            if ($progress !== null) {
                $progress(strtoupper($component).' : terminé.');
            }
        }
        if ($progress !== null) {
            $progress('Tous les composants présents ont été traités.');
        }
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): void
    {
        if ($this->runner !== null) {
            ($this->runner)($command, $cwd);
            return;
        }
        $process = new Process($command, $cwd);
        $process->setTimeout(900);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('%s : %s', implode(' ', $command), trim($process->getErrorOutput()) ?: trim($process->getOutput())));
        }
    }
}
