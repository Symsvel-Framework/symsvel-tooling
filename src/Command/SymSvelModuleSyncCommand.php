<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleInventoryWriter;
use SymSvel\Tooling\I18n\TranslationSourceCatalog;
use SymSvel\Tooling\Module\SvelteModuleSynchronizer;
use SymSvel\Tooling\Module\ComposerModuleSynchronizer;
use SymSvel\Tooling\Module\ComposerModuleRouteSynchronizer;
use SymSvel\Tooling\Module\ComposerModuleInventoryWriter;
use SymSvel\Tooling\Module\ComposerModuleBundleMapWriter;
use SymSvel\Tooling\Module\ModuleBundleMapWriter;
use SymSvel\Tooling\Update\LocalBoDomainPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:sync', description: 'Publish the validated local module inventory')]
final class SymSvelModuleSyncCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ModuleInventoryWriter $writer = new ModuleInventoryWriter(),
        private readonly ModuleBundleMapWriter $bundleWriter = new ModuleBundleMapWriter(),
        private readonly ComposerModuleSynchronizer $composerSynchronizer = new ComposerModuleSynchronizer(),
        private readonly ComposerModuleInventoryWriter $composerWriter = new ComposerModuleInventoryWriter(),
        private readonly ComposerModuleBundleMapWriter $composerBundleWriter = new ComposerModuleBundleMapWriter(),
        private readonly ComposerModuleRouteSynchronizer $composerRoutes = new ComposerModuleRouteSynchronizer(),
        private readonly SvelteModuleSynchronizer $svelteSynchronizer = new SvelteModuleSynchronizer(),
        private readonly LocalBoDomainPublisher $domainPublisher = new LocalBoDomainPublisher(),
        private readonly TranslationSourceCatalog $translationSources = new TranslationSourceCatalog(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'SymSvel workspace root');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Module source: local or composer', 'local');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');
        if (!in_array($source, ['local', 'composer'], true)) {
            $io->error('Source invalide : utilisez local ou composer.');
            return Command::INVALID;
        }
        if ($source === 'composer') {
            try {
                // Validate both outputs before publishing either one.
                $this->composerSynchronizer->prepare($this->projectDir);
                (new \SymSvel\Tooling\Module\ModuleBundleResolver())->resolve(
                    (new \SymSvel\Tooling\Module\ComposerModuleRegistry())->discover($this->projectDir),
                );
                // Validate sources before copying files, then stage the BDD inventory.
                $sources = $this->translationSources->collect($this->projectDir, $this->composerSynchronizer->prepare($this->projectDir));
                $routes = $this->composerRoutes->sync($this->projectDir);
                $svelte = $this->svelteSynchronizer->sync($this->projectDir);
                $this->translationSources->publish($this->projectDir, $sources);
                $this->composerBundleWriter->write($this->projectDir);
                $path = $this->composerWriter->write($this->projectDir);
                // Runs as the CLI owner, not as the PHP-FPM runtime user.
                $this->domainPublisher->publish($this->projectDir);
                $io->success(sprintf('Inventaire Composer et carte des Bundles publiés : %s. Routes locales : %d écrites, %d supprimées, %d externes préservées. Svelte : %d écrits, %d supprimés. Aucun Bundle activé.', $path, $routes['written'], $routes['removed'], $routes['external'], $svelte['written'], $svelte['removed']));
                return Command::SUCCESS;
            } catch (\Throwable $exception) {
                $io->error($exception->getMessage());
                return Command::FAILURE;
            }
        }
        $workspace = $input->getOption('workspace');
        if ($workspace === null) {
            if (basename(rtrim($this->projectDir, '/')) !== 'backend') {
                $io->error('Indiquez --workspace ou lancez depuis backend/.');
                return Command::INVALID;
            }
            $workspace = dirname($this->projectDir);
        }
        try {
            $this->bundleWriter->write((string) $workspace);
            $path = $this->writer->write((string) $workspace);
            $io->success('Inventaire et carte des Bundles publiés : '.$path);
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
