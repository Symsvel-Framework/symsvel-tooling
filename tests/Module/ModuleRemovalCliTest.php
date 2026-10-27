<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Command\SymSvelModuleRemovalMigrationsExecuteCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ModuleRemovalCliTest extends TestCase
{
    public function testExecuteRejectsMissingConfirmationBeforeDatabaseAccess(): void
    {
        $tester = new CommandTester(new SymSvelModuleRemovalMigrationsExecuteCommand(sys_get_temp_dir()));
        self::assertSame(Command::INVALID, $tester->execute(['module' => 'users']));
        self::assertStringContainsString('Confirmation obligatoire', $tester->getDisplay());
    }

    public function testExecuteRejectsMalformedConfirmationBeforeDatabaseAccess(): void
    {
        $tester = new CommandTester(new SymSvelModuleRemovalMigrationsExecuteCommand(sys_get_temp_dir()));
        self::assertSame(Command::INVALID, $tester->execute(['module' => 'users', '--confirm' => 'yes']));
        self::assertStringContainsString('Confirmation obligatoire', $tester->getDisplay());
    }
}
