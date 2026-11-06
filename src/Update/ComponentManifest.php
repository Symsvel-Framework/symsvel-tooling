<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/** Persists framework provenance independently from application Git metadata. */
final class ComponentManifest
{
    public function __construct(private readonly string $backend)
    {
    }

    /** @return array{revision: string, files: array<string, string>}|null */
    public function get(string $component): ?array
    {
        $all = $this->read();
        $entry = $all[$component] ?? null;
        if ($entry === null) {
            return null;
        }
        if (!is_array($entry) || !is_string($entry['revision'] ?? null) || !is_array($entry['files'] ?? null)) {
            throw new \RuntimeException('Manifeste composant invalide : '.$component);
        }
        foreach ($entry['files'] as $path => $hash) {
            if (!is_string($path) || !$this->safeRelativePath($path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \RuntimeException('Empreinte native invalide : '.$component);
            }
        }
        return $entry;
    }

    /** @param array<string, string> $files */
    public function set(string $component, string $revision, array $files): void
    {
        if (!in_array($component, ['bo', 'app', 'front'], true)) {
            throw new \InvalidArgumentException('Composant inconnu.');
        }
        $all = $this->read();
        $all[$component] = ['revision' => $revision, 'files' => $files];
        $directory = $this->backend.'/.symsvel';
        if (is_link($directory) || (is_dir($directory) === false && !mkdir($directory, 0700, true))) {
            throw new \RuntimeException('Répertoire manifeste inaccessible.');
        }
        $file = $directory.'/components.json';
        if (is_link($file)) {
            throw new \RuntimeException('Manifeste symbolique refusé.');
        }
        $temp = tempnam($directory, '.components-');
        if ($temp === false) {
            throw new \RuntimeException('Impossible de préparer le manifeste.');
        }
        try {
            if (file_put_contents($temp, json_encode($all, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false || !rename($temp, $file)) {
                throw new \RuntimeException('Écriture du manifeste impossible.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /** Native paths must remain inside the component, on every platform. */
    private function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\\\\") || str_contains($path, "\\0") || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $file = $this->backend.'/.symsvel/components.json';
        if (is_link($file)) {
            throw new \RuntimeException('Manifeste symbolique refusé.');
        }
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new \RuntimeException('Manifeste composants invalide.');
        }
        return $data;
    }
}
