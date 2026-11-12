<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\ComponentNativePreparation;
use SymSvel\Tooling\Update\ComponentNativeSource;
use SymSvel\Tooling\Update\WorkspaceNativePreparation;
use SymSvel\Tooling\Update\ProjectChannel;

/**
 * Real local Git upstreams for workspace update integration tests.
 * Never replaces production provenance checks or mocks the native preflight.
 */
final class NativeWorkspaceFixture
{
    /** @var array<string, string> */
    private array $repositories = [];

    public function __construct(private readonly string $root)
    {
    }

    /** @param array<string, string> $initial */
    public function component(string $name, array $initial, array $updated): void
    {
        if (!is_file($this->root.'/backend/.symsvel/project.json')) {
            ProjectChannel::write($this->root.'/backend', ProjectChannel::DEV);
        }
        $project = $this->root.'/'.$name;
        $upstream = $this->root.'/upstream-'.$name;
        if ((!is_dir($project) && !mkdir($project, 0775, true)) || !mkdir($upstream, 0775, true)) {
            throw new \RuntimeException('Fixture directory creation failed.');
        }
        foreach ($initial as $path => $contents) {
            $this->write($project.'/'.$path, $contents);
        }
        (new ComponentManifest($this->root.'/backend'))->set(
            $name,
            'initial',
            array_map(static fn (string $contents): string => hash('sha256', $contents), $initial),
        );
        foreach ($updated as $path => $contents) {
            $this->write($upstream.'/'.$path, $contents);
        }
        foreach ([
            ['git', 'init', '-q', '-b', 'dev', $upstream],
            ['git', '-C', $upstream, 'add', '.'],
            ['git', '-C', $upstream, '-c', 'user.name=SymSvel Tests', '-c', 'user.email=tests@example.test', 'commit', '-qm', 'upstream'],
        ] as $command) {
            (new Process($command))->mustRun();
        }
        $this->repositories[$name] = $upstream;
    }

    public function preparation(): WorkspaceNativePreparation
    {
        return new WorkspaceNativePreparation(
            new ComponentNativePreparation(new ComponentNativeSource($this->repositories)),
        );
    }

    private function write(string $path, string $contents): void
    {
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0775, true)) {
            throw new \RuntimeException('Fixture parent directory creation failed.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Fixture write failed.');
        }
    }
}
