<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use SymSvel\Tooling\Installation\DevDocumentationBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Called by create-project after native modules are installed and synchronized.
 * This is a local developer-site rebuild, not an authenticated BO publication.
 */
#[AsCommand(name: 'symsvel:documentation:build-dev', description: 'Build local developer documentation from installed sources')]
final class SymSvelDevDocumentationBuildCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly DevDocumentationBuilder $builder = new DevDocumentationBuilder(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspace = dirname($this->projectDir);
        if (basename($this->projectDir) !== 'backend') {
            throw new \RuntimeException('Construction documentaire réservée au workspace backend/.');
        }
        $this->builder->build($workspace);
        $output->writeln('<info>Documentation développeur actualisée : dev-doc/site/</info>');

        return Command::SUCCESS;
    }
}
