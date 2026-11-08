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
            if (!in_array($status, [SetupState::PENDING, SetupState::RUNNING, SetupState::DATABASE_READY], true)) {
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
            // Doctrine connects to the maintenance database when creating a missing
            // PostgreSQL database. The operation is safe to repeat on setup retries.
            $databaseCreate = new Process([
                PHP_BINARY, 'bin/console', 'doctrine:database:create',
                '--if-not-exists', '--no-interaction',
            ], $this->projectDir);
            $databaseCreate->setTimeout(60);
            $databaseCreate->run();
            if (!$databaseCreate->isSuccessful()) {
                throw new \RuntimeException('Création BDD impossible : '.trim($databaseCreate->getErrorOutput().' '.$databaseCreate->getOutput()));
            }
            // The migration executor opens the target DB connection itself.
            // Do not depend on optional Doctrine console SQL commands.
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
                throw new \RuntimeException('Création du premier administrateur impossible.');
            }
            $pendingAdmin = $workspace.'/.symsvel/pending-admin.json';
            if (is_link($pendingAdmin) || !is_file($pendingAdmin)) {
                throw new \RuntimeException('Données administrateur temporaires indisponibles après finalisation.');
            }
            if (!unlink($pendingAdmin)) {
                throw new \RuntimeException('Impossible de supprimer les données administrateur temporaires.');
            }
            // Compatibility with the current Admins module: it still requires RUNNING.
            // The two-phase wizard will activate DATABASE_READY only after its
            // provisioning contract is migrated away from staged credentials.
            if ($this->state->read($workspace) === SetupState::RUNNING) {
                $this->state->transition($workspace, SetupState::RUNNING, SetupState::DATABASE_READY);
            }
            $this->state->transition($workspace, SetupState::DATABASE_READY, SetupState::COMPLETED);
            $tokenFile = $workspace.'/.symsvel/setup-token';
            if (is_file($tokenFile) && !is_link($tokenFile)) {
                unlink($tokenFile);
            }
            $io->success('Setup terminé : migrations des modules Composer appliquées.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            // A failed setup discards the one-time administrator payload and
            // returns to the initial form. Database operations remain idempotent.
            if (basename($this->projectDir) === 'backend') {
                $workspace = dirname($this->projectDir);
                $pending = $workspace.'/.symsvel/pending-admin.json';
                if (is_file($pending) && !is_link($pending)) {
                    @unlink($pending);
                }
                try {
                    if (in_array($this->state->read($workspace), [SetupState::PENDING, SetupState::RUNNING], true)) {
                        $this->state->reset($workspace);
                    }
                } catch (\Throwable $cleanupError) {
                    $io->warning('Nettoyage setup incomplet : '.$cleanupError->getMessage());
                }
            }
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
