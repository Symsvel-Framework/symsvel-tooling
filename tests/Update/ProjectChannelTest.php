<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use SymSvel\Tooling\Update\ProjectChannel;

final class ProjectChannelTest extends TestCase
{
    private string $backend;

    protected function setUp(): void
    {
        $this->backend = sys_get_temp_dir().'/symsvel-channel-'.bin2hex(random_bytes(8)).'/backend';
        mkdir($this->backend, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(dirname($this->backend));
    }

    public function testPersistsDevChannelAcrossReads(): void
    {
        ProjectChannel::write($this->backend, ProjectChannel::DEV);
        self::assertSame(ProjectChannel::DEV, ProjectChannel::read($this->backend));
        self::assertSame('dev', json_decode((string) file_get_contents($this->backend.'/.symsvel/project.json'), true)['channel']);
    }

    public function testDefaultsAreNotSilentlyInferred(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Canal SymSvel absent');
        ProjectChannel::read($this->backend);
    }

    public function testRefusesImplicitChannelSwitch(): void
    {
        ProjectChannel::write($this->backend, ProjectChannel::STABLE);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('déjà défini');
        ProjectChannel::write($this->backend, ProjectChannel::DEV);
    }

    public function testRejectsInvalidChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectChannel::write($this->backend, 'main');
    }

    public function testRejectsSymlinkedConfigurationDirectory(): void
    {
        $outside = dirname($this->backend).'/outside';
        mkdir($outside);
        symlink($outside, $this->backend.'/.symsvel');
        $this->expectException(\RuntimeException::class);
        ProjectChannel::write($this->backend, ProjectChannel::DEV);
    }
}
