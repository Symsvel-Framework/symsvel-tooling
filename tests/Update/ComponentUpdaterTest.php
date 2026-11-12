<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\ComponentNativeSource;
use SymSvel\Tooling\Update\ComponentUpdater;
use SymSvel\Tooling\Update\ProjectChannel;

final class ComponentUpdaterTest extends TestCase
{
    private string $workspace;
    private string $remote;
    private string $component;
    private ComponentNativeSource $native;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-updater-'.bin2hex(random_bytes(8));
        mkdir($this->workspace.'/backend', 0775, true);
        file_put_contents($this->workspace.'/backend/composer.json', '{}');
        ProjectChannel::write($this->workspace.'/backend', ProjectChannel::DEV);
        $this->remote = $this->workspace.'/remote.git';
        $this->component = $this->workspace.'/app';
        $this->native = new ComponentNativeSource(['app' => $this->remote]);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->workspace]))->mustRun();
    }

    public function testRejectsUnknownComponentWithoutCreatingTransaction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new ComponentUpdater())->update($this->workspace.'/backend', 'other');
        } finally {
            self::assertDirectoryDoesNotExist($this->workspace.'/backend/.symsvel/transactions');
        }
    }

    public function testRejectsSymbolicTransactionDirectoryBeforeFetchingUpstream(): void
    {
        mkdir($this->component);
        file_put_contents($this->component.'/README.md', 'local');
        $outside = $this->workspace.'/outside-transactions';
        mkdir($outside);
        file_put_contents($outside.'/sentinel.txt', 'untouched');
        symlink($outside, $this->workspace.'/backend/.symsvel/transactions');
        (new ComponentManifest($this->workspace.'/backend'))->set('app', 'baseline', ['README.md' => hash('sha256', 'local')]);

        try {
            $this->updater()->update($this->workspace.'/backend', 'app');
            self::fail('A redirected transaction directory must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Répertoire de transactions non fiable', $error->getMessage());
        }
        self::assertSame('local', file_get_contents($this->component.'/README.md'));
        self::assertSame('untouched', file_get_contents($outside.'/sentinel.txt'));
        self::assertSame(['sentinel.txt'], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
    }

    public function testMissingBaselineRefusesWithoutChangingProject(): void
    {
        mkdir($this->component);
        file_put_contents($this->component.'/README.md', 'local');
        try {
            $this->updater()->update($this->workspace.'/backend', 'app');
            self::fail('Missing baseline must be refused.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('historique Git natif absent', $error->getMessage());
        }
        self::assertSame('local', file_get_contents($this->component.'/README.md'));
    }

    public function testFailedUpstreamFetchCleansCheckpointAndPreservesManifest(): void
    {
        $this->seed(['README.md' => 'before']);
        $manifest = $this->workspace.'/backend/.symsvel/components.json';
        $before = file_get_contents($manifest);
        $head = $this->gitHead($this->component);

        // The baseline is valid, but the remote is no longer accessible.
        (new Process(['rm', '-rf', '--', $this->remote]))->mustRun();

        try {
            $this->updater()->update($this->workspace.'/backend', 'app');
            self::fail('A missing upstream must abort the update.');
        } catch (\RuntimeException $error) {
            self::assertNotSame('', $error->getMessage());
        }

        self::assertSame('before', file_get_contents($this->component.'/README.md'));
        self::assertSame($head, $this->gitHead($this->component));
        self::assertSame($before, file_get_contents($manifest));
        $this->assertNoPendingTransactions();
    }

    public function testRuntimeSnapshotCaptureFailureDiscardsPartialCheckpoint(): void
    {
        $this->seed(['README.md' => 'before']);
        $this->publish(['README.md' => 'upstream']);
        $manifest = $this->workspace.'/backend/.symsvel/components.json';
        $before = file_get_contents($manifest);
        $head = $this->gitHead($this->component);

        $outside = $this->workspace.'/external-node-modules';
        mkdir($outside);
        file_put_contents($outside.'/marker.txt', 'untouched');
        symlink($outside, $this->component.'/node_modules');

        try {
            $this->updater()->update($this->workspace.'/backend', 'app');
            self::fail('A symbolic runtime dependency directory must abort capture.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Snapshot runtime refusé', $error->getMessage());
        }

        self::assertSame('before', file_get_contents($this->component.'/README.md'));
        self::assertSame($head, $this->gitHead($this->component));
        self::assertSame($before, file_get_contents($manifest));
        self::assertTrue(is_link($this->component.'/node_modules'));
        self::assertSame('untouched', file_get_contents($outside.'/marker.txt'));
        $this->assertNoPendingTransactions();
    }

    public function testNativeFileConflictDoesNotChangeProjectGit(): void
    {
        $this->seed(['README.md' => 'before']);
        file_put_contents($this->component.'/README.md', 'local edit');
        $this->publish(['README.md' => 'remote edit']);
        $head = $this->gitHead($this->component);

        try {
            $this->updater()->update($this->workspace.'/backend', 'app');
            self::fail('Concurrent edits must conflict.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('README.md', $error->getMessage());
        }
        self::assertSame('local edit', file_get_contents($this->component.'/README.md'));
        self::assertSame($head, $this->gitHead($this->component));
        $this->assertNoPendingTransactions();
    }

    public function testRemoteAdditionAndLocalCommittedRouteCoexistEvenWhenBuildFails(): void
    {
        $this->seed([
            'package.json' => '{"name":"symsvel-test","version":"1.0.0","scripts":{"build":"echo ok"}}',
        ]);
        $localRoute = $this->component.'/src/routes/ct-local/+page.svelte';
        mkdir(dirname($localRoute), 0775, true);
        file_put_contents($localRoute, '<h1>Local</h1>');
        $this->git(['add', '.'], $this->component);
        $this->git(['commit', '-m', 'local project route'], $this->component);
        $head = $this->gitHead($this->component);
        $this->publish(['src/routes/ct-remote/+page.svelte' => '<h1>Remote</h1>']);

        try {
            $this->updater()->update($this->workspace.'/backend', 'app');
            self::fail('npm ci must fail without a package-lock.json.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('état précédent restauré', $error->getMessage());
        }
        self::assertSame($head, $this->gitHead($this->component));
        self::assertSame('<h1>Local</h1>', file_get_contents($localRoute));
        self::assertFileDoesNotExist($this->component.'/src/routes/ct-remote/+page.svelte');
    }

    public function testUpstreamDeletionRestoresNativeFileWhenNpmFails(): void
    {
        $this->seed([
            'README.md' => 'native baseline',
            'package.json' => '{"name":"symsvel-test","version":"1.0.0","scripts":{"build":"echo ok"}}',
        ]);
        $manifestPath = $this->workspace.'/backend/.symsvel/components.json';
        $manifestBefore = file_get_contents($manifestPath);
        $headBefore = $this->gitHead($this->component);

        $publisher = $this->workspace.'/publisher';
        $this->git(['clone', $this->remote, $publisher], $this->workspace);
        $this->git(['config', 'user.email', 'ci@example.test'], $publisher);
        $this->git(['config', 'user.name', 'CI'], $publisher);
        $this->git(['rm', 'README.md'], $publisher);
        $this->git(['commit', '-m', 'remove native readme'], $publisher);
        $this->git(['push', 'origin', 'HEAD'], $publisher);

        try {
            $this->updater()->update($this->workspace.'/backend', 'app');
            self::fail('npm ci must fail without package-lock.json.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('état précédent restauré', $error->getMessage());
        }
        self::assertSame('native baseline', file_get_contents($this->component.'/README.md'));
        self::assertSame($headBefore, $this->gitHead($this->component));
        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        $this->assertNoPendingTransactions();
    }

    public function testRemoteCollisionWithGeneratedFileLeavesWorkspaceIntact(): void
    {
        $this->seed(['README.md' => 'original']);
        $generated = $this->component.'/src/lib/vendor/demo/widget.svelte';
        mkdir(dirname($generated), 0775, true);
        file_put_contents($generated, 'module-owned');
        $this->publish(['src/lib/vendor/demo/widget.svelte' => 'remote-owned']);
        $head = $this->gitHead($this->component);

        try {
            $this->updater()->update($this->workspace.'/backend', 'app');
            self::fail('Remote native file must not overwrite a local file.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('src/lib/vendor/demo/widget.svelte', $error->getMessage());
        }
        self::assertSame('module-owned', file_get_contents($generated));
        self::assertSame($head, $this->gitHead($this->component));
        $this->assertNoPendingTransactions();
    }

    private function assertNoPendingTransactions(): void
    {
        $directory = $this->workspace.'/backend/.symsvel/transactions';
        self::assertSame([], glob($directory.'/update-*') ?: [], 'Preflight must clean its transaction checkpoint.');
    }

    /** @param array<string, string> $files */
    private function seed(array $files): void
    {
        mkdir($this->component);
        $this->git(['init', '-b', 'dev'], $this->component);
        $this->git(['config', 'user.email', 'ci@example.test'], $this->component);
        $this->git(['config', 'user.name', 'CI'], $this->component);
        foreach ($files as $path => $content) {
            $this->write($this->component.'/'.$path, $content);
        }
        $this->git(['add', '.'], $this->component);
        $this->git(['commit', '-m', 'baseline'], $this->component);
        $this->git(['init', '--bare', '-b', 'dev', $this->remote], $this->workspace);
        $this->git(['remote', 'add', 'origin', $this->remote], $this->component);
        $this->git(['push', '-u', 'origin', 'HEAD'], $this->component);
        (new ComponentManifest($this->workspace.'/backend'))->set('app', $this->gitHead($this->component), $this->native->files($this->component));
    }

    /** @param array<string, string> $files */
    private function publish(array $files): void
    {
        $publisher = $this->workspace.'/publisher';
        $this->git(['clone', $this->remote, $publisher], $this->workspace);
        $this->git(['config', 'user.email', 'ci@example.test'], $publisher);
        $this->git(['config', 'user.name', 'CI'], $publisher);
        foreach ($files as $path => $content) {
            $this->write($publisher.'/'.$path, $content);
        }
        $this->git(['add', '.'], $publisher);
        $this->git(['commit', '-m', 'upstream'], $publisher);
        $this->git(['push', 'origin', 'HEAD'], $publisher);
    }

    private function updater(): ComponentUpdater
    {
        return new ComponentUpdater(native: $this->native);
    }

    /** @param list<string> $command */
    private function git(array $command, string $cwd): void
    {
        (new Process(['git', ...$command], $cwd))->mustRun();
    }

    private function gitHead(string $cwd): string
    {
        return trim((new Process(['git', 'rev-parse', 'HEAD'], $cwd))->mustRun()->getOutput());
    }

    private function write(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $content);
    }
}
