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

/** Complete the two-phase wizard only after the administrator and static builds are ready. */
#[AsCommand(name: 'symsvel:setup:complete', description: 'Verify initial administrator, build static interfaces and complete setup')]
final class SymSvelSetupCompleteCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly SetupState $state = new SetupState(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if (basename($this->projectDir) !== 'backend') {
                throw new \RuntimeException('Backend directory required.');
            }
            $workspace = dirname($this->projectDir);
            $status = $this->state->read($workspace);
            if ($status === SetupState::COMPLETED) {
                $output->writeln('<info>Setup already completed.</info>');
                return Command::SUCCESS;
            }
            if ($status !== SetupState::DATABASE_READY) {
                throw new \RuntimeException('Database phase must be completed first.');
            }
            $recovery = $workspace.'/.symsvel/migration-recovery.json';
            if (file_exists($recovery) || is_link($recovery)) {
                throw new \RuntimeException('Pending migration recovery must be resolved first.');
            }
            $verify = new Process([PHP_BINARY, 'bin/console', 'symsvel:admins:bootstrap:verify', '--no-interaction'], $this->projectDir);
            $verify->setTimeout(60);
            $verify->run();
            if (!$verify->isSuccessful()) {
                throw new \RuntimeException('Initial administrator verification failed.');
            }
            foreach (['bo', 'app', 'front'] as $component) {
                $directory = $workspace.'/'.$component;
                if (!file_exists($directory) && !is_link($directory)) {
                    continue;
                }
                if (is_link($directory) || !is_dir($directory) || !is_file($directory.'/package.json')) {
                    throw new \RuntimeException('Invalid interface component: '.$component);
                }
                $npm = getenv('SYMSVEL_SETUP_NPM');
                $executable = is_string($npm) && $npm !== '' && is_file($npm) && is_executable($npm) ? $npm : 'npm';
                // npm may use /usr/bin/env node; preserve its sibling node directory.
                $environment = $executable !== 'npm'
                    ? ['PATH' => dirname($executable).PATH_SEPARATOR.(string) getenv('PATH')]
                    : null;
                $build = new Process([$executable, 'run', 'build'], $directory, $environment);
                $build->setTimeout(600);
                $build->run();
                if (!$build->isSuccessful()) {
                    $diagnostic = trim($build->getErrorOutput()."\n".$build->getOutput());
                    throw new \RuntimeException('Static build failed for '.$component.' (exit '.$build->getExitCode().'). Setup remains resumable.'.($diagnostic !== '' ? "\n".$diagnostic : ' No output from npm.'));
                }
            }
            $this->state->transition($workspace, SetupState::DATABASE_READY, SetupState::COMPLETED);
            $output->writeln('<info>Setup completed.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
