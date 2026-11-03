<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\ComponentLifecycleInstaller;
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
