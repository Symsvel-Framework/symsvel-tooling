<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use SymSvel\Tooling\Command\SymSvelInstallCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;

final class SymSvelInstallCommandTest extends TestCase
{
    public function testLegacyFlatSymfonyLayoutIsRejected(): void
    {
        $dir = sys_get_temp_dir().'/symsvel-flat-'.bin2hex(random_bytes(8));
        mkdir($dir);
        try {
            $tester = new CommandTester(new SymSvelInstallCommand($dir));
            self::assertSame(1, $tester->execute(['--components' => 'bo'], ['interactive' => false]));
            self::assertDirectoryDoesNotExist($dir.'/bo');
        } finally {
            rmdir($dir);
        }
    }

    public function testInvalidExplicitChoiceIsRejected(): void
    {
        $command = new SymSvelInstallCommand(sys_get_temp_dir());
        $tester = new CommandTester($command);
        self::assertSame(2, $tester->execute(['--components' => 'invalid'], ['interactive' => false]));
    }

    public function testExplicitChoiceChecksExistingDestinationWithoutPrompt(): void
    {
        $projectDir = sys_get_temp_dir().'/symsvel-install-test-'.bin2hex(random_bytes(8));
        mkdir($projectDir);
        mkdir($projectDir.'/backend');
        file_put_contents($projectDir.'/backend/composer.json', '{}');
        mkdir($projectDir.'/front');
        file_put_contents($projectDir.'/front/keep.txt', 'preserved');
        try {
            $command = new SymSvelInstallCommand($projectDir.'/backend');
            $tester = new CommandTester($command);
            self::assertSame(1, $tester->execute(['--components' => 'all'], ['interactive' => false]));
            self::assertSame('preserved', file_get_contents($projectDir.'/front/keep.txt'));
            self::assertDirectoryDoesNotExist($projectDir.'/bo');
            self::assertDirectoryDoesNotExist($projectDir.'/app');
        } finally {
            unlink($projectDir.'/front/keep.txt');
            rmdir($projectDir.'/front');
            unlink($projectDir.'/backend/composer.json');
            rmdir($projectDir.'/backend');
            rmdir($projectDir);
        }
    }

    public function testNonInteractiveInstallationIsRejectedWithoutCreatingDirectories(): void
    {
        $projectDir = sys_get_temp_dir().'/symsvel-install-test-'.bin2hex(random_bytes(8));
        mkdir($projectDir);
        mkdir($projectDir.'/backend');
        file_put_contents($projectDir.'/backend/composer.json', '{}');
        try {
            $command = new SymSvelInstallCommand($projectDir.'/backend');
            $command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));
            $tester = new CommandTester($command);
            self::assertSame(2, $tester->execute([], ['interactive' => false]));
            self::assertDirectoryDoesNotExist($projectDir.'/bo');
        } finally {
            unlink($projectDir.'/backend/composer.json');
            rmdir($projectDir.'/backend');
            rmdir($projectDir);
        }
    }

    public function testExistingBoDirectoryIsPreservedDuringWorkspaceBootstrap(): void
    {
        $projectDir = sys_get_temp_dir().'/symsvel-install-test-'.bin2hex(random_bytes(8));
        mkdir($projectDir);
        mkdir($projectDir.'/backend');
        file_put_contents($projectDir.'/backend/composer.json', '{}');
        mkdir($projectDir.'/bo');
        file_put_contents($projectDir.'/bo/keep.txt', 'preserved');
        try {
            $command = new SymSvelInstallCommand($projectDir.'/backend');
            $command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));
            $tester = new CommandTester($command);
            $tester->setInputs(['1']);
            self::assertSame(0, $tester->execute([]));
            self::assertSame('preserved', file_get_contents($projectDir.'/bo/keep.txt'));
            self::assertFileExists($projectDir.'/dev-doc/site/index.html');
        } finally {
            (new \Symfony\Component\Process\Process(['rm', '-rf', '--', $projectDir.'/dev-doc', $projectDir.'/secrets']))->run();
            // Workspace bootstrap creates persistent setup state and a one-time token.
            // Remove both test-owned artifacts before deleting the temporary workspace.
            @unlink($projectDir.'/.symsvel/setup-token');
            @unlink($projectDir.'/.symsvel/setup.json');
            @rmdir($projectDir.'/.symsvel');
            unlink($projectDir.'/bo/keep.txt');
            rmdir($projectDir.'/bo');
            unlink($projectDir.'/backend/composer.json');
            rmdir($projectDir.'/backend');
            rmdir($projectDir);
        }
    }
    public function testAppSelectionChecksAppDirectoryBeforeCloning(): void
    {
        $this->assertSelectedComponentIsChecked('2', 'app');
    }

    public function testFrontSelectionChecksFrontDirectoryBeforeCloning(): void
    {
        $this->assertSelectedComponentIsChecked('3', 'front');
    }

    public function testCombinedSelectionChecksFrontDirectoryBeforeCloning(): void
    {
        $this->assertSelectedComponentIsChecked('4', 'front');
    }

    private function assertSelectedComponentIsChecked(string $choice, string $component): void
    {
        $projectDir = sys_get_temp_dir().'/symsvel-install-test-'.bin2hex(random_bytes(8));
        mkdir($projectDir);
        mkdir($projectDir.'/backend');
        file_put_contents($projectDir.'/backend/composer.json', '{}');
        mkdir($projectDir.'/'.$component);
        file_put_contents($projectDir.'/'.$component.'/keep.txt', 'preserved');
        try {
            $command = new SymSvelInstallCommand($projectDir.'/backend');
            $command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));
            $tester = new CommandTester($command);
            $tester->setInputs([$choice]);
            self::assertSame(1, $tester->execute([]));
            self::assertSame('preserved', file_get_contents($projectDir.'/'.$component.'/keep.txt'));
            self::assertDirectoryDoesNotExist($projectDir.'/bo');
        } finally {
            unlink($projectDir.'/'.$component.'/keep.txt');
            rmdir($projectDir.'/'.$component);
            unlink($projectDir.'/backend/composer.json');
            rmdir($projectDir.'/backend');
            rmdir($projectDir);
        }
    }
    public function testExistingSelectedInterfacesAllowWorkspaceBootstrapWithoutModification(): void
    {
        $root = sys_get_temp_dir().'/symsvel-reinstall-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        file_put_contents($root.'/backend/composer.json', '{}');
        mkdir($root.'/bo');
        file_put_contents($root.'/bo/keep.txt', 'preserved');
        try {
            $tester = new CommandTester(new SymSvelInstallCommand($root.'/backend'));
            self::assertSame(0, $tester->execute(['--components' => 'bo'], ['interactive' => false]));
            self::assertSame('preserved', file_get_contents($root.'/bo/keep.txt'));
            self::assertFileExists($root.'/dev-doc/docs/index.md');
            self::assertFileDoesNotExist($root.'/dev-doc/site/index.html');
            self::assertFileExists($root.'/secrets/.env.example');
        } finally {
            (new \Symfony\Component\Process\Process(['rm', '-rf', '--', $root]))->run();
        }
    }

}
