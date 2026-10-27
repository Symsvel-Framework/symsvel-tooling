<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationDiff;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;

final class ModuleMigrationContractTest extends TestCase
{
    public function testNewSourcesAndImmutableSources(): void
    {
        $source = ['module' => 'users', 'name' => '001.php', 'path' => '/tmp/001.php', 'sha256' => str_repeat('a', 64)];
        $diff = new ModuleMigrationDiff();
        self::assertSame([$source], $diff->compare([$source], [])['pending']);
        self::assertSame([], $diff->compare([$source], ['users/001.php' => ['sha256' => $source['sha256'], 'doctrine' => 'Version1']])['pending']);
        $this->expectException(\DomainException::class);
        $diff->compare([$source], ['users/001.php' => ['sha256' => str_repeat('b', 64), 'doctrine' => 'Version1']]);
    }

    public function testRegistryRoundTrip(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-registry-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $registry = new ModuleMigrationRegistry();
            self::assertSame([], $registry->read($workspace));
            $entries = ['users/001.php' => ['sha256' => str_repeat('a', 64), 'doctrine' => 'DoctrineMigrations\\Version20261026000000']];
            $registry->write($workspace, $entries);
            self::assertSame($entries, $registry->read($workspace));
            $registry->write($workspace, []);
            self::assertSame([], $registry->read($workspace));
        } finally {
            foreach (glob($workspace.'/.symsvel/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($workspace.'/.symsvel')) {
                rmdir($workspace.'/.symsvel');
            }
            rmdir($workspace);
        }
    }
}
