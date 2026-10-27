<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleRemovalExecutionGate;

final class ModuleRemovalExecutionGateTest extends TestCase
{
    public function testMissingModuleCannotBeAuthorizedEvenWithWellFormedFingerprint(): void
    {
        $root = sys_get_temp_dir().'/symsvel-removal-gate-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Module introuvable');
            (new ModuleRemovalExecutionGate())->authorize($root, 'missing', false, str_repeat('a', 64));
        } finally {
            rmdir($root);
        }
    }

    public function testPendingRecoveryBlocksAuthorization(): void
    {
        $root = sys_get_temp_dir().'/symsvel-removal-gate-recovery-'.bin2hex(random_bytes(6));
        mkdir($root.'/.symsvel', 0700, true);
        file_put_contents($root.'/.symsvel/module-removal-recovery.json', '{}');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Désinstallation précédente');
            (new ModuleRemovalExecutionGate())->authorize($root, 'users', false, str_repeat('a', 64));
        } finally {
            unlink($root.'/.symsvel/module-removal-recovery.json');
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }
}
