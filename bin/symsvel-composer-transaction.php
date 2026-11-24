<?php

declare(strict_types=1);

// Thin CLI adapter: the existing ComposerTransactionRunner owns checkpoints,
// rollback, verification and deferred cleanup.
$operation = $argv[1] ?? '';
$args = array_slice($argv, 2);
if (in_array($operation, ['record-back', 'update-back'], true)) {
    if ($args !== []) {
        fwrite(STDERR, "[symsvel] $operation does not accept arguments\n");
        exit(2);
    }
    $backend = getcwd();
    require_once $backend.'/vendor/autoload.php';
    try {
        $updater = new \SymSvel\Tooling\Update\BackendNativeUpdater();
        if ($operation === 'record-back') {
            $updater->recordInstalled($backend);
        } else {
            $updater->update($backend, static function (string $output): void { echo $output; });
        }
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, "[symsvel] ".$error->getMessage().PHP_EOL);
        exit(1);
    }
}
if (!in_array($operation, ['require', 'update', 'remove'], true)) {
    fwrite(STDERR, "[symsvel] Unknown transaction: $operation\n");
    exit(2);
}
if ($operation !== 'update' && ($args === [] || str_starts_with($args[0], '-'))) {
    fwrite(STDERR, "[symsvel] Usage: symsvel $operation vendor/package [--dev]\n");
    exit(2);
}
$dev = false;
$bootstrap = false;
foreach ($args as $index => $argument) {
    if ($argument === '--bootstrap') {
        $bootstrap = true;
        unset($args[$index]);
    } elseif ($argument === '--dev') {
        $dev = true;
        unset($args[$index]);
    }
}
$args = array_values($args);
if ($dev && $operation === 'require' && isset($args[0]) && !str_contains($args[0], ':')) {
    $args[0] .= ':dev-dev';
}
$backend = getcwd();
require_once $backend.'/vendor/autoload.php';
try {
    (new \SymSvel\Tooling\Composer\ComposerTransactionRunner())->run(
        $backend,
        array_merge([$operation], $args),
        static function (string $output): void { echo $output; },
        bootstrap: $bootstrap,
    );
} catch (Throwable $error) {
    fwrite(STDERR, "[symsvel] ".$error->getMessage().PHP_EOL);
    exit(1);
}
