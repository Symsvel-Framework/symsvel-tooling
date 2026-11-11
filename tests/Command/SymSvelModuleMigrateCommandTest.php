<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Command\SymSvelModuleMigrateCommand;

final class SymSvelModuleMigrateCommandTest extends TestCase
{
    public function testCommandDeclaresModuleAndWorkingDirectory(): void
    {
        $reflection = new \ReflectionClass(SymSvelModuleMigrateCommand::class);
        $attributes = $reflection->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);
        self::assertCount(1, $attributes);
        self::assertSame('symsvel:module:migrate', $attributes[0]->newInstance()->name);
        self::assertTrue($reflection->hasMethod('execute'));
        $bundle = (string) file_get_contents(dirname(__DIR__, 2).'/src/SymSvelToolingBundle.php');
        self::assertStringContainsString('->set(SymSvelModuleMigrateCommand::class)', $bundle, 'The command must be registered as a Symfony service.');
        self::assertStringContainsString("'cwd'", (string) file_get_contents(dirname(__DIR__, 2).'/src/Command/SymSvelModuleMigrateCommand.php'));
    }
}
