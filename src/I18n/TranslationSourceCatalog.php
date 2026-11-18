<?php

declare(strict_types=1);

namespace SymSvel\Tooling\I18n;

/**
 * Stages a deterministic inventory from installed modules and native host
 * dictionaries. TypeScript is treated as data, never executed.
 */
final class TranslationSourceCatalog
{
    /** @param list<array{module:string,path:string,components:array<string,string>}> $modules
     *  @return array{schema:int,entries:list<array{scope:string,module:string,key:string,language:string,value:string}>}
     */
    public function collect(string $backend, array $modules): array
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend unavailable.');
        $workspace = dirname($backend);
        $entries = [];
        foreach (['bo', 'app', 'front'] as $scope) {
            if (!is_dir($workspace.'/'.$scope)) {
                continue;
            }
            $this->readFiles($workspace.'/'.$scope.'/src/lib/translations/system', $scope, 'system', $entries);
            foreach ($modules as $module) {
                if (!isset($module['components'][$scope])) {
                    continue;
                }
                $slug = $module['module'];
                if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug)
                    || $slug === 'system') {
                    throw new \InvalidArgumentException('Invalid translation module identifier.');
                }
                $this->readFiles($module['path'].'/'.$scope.'/src/lib/translations', $scope, $slug, $entries);
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp(
            implode("\0", [$a['scope'], $a['module'], $a['key'], $a['language']]),
            implode("\0", [$b['scope'], $b['module'], $b['key'], $b['language']]),
        ));
        return ['schema' => 1, 'entries' => $entries];
    }

    /** @param array{schema:int,entries:list<array<string,string>>} $inventory */
    public function publish(string $backend, array $inventory): string
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend unavailable.');
        $directory = $backend.'/.symsvel';
        if (is_link($directory)) {
            throw new \RuntimeException('Translation manifest directory cannot be a symlink.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create translation manifest directory.');
        }
        $target = $directory.'/translation-sources.json';
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new \RuntimeException('Unsafe translation manifest target.');
        }
        $json = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (is_file($target) && file_get_contents($target) === $json) {
            return $target;
        }
        $temporary = tempnam($directory, '.translations-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot stage translation manifest.');
        }
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !chmod($temporary, 0644)
                || !rename($temporary, $target)) {
                throw new \RuntimeException('Cannot publish translation manifest.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return $target;
    }

    /** @param list<array{scope:string,module:string,key:string,language:string,value:string}> $entries */
    private function readFiles(string $directory, string $scope, string $module, array &$entries): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException('Translation source symlink forbidden: '.$directory);
        }
        if (!is_dir($directory)) {
            return;
        }
        $files = scandir($directory);
        if ($files === false) {
            throw new \RuntimeException('Cannot read translation source directory.');
        }
        foreach ($files as $name) {
            if (in_array($name, ['.', '..', '.gitkeep'], true)) {
                continue;
            }
            $path = $directory.'/'.$name;
            if (is_link($path) || !is_file($path) || !preg_match('/^([a-z]{2,4})\.ts$/D', $name, $match)) {
                throw new \RuntimeException('Invalid translation source: '.$path);
            }
            $content = file_get_contents($path);
            if (!is_string($content) || strlen($content) > 2097152
                || !preg_match('/^\s*export\s+default\s+(\{.*\})\s+as\s+const\s*;\s*$/sD', $content, $matchContent)) {
                throw new \InvalidArgumentException('Translation must be a JSON-compatible TypeScript default export: '.$path);
            }
            try {
                $data = json_decode($matchContent[1], true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new \InvalidArgumentException('Invalid dictionary JSON: '.$path, 0, $error);
            }
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                throw new \InvalidArgumentException('Translation dictionary must be an object: '.$path);
            }
            $this->flatten($data, '', $scope, $module, $match[1], $entries);
        }
    }

    /** @param array<string,mixed> $data
     *  @param list<array{scope:string,module:string,key:string,language:string,value:string}> $entries
     */
    private function flatten(array $data, string $prefix, string $scope, string $module, string $language, array &$entries): void
    {
        foreach ($data as $segment => $value) {
            if (!is_string($segment) || !preg_match('/^(?:[A-Za-z][A-Za-z0-9_.-]*|[0-9]+)$/D', $segment)) {
                throw new \InvalidArgumentException('Invalid translation key segment.');
            }
            $key = $prefix === '' ? $segment : $prefix.'.'.$segment;
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,189}$/D', $module.'.'.$key)) {
                throw new \InvalidArgumentException('Translation key too long.');
            }
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $this->flatten($value, $key, $scope, $module, $language, $entries);
            } elseif (is_string($value) && strlen($value) <= 8192 && !str_contains($value, "\0")) {
                $entries[] = compact('scope', 'module', 'key', 'language', 'value');
            } else {
                throw new \InvalidArgumentException('Translation leaf must be a UTF-8 string.');
            }
        }
    }
}
