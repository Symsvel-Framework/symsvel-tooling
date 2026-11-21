<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\DevDocumentationBuilder;
use SymSvel\Tooling\Installation\WorkspaceInitializer;
use Symfony\Component\Process\Process;

final class DevDocumentationBuilderTest extends TestCase
{
    public function testLocalMkDocsBuildIsRepeatableAndNeverWritesToProduction(): void
    {
        $root = sys_get_temp_dir().'/symsvel-devdocs-'.bin2hex(random_bytes(6));
        mkdir($root);
        try {
            (new WorkspaceInitializer())->initialize($root);
            $commands = [];
            $builder = new DevDocumentationBuilder(static function (array $command, string $cwd) use (&$commands): void {
                $commands[] = $command;
                if (in_array('venv', $command, true)) {
                    mkdir($cwd.'/.venv/bin', 0775, true);
                    file_put_contents($cwd.'/.venv/bin/python', 'fake');
                    chmod($cwd.'/.venv/bin/python', 0755);
                    file_put_contents($cwd.'/.venv/pyvenv.cfg', 'home=fake');
                }
                if (in_array('build', $command, true)) {
                    if (!is_dir($cwd.'/site')) {
                        mkdir($cwd.'/site');
                    }
                    file_put_contents($cwd.'/site/index.html', '<html>Local documentation</html>');
                }
            });
            $builder->build($root);
            self::assertFileExists($root.'/dev-doc/site/index.html');
            self::assertFileExists($root.'/dev-doc/requirements.txt');
            self::assertCount(3, $commands);
            self::assertContains('venv', $commands[0]);
            self::assertContains('pip', $commands[1]);
            self::assertContains('mkdocs', $commands[2]);

            $builder->build($root);
            self::assertCount(5, $commands);
            self::assertFileDoesNotExist($root.'/backend/public/dev-doc/index.html');
            self::assertDirectoryDoesNotExist($root.'/admin-doc/site');
            self::assertDirectoryDoesNotExist($root.'/user-doc/site');
        } finally {
            (new Process(['rm', '-rf', '--', $root]))->mustRun();
        }
    }

    public function testRefusesToWriteThroughSymbolicSiteDirectory(): void
    {
        $root = sys_get_temp_dir().'/symsvel-devdocs-'.bin2hex(random_bytes(6));
        mkdir($root);
        try {
            (new WorkspaceInitializer())->initialize($root);
            mkdir($root.'/elsewhere');
            symlink($root.'/elsewhere', $root.'/dev-doc/site');
            $builder = new DevDocumentationBuilder(static function (): void {
                self::fail('No external command should execute for symbolic site.');
            });
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('dev-doc invalide');
            $builder->build($root);
        } finally {
            (new Process(['rm', '-rf', '--', $root]))->mustRun();
        }
    }
}
