<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Documentation\ComposerDocumentationAssembler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Maintenance-only publication. Never run during create-project or Setup. */
#[AsCommand(name: 'symsvel:documentation:sync', description: 'Assemble installed SymSvel documentation for authenticated BO access')]
final class SymSvelDocumentationSyncCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ComposerDocumentationAssembler $assembler = new ComposerDocumentationAssembler(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->assembler->assemble($this->projectDir);
        $output->writeln(sprintf(
            '<info>Documentation : %d pages, %s.</info>',
            $result['pages'],
            $result['changed'] ? 'assemblage publié' : 'inchangée',
        ));
        return Command::SUCCESS;
    }
}
