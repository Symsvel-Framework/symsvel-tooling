<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Coordinates one module's Schema migration generation.
 * Does not compile, execute, or mutate the database.
 */
final class ModuleSchemaMigrationGenerator
{
    public function __construct(
        private readonly ModuleWorkingDirectoryResolver $resolver = new ModuleWorkingDirectoryResolver(),
        private readonly ModuleRegistry $registry = new ModuleRegistry(),
        private readonly ModuleDesiredSchema $desired = new ModuleDesiredSchema(),
        private readonly ModuleSchemaComparator $comparator = new ModuleSchemaComparator(),
        private readonly ModuleSchemaMigrationRenderer $renderer = new ModuleSchemaMigrationRenderer(),
        private readonly ModuleMigrationSourceWriter $writer = new ModuleMigrationSourceWriter(),
    ) {
    }

    /**
     * @return array{changed: bool, path: ?string, paths: list<string>, tables: list<string>}
     */
    public function generate(
        string $workspace,
        ?string $module,
        string $workingDirectory,
        object $entityManager,
        object $connection,
        ?\DateTimeImmutable $now = null,
    ): array {
        $selected = $this->resolver->resolve($workspace, $module, $workingDirectory);
        $modules = $this->registry->discover($workspace);
        $desired = $this->desired->build($entityManager, $selected);
        $comparison = $this->comparator->compare($connection, $desired['schema'], $modules, $selected['slug']);
        if (!$comparison['changed']) {
            return ['changed' => false, 'path' => null, 'paths' => [], 'tables' => $comparison['tables']];
        }

        $date = $now ?? new \DateTimeImmutable();
        $diff = $comparison['diff'];
        $operations = [];
        foreach ($diff->getCreatedTables() as $table) {
            // Render one new table per file; dependencies between newly created
            // tables require a separate, explicit FK operation.
            $operations[] = ['kind' => 'create', 'table' => $table];
        }
        foreach ($diff->getAlteredTables() as $alteration) {
            $operations[] = ['kind' => 'alter', 'table' => $alteration];
        }
        foreach ($diff->getDroppedTables() as $table) {
            $operations[] = ['kind' => 'drop', 'table' => $table];
        }
        // Validate all sources before writing any file.
        $sources = [];
        foreach ($operations as $offset => $operation) {
            $stamp = $date->modify('+'.$offset.' seconds');
            $version = 'Version'.$stamp->format('YmdHis');
            if ($operation['kind'] === 'create') {
                $singleDiff = new class($operation['table']) {
                    public function __construct(private readonly object $table) {}
                    public function getCreatedTables(): array { return [$this->table]; }
                    public function getDroppedTables(): array { return []; }
                    public function getAlteredTables(): array { return []; }
                    public function getCreatedSchemas(): array { return []; }
                    public function getDroppedSchemas(): array { return []; }
                    public function getCreatedSequences(): array { return []; }
                    public function getDroppedSequences(): array { return []; }
                    public function getAlteredSequences(): array { return []; }
                };
                $source = $this->renderer->render($singleDiff, $version);
            } elseif ($operation['kind'] === 'drop') {
                $source = $this->renderer->renderDropTable($operation['table'], $version);
            } else {
                $source = $this->renderer->renderAlteration($operation['table'], $version);
            }
            $sources[] = ['source' => $source, 'stamp' => $stamp];
        }
        $paths = [];
        try {
            foreach ($sources as $entry) {
                $paths[] = $this->writer->write($selected['path'], $entry['source'], $entry['stamp']);
            }
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                @unlink($path);
            }
            throw $exception;
        }

        return ['changed' => true, 'path' => $paths[0], 'paths' => $paths, 'tables' => $comparison['tables']];
    }
}
