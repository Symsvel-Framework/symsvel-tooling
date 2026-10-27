<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationExecutor;
use SymSvel\Tooling\Module\ModuleMigrationRecovery;

final class ModuleMigrationRecoveryTest extends TestCase
{
    public function testResumeSkipsAlreadyRolledBackVersionsAndKeepsJournal(): void
    {
        $root = sys_get_temp_dir().'/symsvel-resume-'.bin2hex(random_bytes(6));
        mkdir($root.'/.symsvel', 0775, true);
        mkdir($root.'/backend/bin', 0775, true);
        $first = 'DoctrineMigrations\\Version001';
        $second = 'DoctrineMigrations\\Version002';
        $third = 'DoctrineMigrations\\Version003';
        $journal = $root.'/.symsvel/migration-recovery.json';
        file_put_contents($journal, json_encode([
            'schema' => 1, 'state' => 'recovering',
            'planned' => [$first, $second, $third],
            'completed' => [$first, $second, $third],
            'rolled_back' => [$third],
            'in_flight' => null,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/backend/bin/console', <<<'PHP'
<?php
file_put_contents(dirname(__DIR__).'/calls.log', implode(' ', array_slice($argv, 2))."\n", FILE_APPEND);
PHP);
        try {
            $done = (new ModuleMigrationRecovery())->recover($root.'/backend', $root, new ModuleMigrationExecutor());
            self::assertSame([$third, $second, $first], $done);
            self::assertSame(
                [$second.' --down --no-interaction', $first.' --down --no-interaction'],
                explode("\n", trim((string) file_get_contents($root.'/backend/calls.log'))),
            );
            self::assertFileExists($journal);
            $again = (new ModuleMigrationRecovery())->recover($root.'/backend', $root, new ModuleMigrationExecutor());
            self::assertSame($done, $again);
            self::assertCount(2, explode("\n", trim((string) file_get_contents($root.'/backend/calls.log'))));
        } finally {
            unlink($root.'/backend/bin/console');
            unlink($root.'/backend/calls.log');
            unlink($journal);
            rmdir($root.'/backend/bin');
            rmdir($root.'/backend');
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }

    public function testRefusesUnknownInFlightStateWithoutRunningDoctrine(): void
    {
        $root = sys_get_temp_dir().'/symsvel-unsafe-'.bin2hex(random_bytes(6));
        mkdir($root.'/.symsvel', 0775, true);
        $journal = $root.'/.symsvel/migration-recovery.json';
        file_put_contents($journal, json_encode([
            'schema' => 1, 'state' => 'recovering',
            'planned' => ['DoctrineMigrations\\Version001'],
            'completed' => [],
            'rolled_back' => [],
            'in_flight' => 'DoctrineMigrations\\Version001',
        ], JSON_THROW_ON_ERROR));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('incertain');
            (new ModuleMigrationRecovery())->recover($root, $root, new ModuleMigrationExecutor());
        } finally {
            unlink($journal);
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }
}
