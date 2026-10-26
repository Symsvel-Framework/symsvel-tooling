<?php
declare(strict_types=1);
namespace SymSvel\Tooling\Tests\Module;
use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleMaker;
use SymSvel\Tooling\Module\ModuleRemovalPlanner;
final class ModuleRemovalPlannerTest extends TestCase
{
    public function testCascade(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-removal-'.bin2hex(random_bytes(6));
        mkdir($workspace);
        try {
            $maker = new ModuleMaker();
            $maker->create($workspace, 'users');
            $child = $maker->create($workspace, 'user-infos');
            $file = $child.'/module.json';
            $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $manifest['dependencies'] = ['users' => '^1.0'];
            file_put_contents($file, json_encode($manifest));
            $planner = new ModuleRemovalPlanner();
            try {
                $planner->plan($workspace, 'users');
                self::fail('Missing dependency guard');
            } catch (\DomainException $e) {
                self::assertStringContainsString('user-infos', $e->getMessage());
            }
            self::assertSame(['user-infos', 'users'], $planner->plan($workspace, 'users', true)['removal']);
            self::assertSame(['user-infos'], $planner->plan($workspace, 'user-infos')['removal']);
        } finally {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($workspace);
        }
    }
}
