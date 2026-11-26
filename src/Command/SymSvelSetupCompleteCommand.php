<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Installation\SetupState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use SymSvel\Tooling\Process\PhpCliExecutableResolver;
use SymSvel\Tooling\Process\NativeCommand;
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
            $php = PhpCliExecutableResolver::resolve();
            // Temporary setup diagnostic, without credentials or full environment.
            $trace = static function (string $line) use ($workspace): void {
                @file_put_contents($workspace.'/.symsvel/npm-trace.log',
                    gmdate('c').' [tooling pid='.getmypid().'] '.$line."\n", FILE_APPEND | LOCK_EX);
            };
            $trace('start npm='.var_export(getenv('SYMSVEL_SETUP_NPM'), true)
                .' PATH='.var_export(getenv('PATH'), true).' php='.$php);
            $verify = new Process([$php, 'bin/console', 'symsvel:admins:bootstrap:verify', '--no-interaction'], $this->projectDir);
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
                $executable = is_string($npm) && $npm !== '' && is_file($npm) && (PHP_OS_FAMILY === 'Windows' || is_executable($npm)) ? $npm : 'npm';
                // npm may use /usr/bin/env node; preserve its sibling node directory.
                $environment = $executable !== 'npm'
                    ? ['PATH' => dirname($executable).PATH_SEPARATOR.(string) getenv('PATH')]
                    : null;
                $trace('build component='.$component.' npm_env='.var_export($npm, true).' executable='.$executable.' executable_exists='.(is_file($executable) ? 'yes' : 'no').' build_PATH='.var_export($environment['PATH'] ?? getenv('PATH'), true));
                $build = new Process(NativeCommand::resolve([$executable, 'run', 'build']), $directory, $environment);
                $build->setTimeout(600);
                $build->run();
                $trace('build component='.$component.' exit='.var_export($build->getExitCode(), true));
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
