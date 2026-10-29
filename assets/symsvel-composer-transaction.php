<?php

declare(strict_types=1);

use SymSvel\Tooling\Composer\ComposerTransactionRunner;

$backend = dirname(__DIR__);
require $backend.'/vendor/autoload.php';

try {
    (new ComposerTransactionRunner())->run(
        $backend,
        array_slice($argv, 1),
        static function (string $output): void { echo $output; },
    );
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
