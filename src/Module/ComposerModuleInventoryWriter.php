<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Publishes the validated Composer-installed module inventory atomically for local tooling.
 * No runtime services, migrations or Composer packages are modified.
 */
final class ComposerModuleInventoryWriter
{
    public function __construct(private readonly ComposerModuleSynchronizer $synchronizer = new ComposerModuleSynchronizer())
    {
    }

    public function write(string $backend): string
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        $plan = $this->synchronizer->prepare($backend);
        $directory = $backend.'/.symsvel';
        if (is_link($directory)) {
            throw new \RuntimeException('Répertoire de sortie symbolique interdit.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer .symsvel.');
        }
        $target = $directory.'/modules.json';
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new \RuntimeException('Destination de modules invalide.');
        }
        $json = json_encode(['schema' => 1, 'modules' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (is_file($target) && file_get_contents($target) === $json) {
            return $target;
        }
        $temporary = tempnam($directory, '.modules-');
        if ($temporary === false) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire.');
        }
        try {
            if (file_put_contents($temporary, $json) === false || !rename($temporary, $target)) {
                throw new \RuntimeException('Impossible de publier le registre des modules.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $target;
    }
}
