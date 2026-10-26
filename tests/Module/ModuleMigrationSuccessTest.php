<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationExecutor;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;

final class ModuleMigrationSuccessTest extends TestCase
{
    public function testReceiptsAreCommittedOnlyAfterEveryMigrationSucceeds(): void
    {
        $root = sys_get_temp_dir().'/symsvel-success-'.bin2hex(random_bytes(6));
        $backend = $root.'/backend';
        mkdir($backend.'/bin', 0775, true);
        file_put_contents($backend.'/bin/console', "<?php\nfile_put_contents(dirname(__DIR__).'/calls.log', implode(' ', array_slice(\$argv, 2)).\"\\n\", FILE_APPEND);\n");
        $compiled = [];
        foreach (['001', '002'] as $number) {
            $path = $backend.'/Version'.$number.'.php';
            file_put_contents($path, '<?php');
            $version = 'DoctrineMigrations\\Version'.$number;
            $compiled[] = [
                'version' => $version,
                'path' => $path,
                'entries' => ['test/'.$number.'.php' => ['sha256' => str_repeat('a', 64), 'doctrine' => $version]],
            ];
        }
        try {
            $registry = new ModuleMigrationRegistry();
            (new ModuleMigrationExecutor())->install($backend, $root, $compiled, [], $registry);
            self::assertCount(2, $registry->read($root));
            self::assertFileDoesNotExist($root.'/.symsvel/migration-recovery.json');
            self::assertSame(
                ["DoctrineMigrations\\Version001 --up --no-interaction", "DoctrineMigrations\\Version002 --up --no-interaction"],
                explode("\n", trim((string) file_get_contents($backend.'/calls.log'))),
            );
        } finally {
            foreach (glob($backend.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            unlink($backend.'/bin/console');
            rmdir($backend.'/bin');
            rmdir($backend);
            foreach (glob($root.'/.symsvel/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }
}
