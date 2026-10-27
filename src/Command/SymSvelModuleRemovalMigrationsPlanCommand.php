<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleMigrationRemovalPlan;
use SymSvel\Tooling\Module\ModuleCompiledMigrationGuard;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;
use SymSvel\Tooling\Module\ModuleRemovalConfirmation;
use SymSvel\Tooling\Module\ModuleTableOwnership;
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
        private readonly ModuleTableOwnership $ownership = new ModuleTableOwnership(),
        private readonly ModuleMigrationRegistry $receipts = new ModuleMigrationRegistry(),
        private readonly ModuleRemovalConfirmation $confirmation = new ModuleRemovalConfirmation(),
        private readonly ModuleCompiledMigrationGuard $compiledGuard = new ModuleCompiledMigrationGuard(),
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
            // A receipt alone cannot guarantee that Doctrine can execute down().
            // Refuse a plan if its compiled migration file is missing or unsafe.
            $this->compiledGuard->assertAvailable($this->projectDir, $plan['versions']);
            $plan['tables'] = $this->ownership->tables($workspace, $plan['modules']);
            if ($plan['tables'] === [] && $plan['versions'] !== []) {
                throw new \DomainException('Migrations intégrées mais aucune table déclarée : suppression bloquée.');
            }
            // Validate the exact receipt delta before any destructive operation.
            $plan['remaining_receipt_count'] = count($this->receipts->withoutSources($workspace, $plan['sources']));
            // Stable confirmation token for the exact destructive scope.
            // A later executor must recompute and compare it before touching SQL.
            $plan['confirmation'] = $this->confirmation->fingerprint($workspace, $plan);
            if ($input->getOption('json')) {
                $output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $io->warning('Destructive removal plan. Module order: '.implode(' -> ', $plan['modules']));
                $io->text('Doctrine down order: '.implode(' -> ', $plan['versions']));
                $io->text('Owned tables: '.($plan['tables'] === [] ? '(none)' : implode(', ', $plan['tables'])));
                $io->text('Remaining migration receipts: '.$plan['remaining_receipt_count']);
                $io->text('Confirmation fingerprint: '.$plan['confirmation']);
                $io->text('SQL backup required: '.($plan['tables'] === [] ? 'no' : 'yes'));
                $io->text('Preflight only. Execute separately with symsvel:module:remove:migrations:execute <module> --confirm=<fingerprint>.');
                $io->text('No data has been modified.');
            }
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
