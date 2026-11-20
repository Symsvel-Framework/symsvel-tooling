<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Documentation;

use SymSvel\Tooling\Module\ComposerModuleRegistry;

/**
 * Read-only source collection and one atomic private publication.
 * No MkDocs, Python, Node build or runtime routing is involved.
 */
final class ComposerDocumentationAssembler
{
    public const MAX_BYTES = 12582912;
    private const MAX_PAGE_BYTES = 524288;

    public function __construct(private readonly ComposerModuleRegistry $registry = new ComposerModuleRegistry())
    {
    }

    /** @return array{path:string, pages:int, changed:bool} */
    public function assemble(string $backend): array
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        $workspace = dirname($backend);
        $pages = [];
        // Host documentation is development-only; never expose it as admin help.
        foreach ([
            'back' => $backend.'/docs',
            'bo' => $workspace.'/bo/docs',
            'app' => $workspace.'/app/docs',
            'front' => $workspace.'/front/docs',
        ] as $owner => $source) {
            if (is_dir($source)) {
                $this->collect($pages, $source, 'dev', 'host', $owner);
            }
        }
        foreach ($this->registry->discover($backend) as $module => $installed) {
            foreach ($installed['manifest']['documentation'] as $scope => $relative) {
                $this->collect($pages, $installed['path'].'/'.$relative, $scope, 'module', $module);
            }
        }
        ksort($pages, SORT_STRING);
        $pages = array_values($pages);
        $checksum = hash('sha256', self::encode($pages));
        $json = self::encode([
            'schema' => 1,
            'generated_by' => 'symsvel-tooling',
            'checksum' => $checksum,
            'pages' => $pages,
        ])."\n";

        $var = $backend.'/var';
        $directory = $var.'/symsvel';
        $path = $directory.'/documentation.json';
        foreach ([$var, $directory] as $folder) {
            if (is_link($folder) || (file_exists($folder) && !is_dir($folder))) {
                throw new \RuntimeException('Répertoire documentaire non fiable.');
            }
        }
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Publication documentaire non fiable.');
        }
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($existing) || ($existing['schema'] ?? null) !== 1
                || ($existing['generated_by'] ?? null) !== 'symsvel-tooling'
                || !is_array($existing['pages'] ?? null)
                || !is_string($existing['checksum'] ?? null)
                || !hash_equals($existing['checksum'], hash('sha256', self::encode($existing['pages'])))) {
                throw new \RuntimeException('Publication documentaire modifiée localement : refus écriture.');
            }
            if (file_get_contents($path) === $json) {
                return ['path' => $path, 'pages' => count($pages), 'changed' => false];
            }
        }
        foreach ([$var, $directory] as $folder) {
            if (!is_dir($folder) && !mkdir($folder, 0775) && !is_dir($folder)) {
                throw new \RuntimeException('Création du répertoire documentaire impossible.');
            }
        }
        $tmp = tempnam($directory, '.documentation-');
        if ($tmp === false) {
            throw new \RuntimeException('Publication documentaire temporaire impossible.');
        }
        try {
            if (file_put_contents($tmp, $json) === false || !chmod($tmp, 0644) || !rename($tmp, $path)) {
                throw new \RuntimeException('Publication documentaire impossible.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
        return ['path' => $path, 'pages' => count($pages), 'changed' => true];
    }

    /** @param array<string, array<string, string>> $pages */
    private function collect(array &$pages, string $root, string $scope, string $source, string $owner): void
    {
        if (!in_array($scope, ['dev', 'admin', 'user'], true)
            || !in_array($source, ['host', 'module'], true)
            || !preg_match('/^[a-z][a-z0-9-]*$/D', $owner)
            || !is_dir($root) || is_link($root)) {
            throw new \RuntimeException('Source documentaire invalide : '.$owner);
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException('Lien symbolique interdit dans la documentation : '.$owner);
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (!str_ends_with(strtolower($relative), '.md')) {
                continue;
            }
            $segments = explode('/', $relative);
            if ($relative === '' || str_contains($relative, '\\')
            ) {
                throw new \RuntimeException('Chemin documentaire invalide : '.$owner);
            }
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                    throw new \RuntimeException('Chemin documentaire invalide : '.$owner);
                }
            }
            $size = $file->getSize();
            if ($size < 0 || $size > self::MAX_PAGE_BYTES) {
                throw new \RuntimeException('Page documentaire trop volumineuse : '.$owner);
            }
            $markdown = file_get_contents($file->getPathname());
            if (!is_string($markdown) || preg_match('//u', $markdown) !== 1) {
                throw new \RuntimeException('Encodage de documentation invalide : '.$owner);
            }
            $id = $scope.':'.$source.':'.$owner.':'.$relative;
            if (isset($pages[$id])) {
                throw new \RuntimeException('Collision de documentation : '.$id);
            }
            preg_match('/^#\s+(.+)$/m', $markdown, $match);
            $title = trim($match[1] ?? basename($relative, '.md'));
            if (strlen($title) > 500) {
                throw new \RuntimeException('Titre documentaire trop long : '.$owner);
            }
            $pages[$id] = [
                'id' => $id, 'scope' => $scope, 'source' => $source,
                'owner' => $owner, 'file' => $relative,
                'title' => $title, 'markdown' => $markdown,
            ];
        }
        if (strlen(self::encode(array_values($pages))) > self::MAX_BYTES) {
            throw new \RuntimeException('Volume total de documentation trop important.');
        }
    }

    private static function encode(mixed $data): string
    {
        return json_encode($data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
