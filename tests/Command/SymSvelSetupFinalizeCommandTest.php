<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SymSvel\Tooling\Command\SymSvelSetupFinalizeCommand;
use SymSvel\Tooling\Installation\SetupState;

final class SymSvelSetupFinalizeCommandTest extends TestCase
{
    public function testCannotFinalizeBeforeDatabaseConfiguration(): void
    {
        $root = sys_get_temp_dir().'/symsvel-finalize-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        try {
            $state = new SetupState();
            $state->initialize($root);
            $tester = new CommandTester(new SymSvelSetupFinalizeCommand($root.'/backend'));
            self::assertSame(1, $tester->execute([]));
            self::assertSame(SetupState::PENDING, $state->read($root));
            self::assertStringContainsString('DATABASE_URL', $tester->getDisplay());
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testCannotFinalizeWithoutInitializedSetup(): void
    {
        $root = sys_get_temp_dir().'/symsvel-finalize-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        try {
            $tester = new CommandTester(new SymSvelSetupFinalizeCommand($root.'/backend'));
            self::assertSame(1, $tester->execute([]));
            self::assertStringContainsString('Setup non initialisé', $tester->getDisplay());
        } finally {
            rmdir($root.'/backend');
            rmdir($root);
        }
    }
}
