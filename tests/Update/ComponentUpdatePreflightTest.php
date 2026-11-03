<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\ComponentUpdatePreflight;

final class ComponentUpdatePreflightTest extends TestCase
{
    public function testReportsBothFileAndRouteConflictsBeforeAnyWrite(): void
    {
        $preflight = new ComponentUpdatePreflight();
        $plan = $preflight->inspect(
            ['src/lib/native.ts' => 'old'],
            ['src/lib/native.ts' => 'new', 'src/routes/(native)/hello/+page.svelte' => 'remote'],
            ['src/lib/native.ts' => 'local', 'src/routes/hello/+page.svelte' => 'local'],
        );

        self::assertCount(2, $plan['conflicts']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('src/lib/native.ts');
        $preflight->assertSafe($plan);
    }

    public function testAllowsIndependentRemoteAndLocalRoutes(): void
    {
        $preflight = new ComponentUpdatePreflight();
        $plan = $preflight->inspect(
            [],
            ['src/routes/ct-remote/+page.svelte' => 'remote'],
            ['src/routes/ct-local/+page.svelte' => 'local'],
        );

        $preflight->assertSafe($plan);
        self::assertSame(['src/routes/ct-remote/+page.svelte'], $plan['add']);
    }
}
