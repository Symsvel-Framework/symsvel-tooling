<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Resolves module backend bundle classes for generated Symfony configuration.
 */
final class ModuleBundleResolver
{
    /** @param array<string, array{path: string, manifest: array<string, mixed>}> $modules
     *  @return list<array{module: string, class: string, file: string}>
     */
    public function resolve(array $modules): array
    {
        $bundles = [];
        foreach ($modules as $slug => $module) {
            $manifest = $module['manifest'];
            $class = 'SymSvel\\Module\\'.$manifest['name'].'\\'.$manifest['name'].'Bundle';
            $file = $module['path'].'/back/src/'.$manifest['name'].'Bundle.php';
            if (!is_file($file) || is_link($file)) {
                throw new \InvalidArgumentException('Bundle manquant : '.$slug);
            }
            $composerFile = $module['path'].'/composer.json';
            $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
            $prefix = 'SymSvel\\Module\\'.$manifest['name'].'\\';
            if (!is_array($composer) || ($composer['autoload']['psr-4'][$prefix] ?? null) !== 'back/src/') {
                throw new \InvalidArgumentException('Autoload PSR-4 invalide : '.$slug);
            }
            $bundles[] = ['module' => $slug, 'class' => $class, 'file' => $file];
        }

        return $bundles;
    }
}
