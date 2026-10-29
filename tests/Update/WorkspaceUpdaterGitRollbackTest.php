<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\WorkspaceUpdater;

final class WorkspaceUpdaterGitRollbackTest extends TestCase
{
    public function testFrontBuildFailureRestoresAllThreeGitComponents(): void
    {
        $root = sys_get_temp_dir().'/symsvel-update-rollback-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        $initial = [];
        foreach (['bo', 'app', 'front'] as $component) {
            $directory = $root.'/'.$component;
            mkdir($directory);
            $this->git($directory, ['init', '-b', 'main']);
            $this->git($directory, ['config', 'user.email', 'test@example.test']);
            $this->git($directory, ['config', 'user.name', 'SymSvel Test']);
            file_put_contents($directory.'/version.txt', 'before');
            $this->git($directory, ['add', 'version.txt']);
            $this->git($directory, ['commit', '-m', 'before']);
            $initial[$component] = trim($this->git($directory, ['rev-parse', 'HEAD']));
        }
        try {
            $updater = new WorkspaceUpdater(function (array $command, string $cwd) use ($root): ?string {
                if ($command === ['git', 'status', '--porcelain', '--untracked-files=all']) {
                    return '';
                }
                if ($command === ['git', 'merge', '--ff-only', 'origin/main']) {
                    file_put_contents($cwd.'/version.txt', 'after');
                    $this->git($cwd, ['commit', '-am', 'after']);
                }
                if ($command === ['npm', 'run', 'build'] && $cwd === $root.'/front') {
                    throw new \RuntimeException('FRONT build exploded');
                }
                return null;
            });
            try {
                $updater->update($root.'/backend', 'core');
                self::fail('Expected FRONT build failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('Git et artefacts npm/build restaurés', $error->getMessage());
                self::assertStringContainsString('rollback Composer et BDD non garanti', $error->getMessage());
            }
            foreach ($initial as $component => $sha) {
                self::assertSame($sha, trim($this->git($root.'/'.$component, ['rev-parse', 'HEAD'])));
                self::assertSame('main', trim($this->git($root.'/'.$component, ['branch', '--show-current'])));
                self::assertSame('before', file_get_contents($root.'/'.$component.'/version.txt'));
            }
        } finally {
            $this->deleteTree($root);
        }
    }

    /** @param list<string> $args */
    private function git(string $directory, array $args): string
    {
        $process = new Process(['git', ...$args], $directory);
        $process->mustRun();
        return $process->getOutput();
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
