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
        if (!method_exists($diff, 'getCreatedTables') || !method_exists($diff, 'getDroppedTables') || !method_exists($diff, 'getAlteredTables')) {
            throw new \DomainException('Format de diff Doctrine non reconnu.');
        }

        foreach (['getDroppedSchemas', 'getDroppedSequences', 'getAlteredSequences'] as $method) {
            if (!method_exists($diff, $method)) {
                throw new \DomainException('Format de diff Doctrine non reconnu : '.$method);
            }
            if ($diff->{$method}() !== []) {
                throw new \DomainException('Modification ou suppression de schéma ou séquence interdite : '.$method);
            }
        }

        if ($diff->getDroppedTables() !== []) {
            throw new \DomainException('Suppression de table interdite dans une migration générée.');
        }

        // A changed table may include a dropped column, a rename, a changed
        // constraint or a data-losing type change. Fail closed for now.
        if ($diff->getAlteredTables() !== []) {
            throw new \DomainException('Modification de table existante à examiner manuellement.');
        }
    }

    /** Only the creation of new tables is currently eligible for rendering. */
    public function hasChanges(object $diff): bool
    {
        $this->assertNonDestructive($diff);

        return $diff->getCreatedTables() !== [];
    }
}
