<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;
use SymSvel\Tooling\Module\ModuleRemovalConfirmation;

final class ModuleRemovalConfirmationTest extends TestCase
{
    public function testChangedReceiptsInvalidateApproval(): void
    {
        $root = sys_get_temp_dir().'/symsvel-confirm-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        mkdir($root.'/modules/users', 0700, true);
        file_put_contents($root.'/modules/users/module.json', '{}');
        $registry = new ModuleMigrationRegistry();
        $confirmation = new ModuleRemovalConfirmation();
        $plan = ['modules' => ['users'], 'versions' => [], 'sources' => [], 'tables' => ['users']];
        try {
            $registry->write($root, []);
            $fingerprint = $confirmation->fingerprint($root, $plan);
            $confirmation->assertMatches($root, $plan, $fingerprint);
            $registry->write($root, [
                'users/001.php' => ['sha256' => str_repeat('a', 64), 'doctrine' => 'DoctrineMigrations\\Version001'],
            ]);
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('périmée');
            $confirmation->assertMatches($root, $plan, $fingerprint);
        } finally {
            unlink($root.'/.symsvel/migrations.json');
            rmdir($root.'/.symsvel');
            unlink($root.'/modules/users/module.json');
            rmdir($root.'/modules/users');
            rmdir($root.'/modules');
            rmdir($root);
        }
    }
    public function testChangedDestructiveScopeInvalidatesApproval(): void
    {
        $root = sys_get_temp_dir().'/symsvel-confirm-scope-'.bin2hex(random_bytes(6));
        mkdir($root.'/modules/users', 0700, true);
        file_put_contents($root.'/modules/users/module.json', '{}');
        try {
            $confirmation = new ModuleRemovalConfirmation();
            $plan = ['modules' => ['users'], 'versions' => [], 'sources' => [], 'tables' => ['users']];
            $fingerprint = $confirmation->fingerprint($root, $plan);
            $confirmation->assertMatches($root, $plan, $fingerprint);
            $plan['tables'][] = 'user_profiles';
            $this->expectException(\DomainException::class);
            $confirmation->assertMatches($root, $plan, $fingerprint);
        } finally {
            unlink($root.'/modules/users/module.json');
            rmdir($root.'/modules/users');
            rmdir($root.'/modules');
            rmdir($root);
        }
    }

    public function testMalformedApprovalIsRejected(): void
    {
        $root = sys_get_temp_dir().'/symsvel-confirm-invalid-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        try {
            $this->expectException(\DomainException::class);
            (new ModuleRemovalConfirmation())->assertMatches($root, [
                'modules' => ['users'], 'versions' => [], 'sources' => [], 'tables' => [],
            ], 'invalid-token');
        } finally {
            rmdir($root);
        }
    }

    public function testChangedManifestInvalidatesApproval(): void
    {
        $root = sys_get_temp_dir().'/symsvel-confirm-manifest-'.bin2hex(random_bytes(6));
        mkdir($root.'/modules/users', 0700, true);
        $manifest = $root.'/modules/users/module.json';
        file_put_contents($manifest, '{"tables":["users"]}');
        try {
            $plan = ['modules' => ['users'], 'versions' => [], 'sources' => [], 'tables' => ['users']];
            $confirmation = new ModuleRemovalConfirmation();
            $fingerprint = $confirmation->fingerprint($root, $plan);
            file_put_contents($manifest, '{"tables":["users"],"changed":true}');
            $this->expectException(\DomainException::class);
            $confirmation->assertMatches($root, $plan, $fingerprint);
        } finally {
            unlink($manifest);
            rmdir($root.'/modules/users');
            rmdir($root.'/modules');
            rmdir($root);
        }
    }

    public function testChangedMigrationSourceInvalidatesApproval(): void
    {
        $root = sys_get_temp_dir().'/symsvel-confirm-source-'.bin2hex(random_bytes(6));
        mkdir($root.'/modules/users', 0700, true);
        $manifest = $root.'/modules/users/module.json';
        $source = $root.'/modules/users/Version001.php';
        file_put_contents($manifest, '{}');
        file_put_contents($source, '<?php // original');
        try {
            $plan = [
                'modules' => ['users'],
                'versions' => ['DoctrineMigrations\\Version001'],
                'sources' => ['users/Version001.php'],
                'tables' => ['users'],
            ];
            $confirmation = new ModuleRemovalConfirmation();
            $fingerprint = $confirmation->fingerprint($root, $plan);
            file_put_contents($source, '<?php // modified');
            $this->expectException(\DomainException::class);
            $confirmation->assertMatches($root, $plan, $fingerprint);
        } finally {
            unlink($source);
            unlink($manifest);
            rmdir($root.'/modules/users');
            rmdir($root.'/modules');
            rmdir($root);
        }
    }

}
