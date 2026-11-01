<?php

declare(strict_types=1);

// Transaction boundary for Composer + Flex + SymSvel synchronization.
// Restore the complete backend working tree (except dependencies and generated caches)
// if any installation step fails.
$operation = $argv[1] ?? '';
$args = array_slice($argv, 2);
if ($operation !== 'require') {
    fwrite(STDERR, "[symsvel] Transaction '$operation' not implemented yet. No changes made.\n");
    exit(2);
}
if (!$args || str_starts_with($args[0], '-')) {
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
$root = getcwd();
$excluded = ['vendor', 'node_modules', '.git', 'var', '.svelte-kit', 'build', 'dist', 'coverage'];
$backup = sys_get_temp_dir().'/symsvel-transaction-'.bin2hex(random_bytes(10));
if (!mkdir($backup, 0700, true)) {
    fwrite(STDERR, "[symsvel] Cannot create transaction snapshot.\n");
    exit(1);
}
function symsvelFiles(string $root, array $excluded): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file) use ($excluded): bool {
                return !($file->isDir() && in_array($file->getFilename(), $excluded, true));
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile() && !$file->isLink()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $files[$relative] = $file->getPathname();
    }
    return $files;
}
function symsvelRun(array $command, string $cwd): int
{
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    return is_resource($process) ? proc_close($process) : 127;
}
function symsvelRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($dir);
}
register_shutdown_function(static function () use ($backup): void { symsvelRemoveTree($backup); });
try {
    $before = symsvelFiles($root, $excluded);
    foreach ($before as $relative => $source) {
        $target = $backup.'/'.$relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Cannot snapshot '.$relative);
        }
        if (is_link($source)) {
            if (!symlink(readlink($source), $target)) {
                throw new RuntimeException('Cannot snapshot symlink '.$relative);
            }
        } elseif (!copy($source, $target)) {
            throw new RuntimeException('Cannot snapshot '.$relative);
        }
    }
    $command = ['composer', 'require', '--with-all-dependencies'];
    if ($dev) {
        $command[] = '--no-interaction';
    }
    $command[] = $package;
    $status = symsvelRun($command, $root);
    if ($status === 0) {
        fwrite(STDOUT, "[symsvel] Transaction committed.\n");
        exit(0);
    }
    fwrite(STDERR, "[symsvel] Composer/SymSvel failed (code $status). Restoring snapshot...\n");
    // First restore the lockfile so Composer can reconcile vendor against the old graph.
    foreach (['composer.json', 'composer.lock'] as $file) {
        if (isset($before[$file])) {
            copy($backup.'/'.$file, $root.'/'.$file);
        }
    }
    $vendorStatus = symsvelRun(['composer', 'install', '--no-scripts', '--no-plugins', '--no-interaction'], $root);
    // Restore the whole tracked snapshot, including Flex recipes and SymSvel manifests.
    $after = symsvelFiles($root, $excluded);
    foreach ($after as $relative => $file) {
        if (!isset($before[$relative])) {
            unlink($file);
        }
    }
    foreach ($before as $relative => $_source) {
        $destination = $root.'/'.$relative;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0775, true);
        }
        if (is_file($destination) || is_link($destination)) {
            unlink($destination);
        }
        $saved = $backup.'/'.$relative;
        if (is_link($saved)) {
            symlink(readlink($saved), $destination);
        } else {
            copy($saved, $destination);
        }
    }
    fwrite(STDERR, $vendorStatus === 0
        ? "[symsvel] Snapshot restored; vendor reconciled.\n"
        : "[symsvel] Snapshot restored, but vendor reconciliation FAILED. Run composer install --no-scripts --no-plugins.\n");
    exit($vendorStatus === 0 ? $status : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "[symsvel] Transaction error: ".$e->getMessage()." (manual inspection required).\n");
    exit(1);
}
