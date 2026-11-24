<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;
use SymSvel\Tooling\Composer\ComposerTransactionRunner;

/** Update the Composer project skeleton, not the runtime packages in vendor/. */
final class BackendNativeUpdater
{
    public function __construct(
        private readonly ComponentNativeSource $source = new ComponentNativeSource(),
        private readonly ComposerTransactionRunner $transactions = new ComposerTransactionRunner(),
    ) {}

    /** Called once, while Composer's exact source checkout is still available. */
    public function recordInstalled(string $backend): void
    {
        $backend = $this->backend($backend);
        $manifest = new ComponentManifest($backend);
        if ($manifest->get('back') !== null) {
            throw new \RuntimeException('Provenance native Back déjà enregistrée.');
        }
        if (!is_dir($backend.'/.git') || is_link($backend.'/.git')
            || realpath(trim($this->git($backend, ['rev-parse', '--show-toplevel']))) !== $backend) {
            throw new \RuntimeException('Checkout source Back absent : Composer doit conserver le Git natif.');
        }
        $origin = trim($this->git($backend, ['remote', 'get-url', 'origin']));
        if (!preg_match('~^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)Symsvel-Framework/symsvel-back(?:\.git)?$~iD', $origin)) {
            throw new \RuntimeException('Provenance Back non officielle.');
        }
        $revision = trim($this->git($backend, ['rev-parse', 'HEAD']));
        $manifest->set('back', $revision, $this->nativeFiles($backend), $this->git($backend, ['show', 'HEAD:composer.json']));
        // Only a freshly installed, verified upstream checkout is removed.
        // An update never reads or changes application Git metadata.
        (new Process(['rm', '-rf', '--', $backend.'/.git']))->mustRun();
        if (file_exists($backend.'/.git') || is_link($backend.'/.git')) {
            throw new \RuntimeException('Suppression du Git natif Back impossible.');
        }
    }

    /** @param null|callable(string):void $progress */
    public function update(string $backend, ?callable $progress = null): void
    {
        $backend = $this->backend($backend);
        $channel = ProjectChannel::read($backend);
        $baseline = (new ComponentManifest($backend))->get('back');
        if ($baseline === null || !is_string($baseline['composer'] ?? null)) {
            throw new \RuntimeException('Provenance native Back absente : mise à jour automatique refusée.');
        }
        foreach (array_keys($baseline['files']) as $path) {
            if (!$this->managed($path)) {
                throw new \RuntimeException('Chemin Back protégé dans la provenance : '.$path);
            }
        }
        $temporary = sys_get_temp_dir().'/symsvel-back-'.bin2hex(random_bytes(16));
        if (!mkdir($temporary, 0700)) {
            throw new \RuntimeException('Répertoire amont Back indisponible.');
        }
        try {
            $upstream = $temporary.'/upstream';
            $revision = $this->source->fetch('back', $upstream, $channel);
            if ($revision === $baseline['revision']) {
                if ($progress !== null) {
                    $progress('Back est déjà à jour : '.$revision.PHP_EOL);
                }
                return;
            }
            $incoming = $this->nativeFiles($upstream);
            $local = [];
            foreach (array_unique([...array_keys($baseline['files']), ...array_keys($incoming)]) as $path) {
                $target = $backend.'/'.$path;
                for ($parent = dirname($target); $parent !== $backend; $parent = dirname($parent)) {
                    if (is_link($parent) || is_file($parent)) {
                        throw new \RuntimeException('Chemin Back non fiable : '.$path);
                    }
                }
                if (is_link($target) || (file_exists($target) && !is_file($target))) {
                    throw new \RuntimeException('Fichier Back non fiable : '.$path);
                }
                if (is_file($target)) {
                    $hash = hash_file('sha256', $target);
                    if ($hash === false) {
                        throw new \RuntimeException('Empreinte locale impossible : '.$path);
                    }
                    $local[$path] = $hash;
                }
            }
            $preflight = new ComponentUpdatePreflight();
            $plan = $preflight->inspect($baseline['files'], $incoming, $local);
            $preflight->assertSafe($plan);
            $composerPath = $backend.'/composer.json';
            if (is_link($composerPath) || !is_file($composerPath)) {
                throw new \RuntimeException('composer.json local non fiable.');
            }
            $current = (string) file_get_contents($composerPath);
            $nativeComposer = $this->git($upstream, ['show', 'HEAD:composer.json']);
            $merged = (new BackendComposerMerge())->merge($baseline['composer'], $nativeComposer, $current, $channel);
            $update = new BackendNativeUpdatePlan($backend, $upstream, $revision, $incoming, $baseline['files'], $plan, $current, $merged, $nativeComposer);
            // The project lock is never copied from the skeleton. Resolve it
            // with the project's own constraints, modules and repository set.
            $this->transactions->run($backend, ['update', '--minimal-changes', '--no-interaction'], $progress, backUpdate: $update);
        } finally {
            (new Process(['rm', '-rf', '--', $temporary]))->mustRun();
        }
    }

    private function backend(string $backend): string
    {
        if (is_link($backend)) {
            throw new \RuntimeException('Backend symbolique refusé.');
        }
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if (basename($backend) !== 'backend' || !is_file($backend.'/composer.json') || is_link($backend.'/composer.json')) {
            throw new \RuntimeException('Arborescence backend invalide.');
        }
        return $backend;
    }

    /** @return array<string, string> */
    private function nativeFiles(string $checkout): array
    {
        $result = [];
        foreach (explode("\0", $this->git($checkout, ['ls-tree', '-r', '-z', 'HEAD'])) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (!preg_match('/^([0-9]+) blob [a-f0-9]+\t(.+)$/sD', $entry, $parts)) {
                throw new \RuntimeException('Entrée Git native Back non prise en charge.');
            }
            $path = $parts[2];
            if (!$this->managed($path)) {
                continue;
            }
            if (!in_array($parts[1], ['100644', '100755'], true)) {
                throw new \RuntimeException('Fichier Git natif Back non ordinaire : '.$path);
            }
            $result[$path] = hash('sha256', $this->git($checkout, ['show', 'HEAD:'.$path]));
        }
        ksort($result);
        return $result;
    }

    private function managed(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }
        $first = explode('/', $path, 2)[0];
        return !in_array($first, ['.git', '.symsvel', 'vendor', 'var', 'secrets', 'backups', 'auth.json', 'composer.json', 'composer.lock', 'symfony.lock', '.env.local', '.env.local.php', '.env.prod.local.php'], true)
            && !preg_match('~^\.env\..*\.local(?:\.php)?$~D', $path)
            && !str_starts_with($path, 'public/bundles/')
            && !str_starts_with($path, 'config/secrets/');
    }

    private function git(string $checkout, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $checkout);
        $process->setTimeout(300);
        $process->mustRun();
        return $process->getOutput();
    }
}
