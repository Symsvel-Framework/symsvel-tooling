<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\WorkspaceUpdater;

final class WorkspaceUpdaterGitRollbackTest extends TestCase
{
    public function testDatabaseUrlUsesSymfonyEnvironmentSpecificConfiguration(): void
    {
        $root = sys_get_temp_dir().'/symsvel-env-resolution-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        try {
            file_put_contents($root.'/backend/.env', "DATABASE_URL=sqlite:///default.sqlite\n");
            file_put_contents($root.'/backend/.env.test', "DATABASE_URL=sqlite:///core.sqlite\n");
            file_put_contents($root.'/backend/.env.test.local', "DATABASE_URL=sqlite:///core-local.sqlite\n");
            $updater = new WorkspaceUpdater(databaseUrl: 'sqlite:///explicit.sqlite');
            $method = new \ReflectionMethod(WorkspaceUpdater::class, 'resolveDatabaseUrl');
            self::assertSame('sqlite:///explicit.sqlite', $method->invoke($updater, $root.'/backend', 'test'));
            if (!class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
                self::markTestSkipped('Symfony Dotenv is not installed.');
            }
            $external = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
            if (is_string($external) && $external !== '') {
                self::markTestSkipped('External DATABASE_URL overrides Symfony Dotenv files.');
            }
            self::assertSame('sqlite:///core-local.sqlite', $method->invoke(new WorkspaceUpdater(), $root.'/backend', 'test'));
            self::assertSame('sqlite:///default.sqlite', $method->invoke(new WorkspaceUpdater(), $root.'/backend', 'dev'));
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testFrontBuildFailureRestoresAllThreeNativeComponents(): void
    {
        $root = sys_get_temp_dir().'/symsvel-native-rollback-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{"name":"test/backend"}');
        file_put_contents($root.'/backend/composer.lock', '{"packages":[]}');
        $database = $root.'/database.sqlite';
        (new Process(['sqlite3', $database, 'CREATE TABLE probe (value TEXT);']))->mustRun();
        $fixture = new NativeWorkspaceFixture($root);
        foreach (['bo', 'app', 'front'] as $component) {
            $fixture->component($component, ['version.txt' => 'before'], ['version.txt' => 'after']);
        }
        try {
            $runner = static function (array $command, string $cwd) use ($root): void {
                if ($command === ['npm', 'run', 'build'] && $cwd === $root.'/front') {
                    throw new \RuntimeException('FRONT build exploded');
                }
            };
            try {
                (new WorkspaceUpdater($runner, true, 'sqlite://'.$database, $fixture->preparation()))->update($root.'/backend', 'test');
                self::fail('Expected FRONT build failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('sources natives et BDD restaurés', $error->getMessage());
            }
            foreach (['bo', 'app', 'front'] as $component) {
                self::assertSame('before', file_get_contents($root.'/'.$component.'/version.txt'));
            }
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testFrontBuildFailureRestoresNativeRuntimeArtifacts(): void
    {
        $root = sys_get_temp_dir().'/symsvel-native-cascade-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{"name":"test/backend"}');
        file_put_contents($root.'/backend/composer.lock', '{"packages":[]}');
        $database = $root.'/database.sqlite';
        (new Process(['sqlite3', $database, 'CREATE TABLE probe (value TEXT);']))->mustRun();
        $fixture = new NativeWorkspaceFixture($root);
        foreach (['bo', 'app', 'front'] as $component) {
            $fixture->component($component, ['version.txt' => 'before'], ['version.txt' => 'after']);
        }
        mkdir($root.'/front/build', 0775, true);
        file_put_contents($root.'/front/build/index.html', 'before');
        try {
            $runner = static function (array $command, string $cwd) use ($root): void {
                if ($command === ['npm', 'run', 'build'] && $cwd === $root.'/front') {
                    file_put_contents($cwd.'/build/index.html', 'after');
                    throw new \RuntimeException('FRONT build exploded');
                }
            };
            try {
                (new WorkspaceUpdater($runner, true, 'sqlite://'.$database, $fixture->preparation()))->update($root.'/backend', 'test');
                self::fail('Expected FRONT failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
            }
            foreach (['bo', 'app', 'front'] as $component) {
                self::assertSame('before', file_get_contents($root.'/'.$component.'/version.txt'));
            }
            self::assertSame('before', file_get_contents($root.'/front/build/index.html'));
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testFrontFailureRestoresBackendVendorSourcesAndAllInterfaceArtifacts(): void
    {
        $root = sys_get_temp_dir().'/symsvel-update-full-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend/vendor/pkg', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{"name":"test/backend"}');
        file_put_contents($root.'/backend/composer.lock', '{"packages":[]}');
        file_put_contents($root.'/backend/vendor/pkg/index.php', 'vendor-before');
        file_put_contents($root.'/backend/source.php', 'source-before');
        $database = $root.'/rollback.sqlite';
        (new Process(['sqlite3', $database, 'CREATE TABLE rollback_probe (value TEXT); INSERT INTO rollback_probe VALUES (\'before\');']))->mustRun();
        $fixture = new NativeWorkspaceFixture($root);
        foreach (['bo', 'app', 'front'] as $component) {
            $dir = $root.'/'.$component;
            mkdir($dir.'/node_modules/pkg', 0775, true);
            mkdir($dir.'/build', 0775, true);
            file_put_contents($dir.'/node_modules/pkg/index.js', 'npm-before');
            file_put_contents($dir.'/build/index.html', 'build-before');
            $fixture->component($component, ['version.txt' => 'git-before'], ['version.txt' => 'git-after']);
        }
        try {
            $runner = function (array $command, string $cwd) use ($root, $database): ?string {
                if ($command[0] === 'composer') {
                    file_put_contents($cwd.'/composer.json', '{"changed":true}');
                    file_put_contents($cwd.'/composer.lock', '{"changed":true}');
                    file_put_contents($cwd.'/vendor/pkg/index.php', 'vendor-after');
                    file_put_contents($cwd.'/source.php', 'source-after');
                    file_put_contents($cwd.'/new-source.php', 'new');
                    (new Process(['sqlite3', $database, "UPDATE rollback_probe SET value='after'; CREATE TABLE unexpected (id INTEGER);"]))->mustRun();
                }
                if ($command === ['npm', 'ci']) {
                    file_put_contents($cwd.'/node_modules/pkg/index.js', 'npm-after');
                }
                if ($command === ['npm', 'run', 'build']) {
                    file_put_contents($cwd.'/build/index.html', 'build-after');
                    file_put_contents($cwd.'/build/extra.html', 'new');
                    if ($cwd === $root.'/front') {
                        throw new \RuntimeException('FRONT build exploded');
                    }
                }
                return null;
            };
            try {
                (new WorkspaceUpdater($runner, true, 'sqlite://'.$database, $fixture->preparation()))->update($root.'/backend', 'test');
                self::fail('Expected FRONT build failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('vendor, sources natives et BDD restaurés', $error->getMessage());
            }
            self::assertSame('{"name":"test/backend"}', file_get_contents($root.'/backend/composer.json'));
            self::assertSame('{"packages":[]}', file_get_contents($root.'/backend/composer.lock'));
            self::assertSame('vendor-before', file_get_contents($root.'/backend/vendor/pkg/index.php'));
            self::assertSame('source-before', file_get_contents($root.'/backend/source.php'));
            $probe = new Process(['sqlite3', $database, 'SELECT value FROM rollback_probe;']);
            $probe->mustRun();
            self::assertSame('before', trim($probe->getOutput()));
            $unexpected = new Process(['sqlite3', $database, "SELECT COUNT(*) FROM sqlite_master WHERE name='unexpected';"]);
            $unexpected->mustRun();
            self::assertSame('0', trim($unexpected->getOutput()));
            self::assertFileDoesNotExist($root.'/backend/new-source.php');
            foreach (['bo', 'app', 'front'] as $component) {
                $dir = $root.'/'.$component;
                self::assertSame('git-before', file_get_contents($dir.'/version.txt'));
                self::assertSame('npm-before', file_get_contents($dir.'/node_modules/pkg/index.js'));
                self::assertSame('build-before', file_get_contents($dir.'/build/index.html'));
                self::assertFileDoesNotExist($dir.'/build/extra.html');
            }
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testPostgresFrontFailureRestoresWholeDatabaseAndWorkspace(): void
    {
        $url = getenv('SYMSVEL_TEST_POSTGRES_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Dedicated PostgreSQL CI database not configured.');
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['path'] ?? '') !== '/symsvel_rollback_ci') {
            self::fail('Destructive test requires dedicated symsvel_rollback_ci database.');
        }
        $env = ['PGPASSWORD' => rawurldecode((string) ($parts['pass'] ?? ''))];
        $sql = static function (string $query) use ($parts, $env): string {
            $process = new Process([
                'psql', '-X', '-v', 'ON_ERROR_STOP=1',
                '--host='.($parts['host'] ?? '127.0.0.1'),
                '--port='.($parts['port'] ?? 5432),
                '--username='.rawurldecode((string) ($parts['user'] ?? '')),
                '--dbname=symsvel_rollback_ci', '-Atc', $query,
            ], null, $env);
            $process->mustRun();
            return trim($process->getOutput());
        };
        $root = sys_get_temp_dir().'/symsvel-pg-global-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend/vendor/pkg', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{"name":"test/backend"}');
        file_put_contents($root.'/backend/composer.lock', '{"packages":[]}');
        file_put_contents($root.'/backend/vendor/pkg/index.php', 'original');
        $fixture = new NativeWorkspaceFixture($root);
        foreach (['bo', 'app', 'front'] as $component) {
            $dir = $root.'/'.$component;
            mkdir($dir.'/build', 0775, true);
            file_put_contents($dir.'/build/index.html', 'original');
            $fixture->component($component, ['version.txt' => 'original'], ['version.txt' => 'changed']);
        }
        try {
            $sql("DROP TABLE IF EXISTS symsvel_global_extra; DROP TABLE IF EXISTS symsvel_global_probe; CREATE TABLE symsvel_global_probe (value TEXT); INSERT INTO symsvel_global_probe VALUES ('original');");
            $runner = function (array $command, string $cwd) use ($root, $sql): ?string {
                if ($command[0] === 'composer') {
                    file_put_contents($cwd.'/composer.lock', '{"changed":true}');
                    file_put_contents($cwd.'/vendor/pkg/index.php', 'changed');
                    $sql("UPDATE symsvel_global_probe SET value='changed'; CREATE TABLE symsvel_global_extra (id INTEGER);");
                }
                if ($command === ['npm', 'run', 'build']) {
                    file_put_contents($cwd.'/build/index.html', 'changed');
                    if ($cwd === $root.'/front') {
                        throw new \RuntimeException('FRONT build exploded');
                    }
                }
                return null;
            };
            try {
                (new WorkspaceUpdater($runner, true, $url, $fixture->preparation()))->update($root.'/backend', 'test');
                self::fail('Expected FRONT failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('BDD restaurés', $error->getMessage());
            }
            self::assertSame('original', $sql('SELECT value FROM symsvel_global_probe'));
            self::assertSame('0', $sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='symsvel_global_extra'"));
            self::assertSame('{"packages":[]}', file_get_contents($root.'/backend/composer.lock'));
            self::assertSame('original', file_get_contents($root.'/backend/vendor/pkg/index.php'));
            foreach (['bo', 'app', 'front'] as $component) {
                self::assertSame('original', file_get_contents($root.'/'.$component.'/version.txt'));
                self::assertSame('original', file_get_contents($root.'/'.$component.'/build/index.html'));
            }
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testDatabaseRecoveryFailureKeepsCheckpointAndRestoresOtherComponents(): void
    {
        $root = sys_get_temp_dir().'/symsvel-recovery-failure-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend/vendor/pkg', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{"name":"test/backend"}');
        file_put_contents($root.'/backend/composer.lock', '{"packages":[]}');
        file_put_contents($root.'/backend/vendor/pkg/index.php', 'original');
        file_put_contents($root.'/backend/source.php', 'original');
        $database = $root.'/database.sqlite';
        (new Process(['sqlite3', $database, "CREATE TABLE probe (value TEXT); INSERT INTO probe VALUES ('original');"]))->mustRun();
        $bo = $root.'/bo';
        mkdir($bo.'/build', 0775, true);
        file_put_contents($bo.'/build/index.html', 'original');
        $fixture = new NativeWorkspaceFixture($root);
        $fixture->component('bo', ['version.txt' => 'original'], ['version.txt' => 'changed']);
        $checkpointPath = null;
        try {
            $runner = function (array $command, string $cwd) use ($root, $database, &$checkpointPath): ?string {
                if ($command[0] === 'composer') {
                    file_put_contents($cwd.'/composer.lock', '{"changed":true}');
                    file_put_contents($cwd.'/vendor/pkg/index.php', 'changed');
                    file_put_contents($cwd.'/source.php', 'changed');
                    (new Process(['sqlite3', $database, "UPDATE probe SET value='changed';"]))->mustRun();
                }
                if ($command === ['npm', 'run', 'build']) {
                    file_put_contents($cwd.'/build/index.html', 'changed');
                    $checkpoints = glob($root.'/backend/.symsvel/transactions/.database-*');
                    self::assertCount(1, $checkpoints);
                    $checkpointPath = $checkpoints[0];
                    file_put_contents($checkpointPath, 'corrupted backup');
                    throw new \RuntimeException('BO build exploded');
                }
                return null;
            };
            try {
                (new WorkspaceUpdater($runner, true, 'sqlite://'.$database, $fixture->preparation()))->update($root.'/backend', 'test');
                self::fail('Expected rollback failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[BO / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('Rollback incomplet', $error->getMessage());
                self::assertStringContainsString('BACK / BDD', $error->getMessage());
                self::assertStringContainsString('checkpoint conservé', $error->getMessage());
                self::assertStringContainsString('Rollback global non garanti', $error->getMessage());
            }
            self::assertNotNull($checkpointPath);
            self::assertFileExists($checkpointPath);
            self::assertSame('corrupted backup', file_get_contents($checkpointPath));
            self::assertSame('{"packages":[]}', file_get_contents($root.'/backend/composer.lock'));
            self::assertSame('original', file_get_contents($root.'/backend/vendor/pkg/index.php'));
            self::assertSame('original', file_get_contents($root.'/backend/source.php'));
            self::assertSame('original', file_get_contents($bo.'/version.txt'));
            self::assertSame('original', file_get_contents($bo.'/build/index.html'));
            $probe = new Process(['sqlite3', $database, 'SELECT value FROM probe']);
            $probe->mustRun();
            self::assertSame('changed', trim($probe->getOutput()), 'Database restoration failed as intended.');
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testMissingComposerLockFailsBeforeCreatingAnyCheckpoint(): void
    {
        $root = sys_get_temp_dir().'/symsvel-lock-preflight-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{"name":"test/backend"}');
        $database = $root.'/database.sqlite';
        $commands = [];
        try {
            $runner = static function (array $command, string $cwd) use (&$commands): ?string {
                $commands[] = $command;
                return null;
            };
            try {
                (new WorkspaceUpdater($runner, true, 'sqlite:///'.$database))->update($root.'/backend', 'test');
                self::fail('Missing original lock must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('composer.lock initial obligatoire', $error->getMessage());
            }
            self::assertSame([], $commands);
            self::assertDirectoryDoesNotExist($root.'/backend/.symsvel/transactions');
            self::assertSame('{"name":"test/backend"}', file_get_contents($root.'/backend/composer.json'));
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testMissingDatabaseUrlFailsBeforeComposerWithoutChangingWorkspace(): void
    {
        $root = sys_get_temp_dir().'/symsvel-db-preflight-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend/vendor/pkg', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{"name":"test/backend"}');
        file_put_contents($root.'/backend/composer.lock', '{"packages":[]}');
        file_put_contents($root.'/backend/vendor/pkg/index.php', 'original');
        $commands = [];
        try {
            $runner = static function (array $command, string $cwd) use (&$commands): ?string {
                $commands[] = $command;
                return null;
            };
            try {
                (new WorkspaceUpdater($runner, true, ''))->update($root.'/backend', 'test');
                self::fail('Missing database URL must fail closed.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('DATABASE_URL explicite obligatoire', $error->getMessage());
                self::assertStringContainsString('avant Composer', $error->getMessage());
            }
            self::assertSame([], $commands, 'No Composer or Git/npm commands should run.');
            self::assertDirectoryDoesNotExist($root.'/backend/.symsvel/transactions', 'Preflight failure must not create checkpoints.');
            self::assertSame('{"name":"test/backend"}', file_get_contents($root.'/backend/composer.json'));
            self::assertSame('{"packages":[]}', file_get_contents($root.'/backend/composer.lock'));
            self::assertSame('original', file_get_contents($root.'/backend/vendor/pkg/index.php'));
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testSuccessfulUpdateReportsRegistryFailureWithoutRollingBack(): void
    {
        $root = sys_get_temp_dir().'/symsvel-cleanup-warning-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend/vendor/pkg', 0775, true);
        mkdir($root.'/backend/.symsvel/cleanup-pending.json', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{"name":"test/backend"}');
        file_put_contents($root.'/backend/composer.lock', '{"packages":[]}');
        file_put_contents($root.'/backend/vendor/pkg/index.php', 'original');
        $database = $root.'/database.sqlite';
        (new Process(['sqlite3', $database, "CREATE TABLE probe (value TEXT); INSERT INTO probe VALUES ('original');"]))->mustRun();
        $messages = [];
        try {
            $runner = static function (array $command, string $cwd) use ($database): ?string {
                if ($command[0] === 'composer') {
                    file_put_contents($cwd.'/composer.lock', '{"updated":true}');
                    file_put_contents($cwd.'/vendor/pkg/index.php', 'updated');
                    (new Process(['sqlite3', $database, "UPDATE probe SET value='updated';"]))->mustRun();
                }
                return null;
            };
            (new WorkspaceUpdater($runner, true, 'sqlite://'.$database))->update(
                $root.'/backend',
                'test',
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                },
            );
            self::assertSame('{"updated":true}', file_get_contents($root.'/backend/composer.lock'));
            self::assertSame('updated', file_get_contents($root.'/backend/vendor/pkg/index.php'));
            $probe = new Process(['sqlite3', $database, 'SELECT value FROM probe']);
            $probe->mustRun();
            self::assertSame('updated', trim($probe->getOutput()));
            $output = implode("\n", $messages);
            self::assertStringContainsString('AVERTISSEMENT : mise à jour réussie, nettoyage non enregistré', $output);
            self::assertStringContainsString('checkpoints conservés', $output);
            self::assertStringContainsString('Tous les composants présents ont été traités', $output);
            self::assertNotEmpty(glob($root.'/backend/.symsvel/transactions/.database-*'));
            self::assertNotEmpty(glob($root.'/backend/.symsvel/transactions/update-*'));
        } finally {
            $this->deleteTree($root);
        }
    }

    /** @param list<string> $args */
    private function git(string $directory, array $args): string
    {
        $process = new Process(['git', ...$args], $directory);
        $process->mustRun();
        return $process->getOutput();
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->deleteTree($entry->getPathname());
        }
        rmdir($path);
    }
}
