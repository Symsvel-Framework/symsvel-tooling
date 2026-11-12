<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Composer;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Composer\ComposerTransactionRunner;

final class ComposerTransactionRunnerTest extends TestCase
{
    private string $backend;

    protected function setUp(): void
    {
        $this->backend = sys_get_temp_dir().'/symsvel-runner-'.bin2hex(random_bytes(8));
        mkdir($this->backend, 0775, true);
        $workspace = $this->backend;
        $this->backend = $workspace.'/backend';
        mkdir($this->backend);
        file_put_contents($this->backend.'/composer.json', '{"require":{"existing":"1.0"}}');
        file_put_contents($this->backend.'/composer.lock', '{"packages":[{"name":"existing"}]}');
        mkdir($this->backend.'/vendor/composer', 0775, true);
        file_put_contents($this->backend.'/vendor/composer/installed.json', '{"packages":[]}');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backend.'/.symsvel/transactions/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->backend.'/.symsvel/transactions/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->backend.'/.symsvel/transactions');
        @unlink($this->backend.'/.symsvel/cleanup-pending.json');
        @unlink($this->backend.'/.symsvel/cleanup.lock');
        @unlink($this->backend.'/.symsvel/project.json');
        @rmdir($this->backend.'/.symsvel');
        @unlink($this->backend.'/composer.json');
        @unlink($this->backend.'/composer.lock');
        if (is_dir($this->backend.'/vendor')) {
            $this->deleteDirectory($this->backend.'/vendor');
        }
        @rmdir($this->backend);
        @unlink(dirname($this->backend).'/.symsvel/setup.json');
        @rmdir(dirname($this->backend).'/.symsvel');
        @rmdir(dirname($this->backend));
    }


    public function testCompiledMigrationCleanupAcceptsRegisteredDoctrineVersion(): void
    {
        $directory = $this->backend.'/migrations';
        mkdir($directory);
        $version = 'Version20261112092244';
        $file = $directory.'/'.$version.'.php';
        file_put_contents($file, '<?php // compiled fixture');

        try {
            $method = new \ReflectionMethod(ComposerTransactionRunner::class, 'removeCompiledMigrations');
            $method->invoke(new ComposerTransactionRunner(), $this->backend, ['DoctrineMigrations\\'.$version]);
            self::assertFileDoesNotExist($file);
        } finally {
            @unlink($file);
            rmdir($directory);
        }
    }

    public function testStableChannelNeverRegistersDevelopmentModuleRepository(): void
    {
        \SymSvel\Tooling\Update\ProjectChannel::write($this->backend, 'stable');
        $original = (string) file_get_contents($this->backend.'/composer.json');
        $commands = [];
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command) use (&$commands): void {
            $commands[] = $command;
        });
        $runner->run($this->backend, ['require', 'symsvel-framework/module-demo:^1.0']);
        self::assertSame($original, file_get_contents($this->backend.'/composer.json'));
        self::assertContains(['composer', 'require', 'symsvel-framework/module-demo:^1.0'], $commands);
        self::assertCount(1, $commands);
    }

    public function testDevelopmentChannelLeavesThirdPartyComposerRepositoriesUntouched(): void
    {
        \SymSvel\Tooling\Update\ProjectChannel::write($this->backend, 'dev');
        $original = (string) file_get_contents($this->backend.'/composer.json');
        $commands = [];
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command) use (&$commands): void {
            $commands[] = $command;
        });
        $runner->run($this->backend, ['require', 'acme/example:^2.0']);
        self::assertSame($original, file_get_contents($this->backend.'/composer.json'));
        self::assertSame([['composer', 'require', 'acme/example:^2.0']], $commands);
    }

    public function testDevModulePrefersLocalPathRepository(): void
    {
        \SymSvel\Tooling\Update\ProjectChannel::write($this->backend, 'dev');
        $module = dirname($this->backend).'/modules/demo';
        mkdir($module, 0775, true);
        file_put_contents($module.'/composer.json', '{"name":"symsvel-framework/module-demo"}');
        $commands = [];
        try {
            $runner = new ComposerTransactionRunner(commandRunner: static function (array $command) use (&$commands): void {
                $commands[] = $command;
            });
            $runner->run($this->backend, ['require', 'symsvel-framework/module-demo:dev-main']);
            $expected = [
                'type' => 'path',
                'url' => '../modules/demo',
                'options' => [
                    'symlink' => true,
                    'versions' => ['symsvel-framework/module-demo' => 'dev-main'],
                ],
            ];
            self::assertContains(['composer', 'config', '--json', 'repositories.module-demo-dev', json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)], $commands);
            self::assertContains(['composer', 'require', 'symsvel-framework/module-demo:dev-main'], $commands);
            self::assertSame(2, count($commands));
        } finally {
            unlink($module.'/composer.json');
            rmdir($module);
            rmdir(dirname($module));
        }
    }

    public function testDevModuleRejectsLocalComposerNameMismatch(): void
    {
        \SymSvel\Tooling\Update\ProjectChannel::write($this->backend, 'dev');
        $module = dirname($this->backend).'/modules/demo';
        mkdir($module, 0775, true);
        file_put_contents($module.'/composer.json', '{"name":"acme/other"}');
        try {
            $runner = new ComposerTransactionRunner(commandRunner: static function (): void {
                self::fail('Composer must not run for a mismatched local package.');
            });
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Nom Composer du module local incompatible');
            $runner->run($this->backend, ['require', 'symsvel-framework/module-demo:dev-main']);
        } finally {
            unlink($module.'/composer.json');
            rmdir($module);
            rmdir(dirname($module));
        }
    }

    public function testDevModuleRepositoryAlreadyCompatibleIsNotReconfigured(): void
    {
        \SymSvel\Tooling\Update\ProjectChannel::write($this->backend, 'dev');
        file_put_contents($this->backend.'/composer.json', json_encode([
            'repositories' => ['module-demo-dev' => [
                'type' => 'vcs',
                'url' => 'https://github.com/Symsvel-Framework/symsvel-module-demo.git',
            ]],
        ], JSON_THROW_ON_ERROR));
        $commands = [];
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command) use (&$commands): void {
            $commands[] = $command;
        });
        $runner->run($this->backend, ['require', 'symsvel-framework/module-demo:dev-main']);
        self::assertNotContains(['composer', 'config', 'repositories.module-demo-dev', 'vcs', 'https://github.com/Symsvel-Framework/symsvel-module-demo.git'], $commands);
        self::assertContains(['composer', 'require', 'symsvel-framework/module-demo:dev-main'], $commands);
    }

    public function testDevModuleRepositoryConflictIsRejectedWithoutOverwrite(): void
    {
        \SymSvel\Tooling\Update\ProjectChannel::write($this->backend, 'dev');
        $original = json_encode([
            'repositories' => ['module-demo-dev' => [
                'type' => 'vcs',
                'url' => 'https://example.invalid/custom-module.git',
            ]],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->backend.'/composer.json', $original);
        $commands = [];
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command) use (&$commands): void {
            $commands[] = $command;
        });
        try {
            $runner->run($this->backend, ['require', 'symsvel-framework/module-demo:dev-main']);
            self::fail('Conflicting repository must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Dépôt Composer existant incompatible', $error->getMessage());
        }
        self::assertSame($original, file_get_contents($this->backend.'/composer.json'));
        self::assertNotContains(['composer', 'require', 'symsvel-framework/module-demo:dev-main'], $commands);
    }

    public function testDevModuleRepositoryMutationIsRolledBackOnComposerFailure(): void
    {
        \SymSvel\Tooling\Update\ProjectChannel::write($this->backend, 'dev');
        $original = (string) file_get_contents($this->backend.'/composer.json');
        $commands = [];
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $cwd, ?callable $progress) use (&$commands): void {
            $commands[] = $command;
            if (array_slice($command, 0, 3) === ['composer', 'config', 'repositories.module-demo-dev']) {
                file_put_contents($cwd.'/composer.json', '{"repositories":{"module-demo-dev":{"type":"vcs","url":"https://github.com/Symsvel-Framework/symsvel-module-demo.git"}}}');
                return;
            }
            throw new \RuntimeException('Injected Composer failure');
        });
        try {
            $runner->run($this->backend, ['require', 'symsvel-framework/module-demo:dev-main']);
            self::fail('Expected failure.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
        } finally {
            @unlink($this->backend.'/.symsvel/project.json');
        }
        self::assertSame($original, file_get_contents($this->backend.'/composer.json'));
        self::assertContains(['composer', 'config', 'repositories.module-demo-dev', 'vcs', 'https://github.com/Symsvel-Framework/symsvel-module-demo.git'], $commands);
    }

    public function testBootstrapRejectsUpdateAndRemovalBeforeComposer(): void
    {
        $runner = new ComposerTransactionRunner(commandRunner: static function (): void {
            self::fail('Composer must not run for forbidden bootstrap operations.');
        });
        foreach ([['update'], ['remove', 'symsvel-framework/module-roles']] as $arguments) {
            try {
                $runner->run($this->backend, $arguments, bootstrap: true);
                self::fail('Bootstrap accepted a forbidden operation.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Bootstrap réservé', $error->getMessage());
            }
        }
    }

    public function testBootstrapRejectsMissingNativeManifestBeforeComposer(): void
    {
        (new \SymSvel\Tooling\Installation\SetupState())->initialize(dirname($this->backend));
        $runner = new ComposerTransactionRunner(commandRunner: static function (): void {
            self::fail('Composer must not run without native manifest.');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Manifeste des modules natifs introuvable');
        $runner->run($this->backend, ['require', 'symsvel-framework/module-roles'], bootstrap: true);
    }

    public function testBootstrapRejectsPackageNotDeclaredNative(): void
    {
        (new \SymSvel\Tooling\Installation\SetupState())->initialize(dirname($this->backend));
        mkdir($this->backend.'/config');
        file_put_contents($this->backend.'/config/symsvel-native-modules.json', json_encode([
            'schema' => 1,
            'modules' => ['symsvel-framework/module-roles'],
        ], JSON_THROW_ON_ERROR));
        try {
            $runner = new ComposerTransactionRunner(commandRunner: static function (): void {
                self::fail('Composer must not run for non-native package.');
            });
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Bootstrap interdit pour un module non natif');
            $runner->run($this->backend, ['require', 'symsvel-framework/module-acls'], bootstrap: true);
        } finally {
            unlink($this->backend.'/config/symsvel-native-modules.json');
            rmdir($this->backend.'/config');
        }
    }

    public function testBootstrapAllowsUsersOnlyWhenAppIsInstalled(): void
    {
        \SymSvel\Tooling\Update\ProjectChannel::write($this->backend, 'stable');
        $workspace = dirname($this->backend);
        (new \SymSvel\Tooling\Installation\SetupState())->initialize($workspace);
        mkdir($this->backend.'/config');
        file_put_contents($this->backend.'/config/symsvel-native-modules.json', json_encode([
            'schema' => 1,
            'modules' => ['symsvel-framework/module-admins'],
            'components' => ['app' => ['symsvel-framework/module-users']],
        ], JSON_THROW_ON_ERROR));
        $runner = new ComposerTransactionRunner(commandRunner: static function (): void {
            throw new \RuntimeException('Composer reached');
        });
        try {
            try {
                $runner->run($this->backend, ['require', 'symsvel-framework/module-users'], bootstrap: true);
                self::fail('Users must be rejected when App is absent.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Bootstrap interdit', $error->getMessage());
            }
            mkdir($workspace.'/app');
            try {
                $runner->run($this->backend, ['require', 'symsvel-framework/module-users'], bootstrap: true);
                self::fail('Composer runner should be invoked.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Composer reached', $error->getMessage());
                self::assertStringNotContainsString('Bootstrap interdit', $error->getMessage());
            }
        } finally {
            @rmdir($workspace.'/app');
            unlink($this->backend.'/config/symsvel-native-modules.json');
            rmdir($this->backend.'/config');
        }
    }

    public function testBootstrapRejectsCompletedSetupBeforeComposer(): void
    {
        $state = new \SymSvel\Tooling\Installation\SetupState();
        $workspace = dirname($this->backend);
        $state->initialize($workspace);
        $state->transition($workspace, $state::PENDING, $state::RUNNING);
        file_put_contents($this->backend.'/.env.local', "DATABASE_URL=postgresql://localhost/test\n");
        $state->transition($workspace, $state::RUNNING, $state::DATABASE_READY);
        $state->transition($workspace, $state::DATABASE_READY, $state::COMPLETED);
        $runner = new ComposerTransactionRunner(commandRunner: static function (): void {
            self::fail('Composer must not run after setup completion.');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bootstrap interdit hors setup initial');
        $runner->run($this->backend, ['require', 'symsvel-framework/module-roles'], bootstrap: true);
    }

    public function testBootstrapRejectsConfiguredDatabaseBeforeComposer(): void
    {
        $workspace = dirname($this->backend);
        (new \SymSvel\Tooling\Installation\SetupState())->initialize($workspace);
        file_put_contents($this->backend.'/.env.local', "DATABASE_URL=postgresql://localhost/test\n");
        try {
            $runner = new ComposerTransactionRunner(commandRunner: static function (): void {
                self::fail('Composer must not run with configured database.');
            });
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Bootstrap interdit hors setup initial');
            $runner->run($this->backend, ['require', 'symsvel-framework/module-roles'], bootstrap: true);
        } finally {
            unlink($this->backend.'/.env.local');
        }
    }

    public function testFailedInstallRestoresOriginalManifestsAndRunsSafeInstall(): void
    {
        $commands = [];
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend) use (&$commands): void {
            $commands[] = $command;
            if ($command[1] === 'require') {
                file_put_contents($backend.'/composer.json', '{"require":{"broken":"dev-main"}}');
                file_put_contents($backend.'/composer.lock', '{"packages":[{"name":"broken"}]}');
                throw new \RuntimeException('Module Composer dépendant introuvable');
            }
            self::assertSame(['composer', 'require', 'broken:dev-main'], $command);
            self::assertSame('{"require":{"existing":"1.0"}}', file_get_contents($backend.'/composer.json'));
            self::assertSame('{"packages":[{"name":"existing"}]}', file_get_contents($backend.'/composer.lock'));
        });
        try {
            $runner->run($this->backend, ['require', 'broken:dev-main']);
            self::fail('Expected failed Composer operation.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
        }
        self::assertCount(1, $commands);
        self::assertSame('{"require":{"existing":"1.0"}}', file_get_contents($this->backend.'/composer.json'));
        self::assertSame([], glob($this->backend.'/.symsvel/transactions/*'));
    }

    public function testFailedRecoveryKeepsSnapshotForDiagnosis(): void
    {
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
            if ($command[1] === 'update') {
                file_put_contents($backend.'/composer.lock', '{"packages":[]}');
            }
            throw new \RuntimeException('simulated failure');
        });
        try {
            $runner->run($this->backend, ['update', 'existing']);
            self::fail('Expected failed recovery.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            self::assertStringContainsString('simulated failure', $error->getMessage());
        }
        self::assertSame([], glob($this->backend.'/.symsvel/transactions/*'));
    }

    public function testSuccessfulOperationRemovesSnapshot(): void
    {
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command): void {
            self::assertSame(['composer', 'remove', 'unused'], $command);
        });
        $runner->run($this->backend, ['remove', 'unused']);
        self::assertSame([], glob($this->backend.'/.symsvel/transactions/*'));
    }

    public function testSuccessfulComposerOperationKeepsCheckpointWhenCleanupRegistryCannotBeWritten(): void
    {
        mkdir($this->backend.'/.symsvel', 0700, true);
        mkdir($this->backend.'/.symsvel/cleanup-pending.json');
        $messages = [];
        try {
            $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
                file_put_contents($backend.'/composer.lock', '{"updated":true}');
            });
            $runner->run($this->backend, ['update', 'existing'], static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
            self::assertSame('{"updated":true}', file_get_contents($this->backend.'/composer.lock'));
            self::assertCount(1, glob($this->backend.'/.symsvel/transactions/*'));
            self::assertStringContainsString('opération Composer réussie, nettoyage non enregistré', implode("\n", $messages));
        } finally {
            rmdir($this->backend.'/.symsvel/cleanup-pending.json');
            foreach (glob($this->backend.'/.symsvel/transactions/*') ?: [] as $directory) {
                $this->deleteDirectory($directory);
            }
        }
    }

    public function testFailedComposerRollbackRemainsVerifiedWhenCleanupRegistryFails(): void
    {
        mkdir($this->backend.'/.symsvel', 0700, true);
        mkdir($this->backend.'/.symsvel/cleanup-pending.json');
        $messages = [];
        try {
            $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
                file_put_contents($backend.'/composer.lock', '{"damaged":true}');
                throw new \RuntimeException('simulated Composer error');
            });
            $failed = false;
            try {
                $runner->run($this->backend, ['update', 'existing'], static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                });
            } catch (\RuntimeException $error) {
                $failed = true;
                self::assertStringContainsString('dépendances restaurées', $error->getMessage());
                self::assertStringNotContainsString('récupération incomplète', $error->getMessage());
            }
            self::assertTrue($failed);
            self::assertSame('{"packages":[{"name":"existing"}]}', file_get_contents($this->backend.'/composer.lock'));
            self::assertCount(1, glob($this->backend.'/.symsvel/transactions/*'));
            self::assertStringContainsString('rollback Composer restauré, nettoyage non enregistré', implode("\n", $messages));
        } finally {
            rmdir($this->backend.'/.symsvel/cleanup-pending.json');
            foreach (glob($this->backend.'/.symsvel/transactions/*') ?: [] as $directory) {
                $this->deleteDirectory($directory);
            }
        }
    }

    public function testMultipleRecoveryFailuresStillRestoreIndependentSourceFiles(): void
    {
        file_put_contents($this->backend.'/source.txt', 'original source');
        mkdir($this->backend.'/vendor/existing', 0775, true);
        file_put_contents($this->backend.'/vendor/existing/code.php', 'original vendor');
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
            file_put_contents($backend.'/source.txt', 'modified source');
            file_put_contents($backend.'/vendor/existing/code.php', 'modified vendor');
            file_put_contents($backend.'/composer.lock', '{"damaged":true}');
            $transactions = glob($backend.'/.symsvel/transactions/*');
            self::assertCount(1, $transactions);
            $checkpoint = $transactions[0];
            unlink($checkpoint.'/composer.lock');
            unlink($checkpoint.'/backend/runtime/vendor/existing/code.php');
            rmdir($checkpoint.'/backend/runtime/vendor/existing');
            $this->deleteDirectory($checkpoint.'/backend/runtime/vendor');
            throw new \RuntimeException('injected Composer failure');
        });
        try {
            $runner->run($this->backend, ['update', 'existing']);
            self::fail('Recovery must report both corrupted checkpoints.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('récupération incomplète', $error->getMessage());
            self::assertStringContainsString('BACK / manifests', $error->getMessage());
            self::assertStringContainsString('BACK / vendor', $error->getMessage());
            self::assertSame('original source', file_get_contents($this->backend.'/source.txt'));
            self::assertNotEmpty(glob($this->backend.'/.symsvel/transactions/*'));
        } finally {
            foreach (glob($this->backend.'/.symsvel/transactions/*') ?: [] as $checkpoint) {
                $this->deleteDirectory($checkpoint);
            }
            unlink($this->backend.'/source.txt');
            unlink($this->backend.'/vendor/existing/code.php');
            rmdir($this->backend.'/vendor/existing');
            // The shared fixture owns vendor/composer; tearDown removes it.
        }
    }

    public function testCorruptedComposerLockCheckpointDoesNotPartiallyRestoreJson(): void
    {
        $snapshot = new \SymSvel\Tooling\Composer\ComposerTransactionSnapshot();
        $checkpoint = $snapshot->begin($this->backend);
        try {
            file_put_contents($this->backend.'/composer.json', '{"mutated":true}');
            file_put_contents($this->backend.'/composer.lock', '{"mutated":true}');
            file_put_contents($checkpoint.'/composer.lock', '{"corrupted":true}');
            try {
                $snapshot->restoreManifests($this->backend, $checkpoint);
                self::fail('Corrupted checkpoint must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Sauvegarde Composer altérée : composer.lock', $error->getMessage());
            }
            self::assertSame('{"mutated":true}', file_get_contents($this->backend.'/composer.json'));
            self::assertSame('{"mutated":true}', file_get_contents($this->backend.'/composer.lock'));
        } finally {
            $this->deleteDirectory($checkpoint);
        }
    }

    public function testMissingComposerLockCheckpointDoesNotPartiallyRestoreJson(): void
    {
        $snapshot = new \SymSvel\Tooling\Composer\ComposerTransactionSnapshot();
        $checkpoint = $snapshot->begin($this->backend);
        try {
            file_put_contents($this->backend.'/composer.json', '{"mutated":true}');
            file_put_contents($this->backend.'/composer.lock', '{"mutated":true}');
            unlink($checkpoint.'/composer.lock');
            try {
                $snapshot->restoreManifests($this->backend, $checkpoint);
                self::fail('Missing checkpoint must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Sauvegarde Composer altérée : composer.lock', $error->getMessage());
            }
            self::assertSame('{"mutated":true}', file_get_contents($this->backend.'/composer.json'));
            self::assertSame('{"mutated":true}', file_get_contents($this->backend.'/composer.lock'));
        } finally {
            $this->deleteDirectory($checkpoint);
        }
    }

    public function testComposerManifestPermissionsAreRestoredAndCheckpointModeTamperingIsRejected(): void
    {
        chmod($this->backend.'/composer.json', 0640);
        $snapshot = new \SymSvel\Tooling\Composer\ComposerTransactionSnapshot();
        $checkpoint = $snapshot->begin($this->backend);
        try {
            chmod($this->backend.'/composer.json', 0600);
            file_put_contents($this->backend.'/composer.json', '{"mutated":true}');
            $snapshot->restoreManifests($this->backend, $checkpoint);
            self::assertSame(0640, fileperms($this->backend.'/composer.json') & 0777);
            self::assertSame('{"require":{"existing":"1.0"}}', file_get_contents($this->backend.'/composer.json'));

            chmod($checkpoint.'/composer.lock', 0600);
            file_put_contents($this->backend.'/composer.json', '{"mutated":true}');
            try {
                $snapshot->restoreManifests($this->backend, $checkpoint);
                self::fail('Tampered checkpoint permissions must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Sauvegarde Composer altérée : composer.lock', $error->getMessage());
            }
            self::assertSame('{"mutated":true}', file_get_contents($this->backend.'/composer.json'));
        } finally {
            chmod($checkpoint.'/composer.lock', 0644);
            $this->deleteDirectory($checkpoint);
        }
    }

    public function testMissingComposerMetadataAfterSuccessfulUpdateTriggersFullRollback(): void
    {
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
            self::assertSame(['composer', 'update', 'existing'], $command);
            unlink($backend.'/vendor/composer/installed.json');
            file_put_contents($backend.'/composer.lock', '{"damaged":true}');
        });
        try {
            $runner->run($this->backend, ['update', 'existing']);
            self::fail('Missing installed metadata must abort the transaction.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Métadonnées Composer introuvables', $error->getMessage());
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
        }
        self::assertSame('{"packages":[{"name":"existing"}]}', file_get_contents($this->backend.'/composer.lock'));
        self::assertSame('{"packages":[]}', file_get_contents($this->backend.'/vendor/composer/installed.json'));
        self::assertSame([], glob($this->backend.'/.symsvel/transactions/*'));
    }

    public function testInvalidComposerMetadataAfterSuccessfulUpdateTriggersFullRollback(): void
    {
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
            file_put_contents($backend.'/vendor/composer/installed.json', '{"packages":{"invalid":{"name":"test"}}}');
            file_put_contents($backend.'/composer.json', '{"damaged":true}');
        });
        try {
            $runner->run($this->backend, ['update', 'existing']);
            self::fail('Invalid installed metadata must abort the transaction.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Format installed.json invalide', $error->getMessage());
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
        }
        self::assertSame('{"require":{"existing":"1.0"}}', file_get_contents($this->backend.'/composer.json'));
        self::assertSame('{"packages":[]}', file_get_contents($this->backend.'/vendor/composer/installed.json'));
        self::assertSame([], glob($this->backend.'/.symsvel/transactions/*'));
    }

    public function testPostgresPartialSqlFailureRestoresDatabaseThroughComposerTransaction(): void
    {
        $url = getenv('SYMSVEL_TEST_POSTGRES_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Dedicated PostgreSQL integration database not configured.');
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['path'] ?? '') !== '/symsvel_rollback_ci') {
            self::fail('Destructive integration test requires symsvel_rollback_ci.');
        }
        $env = isset($parts['pass']) ? ['PGPASSWORD' => rawurldecode($parts['pass'])] : [];
        $sql = static function (string $query) use ($parts, $env): string {
            $process = new \Symfony\Component\Process\Process([
                'psql', '-X', '-v', 'ON_ERROR_STOP=1',
                '--host='.($parts['host'] ?? '127.0.0.1'),
                '--port='.($parts['port'] ?? 5432),
                '--username='.rawurldecode((string) ($parts['user'] ?? '')),
                '--dbname=symsvel_rollback_ci', '-Atc', $query,
            ], null, $env);
            $process->mustRun();
            return trim($process->getOutput());
        };
        file_put_contents($this->backend.'/.env.local', 'DATABASE_URL="'.$url.'"'."\n");
        try {
            $sql("DROP TABLE IF EXISTS symsvel_partial_created; DROP TABLE IF EXISTS symsvel_partial_original; CREATE TABLE symsvel_partial_original (id INTEGER PRIMARY KEY, value TEXT); INSERT INTO symsvel_partial_original VALUES (1, 'before');");
            $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend) use ($sql): void {
                self::assertSame(['composer', 'update', 'existing'], $command);
                file_put_contents($backend.'/composer.lock', '{"mutated":true}');
                $sql("UPDATE symsvel_partial_original SET value='after'; CREATE TABLE symsvel_partial_created (id INTEGER); INSERT INTO symsvel_partial_created VALUES (7);");
                $sql('INSERT INTO symsvel_partial_created (nonexistent_column) VALUES (8);');
            });
            try {
                $runner->run($this->backend, ['update', 'existing']);
                self::fail('Partial SQL failure must abort Composer transaction.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            }
            self::assertSame('before', $sql('SELECT value FROM symsvel_partial_original WHERE id=1'));
            self::assertSame('0', $sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='symsvel_partial_created'"));
            self::assertSame('{"packages":[{"name":"existing"}]}', file_get_contents($this->backend.'/composer.lock'));
        } finally {
            @unlink($this->backend.'/.env.local');
            $sql('DROP TABLE IF EXISTS symsvel_partial_created; DROP TABLE IF EXISTS symsvel_partial_original;');
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->deleteDirectory($entry->getPathname());
        }
        rmdir($path);
    }

    public function testResidualRecipeSymlinkMarksRecoveryIncomplete(): void
    {
        mkdir($this->backend.'/config');
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
            if ($command[1] === 'require') {
                symlink('../composer.json', $backend.'/config/recipe-link');
                throw new \RuntimeException('simulated Composer failure');
            }
        });
        try {
            $runner->run($this->backend, ['require', 'broken:dev-main']);
            self::fail('Composer failure must be reported.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            self::assertFileDoesNotExist($this->backend.'/config/recipe-link');
        } finally {
            @unlink($this->backend.'/config/recipe-link');
            rmdir($this->backend.'/config');
        }
    }

    public function testResidualFlexRecipeFileMarksRecoveryIncomplete(): void
    {
        mkdir($this->backend.'/config');
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
            if ($command[1] === 'require') {
                file_put_contents($backend.'/config/flex-recipe.yaml', 'created by recipe');
                throw new \RuntimeException('simulated Composer failure');
            }
        });
        try {
            $runner->run($this->backend, ['require', 'broken:dev-main']);
            self::fail('Composer failure must be reported.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            self::assertFileDoesNotExist($this->backend.'/config/flex-recipe.yaml');
        } finally {
            @unlink($this->backend.'/config/flex-recipe.yaml');
            rmdir($this->backend.'/config');
        }
        self::assertSame([], glob($this->backend.'/.symsvel/transactions/*'));
    }

    public function testResidualBoFileMarksWorkspaceRecoveryIncomplete(): void
    {
        $workspace = $this->backend.'/workspace';
        $backend = $workspace.'/backend';
        $bo = $workspace.'/bo';
        mkdir($backend, 0775, true);
        mkdir($bo, 0775, true);
        file_put_contents($backend.'/composer.json', '{"require":{}}');
        file_put_contents($backend.'/composer.lock', '{"packages":[]}');
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command) use ($bo): void {
            if ($command[1] === 'require') {
                file_put_contents($bo.'/generated.js', 'unexpected side effect');
                throw new \RuntimeException('simulated BO failure');
            }
        });
        try {
            $runner->run($backend, ['require', 'broken:dev-main']);
            self::fail('Composer failure must be reported.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            self::assertFileDoesNotExist($bo.'/generated.js');
        } finally {
            foreach (glob($backend.'/.symsvel/transactions/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($backend.'/.symsvel/transactions/*') ?: [] as $dir) {
                @rmdir($dir);
            }
            @rmdir($backend.'/.symsvel/transactions');
            @unlink($backend.'/.symsvel/cleanup-pending.json');
            @unlink($backend.'/.symsvel/cleanup.lock');
            @rmdir($backend.'/.symsvel');
            @unlink($bo.'/generated.js');
            unlink($backend.'/composer.json');
            unlink($backend.'/composer.lock');
            rmdir($backend);
            rmdir($bo);
            rmdir($workspace);
        }
    }

    public function testFailedOperationRestoresModifiedAndDeletedWorkspaceFiles(): void
    {
        $workspace = $this->backend.'/restore-workspace';
        $backend = $workspace.'/backend';
        $bo = $workspace.'/bo';
        mkdir($backend, 0775, true);
        mkdir($bo, 0775, true);
        file_put_contents($backend.'/composer.json', '{"require":{}}');
        file_put_contents($backend.'/composer.lock', '{"packages":[]}');
        file_put_contents($bo.'/existing.js', 'original');
        file_put_contents($bo.'/deleted.js', 'keep me');
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command) use ($bo): void {
            if ($command[1] === 'update') {
                file_put_contents($bo.'/existing.js', 'corrupted');
                unlink($bo.'/deleted.js');
                throw new \RuntimeException('simulated failed update');
            }
        });
        try {
            $runner->run($backend, ['update', 'existing']);
            self::fail('Failed update must throw.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            self::assertSame('original', file_get_contents($bo.'/existing.js'));
            self::assertSame('keep me', file_get_contents($bo.'/deleted.js'));
        } finally {
            foreach (glob($backend.'/.symsvel/transactions/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($backend.'/.symsvel/transactions/*') ?: [] as $dir) {
                @rmdir($dir);
            }
            @rmdir($backend.'/.symsvel/transactions');
            @rmdir($backend.'/.symsvel');
            @unlink($bo.'/existing.js');
            @unlink($bo.'/deleted.js');
            @unlink($backend.'/composer.json');
            @unlink($backend.'/composer.lock');
            @rmdir($backend);
            @rmdir($bo);
            @rmdir($workspace);
        }
    }

    public function testFailedComposerMutationRestoresVendorBytesAndRemovesNewPackage(): void
    {
        mkdir($this->backend.'/vendor/existing', 0775, true);
        file_put_contents($this->backend.'/vendor/existing/code.php', 'original');
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend): void {
            self::assertNotContains('--no-scripts', $command);
            self::assertNotContains('--no-plugins', $command);
            file_put_contents($backend.'/composer.lock', '{"packages":[]}');
            file_put_contents($backend.'/vendor/existing/code.php', 'corrupted');
            mkdir($backend.'/vendor/new-package');
            file_put_contents($backend.'/vendor/new-package/code.php', 'new');
            throw new \RuntimeException('failure after dependency mutation');
        });
        try {
            $runner->run($this->backend, ['update', 'existing']);
            self::fail('Expected injected failure.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dépendances restaurées', $error->getMessage());
        } finally {
            self::assertSame('original', file_get_contents($this->backend.'/vendor/existing/code.php'));
            self::assertDirectoryDoesNotExist($this->backend.'/vendor/new-package');
            self::assertSame('{"packages":[{"name":"existing"}]}', file_get_contents($this->backend.'/composer.lock'));
            self::assertSame([], glob($this->backend.'/.symsvel/transactions/*'));
            unlink($this->backend.'/vendor/existing/code.php');
            rmdir($this->backend.'/vendor/existing');
            // vendor/composer belongs to the shared fixture and is removed in tearDown().
        }
    }

    public function testFailedComposerRollbackReplacesSymlinkedManifestWithoutTouchingOutside(): void
    {
        $outside = sys_get_temp_dir().'/symsvel-composer-outside-'.bin2hex(random_bytes(8));
        file_put_contents($outside, 'outside remains untouched');
        $runner = new ComposerTransactionRunner(commandRunner: static function (array $command, string $backend) use ($outside): void {
            unlink($backend.'/composer.json');
            symlink($outside, $backend.'/composer.json');
            throw new \RuntimeException('simulated recipe failure');
        });
        try {
            try {
                $runner->run($this->backend, ['update', 'existing']);
                self::fail('Composer failure must trigger rollback.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('dépendances restaurées', $error->getMessage());
            }
            self::assertFalse(is_link($this->backend.'/composer.json'));
            self::assertSame('{"require":{"existing":"1.0"}}', file_get_contents($this->backend.'/composer.json'));
            self::assertSame('outside remains untouched', file_get_contents($outside));
        } finally {
            @unlink($outside);
        }
    }

    public function testRejectsMissingOriginalLockWithoutExecutingComposer(): void
    {
        unlink($this->backend.'/composer.lock');
        $runner = new ComposerTransactionRunner(commandRunner: static function (): void {
            self::fail('Composer must not run without an original lock.');
        });
        try {
            $runner->run($this->backend, ['require', 'broken:dev-main']);
            self::fail('Missing lock must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('composer.lock initial obligatoire', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->backend.'/.symsvel/transactions');
    }

    public function testRejectsScriptBypassBeforeSnapshot(): void
    {
        $runner = new ComposerTransactionRunner();
        $this->expectException(\InvalidArgumentException::class);
        $runner->run($this->backend, ['require', 'example', '--no-scripts']);
    }
}
