<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Composer;

/**
 * Persistent snapshot of the root Composer manifests.
 *
 * Does not restore vendor/, database changes or Flex recipe side effects.
 * The caller must perform a separate, script-free Composer recovery.
 */
final class ComposerTransactionSnapshot
{
    private const FILES = ['composer.json', 'composer.lock'];

    public function begin(string $backend): string
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if (!is_file($backend.'/composer.json')) {
            throw new \RuntimeException('composer.json introuvable.');
        }
        $base = $backend.'/.symsvel/transactions';
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('Impossible de créer le registre de transactions.');
        }
        $id = bin2hex(random_bytes(16));
        $directory = $base.'/'.$id;
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Impossible de créer la transaction.');
        }
        $state = [];
        foreach (self::FILES as $name) {
            $source = $backend.'/'.$name;
            $present = is_file($source);
            $state[$name] = $present;
            if ($present && !copy($source, $directory.'/'.$name)) {
                throw new \RuntimeException('Sauvegarde Composer impossible : '.$name);
            }
        }
        file_put_contents($directory.'/manifest.json', json_encode([
            'schema' => 1,
            'backend' => $backend,
            'files' => $state,
            'status' => 'pending',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return $directory;
    }

    /** Restore root manifests only. Does not claim Composer rollback. */
    public function restoreManifests(string $backend, string $directory): void
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        $manifest = $this->read($backend, $directory);
        foreach (self::FILES as $name) {
            $destination = $backend.'/'.$name;
            if ($manifest['files'][$name]) {
                if (!is_file($directory.'/'.$name) || !copy($directory.'/'.$name, $destination)) {
                    throw new \RuntimeException('Restauration Composer impossible : '.$name);
                }
            } elseif (is_file($destination) && !unlink($destination)) {
                throw new \RuntimeException('Suppression Composer impossible : '.$name);
            }
        }
    }

    public function finish(string $backend, string $directory): void
    {
        $manifest = $this->read(realpath($backend) ?: $backend, $directory);
        foreach (self::FILES as $name) {
            if ($manifest['files'][$name]) {
                unlink($directory.'/'.$name);
            }
        }
        unlink($directory.'/manifest.json');
        rmdir($directory);
    }

    /** @return array<string, mixed> */
    private function read(string $backend, string $directory): array
    {
        $root = realpath($backend.'/.symsvel/transactions');
        $actual = realpath($directory);
        if ($root === false || $actual === false || dirname($actual) !== $root) {
            throw new \RuntimeException('Transaction hors registre.');
        }
        $data = json_decode((string) file_get_contents($actual.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['backend'] ?? null) !== $backend
            || !isset($data['files']) || !is_array($data['files'])
            || !array_key_exists('composer.json', $data['files'])
            || !array_key_exists('composer.lock', $data['files'])
            || !is_bool($data['files']['composer.json']) || !is_bool($data['files']['composer.lock'])) {
            throw new \RuntimeException('Manifeste de transaction invalide.');
        }
        return $data;
    }
}
