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
    private const FILES = ['composer.json', 'composer.lock', 'symfony.lock'];

    public function begin(string $backend): string
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        if (!is_file($backend.'/composer.json')) {
            throw new \RuntimeException('composer.json introuvable.');
        }
        foreach (self::FILES as $name) {
            if (is_link($backend.'/'.$name)) {
                throw new \RuntimeException('Manifest Composer symbolique refusé : '.$name);
            }
        }
        if (is_link($backend.'/.symsvel') || is_link($backend.'/.symsvel/transactions')) {
            throw new \RuntimeException('Répertoire de transactions symbolique refusé.');
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
        $fingerprints = [];
        $modes = [];
        foreach (self::FILES as $name) {
            $source = $backend.'/'.$name;
            if (is_link($source)) {
                throw new \RuntimeException('Manifest Composer symbolique refusé : '.$name);
            }
            $present = is_file($source);
            $state[$name] = $present;
            if ($present) {
                $fingerprint = hash_file('sha256', $source);
                if ($fingerprint === false || !copy($source, $directory.'/'.$name)
                    || hash_file('sha256', $directory.'/'.$name) !== $fingerprint) {
                    throw new \RuntimeException('Sauvegarde Composer impossible : '.$name);
                }
                $fingerprints[$name] = $fingerprint;
                $modes[$name] = fileperms($source) & 0777;
                if (!chmod($directory.'/'.$name, $modes[$name])) {
                    throw new \RuntimeException('Permissions snapshot Composer impossibles : '.$name);
                }
            }
        }
        $written = file_put_contents($directory.'/manifest.json', json_encode([
            'schema' => 1,
            'backend' => $backend,
            'files' => $state,
            'fingerprints' => $fingerprints,
            'modes' => $modes,
            'status' => 'pending',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if ($written === false) {
            throw new \RuntimeException('Écriture du manifeste de transaction impossible : '.$directory);
        }
        return $directory;
    }

    /** Restore root manifests only. Does not claim Composer rollback. */
    public function restoreManifests(string $backend, string $directory): void
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        $manifest = $this->read($backend, $directory);
        // Preflight all saved manifests before overwriting any current file.
        foreach (self::FILES as $name) {
            if (!$manifest['files'][$name]) {
                continue;
            }
            $source = $directory.'/'.$name;
            if (!is_file($source) || is_link($source)
                || hash_file('sha256', $source) !== ($manifest['fingerprints'][$name] ?? null)
                || (fileperms($source) & 0777) !== ($manifest['modes'][$name] ?? null)) {
                throw new \RuntimeException('Sauvegarde Composer altérée : '.$name);
            }
        }
        foreach (self::FILES as $name) {
            $destination = $backend.'/'.$name;
            if ($manifest['files'][$name]) {
                if (is_link($destination) && !unlink($destination)) {
                    throw new \RuntimeException('Lien Composer impossible à supprimer : '.$name);
                }
                if (!is_file($directory.'/'.$name)
                    || !copy($directory.'/'.$name, $destination)
                    || !chmod($destination, $manifest['modes'][$name])) {
                    throw new \RuntimeException('Restauration Composer impossible : '.$name);
                }
            } elseif ((is_file($destination) || is_link($destination)) && !unlink($destination)) {
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
        if (is_link($actual.'/manifest.json') || !is_file($actual.'/manifest.json')) {
            throw new \RuntimeException('Manifeste de transaction absent ou symbolique.');
        }
        $data = json_decode((string) file_get_contents($actual.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['backend'] ?? null) !== $backend
            || !isset($data['files']) || !is_array($data['files'])
            || !array_key_exists('composer.json', $data['files'])
            || !array_key_exists('composer.lock', $data['files'])
            || !array_key_exists('symfony.lock', $data['files'])
            || !is_bool($data['files']['composer.json']) || !is_bool($data['files']['composer.lock'])
            || !is_bool($data['files']['symfony.lock']) || !isset($data['fingerprints']) || !is_array($data['fingerprints'])
            || !isset($data['modes']) || !is_array($data['modes'])) {
            throw new \RuntimeException('Manifeste de transaction invalide.');
        }
        foreach (self::FILES as $name) {
            if ($data['files'][$name] && (!isset($data['fingerprints'][$name])
                || !is_string($data['fingerprints'][$name])
                || !preg_match('/^[a-f0-9]{64}$/D', $data['fingerprints'][$name])
                || !isset($data['modes'][$name]) || !is_int($data['modes'][$name])
                || $data['modes'][$name] < 0 || $data['modes'][$name] > 0777)) {
                throw new \RuntimeException('Empreinte Composer invalide : '.$name);
            }
        }
        return $data;
    }
}
