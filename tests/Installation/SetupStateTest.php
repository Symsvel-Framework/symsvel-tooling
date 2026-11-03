<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Installation\SetupState;

final class SetupStateTest extends TestCase
{
    public function testLifecycleIsExplicitAndCannotBeReopened(): void
    {
        $root = sys_get_temp_dir().'/symsvel-setup-'.bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $state = new SetupState();
            self::assertNull($state->read($root));
            $state->initialize($root);
            self::assertSame(SetupState::PENDING, $state->read($root));
            $state->transition($root, SetupState::PENDING, SetupState::RUNNING);
            self::assertSame(SetupState::RUNNING, $state->read($root));
            $state->transition($root, SetupState::RUNNING, SetupState::COMPLETED);
            $state->initialize($root);
            self::assertSame(SetupState::COMPLETED, $state->read($root));
            $this->expectException(\DomainException::class);
            $state->transition($root, SetupState::COMPLETED, SetupState::PENDING);
        } finally {
            @unlink($root.'/.symsvel/setup.json');
            @rmdir($root.'/.symsvel');
            @rmdir($root);
        }
    }
}
