<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Update\LocalBoDomainPublisher;

final class LocalBoDomainPublisherTest extends TestCase
{
    private string $workspace;
    private string $backend;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-bo-domain-'.bin2hex(random_bytes(8));
        $this->backend = $this->workspace.'/backend';
        mkdir($this->workspace.'/.symsvel', 0700, true);
        mkdir($this->backend, 0755);
        file_put_contents($this->backend.'/.env.local', "DATABASE_URL=\"mysql://user:secret@localhost/test\"\nAPP_ENV=dev\n");
        chmod($this->backend.'/.env.local', 0644);
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace.'/.symsvel/project.json');
        @unlink($this->backend.'/.env.local');
        @rmdir($this->backend);
        @rmdir($this->workspace.'/.symsvel');
        @rmdir($this->workspace);
    }

    private function setupDomain(string $domain): void
    {
        file_put_contents(
            $this->workspace.'/.symsvel/project.json',
            json_encode(['schema' => 1, 'channel' => 'dev', 'local_domain' => $domain], JSON_THROW_ON_ERROR),
        );
        chmod($this->workspace.'/.symsvel/project.json', 0600);
    }

    public function testPublishesDomainWhileKeepingPrivateMetadataAndExistingEnvironment(): void
    {
        $this->setupDomain('www.example.fr');
        $publisher = new LocalBoDomainPublisher();
        self::assertTrue($publisher->publish($this->backend));
        $env = (string) file_get_contents($this->backend.'/.env.local');
        self::assertStringContainsString('SYMSVEL_BO_DOMAIN="www.example.fr"', $env);
        self::assertStringContainsString('DATABASE_URL="mysql://user:secret@localhost/test"', $env);
        self::assertSame(0644, fileperms($this->backend.'/.env.local') & 0777);
        self::assertSame(0600, fileperms($this->workspace.'/.symsvel/project.json') & 0777);
        self::assertFalse($publisher->publish($this->backend));
        self::assertSame($env, file_get_contents($this->backend.'/.env.local'));
    }

    public function testReplacesOnlyGeneratedDomainAndPreservesOtherOverrides(): void
    {
        $this->setupDomain('xt.localhost');
        file_put_contents(
            $this->backend.'/.env.local',
            "DATABASE_URL=\"sqlite:///%kernel.project_dir%/var/app.sqlite\"\nSYMSVEL_BO_ORIGIN=\"https://bo.custom.example\"\nSYMSVEL_BO_DOMAIN=\"old.localhost\"\n",
        );
        self::assertTrue((new LocalBoDomainPublisher())->publish($this->backend));
        $env = (string) file_get_contents($this->backend.'/.env.local');
        self::assertStringContainsString('SYMSVEL_BO_DOMAIN="xt.localhost"', $env);
        self::assertSame(1, substr_count($env, 'SYMSVEL_BO_DOMAIN='));
        self::assertStringContainsString('SYMSVEL_BO_ORIGIN="https://bo.custom.example"', $env);
        self::assertStringNotContainsString('old.localhost', $env);
    }

    public function testIncompleteSetupDoesNotModifyRuntimeEnvironment(): void
    {
        $before = (string) file_get_contents($this->backend.'/.env.local');
        self::assertFalse((new LocalBoDomainPublisher())->publish($this->backend));
        file_put_contents($this->workspace.'/.symsvel/project.json', '{"schema":1,"channel":"dev"}');
        self::assertFalse((new LocalBoDomainPublisher())->publish($this->backend));
        self::assertSame($before, file_get_contents($this->backend.'/.env.local'));
    }

    public function testInvalidDomainDoesNotOverwriteExistingRuntimeEnvironment(): void
    {
        $this->setupDomain('http://evil.example');
        $before = (string) file_get_contents($this->backend.'/.env.local');
        $this->expectException(\RuntimeException::class);
        try {
            (new LocalBoDomainPublisher())->publish($this->backend);
        } finally {
            self::assertSame($before, file_get_contents($this->backend.'/.env.local'));
        }
    }

    public function testRejectsSymlinkedPrivateMetadata(): void
    {
        symlink($this->backend.'/.env.local', $this->workspace.'/.symsvel/project.json');
        $this->expectException(\RuntimeException::class);
        (new LocalBoDomainPublisher())->publish($this->backend);
    }
}
