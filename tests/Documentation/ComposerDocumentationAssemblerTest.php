<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Documentation;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Command\SymSvelDocumentationSyncCommand;
use SymSvel\Tooling\Documentation\ComposerDocumentationAssembler;
use SymSvel\Tooling\Module\ModuleMaker;
use Symfony\Component\Console\Tester\CommandTester;

final class ComposerDocumentationAssemblerTest extends TestCase
{
    private string $workspace;
    private string $backend;
    private string $module;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-docs-'.bin2hex(random_bytes(6));
        $this->backend = $this->workspace.'/backend';
        mkdir($this->backend.'/vendor/composer', 0775, true);
        mkdir($this->backend.'/vendor/symsvel-framework', 0775, true);
        mkdir($this->backend.'/docs/adr', 0775, true);
        mkdir($this->workspace.'/bo/docs', 0775, true);
        mkdir($this->workspace.'/admin-doc/docs', 0775, true);
        mkdir($this->workspace.'/user-doc/docs', 0775, true);
        file_put_contents($this->workspace.'/admin-doc/docs/aide.md', '# Paramétrer Choralys');
        file_put_contents($this->workspace.'/user-doc/docs/aide.md', '# Utiliser Choralys');
        file_put_contents($this->backend.'/docs/adr/0001.md', "# Architecture interne\nAucun secret.");
        file_put_contents($this->workspace.'/bo/docs/bo.md', '# BO hôte');
        $this->module = (new ModuleMaker())->create($this->workspace, 'catalogue', 'symsvel-framework', 'bo');
        file_put_contents($this->module.'/docs/admin/guide.md', "# Gestion du catalogue\nLecture seule.");
        symlink($this->module, $this->backend.'/vendor/symsvel-framework/module-catalogue');
        $this->metadata(true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            if ($item->isFile() || $item->isLink()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($this->workspace);
    }

    public function testMaintenanceCommandPublishesPrivateScopedIndexIdempotently(): void
    {
        $cmd = new CommandTester(new SymSvelDocumentationSyncCommand($this->backend));
        self::assertSame(0, $cmd->execute([]));
        $path = $this->backend.'/var/symsvel/documentation.json';
        self::assertFileExists($path);
        self::assertFileDoesNotExist($this->backend.'/public/documentation.json');

        $doc = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(1, $doc['schema']);
        self::assertSame('symsvel-tooling', $doc['generated_by']);
        self::assertSame(hash('sha256', self::pagesJson($doc['pages'])), $doc['checksum']);
        $pages = array_column($doc['pages'], null, 'id');
        self::assertArrayHasKey('admin:module:catalogue:guide.md', $pages);
        self::assertArrayHasKey('dev:module:catalogue:index.md', $pages);
        self::assertArrayHasKey('dev:host:back:adr/0001.md', $pages);
        self::assertArrayHasKey('dev:host:bo:bo.md', $pages);
        self::assertArrayHasKey('admin:host:admin-doc:aide.md', $pages);
        self::assertArrayHasKey('user:host:user-doc:aide.md', $pages);
        self::assertSame('Paramétrer Choralys', $pages['admin:host:admin-doc:aide.md']['title']);
        self::assertSame('Utiliser Choralys', $pages['user:host:user-doc:aide.md']['title']);
        self::assertSame('Gestion du catalogue', $pages['admin:module:catalogue:guide.md']['title']);
        self::assertSame('Lecture seule.', trim(explode("\n", $pages['admin:module:catalogue:guide.md']['markdown'])[1]));

        $before = file_get_contents($path);
        $assembler = new ComposerDocumentationAssembler();
        self::assertFalse($assembler->assemble($this->backend)['changed']);
        self::assertSame($before, file_get_contents($path));

        file_put_contents($this->module.'/docs/admin/guide.md', "# Nouveau guide\nTexte modifié.");
        self::assertTrue($assembler->assemble($this->backend)['changed']);
        self::assertStringContainsString('Nouveau guide', (string) file_get_contents($path));

        unlink($this->backend.'/vendor/symsvel-framework/module-catalogue');
        $this->metadata(false);
        self::assertTrue($assembler->assemble($this->backend)['changed']);
        $after = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertNotContains('admin:module:catalogue:guide.md', array_column($after['pages'], 'id'));
        self::assertContains('dev:host:back:adr/0001.md', array_column($after['pages'], 'id'));
    }

    public function testModifiedPublicationIsNeverOverwritten(): void
    {
        $assembler = new ComposerDocumentationAssembler();
        $assembler->assemble($this->backend);
        $path = $this->backend.'/var/symsvel/documentation.json';
        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $data['pages'][0]['markdown'] = 'local modification';
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modifiée localement');
        $assembler->assemble($this->backend);
    }

    public function testSymlinkInModuleDocsIsRejectedWithoutCreatingOutput(): void
    {
        symlink($this->backend.'/docs/adr/0001.md', $this->module.'/docs/admin/linked.md');
        try {
            (new ComposerDocumentationAssembler())->assemble($this->backend);
            self::fail('Symlinked Markdown must not be copied into the artifact.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Lien symbolique', $error->getMessage());
            self::assertFileDoesNotExist($this->backend.'/var/symsvel/documentation.json');
        }
    }

    private function metadata(bool $installed): void
    {
        $packages = $installed ? [
            ['name' => 'symsvel-framework/module-catalogue',
             'install-path' => '../symsvel-framework/module-catalogue'],
        ] : [];
        file_put_contents($this->backend.'/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    }

    /** @param array<int, mixed> $pages */
    private static function pagesJson(array $pages): string
    {
        return json_encode($pages,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
