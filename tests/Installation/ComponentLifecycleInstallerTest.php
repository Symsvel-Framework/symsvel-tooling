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
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->workspace]))->mustRun();
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
