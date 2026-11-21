<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\Installation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymSvel\Tooling\Installation\LocalDeveloperDocumentationRefresher;

final class LocalDeveloperDocumentationRefresherTest extends TestCase
{
    public function testOnlyLocalWorkspaceWithDocsTriggersAutomaticBuild(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-local-doc-refresh-'.bin2hex(random_bytes(7));
        mkdir($workspace.'/backend', 0775, true);
        $previousEnv = getenv('APP_ENV');
        $previousDefer = getenv('SYMSVEL_DEFER_BUILDS');
        putenv('APP_ENV=dev');
        putenv('SYMSVEL_DEFER_BUILDS');
        $calls = [];
        $messages = [];
        $refresher = new LocalDeveloperDocumentationRefresher(
            static function (string $root) use (&$calls): void { $calls[] = $root; },
        );
        try {
            // A VPS workspace does not contain dev-doc.
            self::assertFalse($refresher->afterSuccess($workspace.'/backend'));
            self::assertSame([], $calls);

            mkdir($workspace.'/dev-doc/docs', 0775, true);
            file_put_contents($workspace.'/dev-doc/mkdocs.yml', 'site_name: Test');
            foreach (['require', 'update', 'remove'] as $operation) {
                self::assertTrue($refresher->afterSuccess($workspace.'/backend', static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }), $operation);
            }
            self::assertSame([$workspace, $workspace, $workspace], $calls);
            self::assertCount(3, $messages);
            self::assertStringContainsString('actualisée', $messages[0]);

            // Initial bootstrap is handled by one explicit post-install build.
            putenv('SYMSVEL_DEFER_BUILDS=1');
            self::assertFalse($refresher->afterSuccess($workspace.'/backend'));
            self::assertCount(3, $calls);
            putenv('SYMSVEL_DEFER_BUILDS');

            // Never run on production even if a dev-doc was mistakenly copied.
            putenv('APP_ENV=prod');
            self::assertFalse($refresher->afterSuccess($workspace.'/backend'));
            self::assertCount(3, $calls);
        } finally {
            $previousEnv === false ? putenv('APP_ENV') : putenv('APP_ENV='.$previousEnv);
            $previousDefer === false ? putenv('SYMSVEL_DEFER_BUILDS') : putenv('SYMSVEL_DEFER_BUILDS='.$previousDefer);
            (new Process(['rm', '-rf', '--', $workspace]))->mustRun();
        }
    }

    public function testDocumentationFailureIsExplicitlyNonFatalAfterCommittedTransaction(): void
    {
        $workspace = sys_get_temp_dir().'/symsvel-local-doc-refresh-'.bin2hex(random_bytes(7));
        mkdir($workspace.'/backend', 0775, true);
        mkdir($workspace.'/dev-doc/docs', 0775, true);
        file_put_contents($workspace.'/dev-doc/mkdocs.yml', 'site_name: Test');
        $previousEnv = getenv('APP_ENV');
        $previousDefer = getenv('SYMSVEL_DEFER_BUILDS');
        putenv('APP_ENV=dev');
        putenv('SYMSVEL_DEFER_BUILDS');
        $messages = [];
        try {
            $refresher = new LocalDeveloperDocumentationRefresher(
                static function (): void { throw new \RuntimeException('MkDocs unavailable'); },
            );
            self::assertFalse($refresher->afterSuccess($workspace.'/backend', static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }));
            self::assertCount(1, $messages);
            self::assertStringContainsString('opération réussie', $messages[0]);
            self::assertStringContainsString('MkDocs unavailable', $messages[0]);
        } finally {
            $previousEnv === false ? putenv('APP_ENV') : putenv('APP_ENV='.$previousEnv);
            $previousDefer === false ? putenv('SYMSVEL_DEFER_BUILDS') : putenv('SYMSVEL_DEFER_BUILDS='.$previousDefer);
            (new Process(['rm', '-rf', '--', $workspace]))->mustRun();
        }
    }
}
