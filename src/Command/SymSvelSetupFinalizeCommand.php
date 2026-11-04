<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Installation\SetupState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'symsvel:setup:finalize', description: 'Execute deferred native module migrations and complete initial setup')]
final class SymSvelSetupFinalizeCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly SetupState $state = new SetupState(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if (basename($this->projectDir) !== 'backend') {
                throw new \RuntimeException('Le backend doit se trouver dans backend/.');
            }
            $workspace = dirname($this->projectDir);
            $status = $this->state->read($workspace);
            if ($status === SetupState::COMPLETED) {
                $io->success('Setup déjà terminé.');
                return Command::SUCCESS;
            }
            if ($status !== SetupState::PENDING && $status !== SetupState::RUNNING) {
                throw new \RuntimeException('Setup non initialisé.');
            }
            if (file_exists($workspace.'/.symsvel/migration-recovery.json')
                || is_link($workspace.'/.symsvel/migration-recovery.json')) {
                throw new \RuntimeException('Récupération de migrations en attente : résolvez le journal avant de finaliser.');
            }
            $environment = $this->projectDir.'/.env.local';
            if (is_link($environment) || !is_file($environment)) {
                throw new \RuntimeException('Configurez DATABASE_URL dans backend/.env.local avant la finalisation.');
            }
            $lines = file($environment, FILE_IGNORE_NEW_LINES);
            $databaseUrl = null;
            if ($lines !== false) {
                foreach ($lines as $line) {
                    if (preg_match('/^\\s*DATABASE_URL\\s*=\\s*(.*?)\\s*$/', $line, $matches) !== 1) {
                        continue;
                    }
                    $databaseUrl = trim(trim($matches[1]), "\"'");
                }
            }
            if ($databaseUrl === null || $databaseUrl === '') {
                throw new \RuntimeException('DATABASE_URL manquant dans backend/.env.local.');
            }
            if ($status === SetupState::PENDING) {
                $this->state->transition($workspace, SetupState::PENDING, SetupState::RUNNING);
            }
            $connectionCheck = new Process([
                PHP_BINARY, 'bin/console', 'doctrine:query:sql', 'SELECT 1', '--no-interaction',
            ], $this->projectDir);
            $connectionCheck->setTimeout(60);
            $connectionCheck->run();
            if (!$connectionCheck->isSuccessful()) {
                throw new \RuntimeException('Connexion BDD impossible : '.trim($connectionCheck->getErrorOutput().' '.$connectionCheck->getOutput()));
            }
            $process = new Process([
                PHP_BINARY, 'bin/console', 'symsvel:module:migrations:compile',
                '--source=composer', '--execute', '--no-interaction',
            ], $this->projectDir);
            $process->setTimeout(null);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Migrations non finalisées : '.trim($process->getErrorOutput().' '.$process->getOutput()));
            }
            $bootstrap = new Process([
                PHP_BINARY, 'bin/console', 'symsvel:admins:bootstrap', '--no-interaction',
            ], $this->projectDir);
            $bootstrap->setTimeout(60);
            $bootstrap->run();
            if (!$bootstrap->isSuccessful()) {
                throw new \RuntimeException('Création du premier administrateur impossible. Données temporaires conservées pour une nouvelle tentative.');
            }
            $this->state->transition($workspace, SetupState::RUNNING, SetupState::COMPLETED);
            $pendingAdmin = $workspace.'/.symsvel/pending-admin.json';
            if (is_link($pendingAdmin) || !is_file($pendingAdmin)) {
                throw new \RuntimeException('Données administrateur temporaires indisponibles après finalisation.');
            }
            if (!unlink($pendingAdmin)) {
                throw new \RuntimeException('Impossible de supprimer les données administrateur temporaires.');
            }
            $tokenFile = $workspace.'/.symsvel/setup-token';
            if (is_file($tokenFile) && !is_link($tokenFile)) {
                unlink($tokenFile);
            }
            $io->success('Setup terminé : migrations des modules Composer appliquées.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
