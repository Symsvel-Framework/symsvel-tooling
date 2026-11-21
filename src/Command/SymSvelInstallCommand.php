<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Installation\ComponentInstaller;
use SymSvel\Tooling\Installation\WorkspaceInitializer;
use SymSvel\Tooling\Update\ProjectChannel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

    protected function configure(): void
    {
        $this->addOption('components', null, InputOption::VALUE_REQUIRED, 'Interfaces à installer : bo, app, front ou all');
    }

    private function resolveWorkspaceRoot(): string
    {
        // Symfony lives in backend/; the interfaces belong to its parent workspace.
        // Refuse ambiguous layouts instead of silently installing beside Symfony files.
        if (basename(rtrim($this->projectDir, '/')) !== 'backend') {
            throw new \RuntimeException('Le backend Symfony doit se trouver dans le dossier backend/ du projet.');
        }

        $root = dirname($this->projectDir);
        if (!is_file($this->projectDir.'/composer.json') || !is_dir($root)) {
            throw new \RuntimeException('Arborescence SymSvel invalide : backend/composer.json introuvable.');
        }

        return $root;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $explicit = $input->getOption('components');
        if ($explicit !== null && !in_array($explicit, ['bo', 'app', 'front', 'all'], true)) {
            $io->error('Valeur --components invalide. Choisissez bo, app, front ou all.');
            return Command::INVALID;
        }
        if ($explicit === null && !$input->isInteractive()) {
            $io->error('En mode non interactif, précisez --components=bo|app|front|all.');
            return Command::INVALID;
        }

        $choices = [
            '1' => 'BO uniquement',
            '2' => 'App',
            '3' => 'Front',
            '4' => 'App + Front',
        ];
        if ($explicit !== null) {
            $choice = ['bo' => '1', 'app' => '2', 'front' => '3', 'all' => '4'][$explicit];
        } else {
            $question = new ChoiceQuestion('Quels composants souhaitez-vous installer ?', $choices, '1');
            $question->setErrorMessage('Choix invalide : %s');
            $selected = $this->getHelper('question')->ask($input, $output, $question);
            $choice = array_search($selected, $choices, true);
        }

        try {
            $components = $this->installer->componentsForChoice((string) $choice);
            $root = $this->resolveWorkspaceRoot();
            $existing = array_values(array_filter($components, static fn (string $component): bool => file_exists($root.'/'.$component) || is_link($root.'/'.$component)));
            if ($existing !== [] && count($existing) !== count($components)) {
                throw new \RuntimeException('Installation partielle détectée : aucun composant existant ne sera écrasé.');
            }
            if ($existing === []) {
                $this->installer->install(
                    $root,
                    $components,
                    static function (string $component) use ($io): void {
                        $io->text(sprintf('Installation de %s...', $component));
                    },
                    ProjectChannel::read($this->projectDir),
                );
            } else {
                $io->text('Interfaces déjà présentes : installation npm et clonage ignorés.');
            }
            $io->text('Initialisation des documentations (dev/admin/user) et secrets...');
            (new WorkspaceInitializer())->initialize($root);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success('Interfaces installées : '.implode(', ', $components));
        $io->note('Sources et documentation minimale préparées. Les builds SvelteKit et MkDocs sont différés.');
        return Command::SUCCESS;
    }
}
