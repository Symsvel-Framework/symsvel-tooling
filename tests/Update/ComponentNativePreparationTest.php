<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\ComponentNativePreparation;
use SymSvel\Tooling\Update\ComponentNativeSource;

final class ComponentNativePreparationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-native-preflight-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/backend', 0775, true);
        mkdir($this->root.'/app');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->root]))->mustRun();
    }

    public function testPreparesChangesWithoutMutatingLocalFilesOrManifest(): void
    {
        $remote = $this->createRemote('before');
        $manifest = new ComponentManifest($this->root.'/backend');
        $manifest->set('app', 'initial', ['README.md' => hash('sha256', 'before')]);
        $before = file_get_contents($this->root.'/backend/.symsvel/components.json');
        file_put_contents($this->root.'/app/README.md', 'before');
        file_put_contents($this->root.'/app/local.txt', 'local');
        $upstream = $this->root.'/upstream';

        $result = (new ComponentNativePreparation(new ComponentNativeSource(['app' => $remote])))
            ->prepare($this->root.'/backend', 'app', $upstream);

        self::assertSame([], $result['plan']['replace']);
        self::assertSame([], $result['plan']['conflicts']);
        self::assertSame('before', file_get_contents($this->root.'/app/README.md'));
        self::assertSame('local', file_get_contents($this->root.'/app/local.txt'));
        self::assertSame($before, file_get_contents($this->root.'/backend/.symsvel/components.json'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $result['revision']);
    }

    public function testRejectsConcurrentEditsBeforeMutation(): void
    {
        $remote = $this->createRemote('upstream');
        (new ComponentManifest($this->root.'/backend'))->set('app', 'initial', [
            'README.md' => hash('sha256', 'baseline'),
        ]);
        file_put_contents($this->root.'/app/README.md', 'local');
        $before = file_get_contents($this->root.'/backend/.symsvel/components.json');

        try {
            (new ComponentNativePreparation(new ComponentNativeSource(['app' => $remote])))
                ->prepare($this->root.'/backend', 'app', $this->root.'/upstream');
            self::fail('Expected native conflict.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('README.md', $error->getMessage());
        }

        self::assertSame('local', file_get_contents($this->root.'/app/README.md'));
        self::assertSame($before, file_get_contents($this->root.'/backend/.symsvel/components.json'));
    }

    private function createRemote(string $readme): string
    {
        $source = $this->root.'/source';
        $remote = $this->root.'/remote.git';
        mkdir($source);
        (new Process(['git', 'init', '-b', 'main'], $source))->mustRun();
        (new Process(['git', 'config', 'user.email', 'ci@example.test'], $source))->mustRun();
        (new Process(['git', 'config', 'user.name', 'CI'], $source))->mustRun();
        file_put_contents($source.'/README.md', $readme);
        (new Process(['git', 'add', '.'], $source))->mustRun();
        (new Process(['git', 'commit', '-m', 'upstream'], $source))->mustRun();
        (new Process(['git', 'clone', '--bare', $source, $remote]))->mustRun();
        return $remote;
    }
}
