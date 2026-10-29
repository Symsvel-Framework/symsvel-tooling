<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;

/** Preserve exact Git HEAD and branch state for a failed workspace update. */
final class GitComponentCheckpoint
{
    private ?string $head = null;
    private ?string $branch = null;

    public function __construct(private readonly string $directory)
    {
    }

    public function capture(): void
    {
        $this->head = trim($this->git(['rev-parse', '--verify', 'HEAD']));
        $branch = trim($this->git(['symbolic-ref', '--quiet', '--short', 'HEAD'], true));
        $this->branch = $branch !== '' ? $branch : null;
        if ($this->head === '') {
            throw new \RuntimeException('Checkpoint Git impossible : HEAD absent.');
        }
    }

    public function restore(): void
    {
        if ($this->head === null) {
            throw new \RuntimeException('Checkpoint Git absent : restauration refusée.');
        }
        // Restore the original branch or detached HEAD before resetting its files.
        if ($this->branch !== null) {
            $this->git(['checkout', '-f', $this->branch]);
        } else {
            $this->git(['checkout', '--detach', '-f', $this->head]);
        }
        $this->git(['reset', '--hard', $this->head]);
        if (trim($this->git(['rev-parse', 'HEAD'])) !== $this->head) {
            throw new \RuntimeException('Rollback Git incomplet : HEAD différent.');
        }
        $currentBranch = trim($this->git(['symbolic-ref', '--quiet', '--short', 'HEAD'], true));
        if (($currentBranch !== '' ? $currentBranch : null) !== $this->branch) {
            throw new \RuntimeException('Rollback Git incomplet : branche différente.');
        }
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, bool $allowFailure = false): string
    {
        $process = new Process(['git', ...$arguments], $this->directory);
        $process->run();
        if (!$process->isSuccessful() && !$allowFailure) {
            throw new \RuntimeException('Git '.implode(' ', $arguments).' : '.trim($process->getErrorOutput()));
        }
        return $process->getOutput();
    }
}
