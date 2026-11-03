<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;

/**
 * Reads framework source from a dedicated upstream clone, never from project Git.
 */
final class ComponentNativeSource
{
    /** @param array<string, string>|null $repositories */
    public function __construct(private readonly ?array $repositories = null)
    {
    }

    private const REPOSITORIES = [
        'bo' => 'git@github.com:Symsvel-Framework/symsvel-bo.git',
        'app' => 'git@github.com:Symsvel-Framework/symsvel-app.git',
        'front' => 'git@github.com:Symsvel-Framework/symsvel-front.git',
    ];

    public function fetch(string $component, string $directory): string
    {
        $repository = ($this->repositories ?? self::REPOSITORIES)[$component] ?? throw new \InvalidArgumentException('Composant inconnu.');
        $process = new Process(['git', 'clone', '--depth', '1', '--', $repository, $directory]);
        $process->setTimeout(300);
        $process->mustRun();

        $head = new Process(['git', 'rev-parse', 'HEAD'], $directory);
        $head->mustRun();
        return trim($head->getOutput());
    }

    /** @return array<string, string> */
    public function files(string $directory): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $entry): bool => !($entry->isDir() && in_array($entry->getFilename(), ['.git', 'node_modules', '.svelte-kit', 'build', 'dist'], true)),
            ),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new \RuntimeException('Lien symbolique natif non pris en charge : '.$entry->getPathname());
            }
            if ($entry->isFile()) {
                $path = substr($entry->getPathname(), strlen($directory) + 1);
                $result[$path] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($result);
        return $result;
    }
}
