<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\ComponentNativePreparation;
use SymSvel\Tooling\Update\ComponentNativeSource;
use SymSvel\Tooling\Update\WorkspaceNativePreparation;
use SymSvel\Tooling\Update\ProjectChannel;

final class WorkspaceNativePreparationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-workspace-native-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/backend', 0775, true);
        ProjectChannel::write($this->root.'/backend', ProjectChannel::DEV);
        mkdir($this->root.'/app', 0775, true);
        mkdir($this->root.'/bo', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->root]))->mustRun();
    }

    public function testPreparesEveryInstalledComponentWithoutChangingProjectFiles(): void
    {
        $remote = $this->remote();
        $manifest = new ComponentManifest($this->root.'/backend');
        foreach (['app', 'bo'] as $component) {
            file_put_contents($this->root.'/'.$component.'/README.md', 'baseline');
            $manifest->set($component, 'initial', ['README.md' => hash('sha256', 'baseline')]);
        }
        $before = file_get_contents($this->root.'/backend/.symsvel/components.json');
        $prepared = $this->preparation($remote)->prepare($this->root.'/backend', $this->root.'/checkpoint');

        self::assertSame(['bo', 'app'], array_keys($prepared));
        foreach (['app', 'bo'] as $component) {
            self::assertSame([], $prepared[$component]['plan']['conflicts']);
            self::assertSame('baseline', file_get_contents($this->root.'/'.$component.'/README.md'));
        }
        self::assertSame($before, file_get_contents($this->root.'/backend/.symsvel/components.json'));
    }

    public function testConflictInLaterComponentPreventsAnyWorkspaceMutation(): void
    {
        $remote = $this->remote();
        $manifest = new ComponentManifest($this->root.'/backend');
        foreach (['bo', 'app'] as $component) {
            $manifest->set($component, 'initial', ['README.md' => hash('sha256', 'baseline')]);
        }
        file_put_contents($this->root.'/bo/README.md', 'baseline');
        file_put_contents($this->root.'/app/README.md', 'local change');
        $before = file_get_contents($this->root.'/backend/.symsvel/components.json');

        try {
            $this->preparation($remote)->prepare($this->root.'/backend', $this->root.'/checkpoint');
            self::fail('Expected a conflict in app.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('README.md', $error->getMessage());
        }
        self::assertSame('baseline', file_get_contents($this->root.'/bo/README.md'));
        self::assertSame('local change', file_get_contents($this->root.'/app/README.md'));
        self::assertSame($before, file_get_contents($this->root.'/backend/.symsvel/components.json'));
    }

    private function preparation(string $remote): WorkspaceNativePreparation
    {
        return new WorkspaceNativePreparation(new ComponentNativePreparation(
            new ComponentNativeSource(['bo' => $remote, 'app' => $remote]),
        ));
    }

    private function remote(): string
    {
        $source = $this->root.'/source';
        $remote = $this->root.'/remote.git';
        mkdir($source);
        foreach ([
            ['git', 'init', '-b', 'dev'],
            ['git', 'config', 'user.email', 'ci@example.test'],
            ['git', 'config', 'user.name', 'CI'],
        ] as $command) {
            (new Process($command, $source))->mustRun();
        }
        file_put_contents($source.'/README.md', 'upstream');
        (new Process(['git', 'add', '.'], $source))->mustRun();
        (new Process(['git', 'commit', '-m', 'initial'], $source))->mustRun();
        (new Process(['git', 'clone', '--bare', $source, $remote]))->mustRun();
        return $remote;
    }
}
