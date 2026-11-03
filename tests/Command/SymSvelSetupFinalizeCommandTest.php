<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SymSvel\Tooling\Command\SymSvelSetupFinalizeCommand;
use SymSvel\Tooling\Installation\SetupState;

final class SymSvelSetupFinalizeCommandTest extends TestCase
{
    public function testMigrationFailureLeavesSetupRunningForRetry(): void
    {
        $root = sys_get_temp_dir().'/symsvel-finalize-fail-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        mkdir($root.'/backend/bin');
        file_put_contents($root.'/backend/.env.local', "DATABASE_URL=postgresql://localhost/test\n");
        file_put_contents($root.'/backend/bin/console', "<?php fwrite(STDERR, 'migration failure'); exit(1);\n");
        try {
            $state = new SetupState();
            $state->initialize($root);
            $tester = new CommandTester(new SymSvelSetupFinalizeCommand($root.'/backend'));
            self::assertSame(1, $tester->execute([]));
            self::assertSame(SetupState::RUNNING, $state->read($root));
            self::assertStringContainsString('migration failure', $tester->getDisplay());
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @unlink($root.'/backend/bin/console');
            @rmdir($root.'/backend/bin');
            @unlink($root.'/backend/.env.local');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testSuccessfulMigrationCommandCompletesSetup(): void
    {
        $root = sys_get_temp_dir().'/symsvel-finalize-ok-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        mkdir($root.'/backend/bin');
        file_put_contents($root.'/backend/.env.local', "DATABASE_URL=postgresql://localhost/test\n");
        file_put_contents($root.'/backend/bin/console', "<?php exit(0);\n");
        try {
            $state = new SetupState();
            $state->initialize($root);
            $tester = new CommandTester(new SymSvelSetupFinalizeCommand($root.'/backend'));
            self::assertSame(0, $tester->execute([]));
            self::assertSame(SetupState::COMPLETED, $state->read($root));
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @unlink($root.'/backend/bin/console');
            @rmdir($root.'/backend/bin');
            @unlink($root.'/backend/.env.local');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testPendingRecoveryJournalPreventsMigrationReplay(): void
    {
        $root = sys_get_temp_dir().'/symsvel-finalize-recovery-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        file_put_contents($root.'/backend/.env.local', "DATABASE_URL=postgresql://localhost/test\n");
        try {
            $state = new SetupState();
            $state->initialize($root);
            file_put_contents($root.'/.symsvel/migration-recovery.json', '{}');
            $tester = new CommandTester(new SymSvelSetupFinalizeCommand($root.'/backend'));
            self::assertSame(1, $tester->execute([]));
            self::assertSame(SetupState::PENDING, $state->read($root));
            self::assertStringContainsString('Récupération de migrations', $tester->getDisplay());
        } finally {
            @unlink($root.'/.symsvel/migration-recovery.json');
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @unlink($root.'/backend/.env.local');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testQuotedEmptyDatabaseUrlDoesNotStartMigrations(): void
    {
        $root = sys_get_temp_dir().'/symsvel-finalize-empty-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        file_put_contents($root.'/backend/.env.local', "DATABASE_URL=\"\"\n");
        try {
            $state = new SetupState();
            $state->initialize($root);
            $tester = new CommandTester(new SymSvelSetupFinalizeCommand($root.'/backend'));
            self::assertSame(1, $tester->execute([]));
            self::assertSame(SetupState::PENDING, $state->read($root));
            self::assertStringContainsString('DATABASE_URL manquant', $tester->getDisplay());
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @unlink($root.'/backend/.env.local');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testCannotFinalizeBeforeDatabaseConfiguration(): void
    {
        $root = sys_get_temp_dir().'/symsvel-finalize-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        try {
            $state = new SetupState();
            $state->initialize($root);
            $tester = new CommandTester(new SymSvelSetupFinalizeCommand($root.'/backend'));
            self::assertSame(1, $tester->execute([]));
            self::assertSame(SetupState::PENDING, $state->read($root));
            self::assertStringContainsString('DATABASE_URL', $tester->getDisplay());
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testCannotFinalizeWithoutInitializedSetup(): void
    {
        $root = sys_get_temp_dir().'/symsvel-finalize-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/backend');
        try {
            $tester = new CommandTester(new SymSvelSetupFinalizeCommand($root.'/backend'));
            self::assertSame(1, $tester->execute([]));
            self::assertStringContainsString('Setup non initialisé', $tester->getDisplay());
        } finally {
            rmdir($root.'/backend');
            rmdir($root);
        }
    }
}
