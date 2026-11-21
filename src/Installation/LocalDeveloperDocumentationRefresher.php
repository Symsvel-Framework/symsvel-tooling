<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

/**
 * Refreshes optional, development-only MkDocs artefacts after a transaction commits.
 * Documentation is derived output: an MkDocs failure must never falsely claim
 * a completed Composer operation was rolled back.
 */
final class LocalDeveloperDocumentationRefresher
{
    /** @param null|\Closure(string):void $build */
    public function __construct(private readonly ?\Closure $build = null)
    {
    }

    /** @param null|callable(string):void $progress */
    public function afterSuccess(string $backend, ?callable $progress = null): bool
    {
        // The bootstrap already rebuilds once after all native modules.
        // No documentation work on the VPS, even if an old dev-doc was copied.
        if (getenv('SYMSVEL_DEFER_BUILDS') === '1' || getenv('APP_ENV') === 'prod') {
            return false;
        }
        $workspace = dirname($backend);
        $docs = $workspace.'/dev-doc';
        if (!is_dir($docs) || is_link($docs)) {
            return false;
        }
        if (!is_file($docs.'/mkdocs.yml') || is_link($docs.'/mkdocs.yml')
            || !is_dir($docs.'/docs') || is_link($docs.'/docs')) {
            if ($progress !== null) {
                $progress('AVERTISSEMENT : documentation locale présente mais incomplète ; lancer symsvel documentation:build-dev après vérification.');
            }
            return false;
        }
        try {
            if ($this->build !== null) {
                ($this->build)($workspace);
            } else {
                (new DevDocumentationBuilder())->build($workspace);
            }
            if ($progress !== null) {
                $progress('Documentation développeur locale actualisée (dev-doc/site/).');
            }
            return true;
        } catch (\Throwable $error) {
            if ($progress !== null) {
                $progress('AVERTISSEMENT : opération réussie, mais build dev-doc échoué : '.$error->getMessage()
                    .'. Réessayer avec symsvel documentation:build-dev.');
            }
            return false;
        }
    }
}
