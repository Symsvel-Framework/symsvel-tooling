<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Composer\ComposerTransactionRunner;
use SymSvel\Tooling\Update\BackendComposerMerge;
use SymSvel\Tooling\Update\BackendNativeUpdater;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\ComponentNativeSource;
use SymSvel\Tooling\Update\ProjectChannel;

final class BackendNativeUpdaterTest extends TestCase
{
    private string $root;
    private string $backend;
    private string $upstream;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-back-test-'.bin2hex(random_bytes(8));
        $this->backend = $this->root.'/backend';
        $this->upstream = $this->root.'/upstream';
        mkdir($this->backend, 0700, true);
        mkdir($this->upstream, 0700, true);
        file_put_contents($this->upstream.'/composer.json', '{"require":{"symfony/yaml":"8.1.*","symsvel-framework/core":"^1.2"}}');
        file_put_contents($this->upstream.'/native.txt', 'old');
        file_put_contents($this->upstream.'/composer.lock', 'skeleton lock');
        $this->git($this->upstream, ['init', '-b', 'dev']);
        $this->git($this->upstream, ['add', '.']);
        $this->git($this->upstream, ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'old']);
        $revision = trim($this->git($this->upstream, ['rev-parse', 'HEAD']));
        ProjectChannel::write($this->backend, 'dev');
        (new ComponentManifest($this->backend))->set('back', $revision, ['native.txt' => hash('sha256', 'old')], (string) file_get_contents($this->upstream.'/composer.json'));
        file_put_contents($this->backend.'/composer.json', '{"require":{"symfony/yaml":"8.1.*","symsvel-framework/core":"dev-dev","symsvel-framework/module-demo":"dev-dev"}}');
        file_put_contents($this->backend.'/composer.lock', '{"packages":[]}');
        mkdir($this->backend.'/vendor/composer', 0700, true);
        file_put_contents($this->backend.'/vendor/composer/installed.json', '{"packages":[]}');
        file_put_contents($this->backend.'/native.txt', 'old');
        file_put_contents($this->backend.'/local.txt', 'keep');
        file_put_contents($this->upstream.'/native.txt', 'new');
        file_put_contents($this->upstream.'/composer.json', '{"require":{"symfony/yaml":"~8.1.11","symsvel-framework/core":"^1.3"}}');
        $this->git($this->upstream, ['add', '.']);
        $this->git($this->upstream, ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'new']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testNativeUpdatePreservesProjectAndResolvesItsOwnLock(): void
    {
        $commands = [];
        $runner = new ComposerTransactionRunner(commandRunner: function (array $command) use (&$commands): void {
            $commands[] = $command;
            if ($command[1] === 'update') {
                self::assertSame('new', file_get_contents($this->backend.'/native.txt'));
                self::assertSame('{"packages":[]}', file_get_contents($this->backend.'/composer.lock'));
            }
        });
        $this->updater($runner)->update($this->backend);
        $manifest = json_decode((string) file_get_contents($this->backend.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('~8.1.11', $manifest['require']['symfony/yaml']);
        self::assertSame('dev-dev', $manifest['require']['symsvel-framework/core']);
        self::assertSame('dev-dev', $manifest['require']['symsvel-framework/module-demo']);
        self::assertSame('keep', file_get_contents($this->backend.'/local.txt'));
        self::assertSame('new', file_get_contents($this->backend.'/native.txt'));
        self::assertContains(['composer', 'audit', '--locked'], $commands);
        self::assertSame(trim($this->git($this->upstream, ['rev-parse', 'HEAD'])), (new ComponentManifest($this->backend))->get('back')['revision']);
    }

    public function testConflictStopsBeforeComposer(): void
    {
        file_put_contents($this->backend.'/native.txt', 'custom');
        $runner = new ComposerTransactionRunner(commandRunner: static function (): void { self::fail('Unexpected Composer call'); });
        $this->expectExceptionMessage('conflits détectés');
        $this->updater($runner)->update($this->backend);
    }

    public function testFailedComposerRestoresNativeSourcesAndProvenance(): void
    {
        $original = file_get_contents($this->backend.'/composer.json');
        $provenance = file_get_contents($this->backend.'/.symsvel/components.json');
        $runner = new ComposerTransactionRunner(commandRunner: static function (): void { throw new \RuntimeException('Injected failure'); });
        try {
            $this->updater($runner)->update($this->backend);
            self::fail('Expected rollback');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
        }
        self::assertSame('old', file_get_contents($this->backend.'/native.txt'));
        self::assertSame($original, file_get_contents($this->backend.'/composer.json'));
        self::assertSame($provenance, file_get_contents($this->backend.'/.symsvel/components.json'));
    }

    public function testComposerConflictIsNotSilentlyOverwritten(): void
    {
        $this->expectExceptionMessage('require.symfony/yaml');
        (new BackendComposerMerge())->merge('{"require":{"symfony/yaml":"8.1.*"}}', '{"require":{"symfony/yaml":"~8.1.11"}}', '{"require":{"symfony/yaml":"8.2.*"}}', 'stable');
    }

    private function updater(ComposerTransactionRunner $runner): BackendNativeUpdater
    {
        return new BackendNativeUpdater(new ComponentNativeSource(['back' => $this->upstream]), $runner);
    }

    private function git(string $cwd, array $args): string
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->mustRun();
        return $process->getOutput();
    }
}
