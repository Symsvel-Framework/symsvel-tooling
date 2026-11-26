<?php

declare(strict_types=1);

use SymSvel\Tooling\Installation\DevDocumentationBuilder;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = $argv[1] ?? throw new RuntimeException('Workspace required');
if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('Native Windows required');
}
$stamp = $root.'/dev-doc/.venv/.symsvel-requirements.sha256';
$python = $root.'/dev-doc/.venv/Scripts/python.exe';
$mkdocs = $root.'/dev-doc/.venv/Scripts/mkdocs.exe';
foreach ([$stamp, $python, $mkdocs] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Missing Windows venv artifact: '.$path);
    }
}
$before = filemtime($stamp);
sleep(2);
(new DevDocumentationBuilder())->build($root);
clearstatcache(true, $stamp);
if ($before !== filemtime($stamp) || !is_file($root.'/dev-doc/site/index.html')) {
    throw new RuntimeException('Repeated Windows build must reuse dependencies and produce HTML.');
}
echo "Native Windows documentation build and dependency cache: OK\n";
