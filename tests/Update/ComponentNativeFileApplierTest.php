<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\ComponentNativeFileApplier;

final class ComponentNativeFileApplierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-applier-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/local', 0775, true);
        mkdir($this->root.'/upstream', 0775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testAppliesAddReplaceAndDeleteWithoutTouchingUnmanagedFile(): void
    {
        file_put_contents($this->root.'/local/replace.txt', 'old');
        file_put_contents($this->root.'/local/delete.txt', 'remove');
        file_put_contents($this->root.'/local/custom.txt', 'keep');
        file_put_contents($this->root.'/upstream/replace.txt', 'new');
        file_put_contents($this->root.'/upstream/add.txt', 'added');

        (new ComponentNativeFileApplier())->apply($this->root.'/local', $this->root.'/upstream', [
            'add' => ['add.txt'],
            'replace' => ['replace.txt'],
            'delete' => ['delete.txt'],
        ], ['replace.txt' => hash('sha256', 'old'), 'delete.txt' => hash('sha256', 'remove')]);

        self::assertSame('new', file_get_contents($this->root.'/local/replace.txt'));
        self::assertSame('added', file_get_contents($this->root.'/local/add.txt'));
        self::assertFileDoesNotExist($this->root.'/local/delete.txt');
        self::assertSame('keep', file_get_contents($this->root.'/local/custom.txt'));
    }

    public function testRefusesAdditionWhenLocalFileAppearsAfterPreflight(): void
    {
        file_put_contents($this->root.'/upstream/add.txt', 'upstream');
        file_put_contents($this->root.'/local/add.txt', 'project');

        try {
            (new ComponentNativeFileApplier())->apply($this->root.'/local', $this->root.'/upstream', [
                'add' => ['add.txt'], 'replace' => [], 'delete' => [],
            ], []);
            self::fail('Concurrent local addition must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('apparu depuis le préflight', $error->getMessage());
        }
        self::assertSame('project', file_get_contents($this->root.'/local/add.txt'));
    }

    public function testRefusesReplacementWhenLocalFileChangesAfterPreflight(): void
    {
        file_put_contents($this->root.'/upstream/replace.txt', 'upstream');
        file_put_contents($this->root.'/local/replace.txt', 'project edit');

        try {
            (new ComponentNativeFileApplier())->apply($this->root.'/local', $this->root.'/upstream', [
                'add' => [], 'replace' => ['replace.txt'], 'delete' => [],
            ], ['replace.txt' => hash('sha256', 'baseline')]);
            self::fail('Concurrent local replacement must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('modifié depuis le préflight', $error->getMessage());
        }
        self::assertSame('project edit', file_get_contents($this->root.'/local/replace.txt'));
    }

    public function testRefusesDeletionWhenFileChangesAfterPreflight(): void
    {
        file_put_contents($this->root.'/local/delete.txt', 'local edit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modifié ou chemin non fiable');
        try {
            (new ComponentNativeFileApplier())->apply($this->root.'/local', $this->root.'/upstream', [
                'add' => [], 'replace' => [], 'delete' => ['delete.txt'],
            ], ['delete.txt' => hash('sha256', 'baseline')]);
        } finally {
            self::assertSame('local edit', file_get_contents($this->root.'/local/delete.txt'));
        }
    }

    public function testRefusesSymlinkedParentWithoutWritingOutsideComponent(): void
    {
        mkdir($this->root.'/outside');
        symlink($this->root.'/outside', $this->root.'/local/redirect');
        mkdir($this->root.'/upstream/redirect');
        file_put_contents($this->root.'/upstream/redirect/file.txt', 'incoming');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Chemin local non fiable');
        try {
            (new ComponentNativeFileApplier())->apply($this->root.'/local', $this->root.'/upstream', [
                'add' => ['redirect/file.txt'], 'replace' => [], 'delete' => [],
            ], []);
        } finally {
            self::assertFileDoesNotExist($this->root.'/outside/file.txt');
        }
    }
}
