<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/** Checks compiled Doctrine files identically during preview and execution. */
final class ModuleCompiledMigrationGuard
{
    /** @param list<string> $versions */
    public function assertAvailable(string $backend, array $versions): void
    {
        $directory = rtrim($backend, '/').'/migrations';
        if (is_link($directory)) {
            throw new \DomainException('Répertoire Doctrine compilé symbolique interdit.');
        }
        foreach ($versions as $version) {
            if (!preg_match('/^DoctrineMigrations\\\\(Version[0-9]+)$/D', $version, $matches)) {
                throw new \DomainException('Version Doctrine compilée invalide : '.$version);
            }
            $compiled = $directory.'/'.$matches[1].'.php';
            if (!is_file($compiled) || is_link($compiled)) {
                throw new \DomainException('Migration Doctrine compilée absente ou invalide : '.$version);
            }
        }
    }
}
