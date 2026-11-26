<?php

declare(strict_types=1);

use SymSvel\Tooling\Installation\ComponentInstaller;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\ProjectChannel;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('Native Windows required');
}
$root = ($argv[1] ?? throw new RuntimeException('Workspace required')).'/component fixture';
$origin = $root.'/origin';
$workspace = $root.'/project';
mkdir($origin.'/src/lib', 0775, true);
mkdir($workspace.'/backend', 0775, true);
file_put_contents($origin.'/src/lib/index.js', 'export const test = true;');
file_put_contents($origin.'/package.json', json_encode([
    'name' => 'symsvel-native-fixture',
    'version' => '1.0.0',
    'private' => true,
    'scripts' => ['build' => 'node build.js'],
], JSON_THROW_ON_ERROR));
file_put_contents($origin.'/package-lock.json', json_encode([
    'name' => 'symsvel-native-fixture',
    'version' => '1.0.0',
    'lockfileVersion' => 3,
    'requires' => true,
    'packages' => ['' => ['name' => 'symsvel-native-fixture', 'version' => '1.0.0']],
], JSON_THROW_ON_ERROR));
file_put_contents($origin.'/build.js', "require('fs').mkdirSync('build', {recursive:true}); require('fs').writeFileSync('build/index.html','OK');");
$git = static function (array $args) use ($origin): string {
    return (new Process(['git', ...$args], $origin))->mustRun()->getOutput();
};
$git(['init', '-b', 'dev']);
$git(['config', 'user.email', 'fixture@example.invalid']);
$git(['config', 'user.name', 'Fixture']);
$git(['add', '.']);
$git(['commit', '-m', 'fixture']);
$head = trim($git(['rev-parse', 'HEAD']));
(new ComponentInstaller(['bo' => $origin]))->install($workspace, ['bo'], null, ProjectChannel::DEV);
$manifest = (new ComponentManifest($workspace.'/backend'))->get('bo');
if (($manifest['revision'] ?? null) !== $head
    || !isset($manifest['files']['src/lib/index.js'])
    || file_exists($workspace.'/bo/.git')
    || file_get_contents($workspace.'/bo/build/index.html') !== 'OK') {
    throw new RuntimeException('Native Git/npm component install or provenance failed.');
}
// Failed clone must not remove pre-existing BO or its build.
try {
    (new ComponentInstaller(['app' => $root.'/missing']))->install($workspace, ['app'], null, ProjectChannel::DEV);
    throw new LogicException('Invalid origin unexpectedly accepted');
} catch (RuntimeException $e) {
    if (file_exists($workspace.'/app') || file_get_contents($workspace.'/bo/build/index.html') !== 'OK') {
        throw new RuntimeException('Failed clone damaged existing component', 0, $e);
    }
}
echo "Windows real Git clone, npm ci/build, native provenance and failed clone: OK\n";
