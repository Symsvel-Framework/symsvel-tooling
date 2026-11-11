<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleEntityMetadataSelector;

final class ModuleEntityMetadataSelectorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-entity-selector-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/catalogue/back/src/Entity', 0775, true);
        mkdir($this->workspace.'/neighbor/back/src/Entity', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (['catalogue', 'neighbor'] as $name) {
            rmdir($this->workspace.'/'.$name.'/back/src/Entity');
            rmdir($this->workspace.'/'.$name.'/back/src');
            rmdir($this->workspace.'/'.$name.'/back');
            rmdir($this->workspace.'/'.$name);
        }
        rmdir($this->workspace);
    }

    public function testSelectsOnlyEntityClassesFromNamedModule(): void
    {
        $inside = $this->makeMapping('catalogue', 'CatalogueEntity');
        $outside = $this->makeMapping('neighbor', 'NeighborEntity');

        $result = (new ModuleEntityMetadataSelector())->select(
            ['path' => $this->workspace.'/catalogue'],
            [$inside, $outside],
        );
        self::assertSame([$inside], $result);
    }

    public function testRejectsMissingEntityDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ModuleEntityMetadataSelector())->select(['path' => $this->workspace.'/missing'], []);
    }

    private function makeMapping(string $module, string $class): object
    {
        $file = $this->workspace.'/'.$module.'/back/src/Entity/'.$class.'.php';
        $name = 'SymSvelSelectorFixture'.bin2hex(random_bytes(8));
        file_put_contents($file, '<?php namespace '.$name.'; class '.$class.' {}');
        require $file;
        $reflection = new \ReflectionClass($name.'\\'.$class);

        return new class($reflection) {
            public function __construct(private readonly \ReflectionClass $reflection)
            {
            }

            public function getReflectionClass(): \ReflectionClass
            {
                return $this->reflection;
            }
        };
    }
}
