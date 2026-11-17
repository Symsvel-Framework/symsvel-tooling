<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationDiff;

final class ModuleMigrationVersionCollisionTest extends TestCase
{
    public function testRejectsIdenticalDoctrineVersionsFromTwoModulesBeforeCompilation(): void
    {
        $sources = [
            ['module' => 'admins', 'name' => 'Version20261117000001.php', 'path' => '/admins.php', 'sha256' => str_repeat('a', 64)],
            ['module' => 'users', 'name' => 'Version20261117000001.php', 'path' => '/users.php', 'sha256' => str_repeat('b', 64)],
        ];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Version20261117000001.php');
        $this->expectExceptionMessage('admins/Version20261117000001.php');
        $this->expectExceptionMessage('users/Version20261117000001.php');
        (new ModuleMigrationDiff())->compare($sources, []);
    }

    public function testAcceptsDistinctVersionsFromDifferentModules(): void
    {
        $sources = [
            ['module' => 'admins', 'name' => 'Version20261117000001.php', 'path' => '/admins.php', 'sha256' => str_repeat('a', 64)],
            ['module' => 'users', 'name' => 'Version20261117000002.php', 'path' => '/users.php', 'sha256' => str_repeat('b', 64)],
        ];

        self::assertSame($sources, (new ModuleMigrationDiff())->compare($sources, [])['pending']);
    }

    public function testReservedVersionCatalogContainsNoDuplicates(): void
    {
        $path = dirname(__DIR__, 2).'/docs/modules/migration-versions.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['schema']);
        self::assertNotEmpty($data['entries']);

        $seen = [];
        foreach ($data['entries'] as $entry) {
            self::assertMatchesRegularExpression('/^Version[0-9]{14}$/D', $entry['version']);
            self::assertMatchesRegularExpression('/^symsvel-(?:back|module-[a-z-]+)$/D', $entry['repository']);
            self::assertSame(
                ($entry['repository'] === 'symsvel-back' ? 'migrations/' : 'Migration/').$entry['version'].'.php',
                $entry['path'],
            );
            self::assertArrayNotHasKey($entry['version'], $seen, 'Duplicate reserved migration version: '.$entry['version']);
            $seen[$entry['version']] = $entry['repository'];
        }
        self::assertCount(22, $seen);
    }
}
