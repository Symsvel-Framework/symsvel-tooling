<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\GitComponentCheckpoint;
use Symfony\Component\Process\Process;

final class GitComponentCheckpointTest extends TestCase
{
    public function testRestoresBranchAndHeadAfterFailedUpdate(): void
    {
        $root = sys_get_temp_dir().'/symsvel-git-checkpoint-'.bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $this->git($root, ['init', '-b', 'main']);
            $this->git($root, ['config', 'user.email', 'test@example.test']);
            $this->git($root, ['config', 'user.name', 'SymSvel Test']);
            file_put_contents($root.'/index.txt', 'before');
            $this->git($root, ['add', 'index.txt']);
            $this->git($root, ['commit', '-m', 'initial']);
            $initial = trim($this->git($root, ['rev-parse', 'HEAD']));
            $checkpoint = new GitComponentCheckpoint($root);
            $checkpoint->capture();
            file_put_contents($root.'/index.txt', 'after');
            $this->git($root, ['commit', '-am', 'update']);
            $this->git($root, ['checkout', '--detach', 'HEAD']);
            $checkpoint->restore();
            self::assertSame($initial, trim($this->git($root, ['rev-parse', 'HEAD'])));
            self::assertSame('main', trim($this->git($root, ['branch', '--show-current'])));
            self::assertSame('before', file_get_contents($root.'/index.txt'));
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testRestoresDetachedHeadAfterCheckoutAndCommit(): void
    {
        $root = sys_get_temp_dir().'/symsvel-git-detached-'.bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $this->git($root, ['init', '-b', 'main']);
            $this->git($root, ['config', 'user.email', 'test@example.test']);
            $this->git($root, ['config', 'user.name', 'SymSvel Test']);
            file_put_contents($root.'/index.txt', 'initial');
            $this->git($root, ['add', 'index.txt']);
            $this->git($root, ['commit', '-m', 'initial']);
            $this->git($root, ['checkout', '--detach', 'HEAD']);
            $initial = trim($this->git($root, ['rev-parse', 'HEAD']));
            $checkpoint = new GitComponentCheckpoint($root);
            $checkpoint->capture();
            file_put_contents($root.'/index.txt', 'changed');
            $this->git($root, ['commit', '-am', 'changed']);
            $checkpoint->restore();
            self::assertSame($initial, trim($this->git($root, ['rev-parse', 'HEAD'])));
            self::assertSame('', trim($this->git($root, ['branch', '--show-current'])));
            self::assertSame('initial', file_get_contents($root.'/index.txt'));
        } finally {
            $this->deleteTree($root);
        }
    }

    private function git(string $directory, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory);
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
