<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationExecutor;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;

final class ModuleMigrationExecutorTest extends TestCase
{
    public function testFailedSecondMigrationCompensatesFirstAndRetainsRecoveryJournal(): void
    {
        $root = sys_get_temp_dir().'/symsvel-recovery-'.bin2hex(random_bytes(6));
        $backend = $root.'/backend';
        mkdir($backend, 0775, true);
        mkdir($root.'/.symsvel');
        $script = <<<'PHP'
<?php
$version = $argv[2] ?? '';
$direction = $argv[3] ?? '';
file_put_contents(dirname(__DIR__).'/calls.log', $version.' '.$direction."\n", FILE_APPEND);
if ($version === 'DoctrineMigrations\\Version002' && $direction === '--up') {
    fwrite(STDERR, 'Simulated failure');
    exit(1);
}
PHP;
        file_put_contents($backend.'/bin-placeholder', '');
        mkdir($backend.'/bin');
        file_put_contents($backend.'/bin/console', $script);
        $compiled = [];
        foreach (['001', '002'] as $number) {
            $path = $backend.'/Version'.$number.'.php';
            file_put_contents($path, '<?php');
            $compiled[] = [
                'version' => 'DoctrineMigrations\\Version'.$number,
                'path' => $path,
                'entries' => ['test/'.$number.'.php' => ['sha256' => str_repeat('a', 64), 'doctrine' => 'DoctrineMigrations\\Version'.$number]],
            ];
        }
        try {
            (new ModuleMigrationExecutor())->install($backend, $root, $compiled, [], new ModuleMigrationRegistry());
            self::fail('The simulated failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('rollback incomplet', $exception->getMessage());
            $journal = json_decode((string) file_get_contents($root.'/.symsvel/migration-recovery.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('recovering', $journal['state']);
            self::assertSame('DoctrineMigrations\\Version002', $journal['in_flight']);
            self::assertSame(['DoctrineMigrations\\Version001'], $journal['completed']);
            self::assertSame(
                ["DoctrineMigrations\\Version001 --up", "DoctrineMigrations\\Version002 --up", "DoctrineMigrations\\Version001 --down"],
                array_values(array_filter(explode("\n", trim((string) file_get_contents($backend.'/calls.log'))))),
            );
            self::assertFileDoesNotExist($root.'/.symsvel/migrations.json');
            self::assertFileExists($compiled[0]['path']);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Récupération de migration en attente');
            (new ModuleMigrationExecutor())->install($backend, $root, $compiled, [], new ModuleMigrationRegistry());
        } finally {
            foreach (glob($backend.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            unlink($backend.'/bin/console');
            rmdir($backend.'/bin');
            rmdir($backend);
            foreach (glob($root.'/.symsvel/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }
    public function testSymlinkedRecoveryDirectoryPreventsDoctrineExecution(): void
    {
        $root = sys_get_temp_dir().'/symsvel-executor-link-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        mkdir($root.'/outside', 0700);
        if (!symlink($root.'/outside', $root.'/.symsvel')) {
            rmdir($root.'/outside');
            rmdir($root);
            self::markTestSkipped('Symlinks unavailable.');
        }
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('symbolique interdit');
            (new ModuleMigrationExecutor())->install($root, $root, [], [], new ModuleMigrationRegistry());
        } finally {
            unlink($root.'/.symsvel');
            rmdir($root.'/outside');
            rmdir($root);
        }
    }

}
