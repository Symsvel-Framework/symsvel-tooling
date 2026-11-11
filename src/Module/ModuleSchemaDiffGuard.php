<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Conservative gate for DBAL SchemaDiff: until column-level changes can be
 * rendered and reversed safely, only new tables are eligible for generation.
 */
final class ModuleSchemaDiffGuard
{
    public function assertNonDestructive(object $diff): void
    {
        if (!method_exists($diff, 'getDroppedTables') || !method_exists($diff, 'getChangedTables')) {
            throw new \DomainException('Format de diff Doctrine non reconnu.');
        }

        if ($diff->getDroppedTables() !== []) {
            throw new \DomainException('Suppression de table interdite dans une migration générée.');
        }

        // A changed table may include a dropped column, a rename, a changed
        // constraint or a data-losing type change. Fail closed for now.
        if ($diff->getChangedTables() !== []) {
            throw new \DomainException('Modification de table existante à examiner manuellement.');
        }
    }
}
