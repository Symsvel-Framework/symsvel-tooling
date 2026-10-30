<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use SymSvel\Tooling\Update\WorkspaceCleanupRegistry;

#[AsCommand(name: 'symsvel:cleanup', description: 'Nettoie les checkpoints des mises à jour SymSvel terminées')]
final class SymSvelCleanupCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = (new WorkspaceCleanupRegistry($this->projectDir))->cleanup();
            if ($result['errors'] !== []) {
                $io->warning('Nettoyage partiel : '.implode(' ; ', $result['errors']));
                return Command::FAILURE;
            }
            $io->success(sprintf('Nettoyage terminé : %d cible(s) supprimée(s), %d transaction(s) en attente.', $result['removed'], $result['remaining']));
            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $io->error($failure->getMessage());
            return Command::FAILURE;
        }
    }
}
