<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;

/**
 * Bootstrap a legacy component only when its original framework Git provenance
 * can be verified. Never changes project refs, HEAD, remotes or working files.
 */
final class LegacyComponentBaseline
{
    private const REPOSITORIES = [
        'bo' => 'symsvel-bo',
        'app' => 'symsvel-app',
        'front' => 'symsvel-front',
    ];

    /** @return array{revision: string, files: array<string, string>} */
    public function recover(string $directory, string $component): array
    {
        $expected = self::REPOSITORIES[$component] ?? throw new \InvalidArgumentException('Composant inconnu.');
        if (!is_dir($directory.'/.git')) {
            throw new \RuntimeException('Migration impossible : historique Git natif absent.');
        }
        $origin = trim($this->git($directory, ['remote', 'get-url', 'origin']));
        if (!preg_match('~^(?:git@github\\.com:|https://github\\.com/)Symsvel-Framework/'.preg_quote($expected, '~').'\\.git$~iD', $origin)) {
            throw new \RuntimeException('Migration automatique refusée : origin n’est pas le dépôt SymSvel officiel.');
        }
        $branch = trim($this->git($directory, ['symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD'], false));
        if ($branch === '') {
            $upstream = trim($this->git($directory, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}'], false));
            if ($upstream !== '' && str_starts_with($upstream, 'origin/')) {
                $branch = 'refs/remotes/'.$upstream;
            } else {
                $remoteRefs = trim($this->git($directory, ['for-each-ref', '--format=%(refname)', 'refs/remotes/origin/']));
                $candidates = array_values(array_filter(explode("\n", $remoteRefs), static fn (string $ref): bool => $ref !== '' && $ref !== 'refs/remotes/origin/HEAD'));
                if (count($candidates) !== 1) {
                    throw new \RuntimeException('Migration impossible : branche native distante ambiguë ou absente.');
                }
                $branch = $candidates[0];
            }
        }
        $revision = trim($this->git($directory, ['merge-base', 'HEAD', $branch]));
        if (!preg_match('/^[a-f0-9]{40}$/D', $revision)) {
            throw new \RuntimeException('Migration impossible : ancêtre natif introuvable.');
        }
        $paths = $this->git($directory, ['ls-tree', '-r', '-z', '--name-only', $revision]);
        $files = [];
        foreach (explode("\0", $paths) as $path) {
            if ($path === '') {
                continue;
            }
            if (str_contains($path, '..') || str_starts_with($path, '/')) {
                throw new \RuntimeException('Chemin natif invalide.');
            }
            $content = $this->git($directory, ['show', $revision.':'.$path]);
            $files[$path] = hash('sha256', $content);
        }
        ksort($files);
        return ['revision' => $revision, 'files' => $files];
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args, bool $required = true): string
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->run();
        if (!$process->isSuccessful()) {
            if (!$required) {
                return '';
            }
            throw new \RuntimeException('Migration native impossible : '.trim($process->getErrorOutput()));
        }
        return $process->getOutput();
    }
}
