<?php

declare(strict_types=1);

// Composer and Flex own dependency installation and recipe application.
// SymSvel's post-update-cmd then performs module synchronization.
$operation = $argv[1] ?? '';
$args = array_slice($argv, 2);
if ($operation !== 'require') {
    fwrite(STDERR, "[symsvel] Transaction '$operation' not implemented yet. No changes made.\n");
    exit(2);
}
if (count($args) < 1 || str_starts_with($args[0], '-')) {
    fwrite(STDERR, "[symsvel] Usage: symsvel require vendor/package [--dev]\n");
    exit(2);
}
$package = array_shift($args);
$dev = false;
foreach ($args as $arg) {
    if ($arg === '--dev') {
        $dev = true;
    } else {
        fwrite(STDERR, "[symsvel] Unknown option: $arg\n");
        exit(2);
    }
}
if ($dev && !str_contains($package, ':')) {
    $package .= ':dev-main';
}
$command = ['composer', 'require', '--with-all-dependencies'];
if ($dev) {
    // --dev here selects a development branch, not Composer's require-dev section.
    $command[] = '--no-interaction';
}
$command[] = $package;
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, getcwd());
if (!is_resource($process)) {
    fwrite(STDERR, "[symsvel] Cannot launch Composer.\n");
    exit(1);
}
exit(proc_close($process));
