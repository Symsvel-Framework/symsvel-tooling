<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Command\SymSvelCleanupCommand;
use SymSvel\Tooling\SymSvelToolingBundle;
use SymSvel\Tooling\Update\WorkspaceCleanupRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class CleanupCommandRegistrationTest extends TestCase
{
    public function testBundleRegistersExecutableCleanupCommand(): void
    {
        $builder = new ContainerBuilder();
        $loader = new PhpFileLoader($builder, new FileLocator(__DIR__));
        $instanceof = [];
        $configurator = new ContainerConfigurator($builder, $loader, $instanceof, __DIR__, __FILE__, 'dev');
        (new SymSvelToolingBundle())->loadExtension([], $configurator, $builder);

        self::assertTrue($builder->hasDefinition(SymSvelCleanupCommand::class));
        $definition = $builder->getDefinition(SymSvelCleanupCommand::class);
        self::assertTrue($definition->isAutowired());
        self::assertTrue($definition->isAutoconfigured());
        $attribute = (new \ReflectionClass(SymSvelCleanupCommand::class))->getAttributes(AsCommand::class);
        self::assertCount(1, $attribute);
        self::assertSame('symsvel:cleanup', $attribute[0]->newInstance()->name);

        $root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/symsvel-cleanup-command-'.bin2hex(random_bytes(8));
        $checkpoint = $root.'/.symsvel/transactions/'.str_repeat('a', 32);
        mkdir($checkpoint, 0700, true);
        file_put_contents($checkpoint.'/snapshot.txt', 'completed');
        try {
            (new WorkspaceCleanupRegistry($root))->enqueue([$checkpoint]);
            // Compile the actual registered definition in isolation: the other
            // commands require application services such as Doctrine.
            foreach (array_keys($builder->getDefinitions()) as $id) {
                if (!in_array($id, ['service_container', SymSvelCleanupCommand::class], true)) {
                    $builder->removeDefinition($id);
                }
            }
            $builder->setParameter('kernel.project_dir', $root);
            $definition->setPublic(true);
            $builder->compile();
            $command = $builder->get(SymSvelCleanupCommand::class);
            self::assertInstanceOf(SymSvelCleanupCommand::class, $command);
            self::assertSame('symsvel:cleanup', $command->getName());
            $tester = new CommandTester($command);
            self::assertSame(0, $tester->execute([]));
            self::assertDirectoryDoesNotExist($checkpoint);
            self::assertSame([], json_decode((string) file_get_contents($root.'/.symsvel/cleanup-pending.json'), true));
        } finally {
            if (is_dir($checkpoint)) {
                if (is_file($checkpoint.'/snapshot.txt')) {
                    unlink($checkpoint.'/snapshot.txt');
                }
                rmdir($checkpoint);
            }
            foreach (['cleanup.lock', 'cleanup-pending.json'] as $name) {
                if (is_file($root.'/.symsvel/'.$name)) {
                    unlink($root.'/.symsvel/'.$name);
                }
            }
            rmdir($root.'/.symsvel/transactions');
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }
}
