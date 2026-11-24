<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/** Prepared, read-only native Back plan. ComposerTransactionRunner owns rollback. */
final readonly class BackendNativeUpdatePlan
{
    public function __construct(
        public string $backend,
        private string $upstream,
        private string $revision,
        private array $incoming,
        private array $baseline,
        private array $plan,
        private string $composerBefore,
        private string $composerAfter,
        private string $nativeComposer,
    ) {}

    public function apply(): void
    {
        $path = $this->backend.'/composer.json';
        if (is_link($path) || !is_file($path) || file_get_contents($path) !== $this->composerBefore) {
            throw new \RuntimeException('composer.json modifié depuis le préflight Back.');
        }
        (new ComponentNativeFileApplier())->apply($this->backend, $this->upstream, $this->plan, $this->baseline);
        if ($this->composerBefore !== $this->composerAfter && file_put_contents($path, $this->composerAfter) === false) {
            throw new \RuntimeException('Écriture de composer.json impossible.');
        }
    }

    public function commit(): void
    {
        (new ComponentManifest($this->backend))->set('back', $this->revision, $this->incoming, $this->nativeComposer);
    }
}
