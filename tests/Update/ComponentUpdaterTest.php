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
