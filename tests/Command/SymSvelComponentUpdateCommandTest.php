<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use SymSvel\Tooling\Command\SymSvelComponentUpdateCommand;

final class SymSvelComponentUpdateCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-component-update-'.bin2hex(random_bytes(8));
        mkdir($this->workspace.'/backend', 0775, true);
        file_put_contents($this->workspace.'/backend/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        foreach (['bo', 'app', 'front'] as $component) {
            if (is_dir($this->workspace.'/'.$component)) {
                rmdir($this->workspace.'/'.$component);
            }
        }
        unlink($this->workspace.'/backend/composer.json');
        rmdir($this->workspace.'/backend');
        rmdir($this->workspace);
    }

    public function testRejectsUnknownComponentBeforeAnyInstallation(): void
    {
        $tester = $this->tester();
        self::assertSame(2, $tester->execute(['component' => 'unknown']));
        self::assertDirectoryDoesNotExist($this->workspace.'/unknown');
    }

    public function testRefusesExistingComponentWithoutChangingItsFiles(): void
    {
        mkdir($this->workspace.'/app');
        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['component' => 'app']));
        self::assertDirectoryExists($this->workspace.'/app');
    }

    private function tester(): CommandTester
    {
        $app = new Application();
        $command = new SymSvelComponentUpdateCommand($this->workspace.'/backend');
        $app->addCommand($command);
        return new CommandTester($app->find('symsvel:component:update'));
    }
}
