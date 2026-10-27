<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Command\SymSvelModuleMigrationsRecoveryFinalizeCommand;
use SymSvel\Tooling\SymSvelToolingBundle;

final class RecoveryFinalizeRegistrationTest extends TestCase
{
    public function testFinalizeCommandIsExplicitlyRegistered(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/SymSvelToolingBundle.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            '->set(SymSvelModuleMigrationsRecoveryFinalizeCommand::class)',
            $source,
        );
        self::assertTrue(class_exists(SymSvelModuleMigrationsRecoveryFinalizeCommand::class));
        self::assertTrue(class_exists(SymSvelToolingBundle::class));
    }
}
