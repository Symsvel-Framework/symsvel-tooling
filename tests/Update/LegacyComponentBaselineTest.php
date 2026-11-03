<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Update\LegacyComponentBaseline;

final class LegacyComponentBaselineTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/symsvel-legacy-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->root]))->mustRun();
    }

    public function testRecoversOriginalNativeFilesDespiteCommittedProjectChanges(): void
    {
        $remote = $this->root.'/remote.git';
        $project = $this->root.'/app';
        $this->git(['init', '--bare', $remote], $this->root);
        $this->git(['clone', $remote, $project], $this->root);
        $this->git(['config', 'user.email', 'ci@example.test'], $project);
        $this->git(['config', 'user.name', 'CI'], $project);
        file_put_contents($project.'/README.md', 'native');
        $this->git(['add', '.'], $project);
        $this->git(['commit', '-m', 'native'], $project);
        $this->git(['push', '-u', 'origin', 'HEAD'], $project);
        $baseline = $this->head($project);

        mkdir($project.'/src/routes/ct-local', 0775, true);
        file_put_contents($project.'/src/routes/ct-local/+page.svelte', 'project route');
        $this->git(['add', '.'], $project);
        $this->git(['commit', '-m', 'project customization'], $project);
        $head = $this->head($project);

        $this->git(['remote', 'set-url', 'origin', 'git@github.com:Symsvel-Framework/symsvel-app.git'], $project);
        $result = (new LegacyComponentBaseline())->recover($project, 'app');

        self::assertSame($baseline, $result['revision']);
        self::assertSame(['README.md' => hash('sha256', 'native')], $result['files']);
        self::assertSame($head, $this->head($project));
        self::assertSame('project route', file_get_contents($project.'/src/routes/ct-local/+page.svelte'));
    }

    public function testRefusesUnverifiedOriginWithoutChangingFiles(): void
    {
        $project = $this->root.'/app';
        mkdir($project);
        $this->git(['init'], $project);
        file_put_contents($project.'/README.md', 'project');
        $this->git(['remote', 'add', 'origin', 'git@github.com:another-owner/choralys-app.git'], $project);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('origin');
        try {
            (new LegacyComponentBaseline())->recover($project, 'app');
        } finally {
            self::assertSame('project', file_get_contents($project.'/README.md'));
        }
    }

    /** @param list<string> $args */
    private function git(array $args, string $cwd): void
    {
        (new Process(['git', ...$args], $cwd))->mustRun();
    }

    private function head(string $cwd): string
    {
        return trim((new Process(['git', 'rev-parse', 'HEAD'], $cwd))->mustRun()->getOutput());
    }
}
