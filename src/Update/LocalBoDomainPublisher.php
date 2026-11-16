<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Update;

/**
 * CLI-only publication of the validated BO hostname in Symfony's runtime env.
 * Never make private Setup metadata accessible to PHP-FPM.
 */
final class LocalBoDomainPublisher
{
    public function publish(string $backend): bool
    {
        $backend = realpath($backend) ?: throw new \RuntimeException('Backend introuvable.');
        $workspace = dirname($backend);
        $privateDirectory = $workspace.'/.symsvel';
        $source = $privateDirectory.'/project.json';
        $target = $backend.'/.env.local';

        if (is_link($privateDirectory) || is_link($source) || is_link($target)) {
            throw new \RuntimeException('Publication domaine BO : lien symbolique interdit.');
        }
        if (!file_exists($source) || !file_exists($target)) {
            return false;
        }
        if (!is_file($source) || !is_readable($source) || !is_file($target) || !is_readable($target)) {
            throw new \RuntimeException('Publication domaine BO : fichier invalide.');
        }

        $metadata = json_decode((string) file_get_contents($source), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || ($metadata['schema'] ?? null) !== 1) {
            throw new \RuntimeException('Publication domaine BO : métadonnées Setup invalides.');
        }
        $domain = $metadata['local_domain'] ?? null;
        if ($domain === null) {
            return false;
        }
        if (!is_string($domain) ||
            !preg_match('/^(?=.{1,253}$)[a-z0-9]+(?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9]+(?:[a-z0-9-]*[a-z0-9])?)*$/D', $domain)) {
            throw new \RuntimeException('Publication domaine BO : domaine Setup invalide.');
        }

        $existing = (string) file_get_contents($target);
        $lines = preg_split('/\r\n|\n|\r/', $existing) ?: [];
        $lines = array_values(array_filter(
            $lines,
            static fn (string $line): bool => preg_match('/^\s*SYMSVEL_BO_DOMAIN\s*=/', $line) !== 1,
        ));
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        $lines[] = 'SYMSVEL_BO_DOMAIN='.json_encode($domain, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $next = implode("\n", $lines)."\n";
        if ($next === $existing) {
            return false;
        }

        $temp = tempnam($backend, '.symsvel-bo-domain-');
        if ($temp === false) {
            throw new \RuntimeException('Publication domaine BO : temporaire indisponible.');
        }
        try {
            $mode = fileperms($target);
            if ($mode === false || !chmod($temp, $mode & 0777)
                || file_put_contents($temp, $next) === false || !rename($temp, $target)) {
                throw new \RuntimeException('Publication domaine BO : écriture atomique impossible.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        return true;
    }
}
