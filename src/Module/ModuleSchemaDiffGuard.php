<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Validates supported DBAL SchemaDiff operations, including intentional
 * destructive changes to module-owned tables. Unknown changes fail closed.
 */
final class ModuleSchemaDiffGuard
{
    public function assertSupported(object $diff): void
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

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $allowed = ['getAddedColumns', 'getAddedIndexes', 'getAddedForeignKeys'];
            $forbidden = ['getModifiedIndexes', 'getRenamedIndexes', 'getModifiedForeignKeys'];
            $supported = ['getChangedColumns', 'getDroppedColumns', 'getDroppedIndexes', 'getDroppedForeignKeys'];
            foreach ([...$allowed, ...$supported, ...$forbidden] as $method) {
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

    /** Supported table creations, removals and alterations are eligible. */
    public function hasChanges(object $diff): bool
    {
        $this->assertSupported($diff);

        return $diff->getCreatedTables() !== [] || $diff->getDroppedTables() !== [] || $diff->getAlteredTables() !== [];
    }
}
