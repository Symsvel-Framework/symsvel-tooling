<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\GeneratedSvelteStatusGuard;

final class GeneratedSvelteStatusGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-generated-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/backend/.symsvel', 0775, true);
        mkdir($this->root.'/app/src/lib/vendor/demo', 0775, true);
        file_put_contents($this->root.'/app/src/lib/vendor/demo/example.svelte', 'generated');
        $path = $this->root.'/app/src/lib/vendor/demo/example.svelte';
        file_put_contents($this->root.'/backend/.symsvel/svelte-files.json', json_encode([$path => hash('sha256', 'generated')], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new \Symfony\Component\Process\Process(['rm', '-rf', '--', $this->root]))->mustRun();
    }

    public function testAcceptsRegisteredUntrackedFile(): void
    {
        (new GeneratedSvelteStatusGuard())->assertSafe(
            $this->root.'/backend',
            $this->root.'/app',
            "?? src/lib/vendor/demo/example.svelte\0",
        );
        self::assertTrue(true);
    }

    public function testRejectsModifiedGeneratedFile(): void
    {
        file_put_contents($this->root.'/app/src/lib/vendor/demo/example.svelte', 'edited');
        $this->expectException(\RuntimeException::class);
        (new GeneratedSvelteStatusGuard())->assertSafe(
            $this->root.'/backend',
            $this->root.'/app',
            "?? src/lib/vendor/demo/example.svelte\0",
        );
    }

    public function testRejectsTrackedChangesEvenWhenRegistered(): void
    {
        $this->expectException(\RuntimeException::class);
        (new GeneratedSvelteStatusGuard())->assertSafe(
            $this->root.'/backend',
            $this->root.'/app',
            " M src/lib/vendor/demo/example.svelte\0",
        );
    }

    public function testRejectsUnknownUntrackedFile(): void
    {
        $this->expectException(\RuntimeException::class);
        (new GeneratedSvelteStatusGuard())->assertSafe(
            $this->root.'/backend',
            $this->root.'/app',
            "?? src/local.svelte\0",
        );
    }
}
