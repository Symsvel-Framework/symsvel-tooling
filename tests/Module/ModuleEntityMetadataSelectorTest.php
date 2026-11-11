<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleEntityMetadataSelector;

final class ModuleEntityMetadataSelectorTest extends TestCase
{
    public function testKeepsOnlyClassesInsideModuleEntityDirectory(): void
    {
        $root = dirname(__DIR__, 2);
        $selector = new ModuleEntityMetadataSelector();
        $inside = new class {
            public function getReflectionClass(): \ReflectionClass
            {
                return new \ReflectionClass(\SymSvel\Tooling\Tests\Module\ModuleEntityMetadataSelectorTest::class);
            }
        };
        $outside = new class {
            public function getReflectionClass(): \ReflectionClass
            {
                return new \ReflectionClass(\SymSvel\Tooling\Module\ModuleEntityMetadataSelector::class);
            }
        };
        $result = $selector->select(['path' => $root], [$inside, $outside]);
        self::assertSame([], $result);
    }

    public function testRejectsMissingEntityDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ModuleEntityMetadataSelector())->select(['path' => sys_get_temp_dir().'/missing-symsvel-module'], []);
    }
}
