<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Resolve explicit table ownership for an entire removal plan.
 * Never infer ownership from table prefixes or SQL migration text.
 */
final class ModuleTableOwnership
{
    /**
     * @param list<string> $modules
     * @return list<string>
     */
    public function tables(string $workspace, array $modules): array
    {
        if ($modules === []) {
            throw new \InvalidArgumentException('Plan de désinstallation vide.');
        }
        $owned = [];
        foreach ($modules as $slug) {
            if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9-]*$/D', $slug)) {
                throw new \InvalidArgumentException('Identifiant de module invalide.');
            }
            $path = rtrim($workspace, '/').'/modules/'.$slug.'/module.json';
            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException('Manifest de module introuvable : '.$slug);
            }
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $tables = $manifest['tables'] ?? null;
            if (!is_array($tables) || !array_is_list($tables)) {
                throw new \DomainException('Propriété des tables non déclarée pour '.$slug.'. Désinstallation bloquée.');
            }
            foreach ($tables as $table) {
                if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) {
                    throw new \DomainException('Table invalide déclarée par '.$slug.'.');
                }
                if (isset($owned[$table])) {
                    throw new \DomainException('Table '.$table.' revendiquée par '.$slug.' et '.$owned[$table].'.');
                }
                $owned[$table] = $slug;
            }
        }
        // A retained module must never claim a table scheduled for deletion.
        $moduleRoot = rtrim($workspace, '/').'/modules';
        foreach (glob($moduleRoot.'/*/module.json') ?: [] as $path) {
            $other = basename(dirname($path));
            if (in_array($other, $modules, true)) {
                continue;
            }
            if (is_link($path)) {
                throw new \DomainException('Manifest symbolique interdit : '.$other);
            }
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) {
                throw new \DomainException('Manifest invalide : '.$other);
            }
            $otherTables = $manifest['tables'] ?? [];
            if (!is_array($otherTables) || !array_is_list($otherTables)) {
                throw new \DomainException('Déclaration de tables invalide : '.$other);
            }
            foreach ($otherTables as $table) {
                if (is_string($table) && isset($owned[$table])) {
                    throw new \DomainException('Table '.$table.' revendiquée par le module conservé '.$other.'.');
                }
            }
        }
        return array_keys($owned);
    }
}
