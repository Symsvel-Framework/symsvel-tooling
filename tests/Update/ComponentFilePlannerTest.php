<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\ComponentFilePlanner;

final class ComponentFilePlannerTest extends TestCase
{
    public function testAddsIndependentRemoteRouteWhilePreservingLocalRoute(): void
    {
        $plan = (new ComponentFilePlanner())->plan(
            ['src/routes/+page.svelte' => 'base'],
            ['src/routes/+page.svelte' => 'base', 'src/routes/ct-remote/+page.svelte' => 'remote'],
            ['src/routes/+page.svelte' => 'base', 'src/routes/ct-local/+page.svelte' => 'local'],
        );

        self::assertSame(['src/routes/ct-remote/+page.svelte'], $plan['add']);
        self::assertSame([], $plan['conflicts']);
        self::assertSame([], $plan['replace']);
    }

    public function testReplacesOnlyUntouchedNativeFile(): void
    {
        $plan = (new ComponentFilePlanner())->plan(
            ['native.txt' => 'old'],
            ['native.txt' => 'new'],
            ['native.txt' => 'old'],
        );
        self::assertSame(['native.txt'], $plan['replace']);
    }

    public function testRefusesConcurrentEditsAndNewFileCollision(): void
    {
        $plan = (new ComponentFilePlanner())->plan(
            ['native.txt' => 'old'],
            ['native.txt' => 'new', 'src/routes/hello/+page.svelte' => 'remote'],
            ['native.txt' => 'local', 'src/routes/hello/+page.svelte' => 'local'],
        );
        self::assertSame(['native.txt', 'src/routes/hello/+page.svelte'], $plan['conflicts']);
    }

    public function testDeletesOnlyUnmodifiedNativeFile(): void
    {
        $planner = new ComponentFilePlanner();
        self::assertSame(['native.txt'], $planner->plan(['native.txt' => 'old'], [], ['native.txt' => 'old'])['delete']);
        self::assertSame(['native.txt'], $planner->plan(['native.txt' => 'old'], [], ['native.txt' => 'edited'])['conflicts']);
    }
}
