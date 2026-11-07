<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * Persisted package/source provenance, independent from Symfony APP_ENV.
 */
final class ProjectChannel
{
    public const STABLE = 'stable';
    public const DEV = 'dev';

    public static function read(string $backend): string
    {
        $directory = $backend.'/.symsvel';
        $path = $directory.'/project.json';
        if (is_link($directory) || is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Canal SymSvel absent ou non fiable : '.$path);
        }
        $configuration = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($configuration) || ($configuration['schema'] ?? null) !== 1
            || !in_array($configuration['channel'] ?? null, [self::STABLE, self::DEV], true)) {
            throw new \RuntimeException('Canal SymSvel invalide : '.$path);
        }
        return $configuration['channel'];
    }

    public static function write(string $backend, string $channel): void
    {
        if (!in_array($channel, [self::STABLE, self::DEV], true)) {
            throw new \InvalidArgumentException('Canal SymSvel inconnu.');
        }
        $directory = $backend.'/.symsvel';
        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new \RuntimeException('Répertoire de configuration SymSvel non fiable.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Création du répertoire de configuration impossible.');
        }
        $path = $directory.'/project.json';
        if (is_link($path) || file_exists($path)) {
            throw new \RuntimeException('Canal SymSvel déjà défini : '.$path);
        }
        $temporary = tempnam($directory, '.project-');
        if ($temporary === false) {
            throw new \RuntimeException('Préparation du canal SymSvel impossible.');
        }
        try {
            $data = json_encode(['schema' => 1, 'channel' => $channel], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($temporary, $data) === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Enregistrement du canal SymSvel impossible.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
