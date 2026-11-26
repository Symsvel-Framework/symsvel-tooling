<?php

declare(strict_types=1);

use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;
use SymSvel\Tooling\Update\RuntimeArtifactSnapshot;
use SymSvel\Tooling\Update\WorkspaceCleanupRegistry;
use SymSvel\Tooling\Update\ComponentNativeFileApplier;
use SymSvel\Tooling\Filesystem\NativeTreeRemoval;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('Native Windows required');
}
function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$root = ($argv[1] ?? throw new RuntimeException('Workspace required')).'/transaction fixture';
mkdir($root.'/backend/src/nested', 0775, true);
mkdir($root.'/backend/vendor', 0775, true);
mkdir($root.'/bo/src', 0775, true);
mkdir($root.'/bo/node_modules/pkg', 0775, true);
$backend = realpath($root.'/backend');
$bo = realpath($root.'/bo');
$transaction = $backend.'/.symsvel/transactions/'.str_repeat('a', 32);
mkdir($transaction, 0775, true);
file_put_contents($backend.'/composer.json', '{}');
file_put_contents($backend.'/src/nested/original.php', 'original');
file_put_contents($backend.'/vendor/untouched', 'vendor');
file_put_contents($bo.'/src/app.js', 'app original');
file_put_contents($bo.'/node_modules/pkg/file.js', 'runtime original');

try {
    $snapshot = new WorkspaceFileSnapshot($backend, $transaction);
    $snapshot->capture();
    check(!file_exists($transaction.'/workspace/backend/composer.json'), 'Composer manifest excluded');
    check(!file_exists($transaction.'/workspace/backend/vendor'), 'Vendor excluded');
    check(!file_exists($transaction.'/workspace/bo/node_modules'), 'Runtime excluded');
    file_put_contents($backend.'/src/nested/original.php', 'changed');
    mkdir($backend.'/src/added/deep', 0775, true);
    file_put_contents($backend.'/src/added/deep/new.php', 'new');
    unlink($bo.'/src/app.js');
    $snapshot->restore();
    check(file_get_contents($backend.'/src/nested/original.php') === 'original', 'Backend source rollback');
    check(!file_exists($backend.'/src/added'), 'Nested new directories removed');
    check(file_get_contents($bo.'/src/app.js') === 'app original', 'Interface source rollback');

    $runtimeDirectory = $transaction.'/bo-runtime';
    mkdir($runtimeDirectory);
    $runtime = new RuntimeArtifactSnapshot($bo, $runtimeDirectory, ['node_modules'], true, true);
    $runtime->capture();
    file_put_contents($bo.'/node_modules/pkg/file.js', 'changed');
    $runtime->restore();
    check(file_get_contents($bo.'/node_modules/pkg/file.js') === 'runtime original', 'Runtime rollback');

    mkdir($root.'/upstream/src/nested', 0775, true);
    file_put_contents($root.'/upstream/src/nested/new.js', 'native');
    (new ComponentNativeFileApplier())->apply($bo, $root.'/upstream', ['add' => ['src/nested/new.js'], 'replace' => [], 'delete' => []], []);
    check(file_get_contents($bo.'/src/nested/new.js') === 'native', 'Native apply with nested path');

    $registry = new WorkspaceCleanupRegistry($backend);
    $registry->enqueue([$transaction]);
    $result = $registry->cleanup();
    check($result['remaining'] === 0 && !file_exists($transaction), 'Deferred cleanup: '.json_encode($result));
    check(file_get_contents($backend.'/vendor/untouched') === 'vendor', 'Excluded files preserved');
} finally {
    NativeTreeRemoval::remove($root);
}
echo "Windows source/runtime rollback, native apply and deferred cleanup: OK\n";
