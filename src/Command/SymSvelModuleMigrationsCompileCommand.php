<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleMigrationCatalog;
use SymSvel\Tooling\Module\ModuleMigrationCompiler;
use SymSvel\Tooling\Module\ModuleMigrationDiff;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:migrations:compile', description: 'Compile new module sources into Doctrine migrations without executing them')]
final class SymSvelModuleMigrationsCompileCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ModuleMigrationCatalog $catalog = new ModuleMigrationCatalog(),
        private readonly ModuleMigrationDiff $diff = new ModuleMigrationDiff(),
        private readonly ModuleMigrationRegistry $registry = new ModuleMigrationRegistry(),
        private readonly ModuleMigrationCompiler $compiler = new ModuleMigrationCompiler(),
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
        try {
            $workspace = $input->getOption('workspace') ?? dirname($this->projectDir);
            $registered = $this->registry->read((string) $workspace);
            $changes = $this->diff->compare($this->catalog->discover((string) $workspace), $registered);
            if ($changes['removed'] !== []) {
                throw new \DomainException('Sources intégrées absentes : '.implode(', ', $changes['removed']).'. Utilisez le cycle de désinstallation.');
            }
            if ($changes['pending'] === []) {
                $io->success('Aucune nouvelle migration.');
                return Command::SUCCESS;
            }
            $compiled = $this->compiler->compile($this->projectDir, $changes['pending']);
            if ($compiled === null) {
                return Command::SUCCESS;
            }
            // This command only compiles. The caller must execute Doctrine,
            // verify its success, then commit migration receipts.
            $io->success('Migration compilée : '.$compiled['path'].' (registre non modifié avant exécution Doctrine).');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
