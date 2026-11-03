<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

use Symfony\Component\Process\Process;

final class ComponentInstaller
{
    private const REPOSITORIES = [
        'bo' => 'git@github.com:Symsvel-Framework/symsvel-bo.git',
        'app' => 'git@github.com:Symsvel-Framework/symsvel-app.git',
        'front' => 'git@github.com:Symsvel-Framework/symsvel-front.git',
    ];

    /** @param array<string, string>|null $repositories */
    public function __construct(private readonly ?array $repositories = null)
    {
    }

    /** @return list<string> */
    public function componentsForChoice(string $choice): array
    {
        return match ($choice) {
            '1' => ['bo'],
            '2' => ['bo', 'app'],
            '3' => ['bo', 'front'],
            '4' => ['bo', 'app', 'front'],
            default => throw new \InvalidArgumentException('Choix de composants invalide.'),
        };
    }

    /**
     * Installs selected components atomically. Existing paths are never modified.
     *
     * @param list<string> $components
     * @param null|callable(string):void $progress
     */
    public function install(string $projectDir, array $components, ?callable $progress = null): void
    {
        foreach ($components as $component) {
            if (!isset(($this->repositories ?? self::REPOSITORIES)[$component])) {
                throw new \InvalidArgumentException(sprintf('Composant inconnu : %s', $component));
            }
            $target = $projectDir.'/'.$component;
            if (file_exists($target) || is_link($target)) {
                throw new \RuntimeException(sprintf('Le répertoire %s existe déjà. Aucun fichier modifié.', $target));
            }
        }

        $created = [];
        try {
            foreach ($components as $component) {
                $target = $projectDir.'/'.$component;
                if ($progress !== null) {
                    $progress($component);
                }
                $created[] = $target;
                $process = new Process(['git', 'clone', '--depth', '1', self::REPOSITORIES[$component], $target]);
                $process->setTimeout(300);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Échec du clonage Git.');
                }
                foreach ([['npm', 'ci'], ['npm', 'run', 'build']] as $command) {
                    $step = new Process($command, $target);
                    $step->setTimeout(600);
                    $step->run();
                    if (!$step->isSuccessful()) {
                        throw new \RuntimeException(sprintf(
                            'Échec de %s dans %s : %s',
                            implode(' ', $command),
                            $component,
                            trim($step->getErrorOutput()) ?: trim($step->getOutput()),
                        ));
                    }
                }
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($created) as $path) {
                if (is_dir($path) && !is_link($path)) {
                    (new Process(['rm', '-rf', '--', $path]))->run();
                }
            }
            throw $exception;
        }
    }
}
