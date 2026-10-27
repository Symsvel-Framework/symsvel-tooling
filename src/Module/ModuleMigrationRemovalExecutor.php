<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Executes an already authorized migration removal under the SQL backup
 * boundary. Files and Composer packages are deliberately not removed here.
 */
final class ModuleMigrationRemovalExecutor
{
    public function __construct(
        private readonly ModuleRemovalExecutionGate $gate = new ModuleRemovalExecutionGate(),
        private readonly ModuleRemovalTransaction $transaction = new ModuleRemovalTransaction(),
        private readonly ModuleMigrationExecutor $migrations = new ModuleMigrationExecutor(),
        private readonly ModuleMigrationRegistry $registry = new ModuleMigrationRegistry(),
    ) {
    }

    public function run(
        string $workspace,
        string $backend,
        string $databaseUrl,
        string $module,
        bool $cascade,
        string $confirmation,
    ): void {
        $plan = $this->gate->authorize($workspace, $backend, $module, $cascade, $confirmation);
        if ($plan['tables'] === []) {
            throw new \DomainException('Désinstallation SQL sans tables déclarées non prise en charge.');
        }
        $previousReceipts = $this->registry->read($workspace);
        try {
            $this->transaction->run($workspace, $databaseUrl, $plan['tables'], function () use ($backend, $workspace, $plan): void {
                foreach ($plan['versions'] as $version) {
                    $this->migrations->execute($backend, $version, 'down');
                }
                $this->registry->write($workspace, $plan['remaining']);
            });
        } catch (\Throwable $failure) {
            // Do not revert committed receipts when only backup cleanup failed.
            $journal = rtrim($workspace, '/').'/.symsvel/module-removal-recovery.json';
            if (is_file($journal) && !is_link($journal)) {
                $data = json_decode((string) file_get_contents($journal), true);
                if (is_array($data) && ($data['state'] ?? null) === 'sql_restored') {
                    try {
                        $this->registry->write($workspace, $previousReceipts);
                    } catch (\Throwable $receiptFailure) {
                        throw new \RuntimeException('SQL restauré mais reçus non restaurés : '.$receiptFailure->getMessage(), 0, $failure);
                    }
                }
            }
            throw $failure;
        }

        // The SQL transaction and receipt commit have succeeded. Only remove
        // Doctrine files belonging to the confirmed removal plan.
        foreach ($plan['versions'] as $version) {
            if (!preg_match('/^DoctrineMigrations\\\\(Version[A-Za-z0-9_]+)$/D', $version, $matches)) {
                throw new \RuntimeException('Version Doctrine invalide pour le nettoyage : '.$version);
            }
            $path = rtrim($backend, '/').'/migrations/'.$matches[1].'.php';
            if (is_link($path)) {
                throw new \RuntimeException('Migration symbolique interdite : '.$path);
            }
            if (!is_file($path)) {
                throw new \RuntimeException('Désinstallation SQL terminée, fichier de migration introuvable : '.$path);
            }
            if (!unlink($path)) {
                throw new \RuntimeException('Désinstallation SQL terminée, nettoyage manuel requis : '.$path);
            }
        }
    }
}
