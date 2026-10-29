<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Composer;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Composer\ComposerTransactionRunner;

final class ComposerRuntimeRollbackTest extends TestCase
{
    public function testComposerFailureRestoresBoAppAndFrontRuntimeArtifacts(): void
    {
        $root = sys_get_temp_dir().'/symsvel-runtime-rollback-'.bin2hex(random_bytes(8));
        $backend = $root.'/backend';
        mkdir($backend, 0775, true);
        file_put_contents($backend.'/composer.json', '{"require":{}}');
        file_put_contents($backend.'/composer.lock', '{"packages":[]}');
        foreach (['bo', 'app', 'front'] as $component) {
            mkdir($root.'/'.$component.'/node_modules/pkg', 0775, true);
            mkdir($root.'/'.$component.'/build', 0775, true);
            file_put_contents($root.'/'.$component.'/node_modules/pkg/index.js', 'original-'.$component);
            file_put_contents($root.'/'.$component.'/build/index.html', 'build-'.$component);
        }
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command) use ($root): void {
            if ($command[1] !== 'require') {
                return;
            }
            foreach (['bo', 'app', 'front'] as $component) {
                file_put_contents($root.'/'.$component.'/node_modules/pkg/index.js', 'broken');
                unlink($root.'/'.$component.'/build/index.html');
                file_put_contents($root.'/'.$component.'/build/partial.html', 'broken');
            }
            throw new \RuntimeException('simulated failure after npm and builds');
        });
        try {
            try {
                $runner->run($backend, ['require', 'broken:dev-main']);
                self::fail('Expected Composer failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            }
            foreach (['bo', 'app', 'front'] as $component) {
                self::assertSame('original-'.$component, file_get_contents($root.'/'.$component.'/node_modules/pkg/index.js'));
                self::assertSame('build-'.$component, file_get_contents($root.'/'.$component.'/build/index.html'));
                self::assertFileDoesNotExist($root.'/'.$component.'/build/partial.html');
            }
            self::assertSame([], glob($backend.'/.symsvel/transactions/*'));
        } finally {
            $this->deleteTree($root);
        }
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->deleteTree($entry->getPathname());
        }
        rmdir($path);
    }
}
