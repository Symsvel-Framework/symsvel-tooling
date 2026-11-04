<?php
declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\WorkspaceInitializer;

final class WorkspaceInitializerTest extends TestCase
{
    public function testInitializationCreatesDocumentationAndSecretsWithoutOverwritingExistingFiles(): void
    {
        $root = sys_get_temp_dir().'/symsvel-workspace-'.bin2hex(random_bytes(6));
        mkdir($root);
        try {
            $initializer = new WorkspaceInitializer();
            $initializer->initialize($root);
            self::assertFileExists($root.'/dev-doc/site/index.html');
            self::assertFileExists($root.'/dev-doc/mkdocs.yml');
            self::assertFileExists($root.'/secrets/.env.example');
            $tokenPath = $root.'/.symsvel/setup-token';
            self::assertFileExists($tokenPath);
            clearstatcache(true, $tokenPath);
            self::assertSame(0600, fileperms($tokenPath) & 0777);
            $token = file_get_contents($tokenPath);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}\\n$/', $token);
            file_put_contents($root.'/secrets/.env.example', 'CUSTOM=1');
            $initializer->initialize($root);
            self::assertSame('CUSTOM=1', file_get_contents($root.'/secrets/.env.example'));
            self::assertSame($token, file_get_contents($tokenPath));
        } finally {
            (new \Symfony\Component\Process\Process(['rm', '-rf', '--', $root]))->run();
        }
    }
}
