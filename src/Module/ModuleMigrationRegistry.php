<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Reads and atomically writes migration receipts after Doctrine succeeds.
 * A receipt is never updated before the corresponding Doctrine operation.
 */
final class ModuleMigrationRegistry
{
    /** @return array<string, array{sha256: string, doctrine: string}> */
    public function read(string $workspace): array
    {
        $path = rtrim($workspace, '/').'/.symsvel/migrations.json';
        if (!is_file($path)) {
            return [];
        }
        if (is_link($path)) {
            throw new \RuntimeException('Registre symbolique interdit.');
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['entries'] ?? null)) {
            throw new \RuntimeException('Registre des migrations invalide.');
        }
        foreach ($data['entries'] as $key => $entry) {
            if (!is_string($key) || !preg_match('~^[a-z][a-z0-9-]*/[A-Za-z0-9_.-]+\.php$~', $key)
                || !is_array($entry) || !preg_match('/^[a-f0-9]{64}$/', $entry['sha256'] ?? '')
                || !is_string($entry['doctrine'] ?? null) || $entry['doctrine'] === '') {
                throw new \RuntimeException('Entrée du registre invalide : '.$key);
            }
        }

        return $data['entries'];
    }

    /** @param array<string, array{sha256: string, doctrine: string}> $entries */
    public function write(string $workspace, array $entries): void
    {
        $root = realpath($workspace) ?: throw new \RuntimeException('Workspace introuvable.');
        $directory = $root.'/.symsvel';
        if (is_link($directory)) {
            throw new \RuntimeException('Répertoire symbolique interdit.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le registre.');
        }
        ksort($entries, SORT_STRING);
        $target = $directory.'/migrations.json';
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new \RuntimeException('Registre invalide.');
        }
        $json = json_encode(['schema' => 1, 'entries' => $entries === [] ? new \stdClass() : $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (is_file($target) && file_get_contents($target) === $json) {
            return;
        }
        $temporary = tempnam($directory, '.migration-');
        if ($temporary === false) {
            throw new \RuntimeException('Fichier temporaire indisponible.');
        }
        try {
            if (file_put_contents($temporary, $json) === false || !rename($temporary, $target)) {
                throw new \RuntimeException('Publication du registre impossible.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
