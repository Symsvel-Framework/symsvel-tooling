<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\WorkspaceNativeApply;

final class WorkspaceNativeRollbackTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-native-rollback-'.bin2hex(random_bytes(8));
        foreach (['backend/.symsvel', 'bo', 'app', 'upstream-bo', 'upstream-app', 'checkpoint'] as $directory) {
            mkdir($this->root.'/'.$directory, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testSourceSnapshotRestoresBothInterfacesAndManifestAfterDownstreamFailure(): void
    {
        $backend = $this->root.'/backend';
        file_put_contents($this->root.'/bo/native.txt', 'bo-before');
        file_put_contents($this->root.'/app/native.txt', 'app-before');
        file_put_contents($this->root.'/bo/project-only.txt', 'untouched');
        file_put_contents($this->root.'/upstream-bo/native.txt', 'bo-after');
        file_put_contents($this->root.'/upstream-app/native.txt', 'app-after');
        $manifest = new ComponentManifest($backend);
        $manifest->set('bo', 'old-bo', ['native.txt' => hash('sha256', 'bo-before')]);
        $manifest->set('app', 'old-app', ['native.txt' => hash('sha256', 'app-before')]);

        $snapshot = new WorkspaceFileSnapshot($backend, $this->root.'/checkpoint', ['backend', 'bo', 'app']);
        $snapshot->capture();
        $prepared = [];
        foreach (['bo', 'app'] as $component) {
            $before = $component.'-before';
            $after = $component.'-after';
            $prepared[$component] = [
                'revision' => 'new-'.$component,
                'incoming' => ['native.txt' => hash('sha256', $after)],
                'baseline' => ['native.txt' => hash('sha256', $before)],
                'plan' => ['add' => [], 'replace' => ['native.txt'], 'delete' => [], 'conflicts' => []],
                'upstream' => $this->root.'/upstream-'.$component,
            ];
        }

        try {
            (new WorkspaceNativeApply())->apply($backend, $prepared);
            self::assertSame('new-app', (new ComponentManifest($backend))->get('app')['revision']);
            throw new \RuntimeException('Simulated npm build failure');
        } catch (\RuntimeException $failure) {
            self::assertSame('Simulated npm build failure', $failure->getMessage());
            $snapshot->restore();
        }

        self::assertSame('bo-before', file_get_contents($this->root.'/bo/native.txt'));
        self::assertSame('app-before', file_get_contents($this->root.'/app/native.txt'));
        self::assertSame('untouched', file_get_contents($this->root.'/bo/project-only.txt'));
        self::assertSame('old-bo', (new ComponentManifest($backend))->get('bo')['revision']);
        self::assertSame('old-app', (new ComponentManifest($backend))->get('app')['revision']);
    }
}
