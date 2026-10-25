<?php

declare(strict_types=1);

namespace SymSvel\Tooling;

use SymSvel\Tooling\Command\SymSvelInstallCommand;
use SymSvel\Tooling\Installation\ComponentInstaller;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SymSvelToolingBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(ComponentInstaller::class)
            ->autowire()
            ->autoconfigure()
            ->set(SymSvelInstallCommand::class)
            ->autowire()
            ->autoconfigure();
    }
}
