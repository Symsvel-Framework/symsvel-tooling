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
        file_put_contents($this->upstream.'/.env', "APP_ENV=dev\nMAILER_DSN=null://null\n");
        file_put_contents($this->upstream.'/.env.example', "MAILER_DSN=null://null\n");
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
        file_put_contents($this->upstream.'/.env', "APP_ENV=dev\nMAILER_DSN=\nMAILTRAP_DSN=\n");
        file_put_contents($this->upstream.'/.env.example', "MAILER_DSN=\nMAILTRAP_DSN=\n");
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

    public function testLegacyEnvProvenanceDoesNotConflictWithProjectConfiguration(): void
    {
        $manifest = new ComponentManifest($this->backend);
        $entry = $manifest->get('back');
        // Simulate the previous recorder, when .env was still tracked.
        $files = $entry['files'] + [
            '.env' => hash('sha256', "APP_ENV=dev\nMAILER_DSN=null://null\n"),
            '.env.example' => hash('sha256', "MAILER_DSN=null://null\n"),
        ];
        $manifest->set('back', $entry['revision'], $files, $entry['composer']);
        $local = "APP_ENV=dev\nPROJECT_CUSTOM=keep\n";
        file_put_contents($this->backend.'/.env', $local);
        file_put_contents($this->backend.'/.env.example', "MAILER_DSN=null://null\n");
        file_put_contents($this->backend.'/.env.local', "PROJECT_PRIVATE=keep\n");
        chmod($this->backend.'/.env.local', 0600);
        $runner = new ComposerTransactionRunner(commandRunner: function (array $command) use ($local): void {
            if ($command[1] === 'update') {
                self::assertSame($local, file_get_contents($this->backend.'/.env'));
                self::assertSame("MAILER_DSN=\nMAILTRAP_DSN=\n", file_get_contents($this->backend.'/.env.example'));
            }
        });
        $this->updater($runner)->update($this->backend);
        self::assertSame($local, file_get_contents($this->backend.'/.env'));
        self::assertSame("PROJECT_PRIVATE=keep\n", file_get_contents($this->backend.'/.env.local'));
        self::assertSame(0600, fileperms($this->backend.'/.env.local') & 0777);
        self::assertArrayNotHasKey('.env', $manifest->get('back')['files']);
        self::assertArrayHasKey('.env.example', $manifest->get('back')['files']);
        self::assertSame('new', file_get_contents($this->backend.'/native.txt'));
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

    public function testComposerBumpAndUpstreamSecurityFloorMergeWithoutWeakeningEither(): void
    {
        $merge = new BackendComposerMerge();
        foreach ([
            ['^2.12|^3.30', '^3.31.1', '^2.12|^3.30.0', '^3.31.1'],
            ['^2.12|^3.30', '^3.31.1', '^3.30.0', '^3.31.1'],
            ['^2.12|^3.30', '^3.31.1', '^3.31.2', '^3.31.2'],
            ['^3.30', '^3.31.1', '>=3.31.1 <4.0', '>=3.31.1 <4.0'],
            ['8.1.*', '~8.1.11', '8.1.12', '8.1.12'],
        ] as [$before, $next, $local, $expected]) {
            $document = static fn (string $constraint): string => json_encode([
                'require' => ['twig/twig' => $constraint],
            ], JSON_THROW_ON_ERROR);
            $merged = $merge->merge($document($before), $document($next), $document($local), 'dev');
            self::assertSame($expected, json_decode($merged, true, 512, JSON_THROW_ON_ERROR)['require']['twig/twig']);
        }
    }

    public function testComposerConstraintConflictsRemainBlocking(): void
    {
        foreach ([
            ['^3.30', '^3.31.1', '3.30.0'],
            ['^3.30', '^3.31.1', '^4.0'],
            ['^3.30', '^3.31.1', '>=3.30 <3.32'],
            ['^3.30', '^3.31.1', '^3.30@dev'],
            ['^3.30', '^3.31.1', 'dev-main as 3.31.1'],
            ['^3.30', '^3.31.1', '^3.0'],
        ] as [$before, $next, $local]) {
            $document = static fn (string $constraint): string => json_encode([
                'require-dev' => ['twig/twig' => $constraint],
            ], JSON_THROW_ON_ERROR);
            try {
                (new BackendComposerMerge())->merge($document($before), $document($next), $document($local), 'dev');
                self::fail('An incompatible or ambiguous constraint was accepted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('require-dev.twig/twig', $e->getMessage());
            }
        }
    }

    public function testRecordsExactGitBaselineWithoutDeletingCheckout(): void
    {
        $fresh = $this->root.'/fresh/backend';
        mkdir(dirname($fresh), 0700, true);
        $this->git($this->root, ['clone', '--branch', 'dev', '--', $this->upstream, $fresh]);
        $this->git($fresh, ['remote', 'set-url', 'origin', 'https://github.com/Symsvel-Framework/symsvel-back.git']);
        $native = (string) file_get_contents($fresh.'/composer.json');
        file_put_contents($fresh.'/composer.json', '{"require":{"local/package":"1.0"}}');
        file_put_contents($fresh.'/native.txt', 'local customization');
        (new BackendNativeUpdater())->recordInstalled($fresh);
        $entry = (new ComponentManifest($fresh))->get('back');
        self::assertSame($native, $entry['composer']);
        self::assertSame(hash('sha256', 'new'), $entry['files']['native.txt']);
        self::assertArrayNotHasKey('composer.lock', $entry['files']);
        self::assertArrayNotHasKey('.env', $entry['files']);
        self::assertArrayHasKey('.env.example', $entry['files']);
        self::assertDirectoryExists($fresh.'/.git');
    }

    public function testMissingBackProvenanceRefusesBeforeFetching(): void
    {
        unlink($this->backend.'/.symsvel/components.json');
        $this->expectExceptionMessage('Provenance native Back absente');
        (new BackendNativeUpdater())->update($this->backend);
    }

    public function testAuditFailureAlsoRestoresNativeSources(): void
    {
        $provenance = file_get_contents($this->backend.'/.symsvel/components.json');
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command): void {
            if ($command[1] === 'audit') {
                throw new \RuntimeException('Security audit failed');
            }
        });
        try {
            $this->updater($runner)->update($this->backend);
            self::fail('Expected audit rollback');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Security audit failed', $error->getMessage());
        }
        self::assertSame('old', file_get_contents($this->backend.'/native.txt'));
        self::assertSame($provenance, file_get_contents($this->backend.'/.symsvel/components.json'));
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
