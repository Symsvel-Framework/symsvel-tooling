<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

use SymSvel\Tooling\Process\NativeCommand;
use SymSvel\Tooling\Filesystem\NativeTreeRemoval;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\ComponentNativeSource;
use SymSvel\Tooling\Update\ProjectChannel;

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
    public function install(string $projectDir, array $components, ?callable $progress = null, string $channel = ProjectChannel::STABLE): void
    {
        if (!in_array($channel, [ProjectChannel::STABLE, ProjectChannel::DEV], true)) {
            throw new \InvalidArgumentException('Canal de composants invalide.');
        }
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
                $process = new Process(['git', 'clone', '--depth', '1', '--branch', $channel === ProjectChannel::DEV ? 'dev' : 'main', '--', ($this->repositories ?? self::REPOSITORIES)[$component], $target]);
                $process->setTimeout(300);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Échec du clonage Git.');
                }
                $nativeFiles = (new ComponentNativeSource())->files($target);
                $revisionProcess = new Process(['git', 'rev-parse', 'HEAD'], $target);
                $revisionProcess->mustRun();
                $revision = trim($revisionProcess->getOutput());

                // The cloned Git repository belongs to the framework. The
                // consuming project must start with its own independent Git
                // history and remote, while provenance remains in the
                // ComponentManifest recorded below.
                $upstreamGit = $target.'/.git';
                if (!is_dir($upstreamGit) || is_link($upstreamGit)) {
                    throw new \RuntimeException('Métadonnées Git du composant invalides : '.$component);
                }
                NativeTreeRemoval::remove($upstreamGit);
                if (file_exists($upstreamGit) || is_link($upstreamGit)) {
                    throw new \RuntimeException('Suppression du Git upstream impossible : '.$component);
                }

                $commands = [['npm', 'ci']];
                if (getenv('SYMSVEL_DEFER_BUILDS') !== '1') {
                    $commands[] = ['npm', 'run', 'build'];
                }
                foreach ($commands as $command) {
                    $step = new Process(NativeCommand::resolve($command), $target);
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
                (new ComponentManifest($projectDir.'/backend'))->set($component, $revision, $nativeFiles);
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($created) as $path) {
                if (is_dir($path) && !is_link($path)) {
                    try {
                        NativeTreeRemoval::remove($path);
                    } catch (\Throwable $cleanup) {
                        throw new \RuntimeException('Installation interrompue ; nettoyage impossible : '.$path.' : '.$cleanup->getMessage(), 0, $exception);
                    }
                }
            }
            throw $exception;
        }
    }
}
