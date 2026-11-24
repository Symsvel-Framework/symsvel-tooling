<?php

declare(strict_types=1);

$backend = dirname(__DIR__);
require $backend.'/vendor/autoload.php';

if (!class_exists(\SymSvel\Tooling\Module\ComposerInterfaceSynchronizer::class)) {
    exit(0);
}

exit((new \SymSvel\Tooling\Module\ComposerInterfaceSynchronizer())->run($backend));
