<?php

declare(strict_types=1);

namespace SymSvel\Tooling;

use SymSvel\Tooling\Command\SymSvelInstallCommand;
use SymSvel\Tooling\Command\SymSvelMakeModuleCommand;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleManifest;
use SymSvel\Tooling\Module\ModuleRegistry;
use SymSvel\Tooling\Module\ModulePlanner;
use SymSvel\Tooling\Module\ModuleRemovalPlanner;
use SymSvel\Tooling\Module\ModuleMigrationCatalog;
use SymSvel\Tooling\Module\ModuleMigrationCompiler;
use SymSvel\Tooling\Module\ModuleMigrationExecutor;
use SymSvel\Tooling\Module\ModuleMigrationRecovery;
use SymSvel\Tooling\Module\ModuleMigrationRemovalPlan;
use SymSvel\Tooling\Module\ModuleTableBackup;
use SymSvel\Tooling\Command\SymSvelModuleRemovalMigrationsPlanCommand;
use SymSvel\Tooling\Module\ModuleMigrationDiff;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;
use SymSvel\Tooling\Module\ModuleSynchronizer;
use SymSvel\Tooling\Command\SymSvelModulePlanCommand;
use SymSvel\Tooling\Command\SymSvelModuleSyncCommand;
use SymSvel\Tooling\Command\SymSvelModuleRemovalPlanCommand;
use SymSvel\Tooling\Command\SymSvelModuleMigrationsCompileCommand;
use SymSvel\Tooling\Command\SymSvelModuleMigrationRecoveryStatusCommand;
use SymSvel\Tooling\Command\SymSvelModuleMigrationRecoverCommand;
use SymSvel\Tooling\Module\ModuleInventoryWriter;
use SymSvel\Tooling\Module\ModuleBundleResolver;
use SymSvel\Tooling\Module\ModuleBundleMapWriter;
use SymSvel\Tooling\Command\SymSvelUpdateCommand;
use SymSvel\Tooling\Update\WorkspaceUpdater;
use SymSvel\Tooling\Installation\ComponentInstaller;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SymSvelToolingBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(ModuleManifest::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleRegistry::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModulePlanner::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleTableBackup::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleMigrationRemovalPlan::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelModuleRemovalMigrationsPlanCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleMigrationRecovery::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleMigrationExecutor::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleMigrationCompiler::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleMigrationCatalog::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleMigrationDiff::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleMigrationRegistry::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleRemovalPlanner::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleSynchronizer::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelModulePlanCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleBundleResolver::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleBundleMapWriter::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleInventoryWriter::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelModuleMigrationRecoverCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelModuleMigrationRecoveryStatusCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelModuleMigrationsCompileCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelModuleRemovalPlanCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelModuleSyncCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(ModuleMaker::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelMakeModuleCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(ComponentInstaller::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelInstallCommand::class)
            ->autowire()
            ->autoconfigure()
            ->set(WorkspaceUpdater::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelUpdateCommand::class)
            ->autowire()
            ->autoconfigure();
    }
}
