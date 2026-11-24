<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;
use SymSvel\Tooling\Module\SvelteModuleSynchronizer;
use SymSvel\Tooling\Update\WorkspaceCleanupRegistry;
use SymSvel\Tooling\Update\ProjectChannel;
use SymSvel\Tooling\Process\PhpCliExecutableResolver;
use Symfony\Component\Process\Process;

/** Installs a new interface and its module files as a single operation. */
final class ComponentLifecycleInstaller
{
    public function __construct(
        private readonly ComponentInstaller $installer = new ComponentInstaller(),
        private readonly SvelteModuleSynchronizer $synchronizer = new SvelteModuleSynchronizer(),
    ) {
    }

    public function install(string $backend, string $component): void
    {
        if (!in_array($component, ['bo', 'app', 'front'], true)) {
            throw new \InvalidArgumentException('Composant inconnu : '.$component);
        }
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if (basename($backend) !== 'backend' || !is_file($backend.'/composer.json')) {
            throw new \RuntimeException('Arborescence backend invalide.');
        }
        $workspace = dirname($backend);
        $target = $workspace.'/'.$component;
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException('Composant déjà présent : '.$component);
        }
        $transaction = $backend.'/.symsvel/transactions/update-'.bin2hex(random_bytes(12));
        if (!is_dir(dirname($transaction)) && !mkdir(dirname($transaction), 0700, true) && !is_dir(dirname($transaction))) {
            throw new \RuntimeException('Répertoire de transactions indisponible.');
        }
        if (!mkdir($transaction, 0700)) {
            throw new \RuntimeException('Création du checkpoint impossible.');
        }
        $sources = new WorkspaceFileSnapshot($backend, $transaction);
        try {
            $sources->capture();
        } catch (\Throwable $failure) {
            // No component has been installed yet; discard the partial checkpoint.
            try {
                $cleanup = new WorkspaceCleanupRegistry($backend);
                $cleanup->enqueue([$transaction]);
                $result = $cleanup->cleanup();
                if ($result['remaining'] > 0) {
                    throw new \RuntimeException(implode(' ; ', $result['errors']));
                }
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    'Snapshot installation échoué ; nettoyage impossible : '.$cleanupFailure->getMessage().'; snapshot : '.$transaction,
                    0,
                    $failure,
                );
            }
            throw $failure;
        }
        $databaseMigrationStarted = false;
        try {
            $this->installer->install($workspace, [$component], null, ProjectChannel::read($backend));
            if ($component === 'app') {
                $manifest = $backend.'/config/symsvel-native-modules.json';
                if (!is_file($manifest) || is_link($manifest)) {
                    throw new \RuntimeException('Manifeste des modules natifs absent ou invalide.');
                }
                $config = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
                if (($config['schema'] ?? null) !== 1) {
                    throw new \RuntimeException('Version du manifeste natif invalide.');
                }
                $packages = $config['components']['app'] ?? null;
                if (!is_array($packages) || !array_is_list($packages)) {
                    throw new \RuntimeException('Modules natifs App non déclarés.');
                }
                $launcher = $backend.'/scripts/symsvel-composer-transaction.php';
                if (!is_file($launcher) || is_link($launcher)) {
                    throw new \RuntimeException('Lanceur Composer transactionnel introuvable.');
                }
                foreach ($packages as $package) {
                    if (!is_string($package) || preg_match('~^symsvel-framework/module-[a-z][a-z0-9-]*$~D', $package) !== 1) {
                        throw new \RuntimeException('Package natif App invalide.');
                    }
                    $require = new Process([PhpCliExecutableResolver::resolve(), $launcher, 'require', $package, '--bootstrap'], $backend);
                    $require->setTimeout(600);
                    $require->run();
                    if (!$require->isSuccessful()) {
                        throw new \RuntimeException('Installation du module natif App impossible : '.trim($require->getErrorOutput() ?: $require->getOutput()));
                    }
                }
            }
            $this->synchronizer->sync($backend);
            // During initial setup, defer all interface builds until the wizard
            // has finished provisioning the database and first administrator.
            if (getenv('SYMSVEL_DEFER_BUILDS') !== '1') {
                $build = new Process(['npm', 'run', 'build'], $target);
                $build->setTimeout(600);
                $build->run();
                if (!$build->isSuccessful()) {
                    throw new \RuntimeException('Build après synchronisation impossible : '.trim($build->getErrorOutput() ?: $build->getOutput()));
                }
            }
            if ($component === 'app') {
                // The initial Setup Wizard executes migrations separately; after
                // a completed setup, the newly installed module needs its tables.
                $stateFile = $workspace.'/.symsvel/setup.json';
                if (is_file($stateFile) && !is_link($stateFile)) {
                    $state = json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
                    if (($state['status'] ?? null) === 'completed') {
                        $databaseMigrationStarted = true;
                        $migrate = new Process([PhpCliExecutableResolver::resolve(), 'bin/console', 'symsvel:module:migrations:compile', '--source=composer', '--execute', '--no-interaction'], $backend);
                        $migrate->setTimeout(600);
                        $migrate->run();
                        if (!$migrate->isSuccessful()) {
                            throw new \RuntimeException('Migrations du module natif App impossibles : '.trim($migrate->getErrorOutput() ?: $migrate->getOutput()));
                        }
                    }
                }
            }
        } catch (\Throwable $failure) {
            if ($databaseMigrationStarted) {
                // Database changes are outside WorkspaceFileSnapshot. Never roll back
                // Composer/module sources while migrations may have been applied.
                throw new \RuntimeException(
                    'Installation App interrompue pendant les migrations ; sources et checkpoint conservés pour diagnostic et reprise. Ne pas relancer automatiquement. Checkpoint : '.$transaction,
                    0,
                    $failure,
                );
            }
            $errors = [];
            if (is_dir($target) && !is_link($target)) {
                $remove = new Process(['rm', '-rf', '--', $target]);
                $remove->run();
                if (!$remove->isSuccessful()) {
                    $errors[] = 'Suppression du composant : '.trim($remove->getErrorOutput());
                }
            }
            try {
                $sources->restore();
            } catch (\Throwable $error) {
                $errors[] = 'Restauration des sources : '.$error->getMessage();
            }
            if ($errors !== []) {
                throw new \RuntimeException('Installation échouée ; rollback incomplet : '.implode(' ; ', $errors).'; snapshot : '.$transaction, 0, $failure);
            }
            throw new \RuntimeException('Installation annulée, état précédent restauré : '.$failure->getMessage(), 0, $failure);
        }
        $cleanup = new WorkspaceCleanupRegistry($backend);
        $cleanup->enqueue([$transaction]);
        $cleanup->cleanup();
    }
}
