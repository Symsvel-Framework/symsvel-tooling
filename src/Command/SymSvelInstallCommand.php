<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'symsvel:install', description: 'Install the SymSvel SvelteKit interfaces')]
final class SymSvelInstallCommand extends Command
{
    private const REPOSITORIES = [
        'bo' => 'git@github.com:Symsvel-Framework/symsvel-bo.git',
        'app' => 'git@github.com:Symsvel-Framework/symsvel-app.git',
        'front' => 'git@github.com:Symsvel-Framework/symsvel-front.git',
    ];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $choices = [
            '1' => 'BO uniquement',
            '2' => 'App',
            '3' => 'Front',
            '4' => 'App + Front',
        ];

        if (!$input->isInteractive()) {
            $io->error('Run symsvel:install interactively to choose the components.');
            return Command::INVALID;
        }

        $question = new ChoiceQuestion('Quels composants souhaitez-vous installer ?', $choices, '1');
        $question->setErrorMessage('Choix invalide : %s');
        $selected = $this->getHelper('question')->ask($input, $output, $question);
        $choice = array_search($selected, $choices, true);

        $components = ['bo'];
        if (in_array((string) $choice, ['2', '4'], true)) {
            $components[] = 'app';
        }
        if (in_array((string) $choice, ['3', '4'], true)) {
            $components[] = 'front';
        }

        // Preflight every target before cloning anything.
        foreach ($components as $component) {
            $target = $this->projectDir.'/'.$component;
            if (file_exists($target) || is_link($target)) {
                $io->error(sprintf('Le répertoire %s existe déjà. Aucun fichier modifié.', $target));
                return Command::FAILURE;
            }
        }

        $created = [];
        foreach ($components as $component) {
            $target = $this->projectDir.'/'.$component;
            $io->text(sprintf('Installation de %s...', $component));
            $process = new Process(['git', 'clone', '--depth', '1', self::REPOSITORIES[$component], $target]);
            $process->setTimeout(300);
            try {
                $process->run();
            } catch (\Throwable $exception) {
                $io->error($exception->getMessage());
                $this->rollback($created);
                return Command::FAILURE;
            }
            if (!$process->isSuccessful()) {
                $io->error(trim($process->getErrorOutput()) ?: 'Échec du clonage Git.');
                if (is_dir($target)) {
                    $created[] = $target;
                }
                $this->rollback($created);
                return Command::FAILURE;
            }
            $created[] = $target;
        }

        $io->success('Interfaces installées : '.implode(', ', $components));
        $io->note('Exécutez npm ci dans chaque interface avant de la démarrer.');
        return Command::SUCCESS;
    }

    /** @param list<string> $paths */
    private function rollback(array $paths): void
    {
        foreach (array_reverse($paths) as $path) {
            // Only remove paths created by this command; no existing paths are touched.
            $process = new Process(['rm', '-rf', '--', $path]);
            $process->run();
        }
    }
}
