<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleMigrationRemovalExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:remove:migrations:execute', description: 'Execute confirmed module down migrations with SQL backup')]
final class SymSvelModuleRemovalMigrationsExecuteCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ModuleMigrationRemovalExecutor $executor = new ModuleMigrationRemovalExecutor(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::REQUIRED, 'Module slug');
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root');
        $this->addOption('cascade', null, InputOption::VALUE_NONE, 'Remove dependent module migrations too');
        $this->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Exact fingerprint from symsvel:module:remove:migrations:plan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $confirmation = $input->getOption('confirm');
        if (!is_string($confirmation) || !preg_match('/^[a-f0-9]{64}$/D', $confirmation)) {
            $io->error('Confirmation obligatoire : fournissez --confirm=<empreinte du plan>.');
            return Command::INVALID;
        }
        $workspace = $input->getOption('workspace');
        if ($workspace === null) {
            $workspace = dirname($this->projectDir);
        }
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!is_string($databaseUrl) || $databaseUrl === '') {
            $io->error('DATABASE_URL absent : exécution refusée.');
            return Command::INVALID;
        }
        try {
            $this->executor->run(
                (string) $workspace,
                $this->projectDir,
                $databaseUrl,
                (string) $input->getArgument('module'),
                (bool) $input->getOption('cascade'),
                $confirmation,
            );
            $io->success('Migrations down exécutées et reçus mis à jour. Fichiers du module et dépendances Composer conservés.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
