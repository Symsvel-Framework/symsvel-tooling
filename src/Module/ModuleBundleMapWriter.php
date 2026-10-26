<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Generates an explicit Symfony bundle map for consumption by the application.
 * Does not execute code from a module or mutate the application's config.
 */
final class ModuleBundleMapWriter
{
    public function __construct(
        private readonly ModuleRegistry $registry = new ModuleRegistry(),
        private readonly ModuleBundleResolver $resolver = new ModuleBundleResolver(),
    ) {
    }

    public function write(string $workspace): string
    {
        $root = realpath($workspace) ?: throw new \RuntimeException('Workspace introuvable.');
        $bundles = $this->resolver->resolve($this->registry->discover($root));
        $directory = $root.'/.symsvel';
        if (is_link($directory)) {
            throw new \RuntimeException('Répertoire symbolique interdit.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer .symsvel.');
        }
        $target = $directory.'/bundles.php';
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new \RuntimeException('Destination invalide.');
        }
        $classes = array_column($bundles, 'class');
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($classes, true).";\n";
        if (is_file($target) && file_get_contents($target) === $contents) {
            return $target;
        }
        $temporary = tempnam($directory, '.bundles-');
        if ($temporary === false) {
            throw new \RuntimeException('Fichier temporaire indisponible.');
        }
        try {
            if (file_put_contents($temporary, $contents) === false || !rename($temporary, $target)) {
                throw new \RuntimeException('Échec de publication.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $target;
    }
}
