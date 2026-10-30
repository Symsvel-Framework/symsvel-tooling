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
        mkdir($this->backend);
        file_put_contents($this->backend.'/composer.json', '{"require":{"existing":"1.0"}}');
        file_put_contents($this->backend.'/composer.lock', '{"packages":[{"name":"existing"}]}');
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
        @rmdir($this->backend.'/.symsvel');
        @unlink($this->backend.'/composer.json');
        @unlink($this->backend.'/composer.lock');
        @rmdir($this->backend);
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
            self::assertSame(['composer', 'require', 'broken:dev-main', '--no-scripts', '--no-plugins'], $command);
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
            self::assertSame(['composer', 'remove', 'unused', '--no-scripts', '--no-plugins'], $command);
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
            rmdir($checkpoint.'/backend/runtime/vendor');
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
            rmdir($this->backend.'/vendor');
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
            self::assertContains('--no-scripts', $command);
            self::assertContains('--no-plugins', $command);
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
            rmdir($this->backend.'/vendor');
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
