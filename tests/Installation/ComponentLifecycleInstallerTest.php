<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\ComponentLifecycleInstaller;
use SymSvel\Tooling\Installation\ComponentInstaller;
use Symfony\Component\Process\Process;

final class ComponentLifecycleInstallerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-lifecycle-'.bin2hex(random_bytes(8));
        mkdir($this->workspace.'/backend', 0775, true);
        file_put_contents($this->workspace.'/backend/composer.json', '{}');
        \SymSvel\Tooling\Update\ProjectChannel::write($this->workspace.'/backend', \SymSvel\Tooling\Update\ProjectChannel::DEV);
        mkdir($this->workspace.'/backend/vendor/composer', 0775, true);
        file_put_contents($this->workspace.'/backend/vendor/composer/installed.json', '{"packages":[]}');
        mkdir($this->workspace.'/backend/config');
        file_put_contents($this->workspace.'/backend/config/symsvel-native-modules.json', json_encode([
            'schema' => 1,
            'modules' => [],
            'components' => ['app' => ['symsvel-framework/module-users']],
        ], JSON_THROW_ON_ERROR));
        mkdir($this->workspace.'/backend/scripts');
        file_put_contents($this->workspace.'/backend/scripts/symsvel-composer-transaction.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/../native-module-invocation.json', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR));
PHP
        );
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->workspace]))->mustRun();
    }


    public function testSnapshotCaptureFailureDiscardsCheckpointBeforeInstall(): void
    {
        $outside = $this->workspace.'/outside';
        mkdir($outside);
        file_put_contents($outside.'/keep.txt', 'untouched');
        symlink($outside, $this->workspace.'/bo');

        try {
            (new ComponentLifecycleInstaller())->install($this->workspace.'/backend', 'app');
            self::fail('A symbolic workspace component must prevent snapshot capture.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Snapshot refusé', $error->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->workspace.'/app');
        self::assertSame('untouched', file_get_contents($outside.'/keep.txt'));
        self::assertTrue(is_link($this->workspace.'/bo'));
        self::assertSame([], glob($this->workspace.'/backend/.symsvel/transactions/update-*') ?: []);
    }

    public function testRollsBackNewComponentAfterSvelteSyncFailure(): void
    {
        $repository = $this->workspace.'/fixture';
        mkdir($repository);
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $repository))->mustRun();
        }
        file_put_contents($repository.'/package.json', '{"name":"symsvel-fixture","version":"1.0.0","scripts":{"build":"echo build-ok"}}');
        file_put_contents($repository.'/package-lock.json', '{"name":"symsvel-fixture","version":"1.0.0","lockfileVersion":3,"requires":true,"packages":{"":{"name":"symsvel-fixture","version":"1.0.0"}}}');
        // Deliberately omit src/lib and src/routes: npm succeeds but Svelte sync must fail.
        (new Process(['git', 'add', '.'], $repository))->mustRun();
        (new Process(['git', 'commit', '-m', 'fixture'], $repository))->mustRun();
        file_put_contents($this->workspace.'/backend/marker.txt', 'untouched');

        $installer = new ComponentLifecycleInstaller(new ComponentInstaller(['app' => $repository]));
        try {
            $installer->install($this->workspace.'/backend', 'app');
            self::fail('Invalid Svelte layout must fail after clone and npm.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('état précédent restauré', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->workspace.'/app');
        self::assertSame('untouched', file_get_contents($this->workspace.'/backend/marker.txt'));
    }


    public function testInstallsValidComponentSuccessfully(): void
    {
        $repository = $this->workspace.'/fixture-success';
        mkdir($repository.'/src/routes', 0775, true);
        mkdir($repository.'/src/lib', 0775, true);
        file_put_contents($repository.'/src/routes/.gitkeep', '');
        file_put_contents($repository.'/src/lib/.gitkeep', '');
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $repository))->mustRun();
        }
        file_put_contents($repository.'/package.json', '{"name":"symsvel-fixture","version":"1.0.0","scripts":{"build":"echo build-ok"}}');
        file_put_contents($repository.'/package-lock.json', '{"name":"symsvel-fixture","version":"1.0.0","lockfileVersion":3,"requires":true,"packages":{"":{"name":"symsvel-fixture","version":"1.0.0"}}}');
        (new Process(['git', 'add', '.'], $repository))->mustRun();
        (new Process(['git', 'commit', '-m', 'fixture'], $repository))->mustRun();

        (new ComponentLifecycleInstaller(new ComponentInstaller(['app' => $repository])))
            ->install($this->workspace.'/backend', 'app');

        self::assertDirectoryExists($this->workspace.'/app/.git');
        self::assertFileExists($this->workspace.'/backend/.symsvel/svelte-files.json');
        self::assertSame(['require', 'symsvel-framework/module-users', '--bootstrap'], json_decode((string) file_get_contents($this->workspace.'/backend/native-module-invocation.json'), true, 512, JSON_THROW_ON_ERROR));
    }


    public function testRollsBackWhenPostSyncBuildFails(): void
    {
        $repository = $this->workspace.'/fixture-build-failure';
        mkdir($repository.'/src/routes', 0775, true);
        mkdir($repository.'/src/lib', 0775, true);
        file_put_contents($repository.'/src/routes/.gitkeep', '');
        file_put_contents($repository.'/src/lib/.gitkeep', '');
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'ci@example.test'], ['git', 'config', 'user.name', 'CI']] as $command) {
            (new Process($command, $repository))->mustRun();
        }
        // ComponentInstaller builds once; lifecycle installer rebuilds after Svelte sync.
        // The second build deliberately fails to exercise the later rollback path.
        file_put_contents($repository.'/package.json', '{"name":"symsvel-fixture","version":"1.0.0","scripts":{"build":"test ! -f build-once && touch build-once"}}');
        file_put_contents($repository.'/package-lock.json', '{"name":"symsvel-fixture","version":"1.0.0","lockfileVersion":3,"requires":true,"packages":{"":{"name":"symsvel-fixture","version":"1.0.0"}}}');
        (new Process(['git', 'add', '.'], $repository))->mustRun();
        (new Process(['git', 'commit', '-m', 'fixture'], $repository))->mustRun();
        file_put_contents($this->workspace.'/backend/marker.txt', 'before');

        try {
            (new ComponentLifecycleInstaller(new ComponentInstaller(['app' => $repository])))
                ->install($this->workspace.'/backend', 'app');
            self::fail('The second build must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('état précédent restauré', $error->getMessage());
            self::assertStringContainsString('Build après synchronisation impossible', $error->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->workspace.'/app');
        self::assertSame('before', file_get_contents($this->workspace.'/backend/marker.txt'));
        self::assertFileDoesNotExist($this->workspace.'/backend/.symsvel/svelte-files.json');
    }

    public function testRejectsUnknownComponentWithoutTouchingWorkspace(): void
    {
        try {
            (new ComponentLifecycleInstaller())->install($this->workspace.'/backend', 'unknown');
            self::fail('An unknown component must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Composant inconnu', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->workspace.'/backend/.symsvel');
    }

    public function testRejectsExistingComponentWithoutTouchingItsFiles(): void
    {
        mkdir($this->workspace.'/app');
        file_put_contents($this->workspace.'/app/local.txt', 'keep me');
        try {
            (new ComponentLifecycleInstaller())->install($this->workspace.'/backend', 'app');
            self::fail('Existing component must be preserved.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('déjà présent', $error->getMessage());
        }
        self::assertSame('keep me', file_get_contents($this->workspace.'/app/local.txt'));
        self::assertDirectoryDoesNotExist($this->workspace.'/backend/.symsvel');
    }
}
