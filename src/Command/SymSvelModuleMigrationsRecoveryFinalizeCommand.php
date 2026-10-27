<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Closes an interrupted installation only after an operator has inspected SQL state.
 * Never attempts to infer whether an interrupted migration was transactional.
 */
#[AsCommand(name: 'symsvel:module:migrations:recovery:finalize', description: 'Finalize a manually verified interrupted migration recovery')]
final class SymSvelModuleMigrationsRecoveryFinalizeCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace root');
        $this->addOption('migration', null, InputOption::VALUE_REQUIRED, 'Exact interrupted Doctrine version to acknowledge');
        $this->addOption('verified-db', null, InputOption::VALUE_NONE, 'Confirm that the database was manually inspected and has no partial changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if (!$input->getOption('verified-db')) {
                throw new \DomainException('Vérifiez manuellement les effets SQL, puis utilisez --verified-db.');
            }
            $workspace = (string) ($input->getOption('workspace') ?? dirname($this->projectDir));
            $directory = rtrim($workspace, '/').'/.symsvel';
            $journal = $directory.'/migration-recovery.json';
            if (is_link($directory) || is_link($journal) || !is_file($journal)) {
                throw new \RuntimeException('Journal de récupération absent ou invalide.');
            }
            $data = json_decode((string) file_get_contents($journal), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['schema'] ?? null) !== 1
                || ($data['state'] ?? null) !== 'recovering'
                || !is_array($data['planned'] ?? null) || !array_is_list($data['planned'])
                || !is_array($data['completed'] ?? null) || !array_is_list($data['completed'])
                || !is_array($data['rolled_back'] ?? null) || !array_is_list($data['rolled_back'])) {
                throw new \RuntimeException('Journal incohérent : clôture refusée.');
            }
            $inFlight = $data['in_flight'] ?? null;
            if (!is_string($inFlight) || !preg_match('/^DoctrineMigrations\\\\Version[A-Za-z0-9_]+$/', $inFlight)
                || $input->getOption('migration') !== $inFlight) {
                throw new \DomainException('Indiquez la version interrompue exacte avec --migration.');
            }
            foreach (['planned', 'completed', 'rolled_back'] as $field) {
                foreach ($data[$field] as $version) {
                    if (!is_string($version) || !preg_match('/^DoctrineMigrations\\\\Version[A-Za-z0-9_]+$/', $version)) {
                        throw new \RuntimeException('Version Doctrine invalide dans le journal.');
                    }
                }
                if (count($data[$field]) !== count(array_unique($data[$field]))) {
                    throw new \RuntimeException('Versions dupliquées dans le journal.');
                }
            }
            if (!in_array($inFlight, $data['planned'], true)
                || in_array($inFlight, $data['completed'], true)
                || array_diff($data['completed'], $data['planned']) !== []
                || array_diff($data['rolled_back'], $data['completed']) !== []
                || array_diff($data['completed'], $data['rolled_back']) !== []) {
                throw new \RuntimeException('Compensations non terminées ou journal incohérent.');
            }
            // All planned versions must be absent from Doctrine's executed metadata.
            // This check cannot prove absence of partial SQL changes: --verified-db is mandatory.
            foreach ($data['planned'] as $version) {
                $executed = $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?',
                    [$version],
                );
                if ((int) $executed !== 0) {
                    throw new \RuntimeException('Migration encore enregistrée dans Doctrine : '.$version);
                }
            }
            // Keep the journal until after the generated files have been removed.
            // An interrupted cleanup can therefore be retried safely.
            foreach ($data['planned'] as $version) {
                $filename = substr($version, strlen('DoctrineMigrations\\')).'.php';
                $path = rtrim($this->projectDir, '/').'/migrations/'.$filename;
                if (is_link($path)) {
                    throw new \RuntimeException('Fichier de migration symbolique interdit : '.$path);
                }
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('Impossible de supprimer la migration générée : '.$path);
                }
            }
            if (!unlink($journal)) {
                throw new \RuntimeException('Impossible de clôturer le journal de récupération.');
            }
            $io->success('Récupération clôturée après vérification manuelle. Les migrations générées non exécutées ont été retirées.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }
}
