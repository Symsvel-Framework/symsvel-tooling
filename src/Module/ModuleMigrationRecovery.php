<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Resumes only rollback steps that were confirmed completed before interruption.
 * An in-flight migration requires database inspection before any automatic action.
 */
final class ModuleMigrationRecovery
{
    public function recover(string $backend, string $workspace, ModuleMigrationExecutor $executor): array
    {
        $path = rtrim($workspace, '/').'/.symsvel/migration-recovery.json';
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Journal de récupération absent ou invalide.');
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1
            || !in_array($data['state'] ?? null, ['running', 'recovering'], true)
            || !is_array($data['planned'] ?? null) || !array_is_list($data['planned'])
            || !is_array($data['completed'] ?? null) || !array_is_list($data['completed'])
            || !is_array($data['rolled_back'] ?? null) || !array_is_list($data['rolled_back'])
            || !is_null($data['in_flight'] ?? null)) {
            throw new \RuntimeException('État de récupération incertain : vérification manuelle requise.');
        }
        foreach (['planned', 'completed', 'rolled_back'] as $field) {
            foreach ($data[$field] as $version) {
                if (!is_string($version) || !preg_match('/^DoctrineMigrations\\\\Version[A-Za-z0-9_]+$/', $version)) {
                    throw new \RuntimeException('Version Doctrine invalide.');
                }
            }
            if (count($data[$field]) !== count(array_unique($data[$field]))) {
                throw new \RuntimeException('Versions dupliquées dans le journal.');
            }
        }
        if (array_diff($data['completed'], $data['planned']) !== []
            || array_diff($data['rolled_back'], $data['completed']) !== []) {
            throw new \RuntimeException('Journal de récupération incohérent.');
        }
        $expected = array_values(array_filter(array_reverse($data['completed']), static fn (string $v): bool => !in_array($v, $data['rolled_back'], true)));
        foreach ($expected as $version) {
            $executor->execute($backend, $version, 'down');
            $data['rolled_back'][] = $version;
            $this->persist($path, $data);
        }
        // Keep the journal: migration files and database state must be checked
        // before allowing another installation, even after successful compensation.
        return $data['rolled_back'];
    }

    private function persist(string $path, array $data): void
    {
        $data['state'] = 'recovering';
        $temporary = tempnam(dirname($path), '.recovery-');
        if ($temporary === false) {
            throw new \RuntimeException('Impossible de créer le journal temporaire.');
        }
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json."\n") === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Impossible de mettre à jour le journal.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
