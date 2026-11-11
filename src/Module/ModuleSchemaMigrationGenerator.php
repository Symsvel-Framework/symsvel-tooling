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
     * @return array{changed: bool, path: ?string, tables: list<string>}
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
            return ['changed' => false, 'path' => null, 'tables' => $comparison['tables']];
        }

        $date = $now ?? new \DateTimeImmutable();
        $version = 'Version'.$date->format('YmdHis');
        // Render and validate completely before creating any source file.
        $php = $this->renderer->render($comparison['diff'], $version);
        $path = $this->writer->write($selected['path'], $php, $date);

        return ['changed' => true, 'path' => $path, 'tables' => $comparison['tables']];
    }
}
