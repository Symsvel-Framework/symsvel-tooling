<?php

declare(strict_types=1);

namespace SymSvel\Tooling;

use SymSvel\Tooling\Command\SymSvelInstallCommand;
use SymSvel\Tooling\Command\SymSvelMakeModuleCommand;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleManifest;
use SymSvel\Tooling\Module\ModuleRegistry;
use SymSvel\Tooling\Module\ModulePlanner;
use SymSvel\Tooling\Module\ModuleSynchronizer;
use SymSvel\Tooling\Command\SymSvelModulePlanCommand;
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
            ->set(ModuleSynchronizer::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelModulePlanCommand::class)
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
