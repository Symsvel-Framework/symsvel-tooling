<?php

declare(strict_types=1);

use SymSvel\Tooling\Composer\WorkspaceFileSnapshot;
use SymSvel\Tooling\Composer\ComposerInterfaceHookPublisher;
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
    mkdir($backend.'/scripts');
    $hook = $backend.'/scripts/symsvel-module-sync.php';
    $canonical = file_get_contents(dirname(__DIR__, 2).'/assets/symsvel-module-sync.php');
    $crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $canonical));
    file_put_contents($hook, $crlf);
    (new ComposerInterfaceHookPublisher())->publish($backend);
    check(file_get_contents($hook) === $crlf, 'CRLF native hook accepted without rewriting');
    file_put_contents($hook, $crlf."// local edit\r\n");
    try {
        (new ComposerInterfaceHookPublisher())->publish($backend);
        throw new LogicException('Local hook edit accepted');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'modifié localement'), 'Local hook edit refused');
    }
    file_put_contents($hook, $crlf);
    $launcher = $backend.'/scripts/launcher.php';
    file_put_contents($launcher, '<?php return true;');
    touch($launcher, 1700000000);
    $launcherTime = filemtime($launcher);
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
    clearstatcache(true, $launcher);
    check(filemtime($launcher) === $launcherTime, 'Unchanged launcher must not be replaced');

    // The real Composer rollback uses a copied vendor checkpoint.
    mkdir($backend.'/vendor/tooling/bin', 0775, true);
    $vendorLauncher = $backend.'/vendor/tooling/bin/transaction.php';
    file_put_contents($vendorLauncher, '<?php return true;');
    touch($vendorLauncher, 1700000000);
    $vendorTime = filemtime($vendorLauncher);
    require $vendorLauncher;
    file_put_contents($backend.'/vendor/changed.php', 'before');
    file_put_contents($backend.'/vendor/deleted.php', 'restore me');
    mkdir($transaction.'/backend-runtime');
    $vendor = new RuntimeArtifactSnapshot($backend, $transaction.'/backend-runtime', ['vendor']);
    $vendor->capture();
    file_put_contents($backend.'/vendor/changed.php', 'after');
    unlink($backend.'/vendor/deleted.php');
    mkdir($backend.'/vendor/new-package');
    file_put_contents($backend.'/vendor/new-package/new.php', 'new');
    $vendor->restore();
    clearstatcache(true, $vendorLauncher);
    check(filemtime($vendorLauncher) === $vendorTime, 'Unchanged vendor launcher must not be replaced');
    check(file_get_contents($backend.'/vendor/changed.php') === 'before', 'Changed vendor restored');
    check(file_get_contents($backend.'/vendor/deleted.php') === 'restore me', 'Deleted vendor restored');
    check(!file_exists($backend.'/vendor/new-package'), 'New vendor package removed');

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
