<?php
declare(strict_types=1);
namespace SymSvel\Tooling\Module;

/** Validates outgoing foreign-key references without claiming foreign tables. */
final class ModuleForeignKeyGuard
{
    /** @param array<string, array{manifest: array<string, mixed>}> $modules */
    public function assertDeclaredTargets(object $schema, array $modules, string $slug): void
    {
        if (!isset($modules[$slug])) {
            throw new \DomainException('Module inconnu : '.$slug);
        }
        $owners = [];
        foreach ($modules as $owner => $module) {
            $tables = $module['manifest']['tables'] ?? [];
            if (!is_array($tables) || !array_is_list($tables)) {
                throw new \DomainException('Déclaration de tables invalide : '.$owner);
            }
            foreach ($tables as $name) {
                if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || isset($owners[$name])) {
                    throw new \DomainException('Table invalide ou déclarée plusieurs fois : '.(string) $name);
                }
                $owners[$name] = $owner;
            }
        }
        foreach ($schema->getTables() as $table) {
            $source = $table->getName();
            if (($owners[$source] ?? null) !== $slug) {
                throw new \DomainException('Table source étrangère au module : '.$source);
            }
            foreach ($table->getForeignKeys() as $foreignKey) {
                $target = $foreignKey->getForeignTableName();
                if (!isset($owners[$target])) {
                    throw new \DomainException('Clé étrangère vers une table non déclarée : '.$source.' -> '.$target);
                }
            }
        }
    }
}
