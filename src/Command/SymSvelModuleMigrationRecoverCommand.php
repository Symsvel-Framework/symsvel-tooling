<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleMigrationExecutor;
use SymSvel\Tooling\Module\ModuleMigrationRecovery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:migrations:recover', description: 'Resume confirmed migration compensations without guessing interrupted SQL state')]
final class SymSvelModuleMigrationRecoverCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ModuleMigrationRecovery $recovery = new ModuleMigrationRecovery(),
        private readonly ModuleMigrationExecutor $executor = new ModuleMigrationExecutor(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'prod') {
            $io->error('Recovery execution is forbidden in production.');
            return Command::FAILURE;
        }
        try {
            $workspace = (string) ($input->getOption('workspace') ?? dirname($this->projectDir));
            $completed = $this->recovery->recover($this->projectDir, $workspace, $this->executor);
            $io->success('Compensations confirmed: '.count($completed).'. Recovery journal retained for database verification.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
