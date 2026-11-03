<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Installation\ComponentInstaller;
use SymSvel\Tooling\Update\ComponentUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Entry point for the CLI's component lifecycle.
 *
 * Existing components are updated through a Git/source/runtime checkpoint.
 */
#[AsCommand(name: 'symsvel:component:update', description: 'Installe ou met à jour une interface SvelteKit')]
final class SymSvelComponentUpdateCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ComponentInstaller $installer = new ComponentInstaller(),
        private readonly ComponentUpdater $updater = new ComponentUpdater(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('component', InputArgument::REQUIRED, 'bo, app ou front');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $component = $input->getArgument('component');
        if (!is_string($component) || !in_array($component, ['bo', 'app', 'front'], true)) {
            $io->error('Composant invalide : bo, app ou front attendu.');
            return Command::INVALID;
        }

        if (basename(rtrim($this->projectDir, '/')) !== 'backend'
            || !is_file($this->projectDir.'/composer.json')) {
            $io->error('Le backend Symfony doit se trouver dans backend/ avec composer.json.');
            return Command::FAILURE;
        }

        $workspace = dirname($this->projectDir);
        $target = $workspace.'/'.$component;
        if (file_exists($target) || is_link($target)) {
            try {
                $this->updater->update($this->projectDir, $component);
                $io->success('Composant '.$component.' mis à jour.');
                return Command::SUCCESS;
            } catch (\Throwable $exception) {
                $io->error($exception->getMessage());
                return Command::FAILURE;
            }
        }

        try {
            $this->installer->install($workspace, [$component], static function (string $name) use ($io): void {
                $io->text('Installation de '.$name.'...');
            });
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success('Composant '.$component.' installé.');
        return Command::SUCCESS;
    }
}
