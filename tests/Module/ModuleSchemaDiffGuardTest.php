<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleSchemaDiffGuard;

final class ModuleSchemaDiffGuardTest extends TestCase
{
    public function testAllowsCreationOnly(): void
    {
        (new ModuleSchemaDiffGuard())->assertNonDestructive($this->diff([], []));
        self::assertTrue(true);
    }

    public function testRejectsDroppedTables(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Suppression de table');
        (new ModuleSchemaDiffGuard())->assertNonDestructive($this->diff(['legacy'], []));
    }

    public function testRejectsChangedTables(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Modification de table');
        (new ModuleSchemaDiffGuard())->assertNonDestructive($this->diff([], ['orders']));
    }

    public function testRejectsUnknownDiffShape(): void
    {
        $this->expectException(\DomainException::class);
        (new ModuleSchemaDiffGuard())->assertNonDestructive(new \stdClass());
    }

    private function diff(array $dropped, array $changed): object
    {
        return new class($dropped, $changed) {
            public function __construct(private array $dropped, private array $changed) {}
            public function getDroppedSchemas(): array { return []; }
            public function getDroppedSequences(): array { return []; }
            public function getAlteredSequences(): array { return []; }
            public function getDroppedTables(): array { return $this->dropped; }
            public function getAlteredTables(): array { return $this->changed; }
        };
    }
}
