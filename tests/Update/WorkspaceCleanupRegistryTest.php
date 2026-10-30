<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\WorkspaceCleanupRegistry;

final class WorkspaceCleanupRegistryTest extends TestCase
{
    public function testEnqueueAndCleanupCommittedTransaction(): void
    {
        $backend = sys_get_temp_dir().'/symsvel-cleanup-'.bin2hex(random_bytes(8)).'/backend';
        mkdir($backend.'/.symsvel/transactions/update-'.str_repeat('a', 24), 0700, true);
        $path = $backend.'/.symsvel/transactions/update-'.str_repeat('a', 24);
        file_put_contents($path.'/snapshot.txt', 'old');
        try {
            $registry = new WorkspaceCleanupRegistry($backend);
            $registry->enqueue([$path]);
            self::assertFileExists($backend.'/.symsvel/cleanup-pending.json');
            $result = $registry->cleanup();
            self::assertSame(1, $result['removed']);
            self::assertSame(0, $result['remaining']);
            self::assertSame([], $result['errors']);
            self::assertDirectoryDoesNotExist($path);
            self::assertSame([], json_decode((string) file_get_contents($backend.'/.symsvel/cleanup-pending.json'), true));
            self::assertSame(0, $registry->cleanup()['removed']);
        } finally {
            $this->remove($backend.'/..');
        }
    }

    public function testRejectsArbitraryPaths(): void
    {
        $backend = sys_get_temp_dir().'/symsvel-cleanup-'.bin2hex(random_bytes(8)).'/backend';
        mkdir($backend.'/.symsvel/transactions', 0700, true);
        try {
            $registry = new WorkspaceCleanupRegistry($backend);
            $this->expectException(\RuntimeException::class);
            $registry->enqueue([$backend.'/composer.json']);
        } finally {
            $this->remove($backend.'/..');
        }
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->remove($entry->getPathname());
        }
        rmdir($path);
    }
}
