<?php
declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

final class WorkspaceInitializer
{
    public function initialize(string $root): void
    {
        (new SetupState())->initialize($root);
        $docs = $root.'/dev-doc';
        $adminDocs = $root.'/admin-doc';
        $userDocs = $root.'/user-doc';
        $secrets = $root.'/secrets';
        foreach ([$docs.'/docs', $adminDocs.'/docs', $userDocs.'/docs', $secrets] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Impossible de créer '.$dir);
            }
        }
        $this->writeIfMissing($docs.'/mkdocs.yml', "site_name: SymSvel - Documentation développeur\nsite_url: ''\ndocs_dir: docs\nsite_dir: site\nuse_directory_urls: false\nplugins: []\nnav:\n  - Accueil: index.md\n");
        $this->writeIfMissing($docs.'/docs/index.md', "# Documentation développeur SymSvel\n\nÀ compléter.\n");
        $this->writeIfMissing($docs.'/requirements.txt', "mkdocs>=1.6,<2\n");
        // Project-specific admin and user documentation is kept outside
        // BO/App source trees and is not exposed as an independent website.
        // Tooling assembles it into the private authenticated documentation index.
        $this->writeIfMissing($adminDocs.'/docs/index.md', "# Documentation administrateur\n\nÀ compléter pour ce projet.\n");
        $this->writeIfMissing($userDocs.'/docs/index.md', "# Documentation utilisateur\n\nÀ compléter pour ce projet.\n");

        $documentationIgnore = <<<'GITIGNORE'
# Python virtual environment and caches
/.venv/
__pycache__/
*.py[cod]
/.pytest_cache/
/.mypy_cache/
/.ruff_cache/

# Documentation build (never version generated output)
/site/

# Operating systems
.DS_Store
Thumbs.db
.AppleDouble
.LSOverride
._*

# CI/CD is application-specific
/.github/workflows/

# Reports
/playwright-report/
/blob-report/
/coverage/

# IDE
/.idea/
/.vscode/*
!/.vscode/extensions.json
!/.vscode/settings.json

# Temporary files
*.log
*.tmp
*.swp
*~
GITIGNORE;
        foreach ([$docs, $adminDocs, $userDocs] as $documentationRoot) {
            $this->writeIfMissing($documentationRoot.'/.gitignore', $documentationIgnore."\n");
        }
        // The one-time setup credential is stored outside the public backend.
        // Never regenerate it when resuming an interrupted installation.
        $setupToken = $root.'/.symsvel/setup-token';
        if (is_link(dirname($setupToken)) || is_link($setupToken)) {
            throw new \RuntimeException('Chemin symbolique du jeton de setup interdit.');
        }
        if (!file_exists($setupToken)) {
            $temporary = tempnam(dirname($setupToken), '.setup-token-');
            if ($temporary === false) {
                throw new \RuntimeException('Impossible de préparer le jeton de setup.');
            }
            try {
                $token = bin2hex(random_bytes(32));
                if (!chmod($temporary, 0600)
                    || file_put_contents($temporary, $token."\n", LOCK_EX) === false
                    || !rename($temporary, $setupToken)) {
                    throw new \RuntimeException('Impossible de créer le jeton de setup.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        } elseif (!is_file($setupToken)) {
            throw new \RuntimeException('Jeton de setup existant invalide.');
        }
        $this->writeIfMissing($secrets.'/.env.example', "# Variables privées SymSvel, à compléter selon les besoins\n");

    }

    private function writeIfMissing(string $path, string $content): void
    {
        if (!file_exists($path) && file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Impossible de créer '.$path);
        }
    }

}
