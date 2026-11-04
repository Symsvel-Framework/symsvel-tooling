<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\SetupState;

final class SetupStateTest extends TestCase
{
    public function testSetupStateIsPrivateAcrossTransitions(): void
    {
        $root = sys_get_temp_dir().'/symsvel-setup-private-'.bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $state = new SetupState();
            $state->initialize($root);
            $path = $root.'/.symsvel/setup.json';
            clearstatcache(true, $path);
            self::assertSame(0600, fileperms($path) & 0777);
            $state->transition($root, SetupState::PENDING, SetupState::RUNNING);
            clearstatcache(true, $path);
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(SetupState::RUNNING, $state->read($root));
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @rmdir($root);
        }
    }

    public function testCannotCompleteWithoutDatabaseConfiguration(): void
    {
        $root = sys_get_temp_dir().'/symsvel-setup-no-db-'.bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $state = new SetupState();
            $state->initialize($root);
            $state->transition($root, SetupState::PENDING, SetupState::RUNNING);
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('configuration BDD absente');
            $state->transition($root, SetupState::RUNNING, SetupState::COMPLETED);
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @rmdir($root);
        }
    }

    public function testCannotCompleteSetupWithPendingMigrationRecovery(): void
    {
        $root = sys_get_temp_dir().'/symsvel-setup-recovery-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        file_put_contents($root.'/backend/.env.local', "DATABASE_URL=postgresql://localhost/test\n");
        try {
            $state = new SetupState();
            $state->initialize($root);
            $state->transition($root, SetupState::PENDING, SetupState::RUNNING);
            $journal = $root.'/.symsvel/migration-recovery.json';
            file_put_contents($journal, '{}');
            try {
                $state->transition($root, SetupState::RUNNING, SetupState::COMPLETED);
                self::fail('A pending recovery must prevent setup completion.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('récupération de migrations', $exception->getMessage());
            }
            self::assertSame(SetupState::RUNNING, $state->read($root));
            unlink($journal);
            $state->transition($root, SetupState::RUNNING, SetupState::COMPLETED);
            self::assertSame(SetupState::COMPLETED, $state->read($root));
        } finally {
            @unlink($root.'/.symsvel/migration-recovery.json');
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @unlink($root.'/backend/.env.local');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testLifecycleIsExplicitAndCannotBeReopened(): void
    {
        $root = sys_get_temp_dir().'/symsvel-setup-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        file_put_contents($root.'/backend/.env.local', "DATABASE_URL=postgresql://localhost/test\n");
        try {
            $state = new SetupState();
            self::assertNull($state->read($root));
            $state->initialize($root);
            self::assertSame(SetupState::PENDING, $state->read($root));
            $state->transition($root, SetupState::PENDING, SetupState::RUNNING);
            self::assertSame(SetupState::RUNNING, $state->read($root));
            $state->transition($root, SetupState::RUNNING, SetupState::COMPLETED);
            $state->initialize($root);
            self::assertSame(SetupState::COMPLETED, $state->read($root));
            $this->expectException(\DomainException::class);
            $state->transition($root, SetupState::COMPLETED, SetupState::PENDING);
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @unlink($root.'/backend/.env.local');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }
}
