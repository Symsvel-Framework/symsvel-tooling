<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:module:migrations:recovery:status', description: 'Inspect a pending migration recovery without modifying the database')]
final class SymSvelModuleMigrationRecoveryStatusCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workspace = (string) ($input->getOption('workspace') ?? dirname($this->projectDir));
        $path = rtrim($workspace, '/').'/.symsvel/migration-recovery.json';
        if (is_link($path)) {
            $io->error('Journal de récupération symbolique interdit.');
            return Command::FAILURE;
        }
        if (!is_file($path)) {
            $io->success('Aucune récupération en attente.');
            return Command::SUCCESS;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['schema'] ?? null) !== 1
                || !in_array($data['state'] ?? null, ['running', 'recovering'], true)
                || !is_array($data['planned'] ?? null) || !is_array($data['completed'] ?? null)
                || !array_is_list($data['planned']) || !array_is_list($data['completed'])
                || !is_array($data['rolled_back'] ?? []) || !array_is_list($data['rolled_back'] ?? [])
                || array_filter($data['rolled_back'] ?? [], static fn (mixed $v): bool => !is_string($v)) !== []
                || array_filter($data['planned'], static fn (mixed $v): bool => !is_string($v)) !== []
                || array_filter($data['completed'], static fn (mixed $v): bool => !is_string($v)) !== []
                || (!is_null($data['in_flight'] ?? null) && !is_string($data['in_flight']))) {
                throw new \RuntimeException('Format du journal invalide.');
            }
            if ($input->getOption('json')) {
                $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $io->warning('Récupération requise : '.$data['state']);
                $io->text('Versions terminées : '.implode(', ', $data['completed']));
                $io->text('Version interrompue : '.($data['in_flight'] ?? 'aucune'));
                $io->text('Compensations confirmées : '.implode(', ', $data['rolled_back'] ?? []));
                $io->text('Ne supprimez pas le journal avant vérification des versions Doctrine et de la base.');
            }
            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
