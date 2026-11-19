<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Bridges Composer "path" symlinks to the per-module Symfony Flex route imports.
 * Published Flex recipe files are never taken over or removed by Tooling.
 * The ledger stores file ownership hashes, NOT a runtime routing registry.
 */
final class ComposerModuleRouteSynchronizer
{
    public function __construct(private readonly ComposerModuleRegistry $registry = new ComposerModuleRegistry())
    {
    }

    /** @return array{written: int, removed: int, external: int} */
    public function sync(string $backend): array
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        $routes = $backend.'/config/routes';
        $stateDir = $backend.'/.symsvel';
        $stateFile = $stateDir.'/composer-routes.json';
        foreach ([$backend.'/config', $routes, $stateDir] as $directory) {
            if (is_link($directory)) {
                throw new \RuntimeException('Répertoire de routes ou registre symbolique interdit : '.$directory);
            }
        }
        if (!is_dir($routes)) {
            throw new \RuntimeException('Répertoire config/routes Symfony introuvable.');
        }
        if (is_link($stateFile) || (file_exists($stateFile) && !is_file($stateFile))) {
            throw new \RuntimeException('Registre de propriété des routes invalide.');
        }
        $previous = [];
        if (is_file($stateFile)) {
            $data = json_decode((string) file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['files'] ?? null)
                || ($data['files'] !== [] && array_is_list($data['files']))) {
                throw new \RuntimeException('Format de registre de routes invalide.');
            }
            $previous = $data['files'];
        }

        // Validate ALL previously managed files before performing a single write.
        foreach ($previous as $relative => $hash) {
            if (!is_string($relative) || !preg_match('~^config/routes/[a-z][a-z0-9-]*\.yaml$~D', $relative)
                || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \RuntimeException('Entrée de registre de routes invalide.');
            }
            $path = $backend.'/'.$relative;
            if (!is_file($path) || is_link($path) || !hash_equals($hash, (string) hash_file('sha256', $path))) {
                throw new \RuntimeException('Import de routes généré modifié ou absent : '.$relative);
            }
        }

        $desired = [];
        foreach ($this->registry->discover($backend) as $slug => $module) {
            $entry = $backend.'/vendor/symsvel-framework/module-'.$slug;
            // An ordinary Composer distribution is owned by Flex, not Tooling.
            if (!is_link($entry)) {
                continue;
            }
            $recipe = $module['path'].'/recipe/recipe.json';
            if (!is_file($recipe)) {
                continue; // No explicit route declaration: never discover routes implicitly.
            }
            if (is_link($recipe)) {
                throw new \RuntimeException('Déclaration de routes symbolique interdite : '.$slug);
            }
            $declaration = json_decode((string) file_get_contents($recipe), true, 32, JSON_THROW_ON_ERROR);
            $lines = $declaration['manifests']['symsvel-framework/module-'.$slug]
                ['files']['config/routes/'.$slug.'.yaml']['contents'] ?? null;
            if ($lines === null) {
                continue;
            }
            $class = $module['manifest']['name'];
            $namespace = 'SymSvel\\Module\\'.$class.'\\Controller';
            if (!is_array($lines) || !array_is_list($lines)
                || !in_array($slug.':', $lines, true)
                || !in_array('    type: attribute', $lines, true)
                || !in_array('        namespace: '.$namespace, $lines, true)) {
                throw new \RuntimeException('Déclaration Flex de routes invalide : '.$slug);
            }
            $controllers = $module['path'].'/back/src/Controller';
            if (!is_dir($controllers)) {
                continue; // Module without a controller: no empty import.
            }
            $hasController = false;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($controllers, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $controller) {
                if ($controller->isLink()) {
                    throw new \RuntimeException('Contrôleur symbolique interdit : '.$slug);
                }
                if ($controller->isFile() && $controller->getExtension() === 'php') {
                    $hasController = true;
                }
            }
            if (!$hasController) {
                continue;
            }
            $relative = 'config/routes/'.$slug.'.yaml';
            $desired[$relative] = '# Generated by SymSvel Tooling for a local Composer path module.'
                ."\n".$slug.":\n    resource:\n"
                ."        path: '../../vendor/symsvel-framework/module-".$slug."/back/src/Controller/'\n"
                ."        namespace: ".$namespace."\n"
                ."    type: attribute\n";
        }

        ksort($desired);
        $next = [];
        $writes = [];
        $external = 0;
        foreach ($desired as $relative => $content) {
            $path = $backend.'/'.$relative;
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new \RuntimeException('Import de routes non régulier : '.$relative);
            }
            if (is_file($path) && !array_key_exists($relative, $previous)) {
                // Flex or the application owns this import: never overwrite or adopt.
                ++$external;
                continue;
            }
            $next[$relative] = hash('sha256', $content);
            if (!is_file($path) || file_get_contents($path) !== $content) {
                $writes[$relative] = $content;
            }
        }
        $removals = array_diff(array_keys($previous), array_keys($next));

        if (is_link($stateDir) || (file_exists($stateDir) && !is_dir($stateDir))) {
            throw new \RuntimeException('Répertoire de registre invalide.');
        }
        if (!is_dir($stateDir) && !mkdir($stateDir, 0775) && !is_dir($stateDir)) {
            throw new \RuntimeException('Création du registre impossible.');
        }
        // Each file is installed atomically. The enclosing CLI Composer transaction
        // checkpoints both config/routes and .symsvel for recovery on I/O failures.
        foreach ($writes as $relative => $content) {
            $this->atomicWrite($backend.'/'.$relative, $content);
        }
        foreach ($removals as $relative) {
            if (!unlink($backend.'/'.$relative)) {
                throw new \RuntimeException('Impossible de retirer un import de routes : '.$relative);
            }
        }
        ksort($next);
        $json = json_encode(['schema' => 1, 'files' => (object) $next],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (!is_file($stateFile) || file_get_contents($stateFile) !== $json) {
            $this->atomicWrite($stateFile, $json);
        }
        return ['written' => count($writes), 'removed' => count($removals), 'external' => $external];
    }

    private function atomicWrite(string $path, string $content): void
    {
        $tmp = tempnam(dirname($path), '.symsvel-route-');
        if ($tmp === false) {
            throw new \RuntimeException('Fichier temporaire de routes indisponible.');
        }
        try {
            if (file_put_contents($tmp, $content) === false || !chmod($tmp, 0644) || !rename($tmp, $path)) {
                throw new \RuntimeException('Publication de routes impossible : '.$path);
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
