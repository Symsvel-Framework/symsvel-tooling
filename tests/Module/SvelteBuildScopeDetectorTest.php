<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\SvelteBuildScopeDetector;

final class SvelteBuildScopeDetectorTest extends TestCase
{
    public function testDetectsOnlyChangedApplicationsIncludingRemovals(): void
    {
        $detector = new SvelteBuildScopeDetector();
        $before = [
            '/workspace/bo/src/lib/a.svelte' => str_repeat('a', 64),
            '/workspace/app/src/lib/b.svelte' => str_repeat('b', 64),
            '/workspace/front/src/lib/c.svelte' => str_repeat('c', 64),
        ];
        $after = [
            '/workspace/bo/src/lib/a.svelte' => str_repeat('a', 64),
            '/workspace/app/src/lib/b.svelte' => str_repeat('d', 64),
        ];
        self::assertSame(['app', 'front'], $detector->changedScopes('/workspace', $before, $after));
        self::assertSame([], $detector->changedScopes('/workspace', $before, $before));
        self::assertSame(['app'], $detector->changedScopes('/workspace',
            ['app/src/lib/b.svelte' => str_repeat('b', 64)],
            ['app/src/lib/b.svelte' => str_repeat('d', 64)],
        ));
        self::assertSame(['bo'], $detector->changedScopes('/workspace', [], [
            '/workspace/bo/src/routes/new/+page.svelte' => str_repeat('e', 64),
        ]));
    }

    public function testDoesNotConfuseApplicationPrefixWithAnotherDirectory(): void
    {
        $detector = new SvelteBuildScopeDetector();
        self::assertSame([], $detector->changedScopes('/workspace', [], [
            '/workspace/bo-extra/src/lib/test.svelte' => str_repeat('a', 64),
        ]));
    }

    public function testReadsMissingAndExistingInventory(): void
    {
        $backend = sys_get_temp_dir().'/symsvel-build-scopes-'.bin2hex(random_bytes(6));
        mkdir($backend.'/.symsvel', 0775, true);
        try {
            $detector = new SvelteBuildScopeDetector();
            self::assertSame([], $detector->readInventory($backend));
            $expected = ['bo/src/lib/a.svelte' => str_repeat('a', 64)];
            file_put_contents($backend.'/.symsvel/svelte-files.json', json_encode($expected, JSON_THROW_ON_ERROR));
            self::assertSame($expected, $detector->readInventory($backend));
            file_put_contents($backend.'/.symsvel/svelte-files.json', json_encode([
                '/Volumes/Workspace/www.choralys.com/bo/src/lib/a.svelte' => str_repeat('a', 64),
            ], JSON_THROW_ON_ERROR));
            self::assertSame($expected, $detector->readInventory($backend));
        } finally {
            @unlink($backend.'/.symsvel/svelte-files.json');
            rmdir($backend.'/.symsvel');
            rmdir($backend);
        }
    }
}
