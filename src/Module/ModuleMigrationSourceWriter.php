<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Writes a new, immutable module migration source. Never compiles or executes it.
 */
final class ModuleMigrationSourceWriter
{
    public function write(string $modulePath, string $source, ?\DateTimeImmutable $now = null): string
    {
        $root = realpath($modulePath);
        if ($root === false || !is_dir($root) || is_link($modulePath)) {
            throw new \InvalidArgumentException('Répertoire du module invalide.');
        }
        $directory = $root.'/Migration';
        if (is_link($directory)) {
            throw new \DomainException('Répertoire Migration symbolique interdit.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775) && !is_dir($directory)) {
            throw new \RuntimeException('Création du répertoire Migration impossible.');
        }
        if (!is_dir($directory) || is_link($directory)) {
            throw new \DomainException('Répertoire Migration invalide.');
        }

        $version = 'Version'.($now ?? new \DateTimeImmutable())->format('YmdHis');
        $path = $directory.'/'.$version.'.php';
        // Exclusive creation prevents overwriting any prior migration, even
        // when two generators happen to choose the same timestamp.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Migration déjà présente ou fichier inaccessible : '.$path);
        }
        try {
            $length = strlen($source);
            $written = 0;
            while ($written < $length) {
                $count = fwrite($handle, substr($source, $written));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException('Écriture incomplète de la migration : '.$path);
                }
                $written += $count;
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            unlink($path);
            throw $exception;
        }
        fclose($handle);

        return $path;
    }
}
