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
            file_put_contents($root.'/backend/.env.core', "DATABASE_URL=sqlite:///core.sqlite\n");
            file_put_contents($root.'/backend/.env.core.local', "DATABASE_URL=sqlite:///core-local.sqlite\n");
            $updater = new WorkspaceUpdater(databaseUrl: 'sqlite:///explicit.sqlite');
            $method = new \ReflectionMethod(WorkspaceUpdater::class, 'resolveDatabaseUrl');
            self::assertSame('sqlite:///explicit.sqlite', $method->invoke($updater, $root.'/backend', 'core'));
            if (!class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
                self::markTestSkipped('Symfony Dotenv is not installed.');
            }
            $external = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
            if (is_string($external) && $external !== '') {
                self::markTestSkipped('External DATABASE_URL overrides Symfony Dotenv files.');
            }
            self::assertSame('sqlite:///core-local.sqlite', $method->invoke(new WorkspaceUpdater(), $root.'/backend', 'core'));
            self::assertSame('sqlite:///default.sqlite', $method->invoke(new WorkspaceUpdater(), $root.'/backend', 'dev'));
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testFrontBuildFailureRestoresAllThreeGitComponents(): void
    {
        $root = sys_get_temp_dir().'/symsvel-update-rollback-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        $initial = [];
        foreach (['bo', 'app', 'front'] as $component) {
            $directory = $root.'/'.$component;
            mkdir($directory);
            $this->git($directory, ['init', '-b', 'main']);
            $this->git($directory, ['config', 'user.email', 'test@example.test']);
            $this->git($directory, ['config', 'user.name', 'SymSvel Test']);
            file_put_contents($directory.'/version.txt', 'before');
            $this->git($directory, ['add', 'version.txt']);
            $this->git($directory, ['commit', '-m', 'before']);
            $initial[$component] = trim($this->git($directory, ['rev-parse', 'HEAD']));
        }
        try {
            $updater = new WorkspaceUpdater(function (array $command, string $cwd) use ($root): ?string {
                if ($command === ['git', 'status', '--porcelain', '--untracked-files=all']) {
                    return '';
                }
                if ($command === ['git', 'merge', '--ff-only', 'origin/main']) {
                    file_put_contents($cwd.'/version.txt', 'after');
                    $this->git($cwd, ['commit', '-am', 'after']);
                }
                if ($command === ['npm', 'run', 'build'] && $cwd === $root.'/front') {
                    throw new \RuntimeException('FRONT build exploded');
                }
                return null;
            });
            try {
                $updater->update($root.'/backend', 'core');
                self::fail('Expected FRONT build failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('Git et artefacts npm/build restaurés', $error->getMessage());
                self::assertStringContainsString('rollback Composer et BDD non garanti', $error->getMessage());
            }
            foreach ($initial as $component => $sha) {
                self::assertSame($sha, trim($this->git($root.'/'.$component, ['rev-parse', 'HEAD'])));
                self::assertSame('main', trim($this->git($root.'/'.$component, ['branch', '--show-current'])));
                self::assertSame('before', file_get_contents($root.'/'.$component.'/version.txt'));
            }
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testRollbackContinuesAfterFrontGitRecoveryFailure(): void
    {
        $root = sys_get_temp_dir().'/symsvel-update-cascade-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0775, true);
        file_put_contents($root.'/backend/composer.json', '{}');
        $initial = [];
        foreach (['bo', 'app', 'front'] as $component) {
            $directory = $root.'/'.$component;
            mkdir($directory);
            $this->git($directory, ['init', '-b', 'main']);
            $this->git($directory, ['config', 'user.email', 'test@example.test']);
            $this->git($directory, ['config', 'user.name', 'SymSvel Test']);
            file_put_contents($directory.'/version.txt', 'before');
            $this->git($directory, ['add', 'version.txt']);
            $this->git($directory, ['commit', '-m', 'before']);
            $initial[$component] = trim($this->git($directory, ['rev-parse', 'HEAD']));
        }
        try {
            $updater = new WorkspaceUpdater(function (array $command, string $cwd) use ($root): ?string {
                if ($command === ['git', 'status', '--porcelain', '--untracked-files=all']) {
                    return '';
                }
                if ($command === ['git', 'merge', '--ff-only', 'origin/main']) {
                    file_put_contents($cwd.'/version.txt', 'after');
                    $this->git($cwd, ['commit', '-am', 'after']);
                }
                if ($command === ['npm', 'run', 'build'] && $cwd === $root.'/front') {
                    // Make FRONT's Git checkpoint unrecoverable while leaving BO/APP
                    // intact. Other component recoveries must still run.
                    $this->deleteTree($cwd.'/.git');
                    throw new \RuntimeException('FRONT build exploded');
                }
                return null;
            });
            try {
                $updater->update($root.'/backend', 'core');
                self::fail('Expected FRONT failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('Rollback incomplet', $error->getMessage());
                self::assertStringContainsString('FRONT / Git', $error->getMessage());
                self::assertStringContainsString('Rollback global non garanti', $error->getMessage());
            }
            foreach (['bo', 'app'] as $component) {
                self::assertSame($initial[$component], trim($this->git($root.'/'.$component, ['rev-parse', 'HEAD'])));
                self::assertSame('before', file_get_contents($root.'/'.$component.'/version.txt'));
            }
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
        $heads = [];
        foreach (['bo', 'app', 'front'] as $component) {
            $dir = $root.'/'.$component;
            mkdir($dir.'/node_modules/pkg', 0775, true);
            mkdir($dir.'/build', 0775, true);
            file_put_contents($dir.'/node_modules/pkg/index.js', 'npm-before');
            file_put_contents($dir.'/build/index.html', 'build-before');
            $this->git($dir, ['init', '-b', 'main']);
            $this->git($dir, ['config', 'user.email', 'test@example.test']);
            $this->git($dir, ['config', 'user.name', 'SymSvel Test']);
            file_put_contents($dir.'/version.txt', 'git-before');
            $this->git($dir, ['add', 'version.txt']);
            $this->git($dir, ['commit', '-m', 'before']);
            $heads[$component] = trim($this->git($dir, ['rev-parse', 'HEAD']));
        }
        try {
            $runner = function (array $command, string $cwd) use ($root, $database): ?string {
                if ($command === ['git', 'status', '--porcelain', '--untracked-files=all']) {
                    return '';
                }
                if ($command[0] === 'composer') {
                    file_put_contents($cwd.'/composer.json', '{"changed":true}');
                    file_put_contents($cwd.'/composer.lock', '{"changed":true}');
                    file_put_contents($cwd.'/vendor/pkg/index.php', 'vendor-after');
                    file_put_contents($cwd.'/source.php', 'source-after');
                    file_put_contents($cwd.'/new-source.php', 'new');
                    (new Process(['sqlite3', $database, "UPDATE rollback_probe SET value='after'; CREATE TABLE unexpected (id INTEGER);"]))->mustRun();
                }
                if ($command === ['git', 'merge', '--ff-only', 'origin/main']) {
                    file_put_contents($cwd.'/version.txt', 'git-after');
                    $this->git($cwd, ['commit', '-am', 'after']);
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
                (new WorkspaceUpdater($runner, true, 'sqlite://'.$database))->update($root.'/backend', 'core');
                self::fail('Expected FRONT build failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('vendor, sources et BDD restaurés', $error->getMessage());
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
            foreach ($heads as $component => $head) {
                $dir = $root.'/'.$component;
                self::assertSame($head, trim($this->git($dir, ['rev-parse', 'HEAD'])));
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
        $heads = [];
        foreach (['bo', 'app', 'front'] as $component) {
            $dir = $root.'/'.$component;
            mkdir($dir.'/build', 0775, true);
            file_put_contents($dir.'/build/index.html', 'original');
            $this->git($dir, ['init', '-b', 'main']);
            $this->git($dir, ['config', 'user.email', 'test@example.test']);
            $this->git($dir, ['config', 'user.name', 'SymSvel Test']);
            file_put_contents($dir.'/version.txt', 'original');
            $this->git($dir, ['add', 'version.txt']);
            $this->git($dir, ['commit', '-m', 'original']);
            $heads[$component] = trim($this->git($dir, ['rev-parse', 'HEAD']));
        }
        try {
            $sql("DROP TABLE IF EXISTS symsvel_global_extra; DROP TABLE IF EXISTS symsvel_global_probe; CREATE TABLE symsvel_global_probe (value TEXT); INSERT INTO symsvel_global_probe VALUES ('original');");
            $runner = function (array $command, string $cwd) use ($root, $sql): ?string {
                if ($command === ['git', 'status', '--porcelain', '--untracked-files=all']) {
                    return '';
                }
                if ($command[0] === 'composer') {
                    file_put_contents($cwd.'/composer.lock', '{"changed":true}');
                    file_put_contents($cwd.'/vendor/pkg/index.php', 'changed');
                    $sql("UPDATE symsvel_global_probe SET value='changed'; CREATE TABLE symsvel_global_extra (id INTEGER);");
                }
                if ($command === ['git', 'merge', '--ff-only', 'origin/main']) {
                    file_put_contents($cwd.'/version.txt', 'changed');
                    $this->git($cwd, ['commit', '-am', 'changed']);
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
                (new WorkspaceUpdater($runner, true, $url))->update($root.'/backend', 'core');
                self::fail('Expected FRONT failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('[FRONT / Build Svelte]', $error->getMessage());
                self::assertStringContainsString('BDD restaurés', $error->getMessage());
            }
            self::assertSame('original', $sql('SELECT value FROM symsvel_global_probe'));
            self::assertSame('0', $sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='symsvel_global_extra'"));
            self::assertSame('{"packages":[]}', file_get_contents($root.'/backend/composer.lock'));
            self::assertSame('original', file_get_contents($root.'/backend/vendor/pkg/index.php'));
            foreach ($heads as $component => $head) {
                self::assertSame($head, trim($this->git($root.'/'.$component, ['rev-parse', 'HEAD'])));
                self::assertSame('original', file_get_contents($root.'/'.$component.'/version.txt'));
                self::assertSame('original', file_get_contents($root.'/'.$component.'/build/index.html'));
            }
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
