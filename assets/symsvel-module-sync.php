<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$backend = dirname(__DIR__);
if (!is_file($backend.'/vendor/symsvel-framework/tooling/src/Command/SymSvelModuleSyncCommand.php')) {
    // Tooling is a development dependency and may be absent in production.
    exit(0);
}

$environment = getenv('APP_ENV') ?: 'dev';
$sync = new Process([PHP_BINARY, 'bin/console', 'symsvel:module:sync', '--source=composer', '--env='.$environment], $backend);
$sync->setTimeout(900);
$sync->run(static function (string $type, string $output): void {
    echo $output;
});
if (!$sync->isSuccessful()) {
    fwrite(STDERR, "SymSvel module synchronization failed.\n");
    exit(1);
}

foreach (['bo', 'app', 'front'] as $scope) {
    $directory = dirname($backend).'/'.$scope;
    if (!is_dir($directory)) {
        continue;
    }
    if (!is_file($directory.'/package.json')) {
        fwrite(STDERR, "Invalid Svelte component: ".$scope." (package.json missing).\n");
        exit(1);
    }
    echo 'SymSvel: building '.strtoupper($scope)."\n";
    $build = new Process(['npm', 'run', 'build'], $directory);
    $build->setTimeout(900);
    $build->run(static function (string $type, string $output): void {
        echo $output;
    });
    if (!$build->isSuccessful()) {
        fwrite(STDERR, 'SymSvel: '.strtoupper($scope)." build failed.\n");
        exit(1);
    }
}
