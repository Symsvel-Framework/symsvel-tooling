<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Filesystem;

use Symfony\Component\Process\Process;

/** Removal of caller-owned temporary trees, never arbitrary project paths. */
final class NativeTreeRemoval
{
    public static function remove(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $process = new Process(['rm', '-rf', '--', $path]);
            $process->setTimeout(60);
            $process->mustRun();
            return;
        }
        // Resolve the parent, not the target: a redirected target must not
        // become an authorized directory. Refuse unexpected reparse paths.
        $parent = realpath(dirname($path));
        if ($parent === false || is_link($path)) {
            throw new \RuntimeException('Cible de nettoyage non fiable : '.$path);
        }
        $expected = $parent.DIRECTORY_SEPARATOR.basename($path);
        self::removeWindows($expected);
    }

    private static function removeWindows(string $path): void
    {
        if (is_link($path)) {
            // A directory symlink on Windows may require rmdir, never recursion.
            if (!@unlink($path) && !@rmdir($path)) {
                throw new \RuntimeException('Suppression du lien impossible : '.$path);
            }
            return;
        }
        if (!file_exists($path)) {
            return;
        }
        $actual = realpath($path);
        if ($actual === false || strcasecmp(str_replace('\\', '/', $actual), str_replace('\\', '/', $path)) !== 0) {
            throw new \RuntimeException('Chemin redirigé refusé : '.$path);
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                self::removeWindows($path.DIRECTORY_SEPARATOR.$entry->getFilename());
            }
            if (!@rmdir($path)) {
                throw new \RuntimeException('Suppression du répertoire impossible : '.$path);
            }
            return;
        }
        if (!is_file($path)) {
            throw new \RuntimeException('Fichier temporaire non ordinaire : '.$path);
        }
        if (@unlink($path)) {
            return;
        }
        // Git marks loose objects read-only on Windows. Retry only on an
        // ordinary file within the verified temporary tree, never a link.
        clearstatcache(true, $path);
        if (is_link($path) || is_writable($path) || !@chmod($path, 0666)) {
            throw new \RuntimeException('Suppression du fichier impossible : '.$path);
        }
        if (!@unlink($path)) {
            @chmod($path, 0444);
            throw new \RuntimeException('Suppression du fichier impossible : '.$path);
        }
    }
}
