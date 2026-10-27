<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleTableOwnership;

final class ModuleTableOwnershipTest extends TestCase
{
    public function testIndependentOwnersAndDuplicateRejection(): void
    {
        $root = sys_get_temp_dir().'/symsvel-ownership-'.bin2hex(random_bytes(6));
        foreach (['users' => ['users'], 'users-infos' => ['users_infos']] as $slug => $tables) {
            mkdir($root.'/modules/'.$slug, 0700, true);
            file_put_contents($root.'/modules/'.$slug.'/module.json', json_encode(['tables' => $tables], JSON_THROW_ON_ERROR));
        }
        try {
            $resolver = new ModuleTableOwnership();
            self::assertSame(['users_infos'], $resolver->tables($root, ['users-infos']));
            self::assertSame(['users', 'users_infos'], $resolver->tables($root, ['users', 'users-infos']));
            file_put_contents($root.'/modules/users-infos/module.json', '{"tables":["users"]}');
            $this->expectException(\DomainException::class);
            $resolver->tables($root, ['users', 'users-infos']);
        } finally {
            foreach (['users', 'users-infos'] as $slug) {
                unlink($root.'/modules/'.$slug.'/module.json');
                rmdir($root.'/modules/'.$slug);
            }
            rmdir($root.'/modules');
            rmdir($root);
        }
    }

    public function testOmittedOwnershipMeansNoTables(): void
    {
        $root = sys_get_temp_dir().'/symsvel-ownership-'.bin2hex(random_bytes(6));
        mkdir($root.'/modules/users', 0700, true);
        file_put_contents($root.'/modules/users/module.json', '{}');
        try {
            self::assertSame([], (new ModuleTableOwnership())->tables($root, ['users']));
        } finally {
            unlink($root.'/modules/users/module.json');
            rmdir($root.'/modules/users');
            rmdir($root.'/modules');
            rmdir($root);
        }
    }
    public function testRetainedModuleCannotClaimRemovedTable(): void
    {
        $root = sys_get_temp_dir().'/symsvel-ownership-'.bin2hex(random_bytes(6));
        foreach (['users', 'audit'] as $slug) {
            mkdir($root.'/modules/'.$slug, 0700, true);
            file_put_contents($root.'/modules/'.$slug.'/module.json', '{"tables":["users"]}');
        }
        try {
            self::assertSame(['users'], (new ModuleTableOwnership())->tables($root, ['users']));
        } finally {
            foreach (['users', 'audit'] as $slug) {
                unlink($root.'/modules/'.$slug.'/module.json');
                rmdir($root.'/modules/'.$slug);
            }
            rmdir($root.'/modules');
            rmdir($root);
        }
    }

    public function testExplicitlyEmptyTablesAreValid(): void
    {
        $root = sys_get_temp_dir().'/symsvel-ownership-'.bin2hex(random_bytes(6));
        mkdir($root.'/modules/connector', 0700, true);
        file_put_contents($root.'/modules/connector/module.json', '{"tables":[]}');
        try {
            self::assertSame([], (new ModuleTableOwnership())->tables($root, ['connector']));
        } finally {
            unlink($root.'/modules/connector/module.json');
            rmdir($root.'/modules/connector');
            rmdir($root.'/modules');
            rmdir($root);
        }
    }

    public function testTablelessRetainedModuleDoesNotBlockRemoval(): void
    {
        $root = sys_get_temp_dir().'/symsvel-ownership-'.bin2hex(random_bytes(6));
        foreach (['users', 'legacy'] as $slug) {
            mkdir($root.'/modules/'.$slug, 0700, true);
        }
        file_put_contents($root.'/modules/users/module.json', '{"tables":["users"]}');
        file_put_contents($root.'/modules/legacy/module.json', '{}');
        try {
            self::assertSame(['users'], (new ModuleTableOwnership())->tables($root, ['users']));
        } finally {
            foreach (['users', 'legacy'] as $slug) {
                unlink($root.'/modules/'.$slug.'/module.json');
                rmdir($root.'/modules/'.$slug);
            }
            rmdir($root.'/modules');
            rmdir($root);
        }
    }

}
