<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/** Three-way merge of the root Composer document; lists are atomic. */
final class BackendComposerMerge
{
    private object $missing;
    /** @var list<string> */
    private array $conflicts = [];

    public function merge(string $baseline, string $incoming, string $local, string $channel): string
    {
        $this->missing = new \stdClass();
        $this->conflicts = [];
        $before = $this->document($baseline);
        $next = $this->document($incoming);
        $current = $this->document($local);
        $result = $this->value($before, $next, $current, '', $channel);
        if ($this->conflicts !== []) {
            throw new \RuntimeException('Conflits composer.json : '.implode(', ', $this->conflicts));
        }
        if ($this->same($result, $current)) {
            return $local;
        }
        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function document(string $contents): \stdClass
    {
        $value = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) {
            throw new \RuntimeException('Document Composer invalide.');
        }
        return $value;
    }

    private function value(mixed $before, mixed $next, mixed $local, string $path, string $channel): mixed
    {
        // Explicit dev provenance is not a customization to be switched to stable.
        if ($channel === ProjectChannel::DEV
            && in_array($path, ['require.symsvel-framework/core', 'require-dev.symsvel-framework/tooling'], true)
            && $local === 'dev-dev' && is_string($next)) {
            return $local;
        }
        if ($this->same($before, $next) || $this->same($next, $local)) {
            return $local;
        }
        if ($this->same($before, $local)) {
            return $next;
        }
        if ($before instanceof \stdClass && $next instanceof \stdClass && $local instanceof \stdClass
            && $before !== $this->missing && $next !== $this->missing && $local !== $this->missing) {
            $result = new \stdClass();
            foreach (array_unique([...array_keys(get_object_vars($local)), ...array_keys(get_object_vars($next)), ...array_keys(get_object_vars($before))]) as $key) {
                $merged = $this->value(
                    property_exists($before, $key) ? $before->{$key} : $this->missing,
                    property_exists($next, $key) ? $next->{$key} : $this->missing,
                    property_exists($local, $key) ? $local->{$key} : $this->missing,
                    ltrim($path.'.'.$key, '.'),
                    $channel,
                );
                if ($merged !== $this->missing) {
                    $result->{$key} = $merged;
                }
            }
            return $result;
        }
        $this->conflicts[] = $path;
        return $local;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a === $this->missing || $b === $this->missing) {
            return $a === $b;
        }
        if ($a instanceof \stdClass && $b instanceof \stdClass) {
            $left = get_object_vars($a);
            $right = get_object_vars($b);
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $key => $value) {
                if (!array_key_exists($key, $right) || !$this->same($value, $right[$key])) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !$this->same($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }
}
