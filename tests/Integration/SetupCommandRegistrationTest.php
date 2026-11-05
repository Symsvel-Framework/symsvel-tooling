<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Command\SymSvelSetupFinalizeCommand;
use SymSvel\Tooling\SymSvelToolingBundle;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Regression contract for the web wizard's Symfony console entry point.
 * A command class alone is not enough: the tooling bundle explicitly registers services.
 */
final class SetupCommandRegistrationTest extends TestCase
{
    public function testWizardFinalizationCommandIsRegisteredByToolingBundle(): void
    {
        $attribute = (new \ReflectionClass(SymSvelSetupFinalizeCommand::class))
            ->getAttributes(AsCommand::class);
        self::assertCount(1, $attribute);
        self::assertSame('symsvel:setup:finalize', $attribute[0]->newInstance()->name);

        $source = file_get_contents((new \ReflectionClass(SymSvelToolingBundle::class))->getFileName());
        self::assertIsString($source);
        self::assertStringContainsString(
            '->set(SymSvelSetupFinalizeCommand::class)',
            $source,
            'The tooling bundle must explicitly register the setup finalization command.'
        );
    }
}
