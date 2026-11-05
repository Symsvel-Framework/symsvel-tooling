<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\SetupAccessPreflight;

final class SetupAccessPreflightTest extends TestCase
{
    public function testReportsMissingWorkspace(): void
    {
        $root = sys_get_temp_dir().'/symsvel-preflight-missing-'.bin2hex(random_bytes(8));
        self::assertSame(['Workspace introuvable.'], (new SetupAccessPreflight())->check($root));
    }

    public function testAcceptsReadableWritableWorkspaceWithoutChangingPermissions(): void
    {
        $root = sys_get_temp_dir().'/symsvel-preflight-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/.symsvel');
        mkdir($root.'/backend');
        file_put_contents($root.'/.symsvel/setup.json', '{"schema":1,"status":"pending"}');
        file_put_contents($root.'/.symsvel/setup-token', 'secret');
        chmod($root.'/.symsvel/setup-token', 0600);
        try {
            self::assertSame([], (new SetupAccessPreflight())->check($root));
            clearstatcache(true, $root.'/.symsvel/setup-token');
            self::assertSame(0600, fileperms($root.'/.symsvel/setup-token') & 0777);
        } finally {
            @unlink($root.'/.symsvel/setup-token');
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

    public function testRejectsSymbolicSetupToken(): void
    {
        $root = sys_get_temp_dir().'/symsvel-preflight-link-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/.symsvel');
        mkdir($root.'/backend');
        file_put_contents($root.'/.symsvel/setup.json', '{}');
        file_put_contents($root.'/target', 'secret');
        symlink($root.'/target', $root.'/.symsvel/setup-token');
        try {
            self::assertContains('setup-token doit être un fichier ordinaire lisible par le processus PHP.', (new SetupAccessPreflight())->check($root));
        } finally {
            @unlink($root.'/.symsvel/setup-token');
            @unlink($root.'/.symsvel/setup.json');
            @unlink($root.'/target');
            @rmdir($root.'/.symsvel');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }
    public function testRejectsSymbolicStagedAdministratorPayload(): void
    {
        $root = sys_get_temp_dir().'/symsvel-preflight-admin-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/.symsvel');
        mkdir($root.'/backend');
        file_put_contents($root.'/.symsvel/setup.json', '{"schema":1,"status":"pending"}');
        file_put_contents($root.'/.symsvel/setup-token', 'secret');
        file_put_contents($root.'/target', 'sensitive');
        symlink($root.'/target', $root.'/.symsvel/pending-admin.json');
        try {
            self::assertContains(
                'pending-admin.json existant invalide, symbolique ou illisible.',
                (new SetupAccessPreflight())->check($root),
            );
        } finally {
            @unlink($root.'/.symsvel/pending-admin.json');
            @unlink($root.'/.symsvel/setup-token');
            @unlink($root.'/.symsvel/setup.json');
            @unlink($root.'/target');
            @rmdir($root.'/.symsvel');
            @rmdir($root.'/backend');
            @rmdir($root);
        }
    }

}
