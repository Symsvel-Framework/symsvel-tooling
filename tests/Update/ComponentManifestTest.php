<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\ComponentManifest;

final class ComponentManifestTest extends TestCase
{
    private string $backend;

    protected function setUp(): void
    {
        $this->backend = sys_get_temp_dir().'/symsvel-manifest-'.bin2hex(random_bytes(8)).'/backend';
        mkdir($this->backend, 0775, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', dirname($this->backend)]))->mustRun();
    }

    public function testRoundTripPreservesOtherComponentEntries(): void
    {
        $manifest = new ComponentManifest($this->backend);
        $app = ['src/routes/+page.svelte' => hash('sha256', 'app')];
        $bo = ['src/routes/+page.svelte' => hash('sha256', 'bo')];

        $manifest->set('app', 'revision-app', $app);
        $manifest->set('bo', 'revision-bo', $bo);
        self::assertSame(['revision' => 'revision-app', 'files' => $app], $manifest->get('app'));
        self::assertSame(['revision' => 'revision-bo', 'files' => $bo], $manifest->get('bo'));

        $manifest->set('app', 'revision-next', $app);
        self::assertSame(['revision' => 'revision-bo', 'files' => $bo], $manifest->get('bo'));
    }

    public function testRejectsInvalidNativeHash(): void
    {
        $this->writeManifest(['app' => [
            'revision' => 'revision',
            'files' => ['README.md' => 'not-a-sha256'],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empreinte native invalide');
        (new ComponentManifest($this->backend))->get('app');
    }

    public function testRejectsTraversalAndNonPortableNativePathsOnRead(): void
    {
        foreach (['../outside.txt', 'src/../../outside.txt', '/absolute.txt', 'src//file.txt', 'src/./file.txt', 'src\\\\file.txt', "src\\0file.txt"] as $path) {
            $this->writeManifest(['app' => ['revision' => 'revision', 'files' => [$path => hash('sha256', 'data')]]]);
            try {
                (new ComponentManifest($this->backend))->get('app');
                self::fail('Unsafe path accepted: '.json_encode($path));
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Empreinte native invalide', $error->getMessage());
            }
        }
    }

    public function testRejectsUnsafeNativePathsBeforeWritingManifest(): void
    {
        $manifest = new ComponentManifest($this->backend);
        $manifest->set('app', 'original', ['README.md' => hash('sha256', 'original')]);
        $before = file_get_contents($this->backend.'/.symsvel/components.json');

        $this->expectException(\InvalidArgumentException::class);
        try {
            $manifest->set('app', 'unsafe', ['../outside.txt' => hash('sha256', 'unsafe')]);
        } finally {
            self::assertSame($before, file_get_contents($this->backend.'/.symsvel/components.json'));
        }
    }

    public function testRejectsMalformedManifestWithoutOverwritingIt(): void
    {
        mkdir($this->backend.'/.symsvel');
        $file = $this->backend.'/.symsvel/components.json';
        file_put_contents($file, '{invalid-json');

        try {
            (new ComponentManifest($this->backend))->set('app', 'revision', []);
            self::fail('Malformed manifest must be rejected.');
        } catch (\JsonException) {
            self::assertSame('{invalid-json', file_get_contents($file));
        }
    }

    public function testRejectsSymlinkManifestWithoutOverwritingTarget(): void
    {
        mkdir($this->backend.'/.symsvel');
        $outside = dirname($this->backend).'/outside.json';
        file_put_contents($outside, '{}');
        symlink($outside, $this->backend.'/.symsvel/components.json');

        try {
            (new ComponentManifest($this->backend))->set('app', 'revision', []);
            self::fail('Symbolic manifest must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('symbolique', $error->getMessage());
        }
        self::assertSame('{}', file_get_contents($outside));
    }

    /** @param array<string, mixed> $data */
    private function writeManifest(array $data): void
    {
        mkdir($this->backend.'/.symsvel');
        file_put_contents(
            $this->backend.'/.symsvel/components.json',
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
