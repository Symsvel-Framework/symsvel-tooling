<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\ComponentRouteCollisionGuard;

final class ComponentRouteCollisionGuardTest extends TestCase
{
    public function testRouteGroupDoesNotHideCollision(): void
    {
        $guard = new ComponentRouteCollisionGuard();
        self::assertSame(
            ['src/routes/(native)/hello/+page.svelte <=> src/routes/hello/+page.svelte'],
            $guard->conflicts(
                [],
                ['src/routes/(native)/hello/+page.svelte' => 'remote'],
                ['src/routes/hello/+page.svelte' => 'local'],
            ),
        );
    }

    public function testIndependentRoutesDoNotCollide(): void
    {
        $guard = new ComponentRouteCollisionGuard();
        self::assertSame([], $guard->conflicts(
            [],
            ['src/routes/ct-remote/+page.svelte' => 'remote'],
            ['src/routes/ct-local/+page.svelte' => 'local'],
        ));
    }

    public function testPreexistingNativeRouteIsNotReported(): void
    {
        $guard = new ComponentRouteCollisionGuard();
        self::assertSame([], $guard->conflicts(
            ['src/routes/hello/+page.svelte' => 'old'],
            ['src/routes/hello/+page.svelte' => 'new'],
            ['src/routes/hello/+page.svelte' => 'local'],
        ));
    }
}
