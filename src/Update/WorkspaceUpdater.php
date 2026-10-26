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

    public function update(string $backendDir, string $environment): void
    {
        if (!in_array($environment, ['core', 'dev', 'prod'], true)) {
            throw new \InvalidArgumentException('Environnement non pris en charge : '.$environment);
        }
        if ($environment === 'prod') {
            throw new \RuntimeException('Mise à jour interdite en production.');
        }
        if (basename($backendDir) !== 'backend' || !is_file($backendDir.'/composer.json')) {
            throw new \RuntimeException('Le backend Symfony doit être dans backend/ avec composer.json.');
        }
        $root = dirname($backendDir);
        $this->run(['composer', 'update', 'symsvel-framework/core', 'symsvel-framework/tooling', '--with-all-dependencies', '--no-interaction'], $backendDir);
        foreach (['bo', 'app', 'front'] as $component) {
            $directory = $root.'/'.$component;
            if (!is_dir($directory)) {
                continue;
            }
            if (!is_dir($directory.'/.git')) {
                throw new \RuntimeException('Le composant '.$component.' n’est pas un dépôt Git.');
            }
            $this->run(['git', 'diff', '--quiet'], $directory);
            $this->run(['git', 'diff', '--cached', '--quiet'], $directory);
            $this->run(['git', 'fetch', 'origin', '--tags'], $directory);
            if ($environment === 'core') {
                $this->run(['git', 'merge', '--ff-only', 'origin/main'], $directory);
            } else {
                $this->run(['git', 'fetch', 'origin', '--tags'], $directory);
                $this->run(['git', 'merge', '--ff-only', 'origin/main'], $directory);
            }
            $this->run(['npm', 'ci'], $directory);
            $this->run(['npm', 'run', 'build'], $directory);
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
