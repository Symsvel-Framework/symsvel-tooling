<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleMigrationRemovalPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:remove:migrations:plan', description: 'Preview destructive down migrations without modifying data')]
final class SymSvelModuleRemovalMigrationsPlanCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ModuleMigrationRemovalPlan $planner = new ModuleMigrationRemovalPlan(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::REQUIRED, 'Module slug');
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root');
        $this->addOption('cascade', null, InputOption::VALUE_NONE, 'Include dependent modules');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $workspace = (string) ($input->getOption('workspace') ?? dirname($this->projectDir));
            $plan = $this->planner->plan($workspace, (string) $input->getArgument('module'), (bool) $input->getOption('cascade'));
            if ($input->getOption('json')) {
                $output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $io->warning('Destructive removal plan. Module order: '.implode(' -> ', $plan['modules']));
                $io->text('Doctrine down order: '.implode(' -> ', $plan['versions']));
                $io->text('No data has been modified.');
            }
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
