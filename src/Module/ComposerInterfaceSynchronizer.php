<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

final class ComposerInterfaceSynchronizer
{
    public function run(string $backend): int
    {
        // Resolve the configured environment even when Composer was started without APP_ENV.
        if (is_file($backend.'/.env')) {
            (new Dotenv())->loadEnv($backend.'/.env');
        }
        $environment = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev');
        if ($environment === 'prod') {
            echo "SymSvel: interface synchronization disabled in production.\n";
            return 0;
        }

        // A fresh production clone has no .env.local yet and only static document
        // roots. Local bootstrap creates the component sources before module install.
        $hasSources = false;
        foreach (['bo', 'app', 'front'] as $scope) {
            if (is_file(dirname($backend).'/'.$scope.'/package.json')) {
                $hasSources = true;
                break;
            }
        }
        if (!$hasSources) {
            echo "SymSvel: no interface sources; skipping module synchronization.\n";
            return 0;
        }

        $detector = new SvelteBuildScopeDetector();
        $before = $detector->readInventory($backend);
        $sync = new Process([PHP_BINARY, 'bin/console', 'symsvel:module:sync', '--source=composer', '--env='.$environment], $backend);
        $sync->setTimeout(900);
        $sync->run(static function (string $type, string $output): void {
            echo $output;
        });
        if (!$sync->isSuccessful()) {
            fwrite(STDERR, "SymSvel module synchronization failed.\n");
            return 1;
        }

        // Bootstrap Composer transactions still synchronize module metadata, but defer
        // expensive Svelte builds until all native modules are installed.
        if (getenv('SYMSVEL_DEFER_BUILDS') === '1') {
            echo "SymSvel: Svelte builds deferred until bootstrap completes.\n";
            return 0;
        }

        $changedScopes = $detector->changedScopes(dirname($backend), $before, $detector->readInventory($backend));
        foreach (['bo', 'app', 'front'] as $scope) {
            $directory = dirname($backend).'/'.$scope;
            if (!is_dir($directory)) {
                continue;
            }
            if (!is_file($directory.'/package.json')) {
                // Hosting providers can pre-create BO/App/Front document roots before
                // any interface has been deployed. Their presence is not proof that a
                // Svelte component exists. The dedicated local finalizer still rejects
                // an incomplete component when building an actual local installation.
                echo 'SymSvel: '.strtoupper($scope)." has no Svelte sources yet; skipping build.\n";
                continue;
            }
            if (!in_array($scope, $changedScopes, true)) {
                continue;
            }
            echo 'SymSvel: building '.strtoupper($scope)."\n";
            $build = new Process(['npm', 'run', 'build'], $directory);
            $build->setTimeout(900);
            $build->run(static function (string $type, string $output): void {
                echo $output;
            });
            if (!$build->isSuccessful()) {
                fwrite(STDERR, 'SymSvel: '.strtoupper($scope)." build failed.\n");
                return 1;
            }
        }
        return 0;
    }
}
