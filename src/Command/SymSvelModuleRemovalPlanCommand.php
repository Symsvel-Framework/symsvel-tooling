<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleRemovalPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:remove:plan', description: 'Preview module removal and dependent modules without changing files or data')]
final class SymSvelModuleRemovalPlanCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ModuleRemovalPlanner $planner = new ModuleRemovalPlanner(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::REQUIRED, 'Module slug');
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root');
        $this->addOption('cascade', null, InputOption::VALUE_NONE, 'Include dependent modules in removal plan');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspace = $input->getOption('workspace');
        if ($workspace === null) {
            if (basename(rtrim($this->projectDir, '/')) !== 'backend') {
                (new SymfonyStyle($input, $output))->error('Indiquez --workspace.');
                return Command::INVALID;
            }
            $workspace = dirname($this->projectDir);
        }

        try {
            $plan = $this->planner->plan((string) $workspace, (string) $input->getArgument('module'), (bool) $input->getOption('cascade'));
            if ($input->getOption('json')) {
                $output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                (new SymfonyStyle($input, $output))->success('Ordre de suppression : '.implode(' -> ', $plan['removal']));
            }
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            (new SymfonyStyle($input, $output))->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
