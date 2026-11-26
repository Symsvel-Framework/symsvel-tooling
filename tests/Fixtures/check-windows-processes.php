<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use SymSvel\Tooling\Process\NativeCommand;
use SymSvel\Tooling\Update\ComponentNativeSource;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('Native Windows required');
}
$root = ($argv[1] ?? throw new RuntimeException('Workspace required')).'/native processes';
mkdir($root, 0775, true);
$run = static function (array $command) use ($root): string {
    $process = new Process(NativeCommand::resolve($command), $root);
    $process->setTimeout(60);
    $process->mustRun();
    return $process->getOutput();
};
if (!str_contains($run(['composer', '--version']), 'Composer version')) {
    throw new RuntimeException('Native Composer invocation failed.');
}
$run(['npm', '--version']);
file_put_contents($root.'/package.json', json_encode([
    'name' => 'symsvel-windows-fixture',
    'version' => '1.0.0',
    'private' => true,
    'scripts' => ['build' => 'node build.js'],
], JSON_THROW_ON_ERROR));
file_put_contents($root.'/build.js', "require('fs').writeFileSync('built.txt', 'OK');");
$run(['npm', 'run', 'build']);
if (file_get_contents($root.'/built.txt') !== 'OK') {
    throw new RuntimeException('Native npm script failed.');
}
mkdir($root.'/nested');
file_put_contents($root.'/nested/source.txt', 'native');
$files = (new ComponentNativeSource())->files($root);
if (!isset($files['nested/source.txt'])) {
    throw new RuntimeException('Component manifest must use slash-separated keys.');
}
echo "Native Windows Composer, npm and component path keys: OK\n";
