<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use Doctrine\Persistence\ManagerRegistry;
use SymSvel\Tooling\Module\ModuleSchemaMigrationGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Internal Tooling endpoint; the public symsvel CLI will invoke this command.
 */
#[AsCommand(name: 'symsvel:module:migrate', description: 'Generate one module migration source from Doctrine Schema metadata')]
final class SymSvelModuleMigrateCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ManagerRegistry $doctrine,
        private readonly ModuleSchemaMigrationGenerator $generator = new ModuleSchemaMigrationGenerator(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::OPTIONAL, 'Module slug or manifest name');
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root');
        $this->addOption('working-directory', null, InputOption::VALUE_REQUIRED, 'Original CLI working directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $workspace = $input->getOption('workspace');
            if ($workspace === null) {
                if (basename(rtrim($this->projectDir, '/')) !== 'backend') {
                    throw new \InvalidArgumentException('Indiquez --workspace ou exécutez depuis backend/.');
                }
                $workspace = dirname($this->projectDir);
            }
            $cwd = $input->getOption('working-directory') ?? getcwd();
            if (!is_string($cwd) || $cwd === '') {
                throw new \InvalidArgumentException('Répertoire de travail invalide.');
            }
            $manager = $this->doctrine->getManager();
            if (!method_exists($manager, 'getConnection')) {
                throw new \RuntimeException('EntityManager Doctrine requis.');
            }
            $result = $this->generator->generate(
                (string) $workspace,
                $input->getArgument('module'),
                $cwd,
                $manager,
                $manager->getConnection(),
            );
            if (!$result['changed']) {
                $io->success('Aucune nouvelle migration.');
                return Command::SUCCESS;
            }
            $io->success('Migration source créée : '.$result['path']);
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
