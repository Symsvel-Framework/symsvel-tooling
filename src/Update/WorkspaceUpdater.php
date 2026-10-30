<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

use Symfony\Component\Process\Process;
use SymSvel\Tooling\Composer\ComposerTransactionSnapshot;
use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;

final class WorkspaceUpdater
{
    /** @param null|callable(list<string>, string):void $runner */
    public function __construct(private readonly mixed $runner = null, private readonly bool $snapshotWithRunner = false, private readonly ?string $databaseUrl = null)
    {
    }

    /** @param null|callable(string):void $progress */
    public function update(string $backendDir, string $environment, ?callable $progress = null): void
    {
        if (!in_array($environment, ['core', 'dev', 'prod'], true)) {
            throw new \InvalidArgumentException('Environnement non pris en charge : '.$environment);
        }
        if ($environment === 'prod') {
            throw new \RuntimeException('Mise à jour interdite en production.');
        }
        if (basename($backendDir) !== 'backend' || !is_file($backendDir.'/composer.json')) {
            throw new \RuntimeException('Le backend Symfony doit être dans backend/ avec composer.json.');
        }
        if ($environment === 'dev') {
            $manifest = json_decode((string) file_get_contents($backendDir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach (['symsvel-framework/core', 'symsvel-framework/tooling'] as $package) {
                $constraint = $manifest['require'][$package] ?? null;
                if (!is_string($constraint) || str_contains($constraint, 'dev-') || str_contains($constraint, '@dev')) {
                    throw new \RuntimeException('Mode dev : '.$package.' doit utiliser une contrainte de release stable dans composer.json (actuellement '.($constraint ?? 'absente').').');
                }
            }
        }
        $root = dirname($backendDir);
        // Validate all present interfaces before changing Composer dependencies.
        foreach (['bo', 'app', 'front'] as $component) {
            $directory = $root.'/'.$component;
            if (!is_dir($directory)) {
                continue;
            }
            if (!is_dir($directory.'/.git')) {
                throw new \RuntimeException('Le composant '.$component.' n’est pas un dépôt Git.');
            }
            $status = trim($this->output(['git', 'status', '--porcelain', '--untracked-files=all'], $directory));
            if ($status !== '' && !$this->onlyManagedGeneratedFiles($status, $directory, $backendDir)) {
                throw new \RuntimeException('Le composant '.$component.' contient des modifications locales : mise à jour annulée avant Composer.');
            }
        }
        $gitCheckpoints = [];
        foreach (['bo', 'app', 'front'] as $component) {
            $directory = $root.'/'.$component;
            if (!is_dir($directory) || ($this->runner !== null && !is_file($directory.'/.git/HEAD'))) {
                // Legacy command-mock tests use placeholder .git directories.
                continue;
            }
            $checkpoint = new GitComponentCheckpoint($directory);
            $checkpoint->capture();
            $gitCheckpoints[$component] = $checkpoint;
        }
        $composerSnapshot = null;
        $workspaceSnapshot = null;
        $composerCheckpoint = null;
        if ($this->runner === null || $this->snapshotWithRunner) {
            // The update is refused before any mutation if Composer cannot be
            // deterministically restored from the original lock file.
            if (!is_file($backendDir.'/composer.lock')) {
                throw new \RuntimeException('BACK : composer.lock initial obligatoire pour rollback.');
            }
            $composerSnapshot = new ComposerTransactionSnapshot();
            $composerCheckpoint = $composerSnapshot->begin($backendDir);
            $workspaceSnapshot = new WorkspaceFileSnapshot($backendDir, $composerCheckpoint);
            try {
                $workspaceSnapshot->capture();
            } catch (\Throwable $failure) {
                throw new \RuntimeException('BACK / Snapshot sources incomplet : '.$failure->getMessage().'; sauvegarde conservée : '.$composerCheckpoint, 0, $failure);
            }
        }
        $vendorSnapshot = null;
        $runtimeSnapshots = [];
        $runtimeTransaction = null;
        if ($this->runner === null || $this->snapshotWithRunner) {
            $runtimeTransaction = $backendDir.'/.symsvel/transactions/update-'.bin2hex(random_bytes(12));
            $vendorSnapshot = new RuntimeArtifactSnapshot($backendDir, $runtimeTransaction.'/backend', ['vendor']);
            try {
                $vendorSnapshot->capture();
            } catch (\Throwable $failure) {
                throw new \RuntimeException('BACK / Snapshot vendor impossible : '.$failure->getMessage().'; sauvegarde conservée : '.$runtimeTransaction, 0, $failure);
            }
            foreach (['bo', 'app', 'front'] as $component) {
                $directory = $root.'/'.$component;
                if (!is_dir($directory)) {
                    continue;
                }
                $snapshot = new RuntimeArtifactSnapshot($directory, $runtimeTransaction.'/'.$component);
                try {
                    $snapshot->capture();
                    $runtimeSnapshots[$component] = $snapshot;
                } catch (\Throwable $failure) {
                    // Snapshot capture failed before any update command. Preserve the
                    // incomplete checkpoint for diagnosis rather than claiming cleanup.
                    throw new \RuntimeException(
                        'Snapshot runtime initial impossible ['.strtoupper($component).'] : '.$failure->getMessage().
                        '; opération annulée avant Composer ; sauvegardes conservées : '.$runtimeTransaction,
                        0,
                        $failure,
                    );
                }
            }
        }
        $databaseBackup = null;
        $databaseCheckpoint = null;
        $effectiveDatabaseUrl = $this->resolveDatabaseUrl($backendDir, $environment);
        if ($this->runner === null || $this->snapshotWithRunner) {
            if (!is_string($effectiveDatabaseUrl) || $effectiveDatabaseUrl === '') {
                throw new \RuntimeException('BACK / BDD : DATABASE_URL explicite obligatoire pour garantir le rollback ; mise à jour annulée avant Composer.');
            }
            $databaseBackup = new WorkspaceDatabaseBackup();
            try {
                $databaseCheckpoint = $databaseBackup->capture($backendDir, $effectiveDatabaseUrl);
            } catch (\Throwable $failure) {
                throw new \RuntimeException('BACK / Snapshot BDD impossible : '.$failure->getMessage().'; mise à jour annulée avant Composer.', 0, $failure);
            }
        }
        $componentInProgress = 'BACK';
        $stepInProgress = 'Composer update';
        try {
        if ($progress !== null) {
            $progress('Backend : mise à jour Composer (Core + Tooling)…');
        }
        $this->run(['composer', 'update', 'symsvel-framework/core', 'symsvel-framework/tooling', '--with-all-dependencies', '--no-interaction', '--no-scripts', '--no-plugins'], $backendDir);
        $stepInProgress = 'Publication des hooks Composer';
        $this->installComposerHooks($backendDir, $progress);
        foreach (['bo', 'app', 'front'] as $component) {
            $componentInProgress = strtoupper($component);
            $stepInProgress = 'Vérification du composant';
            $directory = $root.'/'.$component;
            if (!is_dir($directory)) {
                if ($progress !== null) {
                    $progress(strtoupper($component).' : absent, ignoré.');
                }
                continue;
            }
            if ($progress !== null) {
                $progress(strtoupper($component).' : dépôt Git propre, vérifié avant Composer.');
            }
            if ($progress !== null) {
                $progress(strtoupper($component).' : récupération des commits…');
            }
            $stepInProgress = 'Git fetch';
            $this->run(['git', 'fetch', 'origin', '--tags'], $directory);
            if ($progress !== null) {
                $progress(strtoupper($component).($environment === 'core' ? ' : synchronisation main…' : ' : sélection de la dernière release…'));
            }
            $stepInProgress = 'Git checkout / merge';
            if ($environment === 'core') {
                $this->run(['git', 'merge', '--ff-only', 'origin/main'], $directory);
            } else {
                $tag = $this->latestReleaseTag($directory);
                $this->run(['git', 'checkout', '--detach', $tag], $directory);
                if ($progress !== null) {
                    $progress(strtoupper($component).' : release '.$tag.' sélectionnée.');
                }
            }
            if ($progress !== null) {
                $progress(strtoupper($component).' : installation npm…');
            }
            $stepInProgress = 'npm ci';
            $this->run(['npm', 'ci'], $directory);
            if ($progress !== null) {
                $progress(strtoupper($component).' : build…');
            }
            $stepInProgress = 'Build Svelte';
            $this->run(['npm', 'run', 'build'], $directory);
            if ($progress !== null) {
                $progress(strtoupper($component).' : terminé.');
            }
        }
        } catch (\Throwable $failure) {
            $rollbackErrors = [];
            foreach (array_reverse($gitCheckpoints, true) as $component => $checkpoint) {
                try {
                    $checkpoint->restore();
                } catch (\Throwable $recovery) {
                    $rollbackErrors[] = strtoupper($component).' / Git : '.$recovery->getMessage();
                }
            }
            foreach (array_reverse($runtimeSnapshots, true) as $component => $snapshot) {
                try {
                    $snapshot->restore();
                } catch (\Throwable $recovery) {
                    $rollbackErrors[] = strtoupper($component).' / npm-build : '.$recovery->getMessage();
                }
            }
            if ($composerSnapshot !== null && $composerCheckpoint !== null && $workspaceSnapshot !== null) {
                // Recovery steps are independent: a failed vendor reinstall must
                // never prevent the source files from being restored.
                try {
                    $composerSnapshot->restoreManifests($backendDir, $composerCheckpoint);
                } catch (\Throwable $recovery) {
                    $rollbackErrors[] = 'BACK / manifests Composer : '.$recovery->getMessage();
                }
                if ($vendorSnapshot !== null) {
                    try {
                        $vendorSnapshot->restore();
                    } catch (\Throwable $recovery) {
                        $rollbackErrors[] = 'BACK / vendor : '.$recovery->getMessage();
                    }
                }
                try {
                    $workspaceSnapshot->restore();
                } catch (\Throwable $recovery) {
                    $rollbackErrors[] = 'BACK / fichiers sources : '.$recovery->getMessage();
                }
            }
            if ($databaseBackup !== null && $databaseCheckpoint !== null && is_string($effectiveDatabaseUrl)) {
                try {
                    $databaseBackup->restore($effectiveDatabaseUrl, $databaseCheckpoint);
                } catch (\Throwable $recovery) {
                    $rollbackErrors[] = 'BACK / BDD : '.$recovery->getMessage().'; checkpoint conservé : '.$databaseCheckpoint['path'];
                }
            }
            $rollbackStatus = $rollbackErrors === []
                ? ($composerSnapshot === null
                    ? 'Git et artefacts npm/build restaurés ; rollback Composer et BDD non garanti.'
                    : 'Git, npm/build, vendor, sources et BDD restaurés.')
                : 'Rollback incomplet : '.implode(' ; ', $rollbackErrors).'. Rollback global non garanti.';
            throw new \RuntimeException(
                sprintf('Échec de mise à jour [%s / %s] : %s. %s', $componentInProgress, $stepInProgress, $failure->getMessage(), $rollbackStatus),
                0,
                $failure,
            );
        }
        if ($databaseBackup !== null && $databaseCheckpoint !== null) {
            $databaseBackup->discard($databaseCheckpoint);
        }
        if ($workspaceSnapshot !== null && $composerSnapshot !== null && $composerCheckpoint !== null) {
            $workspaceSnapshot->discard();
            $composerSnapshot->finish($backendDir, $composerCheckpoint);
        }
        foreach ($runtimeSnapshots as $snapshot) {
            $snapshot->discard();
        }
        if ($vendorSnapshot !== null) {
            $vendorSnapshot->discard();
        }
        if ($runtimeTransaction !== null && is_dir($runtimeTransaction) && !rmdir($runtimeTransaction)) {
            throw new \RuntimeException('Nettoyage transaction runtime impossible : '.$runtimeTransaction);
        }
        if ($progress !== null) {
            $progress('Tous les composants présents ont été traités.');
        }
    }


    /**
     * Resolve the same environment-specific Dotenv layers used by Symfony.
     * Explicit constructor injection remains available for isolated tests.
     */
    private function resolveDatabaseUrl(string $backendDir, string $environment): ?string
    {
        if ($this->databaseUrl !== null) {
            return $this->databaseUrl;
        }
        $external = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (is_string($external) && $external !== '') {
            return $external;
        }
        if (!class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
            return null;
        }
        $dotenv = new \Symfony\Component\Dotenv\Dotenv();
        $values = [];
        foreach (['.env', '.env.local', '.env.'.$environment, '.env.'.$environment.'.local'] as $file) {
            if ($environment === 'test' && $file === '.env.local') {
                continue;
            }
            $path = $backendDir.'/'.$file;
            if (!is_file($path)) {
                continue;
            }
            $parsed = $dotenv->parse((string) file_get_contents($path), $path);
            $values = array_replace($values, $parsed);
        }
        $url = $values['DATABASE_URL'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Publish the framework-managed Composer hook without replacing application scripts. */
    private function installComposerHooks(string $backendDir, ?callable $progress): void
    {
        $source = dirname(__DIR__, 2).'/assets/symsvel-module-sync.php';
        if (!is_file($source)) {
            return;
        }
        $manifestPath = $backendDir.'/composer.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('composer.json invalide.');
        }
        $hook = '@php scripts/symsvel-module-sync.php';
        foreach (['post-install-cmd', 'post-update-cmd'] as $event) {
            $commands = $manifest['scripts'][$event] ?? [];
            if (!is_array($commands) || array_is_list($commands) === false) {
                throw new \RuntimeException('Scripts Composer incompatibles : '.$event);
            }
            if (!in_array($hook, $commands, true)) {
                $commands[] = $hook;
            }
            $manifest['scripts'][$event] = $commands;
        }
        $destinationDir = $backendDir.'/scripts';
        $destination = $destinationDir.'/symsvel-module-sync.php';
        $existing = is_file($destination) ? file_get_contents($destination) : null;
        $expected = file_get_contents($source);
        if ($existing !== null && $existing !== $expected) {
            throw new \RuntimeException('Script Composer SymSvel modifié localement : '.$destination);
        }
        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException('Impossible de créer '.$destinationDir);
        }
        if ($existing === null && file_put_contents($destination, $expected) === false) {
            throw new \RuntimeException('Impossible de publier '.$destination);
        }
        if (file_get_contents($manifestPath) !== $encoded && file_put_contents($manifestPath, $encoded) === false) {
            throw new \RuntimeException('Impossible de mettre à jour composer.json.');
        }
        // Publish the opt-in transaction entrypoint alongside the existing sync hook.
        $transactionSource = dirname(__DIR__, 2).'/assets/symsvel-composer-transaction.php';
        $transactionDestination = $destinationDir.'/symsvel-composer-transaction.php';
        if (is_file($transactionSource)) {
            $transactionExpected = file_get_contents($transactionSource);
            $transactionExisting = is_file($transactionDestination) ? file_get_contents($transactionDestination) : null;
            if ($transactionExisting !== null && $transactionExisting !== $transactionExpected) {
                throw new \RuntimeException('Script de transaction SymSvel modifié localement : '.$transactionDestination);
            }
            if ($transactionExisting === null && file_put_contents($transactionDestination, $transactionExpected) === false) {
                throw new \RuntimeException('Impossible de publier '.$transactionDestination);
            }
        }
        if ($progress !== null) {
            $progress('Backend : hooks Composer SymSvel installés.');
        }
    }

    /** Git changes are allowed only for intact, registered, untracked generated files. */
    private function onlyManagedGeneratedFiles(string $status, string $directory, string $backendDir): bool
    {
        $registry = $backendDir.'/.symsvel/svelte-files.json';
        if (!is_file($registry) || is_link($registry)) {
            return false;
        }
        try {
            $entries = json_decode((string) file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($entries)) {
            return false;
        }
        foreach (preg_split('/\R/', $status) ?: [] as $line) {
            if (!str_starts_with($line, '?? ')) {
                return false;
            }
            $relative = substr($line, 3);
            if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
                return false;
            }
            $path = $directory.'/'.$relative;
            if (!str_starts_with($path, $directory.'/src/lib/vendor/') && !str_starts_with($path, $directory.'/src/routes/')) {
                return false;
            }
            $hash = $entries[$path] ?? null;
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !is_file($path) || is_link($path)) {
                return false;
            }
            $parent = dirname($path);
            while ($parent !== $directory && $parent !== dirname($parent)) {
                if (is_link($parent)) {
                    return false;
                }
                $parent = dirname($parent);
            }
            if (hash_file('sha256', $path) !== $hash) {
                return false;
            }
        }
        return true;
    }

    private function latestReleaseTag(string $directory): string
    {
        $tags = $this->output(['git', 'tag', '--list', 'v[0-9]*'], $directory);
        $versions = array_filter(
            preg_split('/\R/', trim($tags)) ?: [],
            static fn (string $tag): bool => preg_match('/^v\d+\.\d+\.\d+$/', $tag) === 1,
        );
        if ($versions === []) {
            throw new \RuntimeException('Aucune release stable (tag vX.Y.Z) disponible dans '.$directory);
        }
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
        return $versions[0];
    }

    /** @param list<string> $command */
    private function output(array $command, string $cwd): string
    {
        if ($this->runner !== null) {
            $result = ($this->runner)($command, $cwd);
            return is_string($result) ? $result : '';
        }
        $process = new Process($command, $cwd);
        $process->mustRun();
        return $process->getOutput();
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): void
    {
        if ($this->runner !== null) {
            ($this->runner)($command, $cwd);
            return;
        }
        $process = new Process($command, $cwd);
        $process->setTimeout(900);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('%s : %s', implode(' ', $command), trim($process->getErrorOutput()) ?: trim($process->getOutput())));
        }
    }
}
