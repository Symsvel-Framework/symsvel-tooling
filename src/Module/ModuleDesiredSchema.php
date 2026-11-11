<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Builds the desired Doctrine schema from the selected module metadata only.
 * Doctrine ORM is supplied by the host project, not by the Tooling package.
 */
final class ModuleDesiredSchema
{
    public function __construct(
        private readonly ModuleEntityMetadataSelector $selector = new ModuleEntityMetadataSelector(),
    ) {
    }

    /**
     * @param array{path: string} $module
     * @return array{schema: object, tables: list<string>}
     */
    public function build(object $entityManager, array $module): array
    {
        if (!class_exists(\Doctrine\ORM\Tools\SchemaTool::class)) {
            throw new \RuntimeException('Doctrine ORM est requis pour générer les migrations de module.');
        }
        if (!method_exists($entityManager, 'getMetadataFactory')) {
            throw new \InvalidArgumentException('EntityManager Doctrine invalide.');
        }
        $factory = $entityManager->getMetadataFactory();
        $metadata = $this->selector->select($module, $factory->getAllMetadata());
        if ($metadata === []) {
            throw new \DomainException('Aucune entité Doctrine trouvée dans ce module.');
        }

        $schema = (new \Doctrine\ORM\Tools\SchemaTool($entityManager))->getSchemaFromMetadata($metadata);
        $tables = [];
        foreach ($schema->getTables() as $table) {
            $tables[] = $table->getName();
        }
        sort($tables, SORT_STRING);

        return ['schema' => $schema, 'tables' => $tables];
    }
}
