<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleSchemaMigrationGenerator;

final class ModuleSchemaMigrationGeneratorTest extends TestCase
{
    public function testExposesExpectedGenerationContract(): void
    {
        $method = new \ReflectionMethod(ModuleSchemaMigrationGenerator::class, 'generate');
        self::assertSame(6, $method->getNumberOfParameters());
        self::assertSame('workspace', $method->getParameters()[0]->getName());
        self::assertSame('connection', $method->getParameters()[4]->getName());
    }
}
