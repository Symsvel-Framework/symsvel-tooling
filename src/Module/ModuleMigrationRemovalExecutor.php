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
        $this->transaction->run($workspace, $databaseUrl, $plan['tables'], function () use ($backend, $workspace, $plan): void {
            foreach ($plan['versions'] as $version) {
                $this->migrations->execute($backend, $version, 'down');
            }
            $this->registry->write($workspace, $plan['remaining']);
        });
    }
}
