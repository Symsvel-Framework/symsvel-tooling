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
            rmdir($root);
        }
    }
}
