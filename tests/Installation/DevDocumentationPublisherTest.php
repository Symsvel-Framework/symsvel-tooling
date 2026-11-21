<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Installation\DevDocumentationPublisher;
use SymSvel\Tooling\Installation\WorkspaceInitializer;
use SymSvel\Tooling\Module\ModuleMaker;

final class DevDocumentationPublisherTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/symsvel-doc-publisher-'.bin2hex(random_bytes(8));
        mkdir($this->workspace.'/backend/docs/guides', 0775, true);
        mkdir($this->workspace.'/backend/vendor/composer', 0775, true);
        mkdir($this->workspace.'/backend/vendor/symsvel-framework/core/docs', 0775, true);
        mkdir($this->workspace.'/backend/vendor/symsvel-framework/tooling/docs', 0775, true);
        mkdir($this->workspace.'/bo/docs', 0775, true);
        (new WorkspaceInitializer())->initialize($this->workspace);
        file_put_contents($this->workspace.'/backend/docs/guides/setup.md', "# Installer le Back\n\nLe vrai guide.\n");
        file_put_contents($this->workspace.'/backend/vendor/symsvel-framework/core/docs/core.md', '# Contrat Core');
        file_put_contents($this->workspace.'/backend/vendor/symsvel-framework/tooling/docs/tooling.md', '# Tooling');
        file_put_contents($this->workspace.'/bo/docs/bo.md', '# Composants BO');
        file_put_contents($this->workspace.'/admin-doc/docs/secret.md', '# Privé administration');
        file_put_contents($this->workspace.'/user-doc/docs/secret.md', '# Privé utilisateur');

        $module = (new ModuleMaker())->create($this->workspace, 'catalogue', 'symsvel-framework', 'bo');
        file_put_contents($module.'/docs/admin/secret.md', '# Module administration privé');
        symlink($module, $this->workspace.'/backend/vendor/symsvel-framework/module-catalogue');
        file_put_contents(
            $this->workspace.'/backend/vendor/composer/installed.json',
            json_encode(['packages' => [
                ['name' => 'symsvel-framework/module-catalogue',
                 'install-path' => '../symsvel-framework/module-catalogue'],
            ]], JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->workspace]))->mustRun();
    }

    public function testDeveloperSiteContainsRealSourcesAndNeverAdminUserPages(): void
    {
        $publisher = new DevDocumentationPublisher();
        $result = $publisher->synchronize($this->workspace);
        self::assertGreaterThanOrEqual(5, $result['pages']);

        $generated = $this->workspace.'/dev-doc/docs/symsvel';
        self::assertStringContainsString('Le vrai guide.', (string) file_get_contents($generated.'/framework/back/guides/setup.md'));
        self::assertFileExists($generated.'/framework/core/core.md');
        self::assertFileExists($generated.'/framework/tooling/tooling.md');
        self::assertFileExists($generated.'/framework/bo/bo.md');
        self::assertFileExists($generated.'/modules/catalogue/index.md');
        self::assertStringContainsString('Installer le Back', (string) file_get_contents($generated.'/index.md'));
        self::assertStringContainsString('Parcourir les guides', (string) file_get_contents($this->workspace.'/dev-doc/docs/index.md'));
        self::assertStringNotContainsString('À compléter.', (string) file_get_contents($this->workspace.'/dev-doc/docs/index.md'));
        self::assertStringNotContainsString('nav:', (string) file_get_contents($this->workspace.'/dev-doc/mkdocs.yml'));
        self::assertStringContainsString('/docs/symsvel/', (string) file_get_contents($this->workspace.'/dev-doc/.gitignore'));
        self::assertFileDoesNotExist($generated.'/modules/catalogue/secret.md');
        self::assertStringNotContainsString('Privé administration', (string) file_get_contents($generated.'/index.md'));
        self::assertStringNotContainsString('Privé utilisateur', (string) file_get_contents($generated.'/index.md'));

        file_put_contents($this->workspace.'/backend/docs/guides/setup.md', '# Mise à jour du guide');
        $publisher->synchronize($this->workspace);
        self::assertStringContainsString('Mise à jour du guide', (string) file_get_contents($generated.'/framework/back/guides/setup.md'));
    }

    public function testNeverOverwritesCustomLandingPageOrReservedGeneratedDirectory(): void
    {
        $publisher = new DevDocumentationPublisher();
        file_put_contents($this->workspace.'/dev-doc/docs/index.md', "# Documentation personnalisée\n");
        $publisher->synchronize($this->workspace);
        self::assertSame("# Documentation personnalisée\n", file_get_contents($this->workspace.'/dev-doc/docs/index.md'));
        $generated = $this->workspace.'/dev-doc/docs/symsvel';
        file_put_contents($generated.'/.generated-by-symsvel', 'untrusted');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('personnalisé');
        $publisher->synchronize($this->workspace);
    }

    public function testRefusesSymbolicGeneratedDirectory(): void
    {
        symlink($this->workspace.'/admin-doc', $this->workspace.'/dev-doc/docs/symsvel');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolique');
        (new DevDocumentationPublisher())->synchronize($this->workspace);
    }
}
