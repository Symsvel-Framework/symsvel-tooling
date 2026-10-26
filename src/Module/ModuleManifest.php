<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

final class ModuleManifest
{
    public function read(string $directory): array
    {
        $path = rtrim($directory, '/').'/module.json';
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('Manifeste introuvable : '.$path);
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('JSON invalide.', 0, $e);
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_string($data['name'] ?? null)
            || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $data['name'])) {
            throw new \InvalidArgumentException('Contrat SymSvel v1 invalide.');
        }
        foreach (['components' => ['back', 'bo', 'app', 'front'], 'documentation' => ['dev', 'admin', 'user']] as $field => $allowed) {
            $entries = $data[$field] ?? null;
            $required = $field === 'components' ? 'back' : 'dev';
            if (!is_array($entries) || array_is_list($entries) || !isset($entries[$required])) {
                throw new \InvalidArgumentException($field.' doit inclure '.$required.'.');
            }
            foreach ($entries as $key => $relative) {
                $expected = $field === 'components' ? $key : 'docs/'.$key;
                if (!in_array($key, $allowed, true) || $relative !== $expected
                    || !is_dir($directory.'/'.$expected) || is_link($directory.'/'.$expected)) {
                    throw new \InvalidArgumentException('Scope '.$field.' invalide : '.$key);
                }
            }
        }
        if (!is_array($data['dependencies'] ?? null) || !is_array($data['extensions'] ?? null)) {
            throw new \InvalidArgumentException('Dépendances ou extensions invalides.');
        }

        return $data;
    }
}
