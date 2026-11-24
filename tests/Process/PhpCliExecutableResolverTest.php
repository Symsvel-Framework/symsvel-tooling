<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Process;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Process\PhpCliExecutableResolver;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class PhpCliExecutableResolverTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('SYMSVEL_PHP_CLI');
        putenv('SYMSVEL_PHP_CLI');
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'SYMSVEL_PHP_CLI' : 'SYMSVEL_PHP_CLI='.$this->previous);
    }

    public function testExplicitOverrideHasPriority(): void
    {
        putenv('SYMSVEL_PHP_CLI='.PHP_BINARY);
        $finder = $this->createMock(PhpExecutableFinder::class);
        $finder->expects(self::never())->method('find');
        self::assertSame(PHP_BINARY, PhpCliExecutableResolver::resolve($finder));
    }

    public function testInvalidOverrideFailsWithoutSilentlyFallingBack(): void
    {
        putenv('SYMSVEL_PHP_CLI='.PHP_BINARY.' --invalid-argument');
        $finder = $this->createMock(PhpExecutableFinder::class);
        $finder->expects(self::never())->method('find');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SYMSVEL_PHP_CLI must designate');
        PhpCliExecutableResolver::resolve($finder);
    }

    public function testExecutableThatIsNotPhpIsRejected(): void
    {
        if (!is_executable('/bin/true')) {
            self::markTestSkipped('Unix true executable required.');
        }
        putenv('SYMSVEL_PHP_CLI=/bin/true');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SYMSVEL_PHP_CLI must designate');
        PhpCliExecutableResolver::resolve();
    }

    public function testNativeFinderIsCalledWithoutShellArguments(): void
    {
        $finder = $this->createMock(PhpExecutableFinder::class);
        $finder->expects(self::once())->method('find')->with(false)->willReturn(PHP_BINARY);
        self::assertSame(PHP_BINARY, PhpCliExecutableResolver::resolve($finder));
    }

    public function testMissingExecutableProvidesExplicitDiagnostic(): void
    {
        $finder = $this->createMock(PhpExecutableFinder::class);
        $finder->method('find')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve an executable PHP CLI');
        PhpCliExecutableResolver::resolve($finder);
    }

    public function testRealCliWithEmptyPhpBinaryAndMissingPath(): void
    {
        if (!is_executable('/bin/bash') || !is_executable(PHP_BINDIR.'/php')) {
            self::markTestSkipped('Bash and the native PHP_BINDIR/php fallback are required.');
        }
        // Actual executable is absolute, but argv[0] is just php and PATH is absent.
        $probe = new Process([
            '/bin/bash', '-c', 'unset PATH; exec -a php "$@"', 'symsvel',
            PHP_BINARY, '-r',
            'require $argv[1]; echo json_encode([PHP_BINARY, getenv("PATH"), \SymSvel\Tooling\Process\PhpCliExecutableResolver::resolve()], JSON_THROW_ON_ERROR);',
            dirname(__DIR__, 2).'/vendor/autoload.php',
        ], null, [
            'PATH' => false, 'PHP_BINARY' => false, 'PHP_PATH' => false,
            'PHP_PEAR_PHP_BIN' => false, 'SYMSVEL_PHP_CLI' => false,
        ]);
        $probe->setTimeout(15);
        $probe->mustRun();
        [$binary, $path, $resolved] = json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $binary);
        self::assertFalse($path);
        self::assertSame(PHP_BINDIR.'/php', $resolved);
        self::assertFileExists($resolved);
        self::assertTrue(is_executable($resolved));
    }
}
