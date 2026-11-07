<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\WorkspaceNativeApply;

final class WorkspaceNativeApplyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-workspace-apply-'.bin2hex(random_bytes(8));
        foreach (['backend', 'bo', 'app', 'upstream-bo', 'upstream-app'] as $directory) {
            mkdir($this->root.'/'.$directory, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testAppliesAllPlansAndCommitsProvenance(): void
    {
        file_put_contents($this->root.'/bo/native.txt', 'old');
        file_put_contents($this->root.'/bo/local.txt', 'custom');
        file_put_contents($this->root.'/upstream-bo/native.txt', 'new');
        file_put_contents($this->root.'/upstream-app/new.txt', 'added');
        $prepared = [
            'bo' => $this->entry('bo', ['add' => [], 'replace' => ['native.txt'], 'delete' => [], 'conflicts' => []], ['native.txt' => hash('sha256', 'old')], ['native.txt' => hash('sha256', 'new')]),
            'app' => $this->entry('app', ['add' => ['new.txt'], 'replace' => [], 'delete' => [], 'conflicts' => []], [], ['new.txt' => hash('sha256', 'added')]),
        ];

        (new WorkspaceNativeApply())->apply($this->root.'/backend', $prepared);

        self::assertSame('new', file_get_contents($this->root.'/bo/native.txt'));
        self::assertSame('custom', file_get_contents($this->root.'/bo/local.txt'));
        self::assertSame('added', file_get_contents($this->root.'/app/new.txt'));
        $manifest = new ComponentManifest($this->root.'/backend');
        self::assertSame('revision-bo', $manifest->get('bo')['revision']);
        self::assertSame('revision-app', $manifest->get('app')['revision']);
    }

    public function testRejectsLateConflictBeforeMutatingAnyComponent(): void
    {
        file_put_contents($this->root.'/bo/native.txt', 'old');
        file_put_contents($this->root.'/upstream-bo/native.txt', 'new');
        $prepared = [
            'bo' => $this->entry('bo', ['add' => [], 'replace' => ['native.txt'], 'delete' => [], 'conflicts' => []], ['native.txt' => hash('sha256', 'old')], ['native.txt' => hash('sha256', 'new')]),
            'app' => $this->entry('app', ['add' => [], 'replace' => [], 'delete' => [], 'conflicts' => ['conflict.txt']], [], []),
        ];

        $this->expectException(\RuntimeException::class);
        try {
            (new WorkspaceNativeApply())->apply($this->root.'/backend', $prepared);
        } finally {
            self::assertSame('old', file_get_contents($this->root.'/bo/native.txt'));
            self::assertNull((new ComponentManifest($this->root.'/backend'))->get('bo'));
        }
    }

    public function testTraversalInLastComponentIsRejectedBeforeFirstWrite(): void
    {
        file_put_contents($this->root.'/bo/native.txt', 'old');
        file_put_contents($this->root.'/upstream-bo/native.txt', 'new');
        $prepared = [
            'bo' => $this->entry('bo', [
                'add' => [], 'replace' => ['native.txt'], 'delete' => [], 'conflicts' => [],
            ], ['native.txt' => hash('sha256', 'old')], ['native.txt' => hash('sha256', 'new')]),
            'app' => $this->entry('app', [
                'add' => ['../outside.txt'], 'replace' => [], 'delete' => [], 'conflicts' => [],
            ], [], ['../outside.txt' => hash('sha256', 'unsafe')]),
        ];

        try {
            (new WorkspaceNativeApply())->apply($this->root.'/backend', $prepared);
            self::fail('Traversal must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Chemin natif invalide', $error->getMessage());
        }
        self::assertSame('old', file_get_contents($this->root.'/bo/native.txt'));
        self::assertNull((new ComponentManifest($this->root.'/backend'))->get('bo'));
    }

    public function testNativeUpdatePreservesProjectGitMetadataAndUnmanagedChanges(): void
    {
        mkdir($this->root.'/bo/.git');
        file_put_contents($this->root.'/bo/.git/HEAD', "ref: refs/heads/project-feature\n");
        file_put_contents($this->root.'/bo/native.txt', 'old');
        file_put_contents($this->root.'/bo/project-only.txt', 'local changes');
        file_put_contents($this->root.'/upstream-bo/native.txt', 'new');

        (new WorkspaceNativeApply())->apply($this->root.'/backend', [
            'bo' => $this->entry('bo', [
                'add' => [], 'replace' => ['native.txt'], 'delete' => [], 'conflicts' => [],
            ], ['native.txt' => hash('sha256', 'old')], ['native.txt' => hash('sha256', 'new')]),
        ]);

        self::assertSame("ref: refs/heads/project-feature\n", file_get_contents($this->root.'/bo/.git/HEAD'));
        self::assertSame('local changes', file_get_contents($this->root.'/bo/project-only.txt'));
        self::assertSame('new', file_get_contents($this->root.'/bo/native.txt'));
    }

    public function testLateLocalChangeInLastComponentStopsEarlierComponentBeforeWriting(): void
    {
        file_put_contents($this->root.'/bo/native.txt', 'old');
        file_put_contents($this->root.'/upstream-bo/native.txt', 'new');
        file_put_contents($this->root.'/app/native.txt', 'locally edited');
        file_put_contents($this->root.'/upstream-app/native.txt', 'upstream');
        $prepared = [
            'bo' => $this->entry('bo', ['add' => [], 'replace' => ['native.txt'], 'delete' => [], 'conflicts' => []], ['native.txt' => hash('sha256', 'old')], ['native.txt' => hash('sha256', 'new')]),
            'app' => $this->entry('app', ['add' => [], 'replace' => ['native.txt'], 'delete' => [], 'conflicts' => []], ['native.txt' => hash('sha256', 'baseline')], ['native.txt' => hash('sha256', 'upstream')]),
        ];

        try {
            (new WorkspaceNativeApply())->apply($this->root.'/backend', $prepared);
            self::fail('Late conflict must stop all components.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Modification locale concurrente : app/native.txt', $error->getMessage());
        }
        self::assertSame('old', file_get_contents($this->root.'/bo/native.txt'));
        self::assertSame('locally edited', file_get_contents($this->root.'/app/native.txt'));
        self::assertNull((new ComponentManifest($this->root.'/backend'))->get('bo'));
    }

    /** @param array{add: list<string>, replace: list<string>, delete: list<string>, conflicts: list<string>} $plan
     * @param array<string, string> $baseline
     * @param array<string, string> $incoming
     * @return array{revision: string, incoming: array<string, string>, baseline: array<string, string>, plan: array{add: list<string>, replace: list<string>, delete: list<string>, conflicts: list<string>}, upstream: string}
     */
    private function entry(string $component, array $plan, array $baseline, array $incoming): array
    {
        return [
            'revision' => 'revision-'.$component,
            'incoming' => $incoming,
            'baseline' => $baseline,
            'plan' => $plan,
            'upstream' => $this->root.'/upstream-'.$component,
        ];
    }
}
