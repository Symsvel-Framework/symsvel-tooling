<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\ComponentFilePlanner;
use SymSvel\Tooling\Update\ComponentUpdatePreflight;

/**
 * Regression scenarios for repeated component updates and local ownership.
 *
 * The planner is pure: these checks do not require Git, npm or a module.
 */
final class ComponentLifecycleRegressionTest extends TestCase
{
    public function testRepeatedUpdateOfUnchangedReleaseIsNoOp(): void
    {
        $files = [
            'src/routes/+page.svelte' => 'sha-page',
            'src/lib/api.ts' => 'sha-api',
        ];

        self::assertSame(
            ['add' => [], 'replace' => [], 'delete' => [], 'conflicts' => []],
            (new ComponentFilePlanner())->plan($files, $files, $files),
        );
    }

    public function testAlreadyAppliedUpstreamFileIsNotOverwritten(): void
    {
        $plan = (new ComponentFilePlanner())->plan(
            ['src/lib/api.ts' => 'old'],
            ['src/lib/api.ts' => 'new'],
            ['src/lib/api.ts' => 'new'],
        );

        self::assertSame(
            ['add' => [], 'replace' => [], 'delete' => [], 'conflicts' => []],
            $plan,
        );
    }

    public function testIdenticalLocalAndNewUpstreamFileIsNotAConflict(): void
    {
        $plan = (new ComponentFilePlanner())->plan(
            [],
            ['src/lib/new.ts' => 'same'],
            ['src/lib/new.ts' => 'same'],
        );

        self::assertSame(
            ['add' => [], 'replace' => [], 'delete' => [], 'conflicts' => []],
            $plan,
        );
    }

    public function testLocalEditOfUnchangedNativeFileIsPreserved(): void
    {
        $plan = (new ComponentFilePlanner())->plan(
            ['src/lib/config.ts' => 'base'],
            ['src/lib/config.ts' => 'base'],
            ['src/lib/config.ts' => 'custom'],
        );

        self::assertSame(
            ['add' => [], 'replace' => [], 'delete' => [], 'conflicts' => []],
            $plan,
        );
    }

    public function testNewUpstreamFileAndUnrelatedLocalCustomizationCoexist(): void
    {
        $plan = (new ComponentUpdatePreflight())->inspect(
            ['src/lib/config.ts' => 'base'],
            [
                'src/lib/config.ts' => 'base',
                'src/routes/new-feature/+page.svelte' => 'remote',
            ],
            [
                'src/lib/config.ts' => 'custom',
                'src/routes/local-feature/+page.svelte' => 'local',
            ],
        );

        (new ComponentUpdatePreflight())->assertSafe($plan);
        self::assertSame(['src/routes/new-feature/+page.svelte'], $plan['add']);
        self::assertSame([], $plan['replace']);
        self::assertSame([], $plan['delete']);
        self::assertSame([], $plan['conflicts']);
    }

    public function testDeletedUpstreamFileWithLocalEditsIsRejected(): void
    {
        $preflight = new ComponentUpdatePreflight();
        $plan = $preflight->inspect(
            ['src/lib/legacy.ts' => 'base'],
            [],
            ['src/lib/legacy.ts' => 'custom'],
        );

        self::assertSame(['src/lib/legacy.ts'], $plan['conflicts']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('src/lib/legacy.ts');
        $preflight->assertSafe($plan);
    }
}
