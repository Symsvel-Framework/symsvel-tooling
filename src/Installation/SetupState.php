<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

/** Persistent setup lifecycle, independent of database configuration. */
final class SetupState
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DATABASE_READY = 'database_ready';
    public const COMPLETED = 'completed';

    public function read(string $workspace): ?string
    {
        $path = $this->path($workspace);
        if (is_link($path) || is_link(dirname($path))) {
            throw new \RuntimeException('État setup symbolique interdit.');
        }
        if (!file_exists($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('État setup illisible : vérifiez les permissions du répertoire .symsvel et de setup.json.');
        }
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1
            || !in_array($data['status'] ?? null, [self::PENDING, self::RUNNING, self::DATABASE_READY, self::COMPLETED], true)) {
            throw new \RuntimeException('État setup invalide.');
        }
        return $data['status'];
    }

    public function initialize(string $workspace): void
    {
        if ($this->read($workspace) !== null) {
            return;
        }
        $this->write($workspace, self::PENDING);
    }

    public function transition(string $workspace, string $expected, string $next): void
    {
        if (!in_array([$expected, $next], [
            [self::PENDING, self::RUNNING],
            [self::RUNNING, self::DATABASE_READY],
            [self::DATABASE_READY, self::COMPLETED],
        ], true)) {
            throw new \DomainException('Transition setup interdite.');
        }
        if ($this->read($workspace) !== $expected) {
            throw new \DomainException('État setup inattendu.');
        }
        if (in_array($next, [self::DATABASE_READY, self::COMPLETED], true) && (!is_file(rtrim($workspace, '/').'/backend/.env.local')
            || is_link(rtrim($workspace, '/').'/backend/.env.local'))) {
            throw new \DomainException('Setup impossible à terminer : configuration BDD absente.');
        }
        if (in_array($next, [self::DATABASE_READY, self::COMPLETED], true) && (file_exists(rtrim($workspace, '/').'/.symsvel/migration-recovery.json')
            || is_link(rtrim($workspace, '/').'/.symsvel/migration-recovery.json'))) {
            throw new \DomainException('Setup impossible à terminer : récupération de migrations en attente.');
        }
        $this->write($workspace, $next);
    }

    /** A failed initial setup starts again from a blank wizard. */
    public function reset(string $workspace): void
    {
        $status = $this->read($workspace);
        if (in_array($status, [self::DATABASE_READY, self::COMPLETED, null], true)) {
            throw new \DomainException('Impossible de réinitialiser un setup terminé ou absent.');
        }
        $this->write($workspace, self::PENDING);
    }

    private function write(string $workspace, string $status): void
    {
        $path = $this->path($workspace);
        $directory = dirname($path);
        if (is_link($directory) || is_link($path)) {
            throw new \RuntimeException('État setup symbolique interdit.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire setup inaccessible.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('Répertoire .symsvel non accessible en écriture.');
        }
        $temporary = @tempnam($directory, '.setup-');
        if ($temporary === false) {
            throw new \RuntimeException('Écriture setup impossible.');
        }
        try {
            $json = json_encode(['schema' => 1, 'status' => $status], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            if (!chmod($temporary, 0644)
                || file_put_contents($temporary, $json) === false
                || !rename($temporary, $path)) {
                throw new \RuntimeException('Publication setup impossible.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function path(string $workspace): string
    {
        $root = realpath($workspace) ?: throw new \RuntimeException('Workspace introuvable.');
        return $root.'/.symsvel/setup.json';
    }
}
