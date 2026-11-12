<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SymSvel\Tooling\Command\SymSvelSetupDatabaseCommand;
use SymSvel\Tooling\Installation\SetupState;

final class SymSvelSetupDatabaseCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-setup-db-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->root.'/backend');
        mkdir($this->root.'/backend/bin');
        file_put_contents($this->root.'/backend/.env.local', "DATABASE_URL=postgresql://localhost/test\n");
        (new SetupState())->initialize($this->root);
    }

    protected function tearDown(): void
    {
        foreach (['backend/commands.log', 'backend/bin/console', 'backend/.env.local', '.symsvel/migration-recovery.json', '.symsvel/setup.json'] as $path) {
            @unlink($this->root.'/'.$path);
        }
        @unlink($this->root.'/backend/migrations/Version20261112171400.php');
        @unlink($this->root.'/.symsvel/native-migration-setup.json');
        @rmdir($this->root.'/backend/migrations');
        @rmdir($this->root.'/backend/var/data');
        @rmdir($this->root.'/backend/var');
        @rmdir($this->root.'/backend/bin');
        @rmdir($this->root.'/backend');
        @rmdir($this->root.'/.symsvel');
        @rmdir($this->root);
    }

    private function command(): CommandTester
    {
        return new CommandTester(new SymSvelSetupDatabaseCommand($this->root.'/backend'));
    }

    private function fakeConsole(string $exitExpression = '0'): void
    {
        file_put_contents($this->root.'/backend/bin/console', '<?php
file_put_contents(dirname(__DIR__)."/commands.log", implode(" ", array_slice($argv, 1))."\\n", FILE_APPEND);
if (($argv[1] ?? "") === "make:migration") {
    @mkdir(dirname(__DIR__)."/migrations");
    file_put_contents(dirname(__DIR__)."/migrations/Version20261112171400.php", "<?php // native observation");
}
exit('.$exitExpression.');
');
    }

    public function testSuccessfulPreparationOnlyRunsDatabaseAndMigrations(): void
    {
        $this->fakeConsole();
        self::assertSame(0, $this->command()->execute([]));
        self::assertSame(SetupState::DATABASE_READY, (new SetupState())->read($this->root));
        self::assertSame([
            'doctrine:database:create --if-not-exists --no-interaction',
            'symsvel:module:migrations:compile --source=composer --execute --no-interaction',
            'make:migration --no-interaction',
            'doctrine:migrations:migrate --no-interaction',
        ], file($this->root.'/backend/commands.log', FILE_IGNORE_NEW_LINES));
    }

    public function testSqliteSkipsDatabaseCreateAndPreparesParentDirectory(): void
    {
        file_put_contents($this->root.'/backend/.env.local', 'DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/test.db"'.PHP_EOL);
        $this->fakeConsole();
        self::assertSame(0, $this->command()->execute([]));
        self::assertDirectoryExists($this->root.'/backend/var/data');
        self::assertSame([
            'symsvel:module:migrations:compile --source=composer --execute --no-interaction',
            'make:migration --no-interaction',
            'doctrine:migrations:migrate --no-interaction',
        ], file($this->root.'/backend/commands.log', FILE_IGNORE_NEW_LINES));
        self::assertSame(SetupState::DATABASE_READY, (new SetupState())->read($this->root));
    }

    public function testQuotedDatabaseUrlIsRecognized(): void
    {
        file_put_contents($this->root.'/backend/.env.local', "APP_ENV=dev\nDATABASE_URL=\"postgresql://localhost/test?serverVersion=17.0.0\"\n");
        $this->fakeConsole();
        self::assertSame(0, $this->command()->execute([]));
        self::assertSame(SetupState::DATABASE_READY, (new SetupState())->read($this->root));
    }

    public function testMissingDatabaseUrlFailsWithoutChangingState(): void
    {
        file_put_contents($this->root.'/backend/.env.local', "APP_ENV=dev\n");
        $this->fakeConsole();
        self::assertSame(1, $this->command()->execute([]));
        self::assertSame(SetupState::PENDING, (new SetupState())->read($this->root));
        self::assertFileDoesNotExist($this->root.'/backend/commands.log');
    }

    public function testReadyStateNeverReplaysMigrations(): void
    {
        $this->fakeConsole();
        self::assertSame(0, $this->command()->execute([]));
        unlink($this->root.'/backend/commands.log');
        self::assertSame(0, $this->command()->execute([]));
        self::assertFileDoesNotExist($this->root.'/backend/commands.log');
    }

    public function testFailureCanResumeFromRunningWithoutAdminCreation(): void
    {
        $this->fakeConsole("(\$argv[1] === 'symsvel:module:migrations:compile' ? 1 : 0)");
        self::assertSame(1, $this->command()->execute([]));
        self::assertSame(SetupState::RUNNING, (new SetupState())->read($this->root));
        $this->fakeConsole();
        self::assertSame(0, $this->command()->execute([]));
        self::assertSame(SetupState::DATABASE_READY, (new SetupState())->read($this->root));
        self::assertStringNotContainsString('symsvel:admins:bootstrap', file_get_contents($this->root.'/backend/commands.log'));
    }

    public function testRecoveryJournalBlocksExecution(): void
    {
        $this->fakeConsole();
        file_put_contents($this->root.'/.symsvel/migration-recovery.json', '{}');
        self::assertSame(1, $this->command()->execute([]));
        self::assertSame(SetupState::PENDING, (new SetupState())->read($this->root));
        self::assertFileDoesNotExist($this->root.'/backend/commands.log');
    }
}
