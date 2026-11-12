<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleDesiredSchema;

final class ModuleDesiredSchemaTest extends TestCase
{
    public function testMessengerTableIsExcludedWithoutRemovingModuleTables(): void
    {
        $schema = new class {
            /** @var array<string, true> */
            public array $tables = [
                'symsvel_test_records' => true,
                'messenger_messages' => true,
            ];

            public function hasTable(string $name): bool
            {
                return isset($this->tables[$name]);
            }

            public function dropTable(string $name): void
            {
                unset($this->tables[$name]);
            }
        };

        $filter = new \ReflectionMethod(ModuleDesiredSchema::class, 'excludeMessengerTransportTable');
        $filter->invoke(new ModuleDesiredSchema(), $schema);
        self::assertSame(['symsvel_test_records' => true], $schema->tables);

        // The filter is safe when Messenger is absent.
        $filter->invoke(new ModuleDesiredSchema(), $schema);
        self::assertSame(['symsvel_test_records' => true], $schema->tables);
    }
}
