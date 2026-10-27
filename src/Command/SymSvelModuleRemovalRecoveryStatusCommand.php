<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:remove:recovery:status')]
final class SymSvelModuleRemovalRecoveryStatusCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspace = (string) ($input->getOption('workspace') ?? dirname($this->projectDir));
        $journal = rtrim($workspace, '/').'/.symsvel/module-removal-recovery.json';
        if (!file_exists($journal) && !is_link($journal)) {
            $output->writeln('Aucune récupération de désinstallation en attente.');
            return Command::SUCCESS;
        }
        if (!is_file($journal) || is_link($journal)) {
            $output->writeln('<error>Journal invalide.</error>');
            return Command::FAILURE;
        }
        try {
            $data = json_decode((string) file_get_contents($journal), true, 512, JSON_THROW_ON_ERROR);
            $backup = $data['backup'] ?? null;
            if (!is_array($data) || !is_array($backup) || !is_string($data['state'] ?? null)) {
                throw new \RuntimeException('Journal illisible.');
            }
            $path = $backup['path'] ?? null;
            $valid = is_string($path) && is_file($path) && !is_link($path)
                && is_string($data['sha256'] ?? null)
                && hash_equals($data['sha256'], (string) hash_file('sha256', $path));
            $output->writeln('État : '.$data['state']);
            $output->writeln('Sauvegarde : '.($valid ? 'intègre' : 'absente ou altérée'));
        } catch (\Throwable $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');
        }
        return Command::FAILURE;
    }
}
