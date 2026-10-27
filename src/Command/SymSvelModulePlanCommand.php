<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:plan', description: 'Validate and preview local module synchronization')]
final class SymSvelModulePlanCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ModuleSynchronizer $synchronizer = new ModuleSynchronizer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'SymSvel workspace root');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the plan as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workspace = $input->getOption('workspace');
        if ($workspace === null) {
            if (basename(rtrim($this->projectDir, '/')) !== 'backend') {
                $io->error('Indiquez --workspace ou lancez depuis backend/.');
                return Command::INVALID;
            }
            $workspace = dirname($this->projectDir);
        }
        if (!is_dir((string) $workspace)) {
            $io->error('Workspace introuvable.');
            return Command::INVALID;
        }
        try {
            $plan = $this->synchronizer->prepare((string) $workspace);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }
        if ($plan === []) {
            $io->note('Aucun module local détecté.');
            return Command::SUCCESS;
        }
        foreach ($plan as $step) {
            $io->writeln($step['module'].' : '.implode(', ', array_keys($step['components'])));
        }
        $io->success(count($plan).' module(s) validé(s). Aucune modification effectuée.');
        return Command::SUCCESS;
    }
}
