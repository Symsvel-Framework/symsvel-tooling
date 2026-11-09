<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\SetupState;

final class SetupStateTest extends TestCase
{
    public function testSetupStateIsReadableAcrossUsersAfterAtomicTransitions(): void
    {
        $root = sys_get_temp_dir().'/symsvel-setup-private-'.bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $state = new SetupState();
            $state->initialize($root);
            $path = $root.'/.symsvel/setup.json';
            clearstatcache(true, $path);
            self::assertSame(0644, fileperms($path) & 0777);
            $state->transition($root, SetupState::PENDING, SetupState::RUNNING);
            clearstatcache(true, $path);
            self::assertSame(0644, fileperms($path) & 0777);
            self::assertSame(SetupState::RUNNING, $state->read($root));
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @rmdir($root);
        }
    }

    public function testRejectsSymbolicSetupState(): void
    {
        $root = sys_get_temp_dir().'/symsvel-setup-link-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/.symsvel');
        $target = $root.'/external.json';
        file_put_contents($target, '{"schema":1,"status":"pending"}');
        symlink($target, $root.'/.symsvel/setup.json');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('symbolique interdit');
            (new SetupState())->read($root);
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @unlink($target);
            @rmdir($root.'/.symsvel');
            @rmdir($root);
        }
    }

    public function testRejectsInvalidStatePayload(): void
    {
        $root = sys_get_temp_dir().'/symsvel-setup-invalid-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/.symsvel');
        file_put_contents($root.'/.symsvel/setup.json', '{"schema":1,"status":"unknown"}');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('État setup invalide');
            (new SetupState())->read($root);
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
            $state->transition($root, SetupState::RUNNING, SetupState::DATABASE_READY);
            $state->transition($root, SetupState::DATABASE_READY, SetupState::COMPLETED);
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
                $state->transition($root, SetupState::RUNNING, SetupState::DATABASE_READY);
            $state->transition($root, SetupState::DATABASE_READY, SetupState::COMPLETED);
                self::fail('A pending recovery must prevent setup completion.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('récupération de migrations', $exception->getMessage());
            }
            self::assertSame(SetupState::RUNNING, $state->read($root));
            unlink($journal);
            $state->transition($root, SetupState::RUNNING, SetupState::DATABASE_READY);
            $state->transition($root, SetupState::DATABASE_READY, SetupState::COMPLETED);
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
            $state->transition($root, SetupState::RUNNING, SetupState::DATABASE_READY);
            $state->transition($root, SetupState::DATABASE_READY, SetupState::COMPLETED);
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
