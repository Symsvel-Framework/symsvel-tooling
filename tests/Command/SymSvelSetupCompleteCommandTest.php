<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SymSvel\Tooling\Command\SymSvelSetupCompleteCommand;
use SymSvel\Tooling\Installation\SetupState;

final class SymSvelSetupCompleteCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-setup-complete-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->root.'/backend');
        mkdir($this->root.'/backend/bin');
        mkdir($this->root.'/bo');
        file_put_contents($this->root.'/backend/.env.local', "DATABASE_URL=sqlite:///%kernel.project_dir%/var/test.db\n");
        file_put_contents($this->root.'/bo/package.json', '{"scripts":{"build":"true"}}');
        (new SetupState())->initialize($this->root);
        (new SetupState())->transition($this->root, SetupState::PENDING, SetupState::RUNNING);
        (new SetupState())->transition($this->root, SetupState::RUNNING, SetupState::DATABASE_READY);
    }

    protected function tearDown(): void
    {
        foreach (['backend/bin/console', 'backend/verify.log', 'backend/.env.local', 'bo/package.json', 'bo/build.log', '.symsvel/setup.json'] as $path) {
            @unlink($this->root.'/'.$path);
        }
        @rmdir($this->root.'/backend/bin');
        @rmdir($this->root.'/backend');
        @rmdir($this->root.'/bo');
        @rmdir($this->root.'/.symsvel');
        @rmdir($this->root);
    }

    private function command(): CommandTester
    {
        return new CommandTester(new SymSvelSetupCompleteCommand($this->root.'/backend'));
    }

    private function fakeVerification(int $exitCode): void
    {
        file_put_contents($this->root.'/backend/bin/console', '<?php
file_put_contents(dirname(__DIR__)."/verify.log", "verify\\n", FILE_APPEND);
exit('.$exitCode.');
');
    }

    public function testFailedVerificationDoesNotCompleteSetup(): void
    {
        $this->fakeVerification(1);
        self::assertSame(1, $this->command()->execute([]));
        self::assertSame(SetupState::DATABASE_READY, (new SetupState())->read($this->root));
        self::assertFileDoesNotExist($this->root.'/bo/build.log');
    }

    public function testSuccessfulCompletionWithoutInterfaces(): void
    {
        $this->fakeVerification(0);
        unlink($this->root.'/bo/package.json');
        rmdir($this->root.'/bo');
        self::assertSame(0, $this->command()->execute([]));
        self::assertSame(SetupState::COMPLETED, (new SetupState())->read($this->root));
        self::assertSame(0, $this->command()->execute([]));
        self::assertCount(1, file($this->root.'/backend/verify.log'));
    }

    public function testInvalidComponentPreservesResumableState(): void
    {
        $this->fakeVerification(0);
        unlink($this->root.'/bo/package.json');
        self::assertSame(1, $this->command()->execute([]));
        self::assertSame(SetupState::DATABASE_READY, (new SetupState())->read($this->root));
    }
}
