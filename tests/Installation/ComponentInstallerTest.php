<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\ComponentInstaller;

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
