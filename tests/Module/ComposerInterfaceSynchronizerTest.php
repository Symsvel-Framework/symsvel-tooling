<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ComposerInterfaceSynchronizer;

final class ComposerInterfaceSynchronizerTest extends TestCase
{
    public function testProductionAndFreshCloneDoNotLaunchCommands(): void
    {
        $root = sys_get_temp_dir().'/symsvel-interface-'.bin2hex(random_bytes(8));
        mkdir($root.'/backend', 0777, true);
        mkdir($root.'/app');
        $previous = getenv('APP_ENV');
        try {
            putenv('APP_ENV=dev');
            $this->expectOutputRegex('/no interface sources.*disabled in production/s');
            self::assertSame(0, (new ComposerInterfaceSynchronizer())->run($root.'/backend'));
            file_put_contents($root.'/app/package.json', '{}');
            putenv('APP_ENV=prod');
            self::assertSame(0, (new ComposerInterfaceSynchronizer())->run($root.'/backend'));
        } finally {
            putenv($previous === false ? 'APP_ENV' : 'APP_ENV='.$previous);
            if (is_file($root.'/app/package.json')) {
                unlink($root.'/app/package.json');
            }
            rmdir($root.'/app');
            rmdir($root.'/backend');
            rmdir($root);
        }
    }
}
