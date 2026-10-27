<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Module;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMigrationRemovalPlan;
use SymSvel\Tooling\Module\ModuleMigrationRegistry;

final class ModuleMigrationRemovalPlanTest extends TestCase
{
    public function testPendingRemovalRecoveryBlocksPlanningBeforeModuleDiscovery(): void
    {
        $root = sys_get_temp_dir().'/symsvel-removal-pending-'.bin2hex(random_bytes(6));
        mkdir($root.'/.symsvel', 0700, true);
        file_put_contents($root.'/.symsvel/module-removal-recovery.json', '{"state":"restore_required"}');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Désinstallation précédente à récupérer');
            (new ModuleMigrationRemovalPlan())->plan($root, 'users', true);
        } finally {
            unlink($root.'/.symsvel/module-removal-recovery.json');
            rmdir($root.'/.symsvel');
            rmdir($root);
        }
    }

    public function testCascadeReversesDependenciesAndRejectsTamperedMigrations(): void
    {
        $root = sys_get_temp_dir().'/symsvel-removal-'.bin2hex(random_bytes(6));
        $files = [];
        try {
            foreach (['Users' => [], 'UsersInfos' => ['users' => '*'], 'UserAddresses' => ['users' => '*']] as $name => $dependencies) {
                $slug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
                $directory = $root.'/modules/'.$slug;
                foreach (['back', 'docs/dev', 'Migration'] as $subdir) {
                    mkdir($directory.'/'.$subdir, 0775, true);
                }
                $manifest = ['schema' => 1, 'name' => $name, 'components' => ['back' => 'back'], 'documentation' => ['dev' => 'docs/dev'], 'dependencies' => $dependencies, 'extensions' => []];
                file_put_contents($directory.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
                $source = $directory.'/Migration/001.php';
                file_put_contents($source, '<?php // '.$name);
                $files[$slug.'/001.php'] = ['sha256' => hash_file('sha256', $source), 'doctrine' => 'DoctrineMigrations\\Version'.$name];
            }
            $registry = new ModuleMigrationRegistry();
            $registry->write($root, $files);
            $planner = new ModuleMigrationRemovalPlan();
            $plan = $planner->plan($root, 'users', true);
            self::assertSame('users', $plan['modules'][count($plan['modules']) - 1]);
            self::assertCount(3, $plan['versions']);
            self::assertSame('DoctrineMigrations\\VersionUsers', $plan['versions'][2]);
            self::assertSame(['user-addresses'], $planner->plan($root, 'user-addresses')['modules']);
            $this->expectException(\DomainException::class);
            $planner->plan($root, 'users');
        } finally {
            foreach (array_keys($files) as $key) {
                [$slug] = explode('/', $key);
                $directory = $root.'/modules/'.$slug;
                foreach (['Migration/001.php', 'module.json'] as $file) {
                    @unlink($directory.'/'.$file);
                }
                foreach (['Migration', 'docs/dev', 'docs', 'back'] as $subdir) {
                    @rmdir($directory.'/'.$subdir);
                }
                @rmdir($directory);
            }
            @unlink($root.'/.symsvel/migrations.json');
            @rmdir($root.'/.symsvel');
            @rmdir($root.'/modules');
            @rmdir($root);
        }
    }
    public function testSymlinkedReceiptDirectoryIsRejected(): void
    {
        $root = sys_get_temp_dir().'/symsvel-receipts-link-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        mkdir($root.'/outside', 0700);
        symlink($root.'/outside', $root.'/.symsvel');
        try {
            $this->expectException(\RuntimeException::class);
            (new ModuleMigrationRegistry())->read($root);
        } finally {
            unlink($root.'/.symsvel');
            rmdir($root.'/outside');
            rmdir($root);
        }
    }

}
