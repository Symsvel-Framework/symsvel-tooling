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

    public function fetch(string $component, string $directory, string $channel = ProjectChannel::DEV): string
    {
        $repository = ($this->repositories ?? self::REPOSITORIES)[$component] ?? throw new \InvalidArgumentException('Composant inconnu.');
        $reference = $channel === ProjectChannel::DEV ? 'main' : $this->latestRelease($repository);
        $process = new Process(['git', 'clone', '--depth', '1', '--branch', $reference, '--', $repository, $directory]);
        $process->setTimeout(300);
        $process->mustRun();

        $head = new Process(['git', 'rev-parse', 'HEAD'], $directory);
        $head->mustRun();
        return trim($head->getOutput());
    }

    private function latestRelease(string $repository): string
    {
        $process = new Process(['git', 'ls-remote', '--tags', '--refs', '--', $repository]);
        $process->setTimeout(300);
        $process->mustRun();
        $versions = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if (!preg_match('~refs/tags/(v?[0-9]+\\.[0-9]+\\.[0-9]+)$~D', $line, $match)) {
                continue;
            }
            $versions[] = $match[1];
        }
        if ($versions === []) {
            throw new \RuntimeException('Aucune release stable disponible : '.$repository);
        }
        usort($versions, static fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));
        return $versions[0];
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
