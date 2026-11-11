<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Restricts ORM metadata to entity classes physically owned by one module.
 * No global schema diff may be generated from unfiltered metadata.
 */
final class ModuleEntityMetadataSelector
{
    /**
     * @template T of object
     * @param iterable<T> $metadata
     * @return list<T>
     */
    public function select(array $module, iterable $metadata): array
    {
        $root = realpath(rtrim($module['path'], '/').'/back/src/Entity');
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('Répertoire Entity du module introuvable.');
        }
        $selected = [];
        foreach ($metadata as $mapping) {
            if (!method_exists($mapping, 'getReflectionClass')) {
                throw new \InvalidArgumentException('Métadonnées ORM invalides.');
            }
            $reflection = $mapping->getReflectionClass();
            if (!$reflection instanceof \ReflectionClass) {
                throw new \InvalidArgumentException('Classe ORM non résolue.');
            }
            $file = $reflection->getFileName();
            $canonical = $file === false ? false : realpath($file);
            if ($canonical === false || !str_starts_with($canonical, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (($mapping->isMappedSuperclass ?? false) || ($mapping->isEmbeddedClass ?? false)) {
                continue;
            }
            $selected[] = $mapping;
        }
        return $selected;
    }
}
