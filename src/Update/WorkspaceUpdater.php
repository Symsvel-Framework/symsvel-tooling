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
        if (basename($backendDir) !== 'backend' || !is_file($backendDir.'/composer.json')) {
            throw new \RuntimeException('Le backend Symfony doit être dans backend/ avec composer.json.');
        }
        if ($environment === 'dev') {
            $manifest = json_decode((string) file_get_contents($backendDir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach (['symsvel-framework/core', 'symsvel-framework/tooling'] as $package) {
                $constraint = $manifest['require'][$package] ?? null;
                if (!is_string($constraint) || str_contains($constraint, 'dev-') || str_contains($constraint, '@dev')) {
                    throw new \RuntimeException('Mode dev : '.$package.' doit utiliser une contrainte de release stable dans composer.json (actuellement '.($constraint ?? 'absente').').');
                }
            }
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
                $progress(strtoupper($component).($environment === 'core' ? ' : synchronisation main…' : ' : sélection de la dernière release…'));
            }
            if ($environment === 'core') {
                $this->run(['git', 'merge', '--ff-only', 'origin/main'], $directory);
            } else {
                $tag = $this->latestReleaseTag($directory);
                $this->run(['git', 'checkout', '--detach', $tag], $directory);
                if ($progress !== null) {
                    $progress(strtoupper($component).' : release '.$tag.' sélectionnée.');
                }
            }
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

    private function latestReleaseTag(string $directory): string
    {
        $tags = $this->output(['git', 'tag', '--list', 'v[0-9]*'], $directory);
        $versions = array_filter(
            preg_split('/\\R/', trim($tags)) ?: [],
            static fn (string $tag): bool => preg_match('/^v\\d+\\.\\d+\\.\\d+$/', $tag) === 1,
        );
        if ($versions === []) {
            throw new \RuntimeException('Aucune release stable (tag vX.Y.Z) disponible dans '.$directory);
        }
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
        return $versions[0];
    }

    /** @param list<string> $command */
    private function output(array $command, string $cwd): string
    {
        if ($this->runner !== null) {
            $result = ($this->runner)($command, $cwd);
            return is_string($result) ? $result : '';
        }
        $process = new Process($command, $cwd);
        $process->mustRun();
        return $process->getOutput();
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
