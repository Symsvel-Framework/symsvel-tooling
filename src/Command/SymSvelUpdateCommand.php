<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Update\WorkspaceUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:update', description: 'Met à jour les composants SymSvel en local')]
final class SymSvelUpdateCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        private readonly WorkspaceUpdater $updater = new WorkspaceUpdater(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);
        $io->title('Mise à jour SymSvel');
        try {
            $this->updater->update($this->projectDir, $this->environment, static function (string $message) use ($io): void {
                $io->writeln('  '.$message);
            });
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf('Mise à jour locale terminée en %.1f s.', microtime(true) - $started));
        return Command::SUCCESS;
    }
}
