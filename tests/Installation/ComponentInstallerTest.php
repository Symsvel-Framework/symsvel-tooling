<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\ComponentInstaller;
use SymSvel\Tooling\Update\ComponentManifest;
use SymSvel\Tooling\Update\ProjectChannel;
use Symfony\Component\Process\Process;

final class ComponentInstallerTest extends TestCase
{
    public function testSelectionIncludesBoForEveryChoice(): void
    {
        $installer = new ComponentInstaller();
        self::assertSame(['bo'], $installer->componentsForChoice('1'));
        self::assertSame(['bo', 'app'], $installer->componentsForChoice('2'));
        self::assertSame(['bo', 'front'], $installer->componentsForChoice('3'));
        self::assertSame(['bo', 'app', 'front'], $installer->componentsForChoice('4'));
    }

    public function testCloneSeparatesProjectGitFromFrameworkOnStableAndDev(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-installer-git-'.bin2hex(random_bytes(8));
        $upstream = $workspace.'/upstream';
        mkdir($workspace.'/backend', 0775, true);
        mkdir($upstream, 0775, true);
        try {
            foreach ([
                ['git', 'init', '-b', 'main'],
                ['git', 'config', 'user.name', 'CI'],
                ['git', 'config', 'user.email', 'ci@example.test'],
            ] as $command) {
                (new Process($command, $upstream))->mustRun();
            }
            file_put_contents($upstream.'/package.json', '{"name":"git-fixture","version":"1.0.0","scripts":{"build":"echo ok"}}');
            file_put_contents($upstream.'/package-lock.json', '{"name":"git-fixture","version":"1.0.0","lockfileVersion":3,"requires":true,"packages":{"":{"name":"git-fixture","version":"1.0.0"}}}');
            file_put_contents($upstream.'/readme.txt', 'native content');
            (new Process(['git', 'add', '.'], $upstream))->mustRun();
            (new Process(['git', 'commit', '-m', 'fixture'], $upstream))->mustRun();
            (new Process(['git', 'branch', 'dev'], $upstream))->mustRun();

            foreach ([ProjectChannel::STABLE => 'bo', ProjectChannel::DEV => 'app'] as $channel => $component) {
                $installer = new ComponentInstaller([$component => $upstream]);
                $installer->install($workspace, [$component], channel: $channel);
                self::assertFileDoesNotExist($workspace.'/'.$component.'/.git');
                self::assertDirectoryDoesNotExist($workspace.'/'.$component.'/.git');
                self::assertSame('native content', file_get_contents($workspace.'/'.$component.'/readme.txt'));
                $manifest = (new ComponentManifest($workspace.'/backend'))->get($component);
                self::assertNotNull($manifest);
                self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/D', $manifest['revision']);
                self::assertSame(hash('sha256', 'native content'), $manifest['files']['readme.txt']);
            }
        } finally {
            (new Process(['rm', '-rf', '--', $workspace]))->mustRun();
        }
    }

    public function testExistingDirectoryBlocksAllClones(): void
    {
        $dir = sys_get_temp_dir().'/symsvel-installer-'.bin2hex(random_bytes(8));
        mkdir($dir);
        mkdir($dir.'/front');
        file_put_contents($dir.'/front/keep.txt', 'safe');
        try {
            $installer = new ComponentInstaller();
            $this->expectException(\RuntimeException::class);
            try {
                $installer->install($dir, ['bo', 'front']);
            } finally {
                self::assertDirectoryDoesNotExist($dir.'/bo');
                self::assertSame('safe', file_get_contents($dir.'/front/keep.txt'));
            }
        } finally {
            unlink($dir.'/front/keep.txt');
            rmdir($dir.'/front');
            rmdir($dir);
        }
    }

    public function testUnknownComponentIsRejectedBeforeWriting(): void
    {
        $installer = new ComponentInstaller();
        $this->expectException(\InvalidArgumentException::class);
        $installer->install(sys_get_temp_dir(), ['unknown']);
    }
}
