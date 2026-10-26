<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Coordinates a destructive module operation behind a durable SQL backup.
 * The caller supplies the module-owned tables and the removal operation.
 * Never deletes the backup when rollback or verification fails.
 */
final class ModuleRemovalTransaction
{
    public function __construct(private readonly ModuleTableBackup $backups = new ModuleTableBackup())
    {
    }

    /**
     * @param list<string> $tables
     * @param callable():void $remove
     */
    public function run(string $workspace, string $databaseUrl, array $tables, callable $remove): void
    {
        $directory = rtrim($workspace, '/').'/.symsvel';
        $journal = $directory.'/module-removal-recovery.json';
        if (file_exists($journal) || is_link($journal)
            || file_exists($directory.'/migration-recovery.json') || is_link($directory.'/migration-recovery.json')) {
            throw new \RuntimeException('Récupération en attente : désinstallation bloquée.');
        }
        $backup = $this->backups->create($workspace, $databaseUrl, $tables);
        try {
            $this->write($journal, [
                'schema' => 1,
                'state' => 'prepared',
                'backup' => $backup,
                'sha256' => hash_file('sha256', $backup['path']),
            ]);
        } catch (\Throwable $failure) {
            // Nothing has touched the database yet.
            @unlink($backup['path']);
            throw $failure;
        }

        try {
            $this->write($journal, [
                'schema' => 1,
                'state' => 'removing',
                'backup' => $backup,
                'sha256' => hash_file('sha256', $backup['path']),
            ]);
            $remove();
        } catch (\Throwable $failure) {
            // Do not assume a failed SQL statement was transactional.
            // A recovery operator must inspect the state before any retry.
            $this->write($journal, [
                'schema' => 1,
                'state' => 'restore_required',
                'backup' => $backup,
                'sha256' => hash_file('sha256', $backup['path']),
            ]);
            throw new \RuntimeException(
                'Désinstallation interrompue : sauvegarde conservée. Restauration requise : '.$journal,
                0,
                $failure,
            );
        }

        // The caller's operation has fully succeeded. Keep the backup if
        // journal cleanup fails, so an operator can resolve the state.
        $this->write($journal, [
            'schema' => 1,
            'state' => 'committed',
            'backup' => $backup,
            'sha256' => hash_file('sha256', $backup['path']),
        ]);
        if (!unlink($journal)) {
            throw new \RuntimeException('Désinstallation terminée, nettoyage du journal impossible.');
        }
        if (!unlink($backup['path'])) {
            throw new \RuntimeException('Désinstallation terminée, nettoyage de la sauvegarde impossible.');
        }
    }

    /**
     * Explicit recovery only: restoration may overwrite existing tables.
     * The operator must ensure module files and Doctrine receipts are restored.
     */
    public function restore(string $workspace, string $databaseUrl): void
    {
        $journal = rtrim($workspace, '/').'/.symsvel/module-removal-recovery.json';
        if (!is_file($journal) || is_link($journal)) {
            throw new \RuntimeException('Journal de désinstallation introuvable.');
        }
        $data = json_decode((string) file_get_contents($journal), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1
            || !in_array($data['state'] ?? null, ['restore_required', 'restoring'], true)
            || !is_array($data['backup'] ?? null)
            || !is_string($data['sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'])) {
            throw new \RuntimeException('Journal invalide ou restauration non autorisée.');
        }
        $backup = $data['backup'];
        if (!isset($backup['path'], $backup['driver'], $backup['tables'])
            || !is_string($backup['path']) || !is_string($backup['driver']) || !is_array($backup['tables'])
            || !is_file($backup['path']) || is_link($backup['path'])
            || !hash_equals($data['sha256'], (string) hash_file('sha256', $backup['path']))) {
            throw new \RuntimeException('Sauvegarde absente ou altérée.');
        }
        $this->write($journal, array_replace($data, ['state' => 'restoring']));
        $this->backups->restore($databaseUrl, $backup);
        // Retain both artifacts after SQL restore. Other removal steps (files,
        // Composer and migration receipts) still need consistency checks.
        $this->write($journal, array_replace($data, ['state' => 'sql_restored']));
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        $temporary = tempnam(dirname($path), '.removal-journal-');
        if ($temporary === false) {
            throw new \RuntimeException('Impossible de créer le journal.');
        }
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json."\n") === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Impossible de publier le journal.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
