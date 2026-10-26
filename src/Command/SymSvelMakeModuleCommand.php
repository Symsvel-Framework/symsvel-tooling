<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleMaker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:make:module', description: 'Generate a SymSvel module in the workspace modules/ directory')]
final class SymSvelMakeModuleCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ModuleMaker $maker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Module slug (e.g. documents)');
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root (defaults to parent of backend/)');
        $this->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Profile: bo, app, front or all (default: back)', 'back');
        $this->addOption('vendor', null, InputOption::VALUE_REQUIRED, 'Composer vendor', 'symsvel-framework');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workspace = $input->getOption('workspace');
        if ($workspace === null) {
            if (basename(rtrim($this->projectDir, '/')) !== 'backend') {
                $io->error('Indiquez --workspace, ou exécutez depuis un backend/ SymSvel.');
                return Command::INVALID;
            }
            $workspace = dirname($this->projectDir);
        }
        try {
            $destination = $this->maker->create((string) $workspace, (string) $input->getArgument('name'), (string) $input->getOption('vendor'), (string) $input->getOption('scopes'));
            $io->success('Module créé : '.$destination);
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
