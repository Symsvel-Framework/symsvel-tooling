<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * PHP Schema renderer for module-owned table changes.
 * Doctrine DBAL is provided by the host project.
 */
final class ModuleSchemaMigrationRenderer
{
    /**
     * Render a single additive change to an existing table. The caller creates
     * one migration per operation and orders table creation before alterations.
     */
    public function renderAlteration(object $tableDiff, string $version): string
    {
        if (!preg_match('/^Version[0-9]{14}$/D', $version)) {
            throw new \InvalidArgumentException('Version de migration invalide.');
        }
        $old = $tableDiff->getOldTable();
        $name = $old->getName();
        $up = ['        $table = $schema->getTable('.var_export($name, true).');'];
        $down = [];
        // Remove dependent constraints before columns and restore them last.
        foreach ($tableDiff->getDroppedForeignKeys() as $foreignKey) {
            $up[] = '        $table->removeForeignKey('.var_export($foreignKey->getName(), true).');';
            $down[] = $this->foreignKeyStatement($foreignKey);
        }
        foreach ($tableDiff->getDroppedIndexes() as $index) {
            if ($index->getFlags() !== [] || $index->getOptions() !== []) {
                throw new \DomainException('Options d’index non prises en charge.');
            }
            $up[] = '        $table->dropIndex('.var_export($index->getName(), true).');';
            $down[] = $this->indexStatement($index);
        }
        foreach ($tableDiff->getDroppedColumns() as $column) {
            $up[] = '        $table->dropColumn('.var_export($column->getName(), true).');';
            $down[] = $this->columnStatement($column);
        }
        foreach ($tableDiff->getChangedColumns() as $columnDiff) {
            $oldColumn = $columnDiff->getOldColumn();
            $newColumn = $columnDiff->getNewColumn();
            if ($oldColumn->getName() !== $newColumn->getName()) {
                throw new \DomainException('Renommage de colonne non pris en charge.');
            }
            $up[] = $this->changeColumnStatement($newColumn);
            $down[] = $this->changeColumnStatement($oldColumn);
        }
        foreach ($tableDiff->getAddedColumns() as $column) {
            $type = \Doctrine\DBAL\Types\Type::getTypeRegistry()->lookupName($column->getType());
            $options = [
                'notnull' => $column->getNotnull(),
                'default' => $column->getDefault(),
                'autoincrement' => $column->getAutoincrement(),
                'unsigned' => $column->getUnsigned(),
                'fixed' => $column->getFixed(),
            ];
            if ($column->getLength() !== null) {
                $options['length'] = $column->getLength();
            }
            if ($column->getPrecision() !== null) {
                $options['precision'] = $column->getPrecision();
            }
            if ($column->getScale() !== null) {
                $options['scale'] = $column->getScale();
            }
            if ($column->getDefault() !== null && !is_scalar($column->getDefault())) {
                throw new \DomainException('Valeur par défaut non représentable.');
            }
            $up[] = '        $table->addColumn('.var_export($column->getName(), true).', '.var_export($type, true).', '.var_export($options, true).');';
            $down[] = '        $table->dropColumn('.var_export($column->getName(), true).');';
        }
        foreach ($tableDiff->getAddedIndexes() as $index) {
            if ($index->getFlags() !== [] || $index->getOptions() !== []) {
                throw new \DomainException('Options d’index non prises en charge.');
            }
            $method = $index->isUnique() ? 'addUniqueIndex' : 'addIndex';
            $up[] = '        $table->'.$method.'('.var_export($index->getColumns(), true).', '.var_export($index->getName(), true).');';
            $down[] = '        $table->dropIndex('.var_export($index->getName(), true).');';
        }
        foreach ($tableDiff->getAddedForeignKeys() as $foreignKey) {
            $up[] = $this->foreignKeyStatement($foreignKey);
            $down[] = '        $table->removeForeignKey('.var_export($foreignKey->getName(), true).');';
        }
        if (count($up) === 1) {
            throw new \DomainException('Modification de table vide.');
        }
        $down = array_reverse($down);
        array_unshift($down, '        $table = $schema->getTable('.var_export($name, true).');');
        return $this->template($version, implode("\n", $up), implode("\n", $down));
    }

    private function columnStatement(object $column): string
    {
        $type = \Doctrine\DBAL\Types\Type::getTypeRegistry()->lookupName($column->getType());
        $options = [
            'notnull' => $column->getNotnull(),
            'default' => $column->getDefault(),
            'autoincrement' => $column->getAutoincrement(),
            'unsigned' => $column->getUnsigned(),
            'fixed' => $column->getFixed(),
        ];
        foreach (['length' => 'getLength', 'precision' => 'getPrecision', 'scale' => 'getScale'] as $key => $method) {
            if ($column->{$method}() !== null) {
                $options[$key] = $column->{$method}();
            }
        }
        if ($column->getDefault() !== null && !is_scalar($column->getDefault())) {
            throw new \DomainException('Valeur par défaut non représentable.');
        }
        return '        $table->addColumn('.var_export($column->getName(), true).', '.var_export($type, true).', '.var_export($options, true).');';
    }

    private function changeColumnStatement(object $column): string
    {
        return str_replace('$table->addColumn(', '$table->modifyColumn(', $this->columnStatement($column));
    }

    private function indexStatement(object $index): string
    {
        $method = $index->isUnique() ? 'addUniqueIndex' : 'addIndex';
        return '        $table->'.$method.'('.var_export($index->getColumns(), true).', '.var_export($index->getName(), true).');';
    }

    private function foreignKeyStatement(object $foreignKey): string
    {
        return '        $table->addForeignKeyConstraint('.var_export($foreignKey->getForeignTableName(), true).', '.var_export($foreignKey->getLocalColumns(), true).', '.var_export($foreignKey->getForeignColumns(), true).', '.var_export($foreignKey->getOptions(), true).', '.var_export($foreignKey->getName(), true).');';
    }

    /** Restore table structure on down; deleted row data cannot be recovered. */
    public function renderDropTable(object $table, string $version): string
    {
        $diff = new class($table) {
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
        $create = $this->render($diff, $version);
        if (!preg_match('~public function up\\(Schema \\$schema\\): void\\s*\\{(.*?)\\}\\s*public function down\\(Schema \\$schema\\): void\\s*\\{(.*?)\\}~s', $create, $matches)) {
            throw new \DomainException('Migration de suppression de table non représentable.');
        }
        return $this->template($version, trim($matches[2], "\n"), trim($matches[1], "\n"));
    }

    private function template(string $version, string $up, string $down): string
    {
        $template = <<<'PHP'
<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class __VERSION__ extends AbstractMigration
{
    public function up(Schema $schema): void
    {
__UP__
    }

    public function down(Schema $schema): void
    {
__DOWN__
    }
}
PHP;

        return str_replace(['__VERSION__', '__UP__', '__DOWN__'], [$version, $up, $down], $template)."\n";
    }

    public function render(object $diff, string $version): string
    {
        if (!preg_match('/^Version[0-9]{14}$/D', $version)) {
            throw new \InvalidArgumentException('Version de migration invalide.');
        }
        (new ModuleSchemaDiffGuard())->assertSupported($diff);
        $tables = $diff->getCreatedTables();
        if ($tables === []) {
            throw new \DomainException('Aucune table à créer.');
        }

        $up = [];
        $down = [];
        foreach ($tables as $table) {
            if (!$table instanceof \Doctrine\DBAL\Schema\Table) {
                throw new \DomainException('Table DBAL invalide.');
            }
            if ($table->getForeignKeys() !== []) {
                throw new \DomainException('Clés étrangères non prises en charge : '.$table->getName());
            }
            $name = var_export($table->getName(), true);
            $up[] = '        $table = $schema->createTable('.$name.');';
            foreach ($table->getColumns() as $column) {
                $type = \Doctrine\DBAL\Types\Type::getTypeRegistry()->lookupName($column->getType());
                $options = [
                    'notnull' => $column->getNotnull(),
                    'default' => $column->getDefault(),
                    'autoincrement' => $column->getAutoincrement(),
                    'unsigned' => $column->getUnsigned(),
                    'fixed' => $column->getFixed(),
                ];
                if ($column->getLength() !== null) {
                    $options['length'] = $column->getLength();
                }
                if ($column->getPrecision() !== null) {
                    $options['precision'] = $column->getPrecision();
                }
                if ($column->getScale() !== null) {
                    $options['scale'] = $column->getScale();
                }
                $default = $column->getDefault();
                if ($default !== null && !is_scalar($default)) {
                    throw new \DomainException('Valeur par défaut non représentable : '.$column->getName());
                }
                $up[] = '        $table->addColumn('.var_export($column->getName(), true).', '.var_export($type, true).', '.var_export($options, true).');';
            }
            $primary = $table->getPrimaryKey();
            if ($primary !== null) {
                $up[] = '        $table->setPrimaryKey('.var_export($primary->getColumns(), true).');';
            }
            foreach ($table->getIndexes() as $index) {
                if ($index->isPrimary()) {
                    continue;
                }
                if ($index->getFlags() !== [] || $index->getOptions() !== []) {
                    throw new \DomainException('Options d’index non prises en charge : '.$index->getName());
                }
                $method = $index->isUnique() ? 'addUniqueIndex' : 'addIndex';
                $up[] = '        $table->'.$method.'('.var_export($index->getColumns(), true).', '.var_export($index->getName(), true).');';
            }
            $down[] = '        $schema->dropTable('.$name.');';
        }

        $template = <<<'PHP'
<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class __VERSION__ extends AbstractMigration
{
    public function up(Schema $schema): void
    {
__UP__
    }

    public function down(Schema $schema): void
    {
__DOWN__
    }
}
PHP;

        return str_replace(
            ['__VERSION__', '__UP__', '__DOWN__'],
            [$version, implode("\n", $up), implode("\n", array_reverse($down))],
            $template,
        )."\n";
    }
}
