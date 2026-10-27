<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Module\ModuleInventoryWriter;
use SymSvel\Tooling\Module\ModuleBundleMapWriter;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'SymSvel workspace root');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
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
