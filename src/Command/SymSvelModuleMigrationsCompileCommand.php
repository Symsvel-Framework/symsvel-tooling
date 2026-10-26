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
use Symfony\Component\Process\Process;
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
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Execute compiled migrations using Doctrine and record receipts');
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
            $execute = (bool) $input->getOption('execute');
            // Compile one source per Doctrine version. This preserves independent
            // rollback and module ownership, even when sources share a release.
            foreach ($changes['pending'] as $source) {
                $compiled = $this->compiler->compile($this->projectDir, [$source]);
                if ($compiled === null) {
                    continue;
                }
                if (!$execute) {
                    $io->text('Compilée : '.$compiled['path'].' (non exécutée).');
                    continue;
                }
                $process = new Process(
                    [PHP_BINARY, 'bin/console', 'doctrine:migrations:execute', $compiled['version'], '--up', '--no-interaction'],
                    $this->projectDir,
                );
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new \\RuntimeException('Doctrine a échoué pour '.$compiled['version'].': '.$process->getErrorOutput().' '.$process->getOutput());
                }
                $registered = array_merge($registered, $compiled['entries']);
                $this->registry->write((string) $workspace, $registered);
                $io->text('Exécutée et enregistrée : '.$compiled['version']);
            }
            $io->success($execute ? 'Migrations exécutées.' : 'Compilation terminée. Utilisez --execute pour exécuter et enregistrer.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
