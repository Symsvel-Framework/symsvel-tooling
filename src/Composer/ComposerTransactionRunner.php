<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Composer;

use SymSvel\Tooling\Filesystem\LocalMetadata;

use SymSvel\Tooling\Process\PhpCliExecutableResolver;
use SymSvel\Tooling\Process\NativeCommand;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\RuntimeArtifactSnapshot;
use SymSvel\Tooling\Update\BackendNativeUpdatePlan;
use SymSvel\Tooling\Update\ProjectChannel;
use SymSvel\Tooling\Update\WorkspaceDatabaseBackup;
use SymSvel\Tooling\Update\WorkspaceCleanupRegistry;
use SymSvel\Tooling\Module\ComposerModuleMigrationCatalog;
use SymSvel\Tooling\Module\ModuleMigrationDiff;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;
use SymSvel\Tooling\Module\ModuleMigrationExecutor;
use SymSvel\Tooling\Installation\SetupState;
use SymSvel\Tooling\Installation\LocalDeveloperDocumentationRefresher;

/**
 * Opt-in Composer transaction runner.
 *
 * A subprocess can be recovered after it exits; native composer require/update/remove
 * cannot be made transactional by a post-update script.
 */
final class ComposerTransactionRunner
{
    public function __construct(
        private readonly ComposerTransactionSnapshot $snapshots = new ComposerTransactionSnapshot(),
        /** @var null|callable(list<string>, string, ?callable):void */
        private readonly mixed $commandRunner = null,
        private readonly LocalDeveloperDocumentationRefresher $docsRefresher = new LocalDeveloperDocumentationRefresher(),
    ) {
    }

    /**
     * @param list<string> $arguments Composer arguments, starting with require/update/remove
     * @param null|callable(string):void $progress
     */
    public function run(string $backend, array $arguments, ?callable $progress = null, bool $bootstrap = false, ?BackendNativeUpdatePlan $backUpdate = null): void
    {
        if (!in_array($arguments[0] ?? '', ['require', 'update', 'remove'], true)) {
            throw new \InvalidArgumentException('Opération Composer non prise en charge.');
        }
        if ($bootstrap && ($arguments[0] !== 'require' || count($arguments) < 2)) {
            throw new \InvalidArgumentException('Bootstrap réservé à l’installation de modules natifs.');
        }
        foreach ($arguments as $argument) {
            if (in_array($argument, ['--no-scripts', '--no-plugins', '--working-dir', '-d'], true)
                || str_starts_with($argument, '--working-dir=')) {
                throw new \InvalidArgumentException('Option Composer incompatible avec la transaction : '.$argument);
            }
        }
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if ($backUpdate !== null && ($bootstrap || $arguments[0] !== 'update' || $backUpdate->backend !== $backend)) {
            throw new \InvalidArgumentException('Plan natif Back incompatible avec la transaction.');
        }
        if ($arguments[0] === 'remove') {
            $this->preventNativeModuleRemoval($backend, $arguments);
        }
        if ($bootstrap) {
            if (!$this->isUnconfiguredWorkspace($backend)) {
                throw new \RuntimeException('Bootstrap interdit hors setup initial en attente.');
            }
            $manifest = $backend.'/config/symsvel-native-modules.json';
            if (!is_file($manifest) || is_link($manifest)) {
                throw new \RuntimeException('Manifeste des modules natifs introuvable.');
            }
            $configuration = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            $allowed = $configuration['modules'] ?? null;
            if (($configuration['schema'] ?? null) !== 1 || !is_array($allowed) || !array_is_list($allowed)) {
                throw new \RuntimeException('Manifeste des modules natifs invalide.');
            }
            $componentModules = $configuration['components'] ?? [];
            if (!is_array($componentModules)) {
                throw new \RuntimeException('Modules natifs de composants invalides.');
            }
            foreach ($componentModules as $component => $packages) {
                if (!is_string($component) || !in_array($component, ['bo', 'app', 'front'], true)
                    || !is_array($packages) || !array_is_list($packages)) {
                    throw new \RuntimeException('Modules natifs de composants invalides.');
                }
                foreach ($packages as $componentPackage) {
                    if (!is_string($componentPackage) || !preg_match('~^symsvel-framework/module-[a-z][a-z0-9-]*$~D', $componentPackage)) {
                        throw new \RuntimeException('Package natif de composant invalide.');
                    }
                }
                if (is_dir(dirname($backend).'/'.$component)) {
                    array_push($allowed, ...$packages);
                }
            }
            $packages = array_slice($arguments, 1);
            if ($packages === []) {
                throw new \RuntimeException('Bootstrap sans module natif.');
            }
            foreach ($packages as $requested) {
                if (!is_string($requested) || str_starts_with($requested, '-')) {
                    throw new \RuntimeException('Bootstrap : seules les contraintes de modules natifs sont autorisées.');
                }
                $package = explode(':', $requested, 2)[0];
                if (!in_array($package, $allowed, true)) {
                    throw new \RuntimeException('Bootstrap interdit pour un module non natif : '.$package);
                }
            }
        }
        if (!is_file($backend.'/composer.lock')) {
            throw new \RuntimeException('composer.lock initial obligatoire pour garantir la restauration des dépendances.');
        }
        $databaseUrl = $this->databaseUrl($backend);
        $databaseBackup = new WorkspaceDatabaseBackup();
        $databaseCheckpoint = null;
        $migrationJournal = dirname($backend).'/.symsvel/migration-recovery.json';
        $migrationJournalExisted = file_exists($migrationJournal) || is_link($migrationJournal);
        $initialFiles = $this->workspaceFiles($backend);
        $transaction = $this->snapshots->begin($backend);
        $workspaceSnapshot = new WorkspaceFileSnapshot($backend, $transaction);
        $vendorSnapshot = new RuntimeArtifactSnapshot($backend, $transaction.'/backend', ['vendor']);
        /** @var list<RuntimeArtifactSnapshot> $runtimeSnapshots */
        $runtimeSnapshots = [];
        try {
            if ($databaseUrl !== null) {
                $databaseCheckpoint = $databaseBackup->capture($backend, $databaseUrl);
            }
            $workspaceSnapshot->capture();
            $vendorSnapshot->capture();
            foreach (['bo', 'app', 'front'] as $component) {
                $directory = dirname($backend).'/'.$component;
                if (!is_dir($directory)) {
                    continue;
                }
                // Composer scripts need node_modules, while rollback must restore
                // any runtime mutation. Move originals into the checkpoint, then
                // recreate the active trees from that stable snapshot.
                $componentCheckpoint = $transaction.'/'.$component;
                if (!is_dir($componentCheckpoint) && !mkdir($componentCheckpoint, 0700, true)
                    && !is_dir($componentCheckpoint)) {
                    throw new \RuntimeException('Checkpoint runtime impossible : '.$component);
                }
                $runtime = new RuntimeArtifactSnapshot(
                    $directory,
                    $componentCheckpoint,
                    moveOnCapture: true,
                    keepWorkingCopy: true,
                );
                $runtime->capture();
                $runtimeSnapshots[$component] = $runtime;
            }
        } catch (\Throwable $failure) {
            // A capture that failed to move an original back must retain the
            // entire checkpoint. A successful local return is already handled
            // inside RuntimeArtifactSnapshot::capture().
            throw new \RuntimeException(
                'BACK / Snapshot initial incomplet : '.$failure->getMessage().'; opération Composer annulée avant exécution ; snapshot conservé : '.$transaction,
                0,
                $failure,
            );
        }
        try {
            $backUpdate?->apply();
            $removedVersions = [];
            if ($arguments[0] === 'remove') {
                $removedVersions = $this->removeModuleMigrations($backend, $arguments, $databaseUrl, $progress);
            }
            $this->prepareDevelopmentModuleRepositories($backend, $arguments, $progress);
            // Composer's post-update scripts run cache:clear before the application's
            // legacy module-sync hook. Defer them until ownership is refreshed.
            $deferScripts = is_file($backend.'/bin/console');
            $composerArguments = array_merge(['composer'], $arguments, $deferScripts ? ['--no-scripts'] : []);
            $this->execute($composerArguments, $backend, $progress);
            if ($deferScripts) {
                (new ComposerInterfaceHookPublisher())->publish($backend);
                // Pure PHP metadata writers: never boot Symfony with stale module paths.
                (new \SymSvel\Tooling\Module\ComposerModuleBundleMapWriter())->write($backend);
                (new \SymSvel\Tooling\Module\ComposerModuleInventoryWriter())->write($backend);
                $this->execute(['composer', 'run-script', 'post-update-cmd'], $backend, $progress);
            }
            // Ownership compilation consumes the refreshed Composer module inventory.
            // Publish it before booting the container for migrations or settings.
            if (is_file($backend.'/bin/console')) {
                $this->execute([PhpCliExecutableResolver::resolve(), 'bin/console', 'symsvel:module:sync', '--source=composer', '--no-interaction'], $backend, $progress);
            }
            $this->applyComposerMigrations($backend, $databaseUrl, $progress, $bootstrap);
            if ($databaseUrl !== null && is_file($backend.'/vendor/symsvel-framework/module-settings/back/src/SettingsBundle.php')) {
                $this->execute([PhpCliExecutableResolver::resolve(), 'bin/console', 'symsvel:settings:sync', '--no-interaction'], $backend, $progress);
            }
            // The bundle owns its tables and import logic. Tooling only invokes
            // the post-migration operation while the Composer rollback is active.
            if ($databaseUrl !== null && is_file($backend.'/vendor/symsvel-framework/module-languages/back/src/LanguagesBundle.php')) {
                $this->execute([PhpCliExecutableResolver::resolve(), 'bin/console', 'symsvel:languages:translations:sync', '--no-interaction'], $backend, $progress);
            }
            $this->removeCompiledMigrations($backend, $removedVersions);
            if ($backUpdate !== null) {
                $this->execute(['composer', 'audit', '--locked'], $backend, $progress);
                $backUpdate->commit();
            }
        } catch (\Throwable $failure) {
            $recoveryErrors = [];
            if ($databaseCheckpoint !== null) {
                try {
                    $databaseBackup->restore($databaseUrl, $databaseCheckpoint);
                } catch (\Throwable $recovery) {
                    $recoveryErrors[] = 'BDD / PostgreSQL : '.$recovery->getMessage();
                }
            }
            // Every recovery step is independent: a failed manifest restore
            // must not prevent vendor, sources or frontend artifacts recovery.
            try {
                $this->snapshots->restoreManifests($backend, $transaction);
            } catch (\Throwable $recovery) {
                $recoveryErrors[] = 'BACK / manifests : '.$recovery->getMessage();
            }
            try {
                $vendorSnapshot->restore();
            } catch (\Throwable $recovery) {
                $recoveryErrors[] = 'BACK / vendor : '.$recovery->getMessage();
            }
            try {
                $workspaceSnapshot->restore();
            } catch (\Throwable $recovery) {
                $recoveryErrors[] = 'Workspace / sources : '.$recovery->getMessage();
            }
            foreach ($runtimeSnapshots as $component => $runtime) {
                try {
                    $runtime->restore();
                } catch (\Throwable $recovery) {
                    $recoveryErrors[] = strtoupper($component).' / npm-build : '.$recovery->getMessage();
                }
            }
            try {
                if (!is_file($backend.'/composer.lock')) {
                    throw new \RuntimeException('composer.lock initial absent après restauration.');
                }
                $restoredFiles = $this->workspaceFiles($backend);
                $changedFiles = array_keys(array_diff_assoc($restoredFiles, $initialFiles));
                $changedFiles = array_unique(array_merge($changedFiles, array_keys(array_diff_key($initialFiles, $restoredFiles))));
                if ($changedFiles !== []) {
                    throw new \RuntimeException('Fichiers hors Composer modifiés : '.implode(', ', array_slice($changedFiles, 0, 10)));
                }
            } catch (\Throwable $verification) {
                $recoveryErrors[] = 'Vérification : '.$verification->getMessage();
            }
            // A verified PostgreSQL checkpoint supersedes the migration executor's
            // interrupted-DDL warning. Only remove a journal created by this transaction.
            // Keep it if recovery failed, no DB checkpoint exists, or it predates us.
            if ($recoveryErrors === [] && $databaseCheckpoint !== null && !$migrationJournalExisted
                && (file_exists($migrationJournal) || is_link($migrationJournal))) {
                if (is_link($migrationJournal) || !is_file($migrationJournal) || !unlink($migrationJournal)) {
                    $recoveryErrors[] = 'Migrations / journal de récupération non nettoyé : '.$migrationJournal;
                } elseif ($progress !== null) {
                    $progress('Journal de récupération des migrations nettoyé après restauration PostgreSQL vérifiée.'.PHP_EOL);
                }
            }
            if ($recoveryErrors !== []) {
                throw new \RuntimeException(
                    'Échec Composer : '.$failure->getMessage().'; récupération incomplète : '.implode(' ; ', $recoveryErrors).
                    '; snapshot conservé : '.$transaction,
                    0,
                    $failure,
                );
            }
            if ($databaseCheckpoint !== null) {
                $databaseBackup->discard($databaseCheckpoint);
            }
            // Recovery has been verified. Cleanup is separate housekeeping.
            try {
                $registry = new WorkspaceCleanupRegistry($backend);
                $registry->enqueue([$transaction]);
                $cleanup = $registry->cleanup();
                if ($cleanup['errors'] !== [] && $progress !== null) {
                    $progress('AVERTISSEMENT : rollback Composer restauré, nettoyage différé (symsvel:cleanup) : '.implode(' ; ', $cleanup['errors']));
                }
            } catch (\Throwable $cleanupFailure) {
                if ($progress !== null) {
                    $progress('AVERTISSEMENT : rollback Composer restauré, nettoyage non enregistré : '.$cleanupFailure->getMessage().'; checkpoint conservé si présent : '.$transaction);
                }
            }
            // The migration executor cannot certify an interrupted DDL statement on its own.
            // A successfully restored global database checkpoint supersedes that local warning.
            $failureMessage = $failure->getMessage();
            if ($databaseCheckpoint !== null && str_contains($failureMessage, 'Récupération manuelle requise, rollback incomplet')) {
                $failureMessage = preg_replace(
                    '/\\. Récupération manuelle requise, rollback incomplet : .*$/s',
                    '',
                    $failureMessage,
                ) ?? $failureMessage;
                if ($progress !== null) {
                    $progress('Restauration globale vérifiée : checkpoint PostgreSQL et workspace restaurés ; aucune récupération manuelle requise pour cette transaction.'.PHP_EOL);
                }
            }
            throw new \RuntimeException('Opération Composer annulée et dépendances restaurées : '.$failureMessage, 0, $failure);
        }
        if ($databaseCheckpoint !== null) {
            $databaseBackup->discard($databaseCheckpoint);
        }
        // Composer has succeeded. Register the checkpoint before deleting anything:
        // an interrupted cleanup can be retried without turning success into failure.
        try {
            $registry = new WorkspaceCleanupRegistry($backend);
            $registry->enqueue([$transaction]);
            $result = $registry->cleanup();
            if ($result['errors'] !== [] && $progress !== null) {
                $progress('AVERTISSEMENT : opération Composer réussie, nettoyage différé (symsvel:cleanup) : '.implode(' ; ', $result['errors']));
            }
        } catch (\Throwable $cleanupFailure) {
            if ($progress !== null) {
                $progress('AVERTISSEMENT : opération Composer réussie, nettoyage non enregistré ou interrompu : '.$cleanupFailure->getMessage().'; checkpoint conservé si présent : '.$transaction);
            }
        }
        // Derived local docs are regenerated only after the Composer transaction
        // commits. Never publish module pages while a rollback is still possible.
        $this->docsRefresher->afterSuccess($backend, $progress);
    }

    /**
     * Register official module sources inside the transaction, after its
     * composer.json checkpoint. Never mutate third-party repositories.
     *
     * @param list<string> $arguments
     * @param null|callable(string):void $progress
     */
    private function prepareDevelopmentModuleRepositories(string $backend, array $arguments, ?callable $progress): void
    {
        if ($arguments[0] !== 'require') {
            return;
        }
        $modules = array_values(array_filter(
            array_slice($arguments, 1),
            static fn (string $argument): bool => (bool) preg_match('~^symsvel-framework/module-[a-z][a-z0-9-]*(?::[^\\s]+)?$~D', $argument),
        ));
        if ($modules === [] || ProjectChannel::read($backend) !== ProjectChannel::DEV) {
            return;
        }
        $manifest = json_decode((string) file_get_contents($backend.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Manifest Composer invalide.');
        }
        $repositories = $manifest['repositories'] ?? [];
        if (!is_array($repositories)) {
            throw new \RuntimeException('Repositories Composer invalides.');
        }
        foreach (array_slice($arguments, 1) as $argument) {
            if (!preg_match('~^(symsvel-framework/module-([a-z][a-z0-9-]*))(?:[:][^\\s]+)?$~D', $argument, $matches)) {
                continue;
            }
            $name = $matches[2];
            $key = 'module-'.$name.'-dev';
            $url = 'https://github.com/Symsvel-Framework/symsvel-module-'.$name.'.git';
            $local = dirname($backend).'/modules/'.$name;
            $repository = ['type' => 'vcs', 'url' => $url];
            if (is_dir($local) && !is_link($local)) {
                // A local development module takes precedence over remote VCS/Satis.
                // Composer path packages without a Git tag need an explicit dev-main version.
                $manifest = $local.'/composer.json';
                if (!is_file($manifest) || is_link($manifest)) {
                    throw new \RuntimeException('composer.json du module local introuvable : '.$manifest);
                }
                $package = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($package) || ($package['name'] ?? null) !== $matches[1]) {
                    throw new \RuntimeException('Nom Composer du module local incompatible : '.$local);
                }
                $repository = [
                    'type' => 'path',
                    'url' => '../modules/'.$name,
                    'options' => [
                        'symlink' => true,
                        'versions' => [$matches[1] => 'dev-main'],
                    ],
                ];
            }
            if (array_key_exists($key, $repositories)) {
                if ($repositories[$key] !== $repository) {
                    throw new \RuntimeException('Dépôt Composer existant incompatible : '.$key);
                }
                continue;
            }
            if ($repository['type'] === 'path') {
                $this->execute(['composer', 'config', '--json', 'repositories.'.$key, json_encode($repository, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)], $backend, $progress);
            } else {
                $this->execute(['composer', 'config', 'repositories.'.$key, 'vcs', $url], $backend, $progress);
            }
            $repositories[$key] = $repository;
        }
    }

    /**
     * Keep mandatory native modules installed. Use the same persisted manifest
     * already consumed by bootstrap, not a hardcoded Tooling package list.
     *
     * @param list<string> $arguments
     */
    private function preventNativeModuleRemoval(string $backend, array $arguments): void
    {
        $manifest = $backend.'/config/symsvel-native-modules.json';
        if (!file_exists($manifest) && !is_link($manifest)) {
            return; // Compatibility with isolated composer test fixtures.
        }
        if (is_link($manifest) || !is_file($manifest) || !is_readable($manifest)) {
            throw new \RuntimeException('Manifeste des modules natifs absent ou non fiable.');
        }
        $config = json_decode((string) file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config) || ($config['schema'] ?? null) !== 1
            || !is_array($config['modules'] ?? null) || !array_is_list($config['modules'])) {
            throw new \RuntimeException('Manifeste des modules natifs invalide.');
        }
        $required = $config['modules'];
        $components = $config['components'] ?? [];
        if (!is_array($components)) {
            throw new \RuntimeException('Modules natifs de composants invalides.');
        }
        foreach ($components as $component => $packages) {
            if (!in_array($component, ['bo', 'app', 'front'], true)
                || !is_array($packages) || !array_is_list($packages)) {
                throw new \RuntimeException('Modules natifs de composants invalides.');
            }
            if (is_dir(dirname($backend).'/'.$component)) {
                array_push($required, ...$packages);
            }
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || !str_starts_with($argument, 'symsvel-framework/module-')) {
                continue;
            }
            if (in_array($argument, $required, true)) {
                throw new \DomainException('Module natif obligatoire non désinstallable : '.$argument);
            }
        }
    }

    /** @param list<string> $arguments
     *  @param null|callable(string):void $progress
     *  @return list<string> Doctrine versions removed by this transaction
     */
    private function removeModuleMigrations(string $backend, array $arguments, ?string $databaseUrl, ?callable $progress): array
    {
        $packages = array_values(array_filter(
            array_slice($arguments, 1),
            static fn (string $arg): bool => !str_starts_with($arg, '-'),
        ));
        if ($packages === []) {
            return [];
        }
        $registry = new ModuleMigrationRegistry();
        $workspace = dirname($backend);
        $registered = $registry->read($workspace);
        $removing = [];
        foreach ($packages as $package) {
            if (!preg_match('~^symsvel-framework/module-([a-z][a-z0-9-]*)$~D', $package, $matches)) {
                continue;
            }
            foreach ($registered as $key => $receipt) {
                if (str_starts_with($key, $matches[1].'/')) {
                    $removing[$key] = $receipt;
                }
            }
        }
        if ($removing === []) {
            return [];
        }
        if ($databaseUrl === null) {
            throw new \RuntimeException('Désinstallation avec migrations interdite sans checkpoint BDD.');
        }
        $versions = [];
        foreach ($removing as $receipt) {
            $versions[$receipt['doctrine']] = true;
        }
        foreach ($registered as $key => $receipt) {
            if (!isset($removing[$key]) && isset($versions[$receipt['doctrine']])) {
                throw new \DomainException('Migration Doctrine partagée : suppression partielle interdite.');
            }
        }
        $executor = new ModuleMigrationExecutor();
        $orderedVersions = array_keys($versions);
        rsort($orderedVersions, SORT_STRING);
        foreach ($orderedVersions as $version) {
            if ($progress !== null) {
                $progress('Doctrine down : '.$version.PHP_EOL);
            }
            $executor->execute($backend, $version, 'down');
        }
        $registry->write($workspace, $registry->withoutSources($workspace, array_keys($removing)));
        return $orderedVersions;
    }

    /** @param list<string> $versions */
    private function removeCompiledMigrations(string $backend, array $versions): void
    {
        foreach ($versions as $version) {
            if (!preg_match('/^DoctrineMigrations\\\\(Version[A-Za-z0-9_]+)$/D', $version, $matches)) {
                throw new \RuntimeException('Version Doctrine invalide : '.$version);
            }
            $path = $backend.'/migrations/'.$matches[1].'.php';
            if (is_link($path)) {
                throw new \RuntimeException('Migration symbolique interdite : '.$path);
            }
            if (!is_file($path) || !unlink($path)) {
                throw new \RuntimeException('Nettoyage de migration impossible : '.$path);
            }
        }
    }

    /** @param null|callable(string):void $progress */
    private function applyComposerMigrations(string $backend, ?string $databaseUrl, ?callable $progress, bool $bootstrap): void
    {
        $workspace = dirname($backend);
        $registry = new ModuleMigrationRegistry();
        $registered = $registry->read($workspace);
        $sources = (new ComposerModuleMigrationCatalog())->discover($backend);
        $changes = (new ModuleMigrationDiff())->compare($sources, $registered);
        if ($changes['removed'] !== []) {
            throw new \DomainException(
                'Sources de migrations absentes après opération Composer : '.implode(', ', $changes['removed']).
                '. Transaction annulée pour préserver la BDD.'
            );
        }
        if ($changes['pending'] === []) {
            return;
        }
        if ($databaseUrl === null) {
            if ($bootstrap && $this->isUnconfiguredWorkspace($backend)) {
                if ($progress !== null) {
                    $progress('Bootstrap : migrations conservées en attente du Setup Wizard.'.PHP_EOL);
                }
                return;
            }
            throw new \RuntimeException('Migrations détectées sans DATABASE_URL dans .env.local : transaction refusée.');
        }
        if ($progress !== null) {
            $progress('Compilation et exécution des migrations Composer...'.PHP_EOL);
        }
        $this->execute([
            PhpCliExecutableResolver::resolve(), 'bin/console', 'symsvel:module:migrations:compile',
            '--source=composer', '--execute', '--no-interaction',
        ], $backend, $progress);
    }

    /** An explicit bootstrap request is valid only before any module migration receipt exists. */
    private function isUnconfiguredWorkspace(string $backend): bool
    {
        $registry = new ModuleMigrationRegistry();
        return (new SetupState())->read(dirname($backend)) === SetupState::PENDING
            && $registry->read(dirname($backend)) === []
            && !is_file($backend.'/.env.local')
            && !is_link($backend.'/.env.local');
    }

    /**
     * Only enable database checkpoints when a concrete local DATABASE_URL is set.
     * Never attempt a destructive restore against a default/example .env.
     */
    private function databaseUrl(string $backend): ?string
    {
        $file = $backend.'/.env.local';
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $contents = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contents === false) {
            throw new \RuntimeException('Lecture de .env.local impossible.');
        }
        foreach ($contents as $line) {
            if (preg_match('/^\\s*DATABASE_URL\\s*=\\s*(.*?)\\s*$/', $line, $matches)) {
                $value = trim($matches[1]);
                if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                    $value = substr($value, 1, -1);
                }
                if ($value === '') {
                    throw new \RuntimeException('DATABASE_URL vide dans .env.local.');
                }
                return $value;
            }
        }
        return null;
    }

    /**
     * Fingerprint application source in all workspace components. This detects
     * unrolled-back changes without falsely claiming they have been restored.
     *
     * @return array<string, string>
     */
    private function workspaceFiles(string $backend): array
    {
        $files = [];
        foreach (['backend' => $backend, 'bo' => dirname($backend).'/bo', 'app' => dirname($backend).'/app', 'front' => dirname($backend).'/front'] as $component => $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach ($this->projectFiles($directory) as $path => $fingerprint) {
                $files[$component.'/'.$path] = $fingerprint;
            }
        }

        return $files;
    }

    /** @return array<string, string> */
    private function projectFiles(string $backend): array
    {
        $files = [];
        // Prune dependency and transaction directories before traversal, not after.
        // A post-filter still walks every file in vendor/, which can be enormous.
        $directory = new \RecursiveDirectoryIterator($backend, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $entry) use ($backend): bool {
                if ($entry->isDir() && !$entry->isLink()) {
                    return !in_array($entry->getFilename(), ['node_modules', '.git', '.svelte-kit', '.next', 'build', 'dist', '.cache'], true)
                    && !($entry->getFilename() === 'vendor' && $entry->getPath() === $backend)
                        && !($entry->getFilename() === 'transactions' && basename(dirname($entry->getPathname())) === '.symsvel');
                }

                return true;
            },
        );
        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (LocalMetadata::isIgnored($file) || (!$file->isFile() && !$file->isLink())) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($backend) + 1);
            if (preg_match('~^(?:vendor|node_modules|\\.git|\\.symsvel/transactions)/~', $relative)
                || in_array($relative, ['composer.json', 'composer.lock', 'symfony.lock', '.symsvel/cleanup-pending.json'], true)) {
                continue;
            }
            $files[$relative] = $file->isLink()
                ? 'symlink:'.readlink($file->getPathname())
                : 'file:'.hash_file('sha256', $file->getPathname()).':mode:'.decoct(fileperms($file->getPathname()) & 0777);
        }
        return $files;
    }

    /** @param list<string> $command */
    private function execute(array $command, string $backend, ?callable $progress): void
    {
        if ($this->commandRunner !== null) {
            ($this->commandRunner)($command, $backend, $progress);
            return;
        }
        $process = new Process(NativeCommand::resolve($command), $backend);
        $process->setTimeout(900);
        $process->run(static function (string $type, string $output) use ($progress): void {
            if ($progress !== null) {
                $progress($output);
            }
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Commande échouée (code '.$process->getExitCode().') : '.implode(' ', $command));
        }
    }
}
