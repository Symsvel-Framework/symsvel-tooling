<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;
use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;
use SymSvel\Tooling\Module\SvelteModuleSynchronizer;

/** Transactional native-file updates; project Git is never modified. */
final class ComponentUpdater
{
    public function __construct(
        private readonly SvelteModuleSynchronizer $synchronizer = new SvelteModuleSynchronizer(),
        private readonly ComponentNativeSource $native = new ComponentNativeSource(),
        private readonly ComponentUpdatePreflight $preflight = new ComponentUpdatePreflight(),
        private readonly LegacyComponentBaseline $legacy = new LegacyComponentBaseline(),
    ) {
    }

    public function update(string $backend, string $component): void
    {
        if (!in_array($component, ['bo', 'app', 'front'], true)) {
            throw new \InvalidArgumentException('Composant inconnu : '.$component);
        }
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if (basename($backend) !== 'backend' || !is_file($backend.'/composer.json')) {
            throw new \RuntimeException('Arborescence backend invalide.');
        }
        $directory = dirname($backend).'/'.$component;
        if (is_link($directory) || !is_dir($directory)) {
            throw new \RuntimeException('Composant absent ou symbolique : '.$component);
        }
        $manifest = new ComponentManifest($backend);
        $baseline = $manifest->get($component);
        if ($baseline === null) {
            $baseline = $this->legacy->recover($directory, $component);
        }

        $transaction = $backend.'/.symsvel/transactions/update-'.bin2hex(random_bytes(12));
        if (!is_dir(dirname($transaction)) && !mkdir(dirname($transaction), 0700, true) && !is_dir(dirname($transaction))) {
            throw new \RuntimeException('Répertoire de transactions indisponible.');
        }
        if (!mkdir($transaction, 0700)) {
            throw new \RuntimeException('Création du checkpoint impossible.');
        }
        $upstream = $transaction.'/upstream';
        $revision = $this->native->fetch($component, $upstream);
        $incoming = $this->native->files($upstream);
        $local = $this->native->files($directory);
        $plan = $this->preflight->inspect($baseline['files'], $incoming, $local);
        $this->preflight->assertSafe($plan);

        $sources = new WorkspaceFileSnapshot($backend, $transaction);
        $runtime = new RuntimeArtifactSnapshot($directory, $transaction);
        $sources->capture();
        $runtime->capture();
        try {
            foreach (['add', 'replace'] as $action) {
                foreach ($plan[$action] as $path) {
                    $source = $upstream.'/'.$path;
                    $target = $directory.'/'.$path;
                    $parent = dirname($target);
                    if (!$this->safeParent($directory, $parent)) {
                        throw new \RuntimeException('Chemin local non fiable : '.$path);
                    }
                    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                        throw new \RuntimeException('Création impossible : '.$path);
                    }
                    if (is_link($target) || is_dir($target) || !copy($source, $target)) {
                        throw new \RuntimeException('Copie native impossible : '.$path);
                    }
                    chmod($target, fileperms($source) & 0777);
                }
            }
            foreach ($plan['delete'] as $path) {
                if (!unlink($directory.'/'.$path)) {
                    throw new \RuntimeException('Suppression native impossible : '.$path);
                }
            }
            $this->run(['npm', 'ci'], $directory);
            $this->synchronizer->sync($backend);
            $this->run(['npm', 'run', 'build'], $directory);
            $manifest->set($component, $revision, $incoming);
        } catch (\Throwable $failure) {
            $errors = [];
            foreach (['sources' => static fn () => $sources->restore(), 'npm/build' => static fn () => $runtime->restore()] as $name => $restore) {
                try {
                    $restore();
                } catch (\Throwable $error) {
                    $errors[] = $name.' : '.$error->getMessage();
                }
            }
            if ($errors !== []) {
                throw new \RuntimeException('Rollback incomplet : '.implode(' ; ', $errors).'; snapshot : '.$transaction, 0, $failure);
            }
            throw new \RuntimeException('Mise à jour annulée, état précédent restauré : '.$failure->getMessage(), 0, $failure);
        }
        $cleanup = new WorkspaceCleanupRegistry($backend);
        $cleanup->enqueue([$transaction]);
        $cleanup->cleanup();
    }

    private function safeParent(string $root, string $parent): bool
    {
        while ($parent !== $root) {
            if (is_link($parent) || is_file($parent) || !str_starts_with($parent, $root.'/')) {
                return false;
            }
            $parent = dirname($parent);
        }
        return true;
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(600);
        $process->mustRun();
    }
}
