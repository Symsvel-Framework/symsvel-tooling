<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleTableOwnershipGuard;

final class ModuleTableOwnershipGuardTest extends TestCase
{
    public function testAllowsExclusivelyOwnedTables(): void
    {
        (new ModuleTableOwnershipGuard())->assertExclusive([
            'catalogue' => ['manifest' => ['tables' => ['catalogue_items']]],
            'users' => ['manifest' => ['tables' => ['users']]],
        ], 'catalogue', ['catalogue_items']);
        self::assertTrue(true);
    }

    public function testRejectsConflictingDeclarations(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('déclarée par plusieurs modules');
        (new ModuleTableOwnershipGuard())->assertExclusive([
            'catalogue' => ['manifest' => ['tables' => ['shared']]],
            'users' => ['manifest' => ['tables' => ['shared']]],
        ], 'catalogue', ['shared']);
    }

    public function testRejectsUndeclaredTargetTable(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('non attribuée exclusivement');
        (new ModuleTableOwnershipGuard())->assertExclusive([
            'catalogue' => ['manifest' => ['tables' => ['catalogue_items']]],
        ], 'catalogue', ['catalogue_tags']);
    }

    public function testRejectsMissingSelectedModule(): void
    {
        $this->expectException(\DomainException::class);
        (new ModuleTableOwnershipGuard())->assertExclusive([], 'missing', []);
    }
}
