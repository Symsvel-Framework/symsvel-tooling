<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\RuntimeArtifactSnapshot;

final class RuntimeArtifactSnapshotTest extends TestCase
{
    public function testRestoresNpmDependenciesAndBuildAfterFailure(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-'.bin2hex(random_bytes(8));
        $component = $root.'/bo';
        $transaction = $root.'/transaction';
        mkdir($component.'/node_modules/pkg', 0775, true);
        mkdir($component.'/build', 0775, true);
        file_put_contents($component.'/node_modules/pkg/index.js', 'original');
        file_put_contents($component.'/build/index.html', 'old build');
        $snapshot = new RuntimeArtifactSnapshot($component, $transaction);
        try {
            $snapshot->capture();
            file_put_contents($component.'/node_modules/pkg/index.js', 'broken');
            unlink($component.'/build/index.html');
            file_put_contents($component.'/build/extra.html', 'partial');
            mkdir($component.'/dist');
            file_put_contents($component.'/dist/new.js', 'partial');
            $snapshot->restore();
            self::assertSame('original', file_get_contents($component.'/node_modules/pkg/index.js'));
            self::assertSame('old build', file_get_contents($component.'/build/index.html'));
            self::assertFileDoesNotExist($component.'/build/extra.html');
            self::assertDirectoryDoesNotExist($component.'/dist');
        } finally {
            $snapshot->discard();
            $this->deleteTree($root);
        }
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->deleteTree($entry->getPathname());
        }
        rmdir($path);
    }
}
