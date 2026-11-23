<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleMigrationCatalog;
use SymSvel\Tooling\Module\ComposerModuleMigrationCatalog;
use SymSvel\Tooling\Module\ModuleMigrationCompiler;
use SymSvel\Tooling\Module\ModuleMigrationDiff;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;
use SymSvel\Tooling\Module\ModuleMigrationExecutor;
use SymSvel\Tooling\Installation\SetupState;
use SymSvel\Tooling\Module\InitialSetupMigrationResolver;
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
        private readonly ComposerModuleMigrationCatalog $composerCatalog = new ComposerModuleMigrationCatalog(),
        private readonly ModuleMigrationDiff $diff = new ModuleMigrationDiff(),
        private readonly ModuleMigrationRegistry $registry = new ModuleMigrationRegistry(),
        private readonly ModuleMigrationCompiler $compiler = new ModuleMigrationCompiler(),
        private readonly ModuleMigrationExecutor $executor = new ModuleMigrationExecutor(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Migration sources: workspace or composer', 'workspace');
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Execute compiled migrations using Doctrine and record receipts');
        $this->addOption('setup-initial', null, InputOption::VALUE_NONE, 'Reuse versioned module migrations during the initial database setup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $workspace = $input->getOption('workspace') ?? dirname($this->projectDir);
            $journalPath = rtrim((string) $workspace, '/').'/.symsvel/migration-recovery.json';
            if (file_exists($journalPath) || is_link($journalPath)) {
                throw new \RuntimeException('Migration recovery pending: '.$journalPath);
            }
            $source = $input->getOption('source');
            if (!in_array($source, ['workspace', 'composer'], true)) {
                throw new \InvalidArgumentException('Source de migrations invalide : '.(string) $source);
            }
            $registered = $this->registry->read((string) $workspace);
            $sources = $source === 'composer'
                ? $this->composerCatalog->discover($this->projectDir)
                : $this->catalog->discover((string) $workspace);
            $changes = $this->diff->compare($sources, $registered);
            if ($changes['removed'] !== []) {
                throw new \DomainException('Sources intégrées absentes : '.implode(', ', $changes['removed']).'. Utilisez le cycle de désinstallation.');
            }
            $execute = (bool) $input->getOption('execute');
            $initialSetup = (bool) $input->getOption('setup-initial');
            if ($initialSetup && (!$execute || $source !== 'composer'
                || (new SetupState())->read((string) $workspace) !== SetupState::RUNNING)) {
                throw new \DomainException('Réutilisation des migrations réservée au Setup initial en cours.');
            }
            if ($execute && !$initialSetup && ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'prod') {
                throw new \DomainException('Exécution de migrations interdite en production.');
            }
            if ($changes['pending'] === []) {
                $io->success('Aucune nouvelle migration.');
                return Command::SUCCESS;
            }
            // Compile every source before touching the database. The executor
            // commits receipts only when the complete batch succeeds.
            $compiled = [];
            try {
                foreach ($changes['pending'] as $source) {
                    if ($initialSetup) {
                        $migration = (new InitialSetupMigrationResolver())->resolve($this->projectDir, $source, $this->compiler);
                        if (!$migration['created']) {
                            $io->text('Réutilisée : '.$migration['path']);
                        }
                    } else {
                        $migration = $this->compiler->compile($this->projectDir, [$source]);
                        if ($migration !== null) {
                            $migration['created'] = true;
                        }
                    }
                    if ($migration !== null) {
                            $migration['created'] = true;
                        }
                    }
                    if ($migration !== null) {
                        $compiled[] = $migration;
                        if (!$initialSetup || ($migration['created'] ?? true)) {
                            $io->text('Compilée : '.$migration['path']);
                        }
                    }
                }
            } catch (\Throwable $compileFailure) {
                foreach ($compiled as $migration) {
                    if (($migration['created'] ?? true) && is_file($migration['path'])) {
                        unlink($migration['path']);
                    }
                }
                throw $compileFailure;
            }
            if ($execute) {
                $this->executor->install($this->projectDir, (string) $workspace, $compiled, $registered, $this->registry);
            }
            $io->success($execute ? 'Migrations exécutées.' : 'Compilation terminée. Utilisez --execute pour exécuter et enregistrer.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
