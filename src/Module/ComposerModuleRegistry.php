<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Discovers installed SymSvel packages through Composer's installed metadata.
 * Unlike the legacy workspace registry, this never scans arbitrary directories.
 */
final class ComposerModuleRegistry
{
    public function __construct(private readonly ModuleManifest $reader = new ModuleManifest())
    {
    }

    /** @return array<string, array{path: string, manifest: array<string, mixed>}> */
    public function discover(string $backend): array
    {
        $backend = realpath($backend) ?: throw new \InvalidArgumentException('Backend introuvable.');
        $installed = $backend.'/vendor/composer/installed.json';
        if (!is_file($installed) || is_link($installed)) {
            throw new \RuntimeException('Métadonnées Composer introuvables : '.$installed);
        }
        $data = json_decode((string) file_get_contents($installed), true, 512, JSON_THROW_ON_ERROR);
        $packages = $data['packages'] ?? $data;
        if (!is_array($packages) || !array_is_list($packages)) {
            throw new \InvalidArgumentException('Format installed.json invalide.');
        }

        $modules = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                throw new \InvalidArgumentException('Package Composer invalide.');
            }
            if (!preg_match('~^symsvel-framework/module-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$~D', $package['name'], $matches)) {
                continue;
            }
            $slug = $matches[1];
            $relative = $package['install-path'] ?? '../symsvel-framework/module-'.$slug;
            if (!is_string($relative) || $relative === '') {
                throw new \InvalidArgumentException('Chemin Composer invalide : '.$slug);
            }
            $path = realpath($backend.'/vendor/composer/'.$relative);
            $vendor = realpath($backend.'/vendor/symsvel-framework');
            if ($path === false || $vendor === false || !str_starts_with($path, $vendor.'/')) {
                throw new \InvalidArgumentException('Module hors du répertoire vendor autorisé : '.$slug);
            }
            $manifest = $this->reader->read($path);
            $expected = implode('', array_map('ucfirst', explode('-', $slug)));
            if ($manifest['name'] !== $expected) {
                throw new \InvalidArgumentException('Nom du manifeste incohérent : '.$slug);
            }
            if (isset($modules[$slug])) {
                throw new \InvalidArgumentException('Module Composer dupliqué : '.$slug);
            }
            $modules[$slug] = ['path' => $path, 'manifest' => $manifest];
        }
        ksort($modules, SORT_STRING);

        return $modules;
    }
}
