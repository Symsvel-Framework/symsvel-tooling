<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Installation\SetupState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'symsvel:setup:database', description: 'Create the database and run native migrations before administrator setup')]
final class SymSvelSetupDatabaseCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly SetupState $state = new SetupState(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stage = 'preflight';
        try {
            if (basename($this->projectDir) !== 'backend') {
                throw new \RuntimeException('Backend directory required.');
            }
            $workspace = dirname($this->projectDir);
            $status = $this->state->read($workspace);
            if ($status === SetupState::DATABASE_READY) {
                $output->writeln('<info>Database already prepared.</info>');
                return Command::SUCCESS;
            }
            if (!in_array($status, [SetupState::PENDING, SetupState::RUNNING], true)) {
                throw new \RuntimeException('Database setup is not available in the current state.');
            }
            $env = $this->projectDir.'/.env.local';
            if (is_link($env) || !is_file($env) || !is_readable($env)) {
                throw new \RuntimeException('Database configuration is missing or unreadable.');
            }
            $contents = file_get_contents($env);
            if (!is_string($contents) || preg_match('/^\s*DATABASE_URL\s*=\s*[^\r\n]+/m', $contents) !== 1) {
                throw new \RuntimeException('DATABASE_URL is missing.');
            }
            $recovery = $workspace.'/.symsvel/migration-recovery.json';
            if (file_exists($recovery) || is_link($recovery)) {
                throw new \RuntimeException('Pending migration recovery must be resolved first.');
            }
            if ($status === SetupState::PENDING) {
                $this->state->transition($workspace, SetupState::PENDING, SetupState::RUNNING);
            }
            preg_match('/^\\s*DATABASE_URL\\s*=\\s*([^\\r\\n]+)/m', $contents, $matches);
            $databaseUrl = trim(trim($matches[1]), chr(34).chr(39));
            $sqlite = str_starts_with($databaseUrl, 'sqlite:');
            if ($sqlite) {
                // SQLite opens/creates its database file on first connection.
                // Doctrine's database:create --if-not-exists cannot list SQLite databases.
                $prefix = 'sqlite:///%kernel.project_dir%/';
                if (!str_starts_with($databaseUrl, $prefix)) {
                    throw new \RuntimeException('Unsupported SQLite database path for local setup.');
                }
                $relativePath = substr($databaseUrl, strlen($prefix));
                if ($relativePath === '' || str_contains($relativePath, '..')
                    || preg_match('~^[a-zA-Z0-9_./-]+$~D', $relativePath) !== 1) {
                    throw new \RuntimeException('Invalid SQLite database path.');
                }
                $directory = dirname($this->projectDir.'/'.$relativePath);
                if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Unable to create SQLite database directory.');
                }
            }
            $commands = [];
            if (!$sqlite) {
                $commands[] = [PHP_BINARY, 'bin/console', 'doctrine:database:create', '--if-not-exists', '--no-interaction'];
            }
            // Module-owned migrations must run before Doctrine computes the residual diff.
            $commands[] = [PHP_BINARY, 'bin/console', 'symsvel:module:migrations:compile', '--source=composer', '--execute', '--setup-initial', '--no-interaction'];
            foreach ($commands as $arguments) {
                $stage = $arguments[2];
                $process = new Process($arguments, $this->projectDir);
                $process->setTimeout(null);
                $process->run();
                if (!$process->isSuccessful()) {
                    // Preserve the failing child command's diagnostic for the local-only Setup.
                    // Back sanitizes connection details before presenting this output in the Wizard.
                    $details = trim($process->getErrorOutput()."\n".$process->getOutput());
                    throw new \RuntimeException('Database preparation failed during '.($arguments[2] ?? 'console command')
                        .' (exit '.($process->getExitCode() ?? 'unknown').'). '
                        .($details !== '' ? $details : 'The command provided no diagnostic.'));
                }
            }

            // Native framework migrations are versioned in the backend skeleton.
            // Never generate a global ORM diff here: it would drop module-owned tables.
            $stage = 'doctrine:migrations:migrate';
            $migrate = new Process([PHP_BINARY, 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], $this->projectDir);
            $migrate->setTimeout(null);
            $migrate->run();
            if (!$migrate->isSuccessful()) {
                throw new \RuntimeException('Native Doctrine migration failed: '.trim($migrate->getErrorOutput().' '.$migrate->getOutput()));
            }
            // Settings rows are provisioned only after all module and native migrations.
            // The Settings module owns this command; Tooling only orchestrates it.
            if (is_file($this->projectDir.'/vendor/symsvel-framework/module-settings/back/src/SettingsBundle.php')) {
                $stage = 'symsvel:settings:sync';
                $sync = new Process([PHP_BINARY, 'bin/console', 'symsvel:settings:sync', '--no-interaction'], $this->projectDir);
                $sync->setTimeout(null);
                $sync->run();
                if (!$sync->isSuccessful()) {
                    throw new \RuntimeException('Initial settings synchronization failed: '.trim($sync->getErrorOutput().' '.$sync->getOutput()));
                }
            }
            // Persist source dictionaries only after Languages migrations and Settings sync.
            if (is_file($this->projectDir.'/vendor/symsvel-framework/module-languages/back/src/LanguagesBundle.php')) {
                $stage = 'symsvel:languages:translations:sync';
                $translations = new Process([PHP_BINARY, 'bin/console', 'symsvel:languages:translations:sync', '--no-interaction'], $this->projectDir);
                $translations->setTimeout(null);
                $translations->run();
                if (!$translations->isSuccessful()) {
                    throw new \RuntimeException('Translation source synchronization failed: '.trim($translations->getErrorOutput().' '.$translations->getOutput()));
                }
            }
            $this->state->transition($workspace, SetupState::RUNNING, SetupState::DATABASE_READY);
            $output->writeln('<info>Database and migrations ready.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');
            $output->writeln(sprintf('Setup diagnostic: stage=%s sapi=%s binary=%s exception=%s at %s:%d',
                $stage, PHP_SAPI, PHP_BINARY === '' ? '[empty]' : PHP_BINARY,
                get_class($exception), $exception->getFile(), $exception->getLine()));
            return Command::FAILURE;
        }
    }
}
