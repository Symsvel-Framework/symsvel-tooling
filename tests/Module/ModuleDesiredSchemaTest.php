<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleDesiredSchema;

final class ModuleDesiredSchemaTest extends TestCase
{
    public function testAllowsEmptyOrmMetadataAfterLastEntityRemoval(): void
    {
        if (!class_exists(\Doctrine\ORM\Tools\SchemaTool::class)) {
            self::markTestSkipped('Doctrine ORM fourni par le projet hôte.');
        }
        $root = sys_get_temp_dir().'/symsvel-empty-entities-'.bin2hex(random_bytes(5));
        mkdir($root.'/back/src/Entity', 0775, true);
        try {
            $entityManager = new class {
                public function getMetadataFactory(): object
                {
                    return new class {
                        public function getAllMetadata(): array { return []; }
                    };
                }
            };
            $result = (new ModuleDesiredSchema())->build($entityManager, ['path' => $root]);
            self::assertSame([], $result['tables']);
            self::assertSame([], $result['schema']->getTables());
        } finally {
            rmdir($root.'/back/src/Entity');
            rmdir($root.'/back/src');
            rmdir($root.'/back');
            rmdir($root);
        }
    }

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
