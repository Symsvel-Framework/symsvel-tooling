<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Conservative gate for DBAL SchemaDiff: only additive changes are allowed.
 * All destructive or unrecognized table alterations fail closed.
 */
final class ModuleSchemaDiffGuard
{
    public function assertNonDestructive(object $diff): void
    {
        if (!method_exists($diff, 'getCreatedTables') || !method_exists($diff, 'getDroppedTables') || !method_exists($diff, 'getAlteredTables')) {
            throw new \DomainException('Format de diff Doctrine non reconnu.');
        }

        foreach (['getCreatedSchemas', 'getDroppedSchemas', 'getCreatedSequences', 'getDroppedSequences', 'getAlteredSequences'] as $method) {
            if (!method_exists($diff, $method)) {
                throw new \DomainException('Format de diff Doctrine non reconnu : '.$method);
            }
            if ($diff->{$method}() !== []) {
                throw new \DomainException('Opération sur schéma ou séquence non prise en charge : '.$method);
            }
        }

        if ($diff->getDroppedTables() !== []) {
            throw new \DomainException('Suppression de table interdite dans une migration générée.');
        }

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $allowed = ['getAddedColumns', 'getAddedIndexes', 'getAddedForeignKeys'];
            $forbidden = ['getChangedColumns', 'getDroppedColumns', 'getModifiedIndexes', 'getDroppedIndexes', 'getRenamedIndexes', 'getModifiedForeignKeys', 'getDroppedForeignKeys'];
            foreach ([...$allowed, ...$forbidden] as $method) {
                if (!method_exists($tableDiff, $method)) {
                    throw new \DomainException('Format de modification DBAL non reconnu : '.$method);
                }
            }
            foreach ($forbidden as $method) {
                if ($tableDiff->{$method}() !== []) {
                    throw new \DomainException('Modification destructive ou ambiguë interdite : '.$method);
                }
            }
            foreach ($tableDiff->getAddedColumns() as $column) {
                if ($column->getNotnull() && $column->getDefault() === null) {
                    throw new \DomainException('Nouvelle colonne NOT NULL sans valeur par défaut interdite : '.$column->getName());
                }
            }
        }
    }

    /** Additive table changes and new tables are eligible for rendering. */
    public function hasChanges(object $diff): bool
    {
        $this->assertNonDestructive($diff);

        return $diff->getCreatedTables() !== [] || $diff->getAlteredTables() !== [];
    }
}
