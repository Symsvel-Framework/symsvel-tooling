<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Installation\ComponentInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symsvel:install', description: 'Install the SymSvel SvelteKit interfaces')]
final class SymSvelInstallCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ComponentInstaller $installer = new ComponentInstaller(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Run symsvel:install interactively to choose the components.');
            return Command::INVALID;
        }

        $choices = [
            '1' => 'BO uniquement',
            '2' => 'App',
            '3' => 'Front',
            '4' => 'App + Front',
        ];
        $question = new ChoiceQuestion('Quels composants souhaitez-vous installer ?', $choices, '1');
        $question->setErrorMessage('Choix invalide : %s');
        $selected = $this->getHelper('question')->ask($input, $output, $question);
        $choice = array_search($selected, $choices, true);

        try {
            $components = $this->installer->componentsForChoice((string) $choice);
            $this->installer->install(
                $this->projectDir,
                $components,
                static function (string $component) use ($io): void {
                    $io->text(sprintf('Installation de %s...', $component));
                },
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success('Interfaces installées : '.implode(', ', $components));
        $io->note('Dépendances npm installées et builds SvelteKit terminés. Démarrez les interfaces avec npm run dev.');
        return Command::SUCCESS;
    }
}
