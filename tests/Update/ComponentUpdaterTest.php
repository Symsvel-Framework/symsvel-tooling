<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\ComponentUpdater;
use Symfony\Component\Process\Process;

final class ComponentUpdaterTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-updater-'.bin2hex(random_bytes(8));
        mkdir($this->workspace.'/backend', 0775, true);
        file_put_contents($this->workspace.'/backend/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        $process = new Process(['rm', '-rf', '--', $this->workspace]);
        $process->mustRun();
    }

    public function testRejectsUnknownComponentWithoutCreatingTransaction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new ComponentUpdater())->update($this->workspace.'/backend', 'other');
        } finally {
            self::assertDirectoryDoesNotExist($this->workspace.'/backend/.symsvel');
        }
    }


    public function testRestoresGitAndSourcesWhenNpmCiFails(): void
    {
        $remote = $this->workspace.'/remote.git';
        (new Process(['git', 'init', '--bare', $remote]))->mustRun();
        $component = $this->workspace.'/app';
        (new Process(['git', 'clone', $remote, $component]))->mustRun();
        foreach ([['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $component))->mustRun();
        }
        file_put_contents($component.'/README.md', 'before');
        file_put_contents($component.'/package.json', '{"name":"symsvel-test","version":"1.0.0","scripts":{"build":"echo ok"}}');
        (new Process(['git', 'add', '.'], $component))->mustRun();
        (new Process(['git', 'commit', '-m', 'before'], $component))->mustRun();
        (new Process(['git', 'push', '-u', 'origin', 'HEAD'], $component))->mustRun();
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $component))->mustRun()->getOutput());

        $other = $this->workspace.'/publisher';
        (new Process(['git', 'clone', $remote, $other]))->mustRun();
        foreach ([['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $other))->mustRun();
        }
        file_put_contents($other.'/README.md', 'after');
        // No package-lock.json: npm ci must fail after Git fast-forward.
        (new Process(['git', 'add', '.'], $other))->mustRun();
        (new Process(['git', 'commit', '-m', 'after'], $other))->mustRun();
        (new Process(['git', 'push', 'origin', 'HEAD'], $other))->mustRun();

        try {
            (new ComponentUpdater())->update($this->workspace.'/backend', 'app');
            self::fail('npm ci without a lockfile must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('état précédent restauré', $error->getMessage());
        }
        self::assertSame('before', file_get_contents($component.'/README.md'));
        self::assertSame($head, trim((new Process(['git', 'rev-parse', 'HEAD'], $component))->mustRun()->getOutput()));
        self::assertSame('', trim((new Process(['git', 'status', '--porcelain'], $component))->mustRun()->getOutput()));
    }


    public function testRegisteredGeneratedFileSurvivesFailedUpdate(): void
    {
        $remote = $this->workspace.'/remote.git';
        (new Process(['git', 'init', '--bare', $remote]))->mustRun();
        $component = $this->workspace.'/app';
        (new Process(['git', 'clone', $remote, $component]))->mustRun();
        foreach ([['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $component))->mustRun();
        }
        file_put_contents($component.'/package.json', '{"name":"symsvel-test","version":"1.0.0","scripts":{"build":"echo ok"}}');
        (new Process(['git', 'add', '.'], $component))->mustRun();
        (new Process(['git', 'commit', '-m', 'initial'], $component))->mustRun();
        (new Process(['git', 'push', '-u', 'origin', 'HEAD'], $component))->mustRun();
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $component))->mustRun()->getOutput());

        $generated = $component.'/src/lib/vendor/demo/widget.svelte';
        mkdir(dirname($generated), 0775, true);
        file_put_contents($generated, 'generated component');
        mkdir($this->workspace.'/backend/.symsvel', 0775, true);
        file_put_contents(
            $this->workspace.'/backend/.symsvel/svelte-files.json',
            json_encode([$generated => hash_file('sha256', $generated)], JSON_THROW_ON_ERROR),
        );

        $publisher = $this->workspace.'/publisher';
        (new Process(['git', 'clone', $remote, $publisher]))->mustRun();
        foreach ([['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $publisher))->mustRun();
        }
        file_put_contents($publisher.'/CHANGELOG.md', 'new version');
        (new Process(['git', 'add', '.'], $publisher))->mustRun();
        (new Process(['git', 'commit', '-m', 'update'], $publisher))->mustRun();
        (new Process(['git', 'push', 'origin', 'HEAD'], $publisher))->mustRun();

        try {
            (new ComponentUpdater())->update($this->workspace.'/backend', 'app');
            self::fail('npm ci must fail without package-lock.json.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('état précédent restauré', $error->getMessage());
        }

        self::assertSame($head, trim((new Process(['git', 'rev-parse', 'HEAD'], $component))->mustRun()->getOutput()));
        self::assertSame('generated component', file_get_contents($generated));
        self::assertFileDoesNotExist($component.'/CHANGELOG.md');
        self::assertSame(
            hash_file('sha256', $generated),
            json_decode((string) file_get_contents($this->workspace.'/backend/.symsvel/svelte-files.json'), true, 512, JSON_THROW_ON_ERROR)[$generated],
        );
    }


    public function testRemoteCollisionWithGeneratedFileLeavesWorkspaceIntact(): void
    {
        $remote = $this->workspace.'/remote.git';
        (new Process(['git', 'init', '--bare', $remote]))->mustRun();
        $component = $this->workspace.'/app';
        (new Process(['git', 'clone', $remote, $component]))->mustRun();
        foreach ([['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $component))->mustRun();
        }
        file_put_contents($component.'/README.md', 'original');
        (new Process(['git', 'add', '.'], $component))->mustRun();
        (new Process(['git', 'commit', '-m', 'initial'], $component))->mustRun();
        (new Process(['git', 'push', '-u', 'origin', 'HEAD'], $component))->mustRun();
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $component))->mustRun()->getOutput());

        $generated = $component.'/src/lib/vendor/demo/widget.svelte';
        mkdir(dirname($generated), 0775, true);
        file_put_contents($generated, 'module-owned');
        mkdir($this->workspace.'/backend/.symsvel', 0775, true);
        file_put_contents($this->workspace.'/backend/.symsvel/svelte-files.json', json_encode([
            $generated => hash_file('sha256', $generated),
        ], JSON_THROW_ON_ERROR));

        $publisher = $this->workspace.'/publisher';
        (new Process(['git', 'clone', $remote, $publisher]))->mustRun();
        foreach ([['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $publisher))->mustRun();
        }
        mkdir($publisher.'/src/lib/vendor/demo', 0775, true);
        file_put_contents($publisher.'/src/lib/vendor/demo/widget.svelte', 'remote-owned');
        (new Process(['git', 'add', '.'], $publisher))->mustRun();
        (new Process(['git', 'commit', '-m', 'conflicting remote file'], $publisher))->mustRun();
        (new Process(['git', 'push', 'origin', 'HEAD'], $publisher))->mustRun();

        try {
            (new ComponentUpdater())->update($this->workspace.'/backend', 'app');
            self::fail('Remote Git must not overwrite a module-generated file.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('état précédent restauré', $error->getMessage());
        }

        self::assertSame($head, trim((new Process(['git', 'rev-parse', 'HEAD'], $component))->mustRun()->getOutput()));
        self::assertSame('module-owned', file_get_contents($generated));
        self::assertSame('original', file_get_contents($component.'/README.md'));
        self::assertSame('?? src/', trim((new Process(['git', 'status', '--porcelain'], $component))->mustRun()->getOutput()));
    }

    public function testRejectsDirtyGitComponentWithoutChangingIt(): void
    {
        $component = $this->workspace.'/app';
        mkdir($component);
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $component))->mustRun();
        }
        file_put_contents($component.'/README.md', 'initial');
        (new Process(['git', 'add', 'README.md'], $component))->mustRun();
        (new Process(['git', 'commit', '-m', 'initial'], $component))->mustRun();
        file_put_contents($component.'/README.md', 'local changes');

        try {
            (new ComponentUpdater())->update($this->workspace.'/backend', 'app');
            self::fail('A dirty component must not be updated.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Modifications locales', $error->getMessage());
        }
        self::assertSame('local changes', file_get_contents($component.'/README.md'));
        self::assertDirectoryDoesNotExist($this->workspace.'/backend/.symsvel');
    }
}
