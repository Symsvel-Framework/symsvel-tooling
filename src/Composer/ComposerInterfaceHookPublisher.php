<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Composer;

final class ComposerInterfaceHookPublisher
{
    public function publish(string $backend): void
    {
        $destination = $backend.'/scripts/symsvel-module-sync.php';
        if (!is_file($destination)) {
            return;
        }
        $existing = file_get_contents($destination);
        $expected = file_get_contents(dirname(__DIR__, 2).'/assets/symsvel-module-sync.php');
        if ($existing === false || $expected === false || is_link($destination)) {
            throw new \RuntimeException('Hook Composer illisible ou symbolique.');
        }
        if ($existing === $expected) {
            return;
        }
        $legacy = ['598f0b40b6bff9a093e39e521b6d2a52e6d51025', 'faf1950f1d59ee6bc07682a6704fcbf3657d986f', 'fd1c818615dcb452df4a9c22c8ad03bd09b3fd30'];
        if (!in_array(sha1('blob '.strlen($existing)."\0".$existing), $legacy, true)) {
            throw new \RuntimeException('Script Composer SymSvel modifié localement : '.$destination);
        }
        if (file_put_contents($destination, $expected) === false) {
            throw new \RuntimeException('Impossible de publier '.$destination);
        }
    }
}
