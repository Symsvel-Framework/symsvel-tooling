<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class StandaloneLauncherTest extends TestCase
{
    public function testHelpAndUnknownCommand(): void
    {
        $script = dirname(__DIR__, 2).'/bin/symsvel';
        $help = new Process([PHP_BINARY, $script, '--help']);
        $help->run();
        self::assertSame(0, $help->getExitCode());
        self::assertStringContainsString('make:module', $help->getOutput());

        $unknown = new Process([PHP_BINARY, $script, 'unknown']);
        $unknown->run();
        self::assertSame(2, $unknown->getExitCode());
    }

    public function testMissingNameIsRejected(): void
    {
        $script = dirname(__DIR__, 2).'/bin/symsvel';
        $process = new Process([PHP_BINARY, $script, 'make:module']);
        $process->run();
        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Nom de module requis', $process->getErrorOutput());
    }
}
