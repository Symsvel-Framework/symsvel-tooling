<?php
declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

final class WorkspaceInitializer
{
    public function initialize(string $root): void
    {
        (new SetupState())->initialize($root);
        $docs = $root.'/dev-doc';
        $secrets = $root.'/secrets';
        foreach ([$docs.'/docs', $secrets] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Impossible de créer '.$dir);
            }
        }
        $this->writeIfMissing($docs.'/mkdocs.yml', "site_name: SymSvel - Documentation développeur\ndocs_dir: docs\nsite_dir: site\nnav:\n  - Accueil: index.md\n");
        $this->writeIfMissing($docs.'/docs/index.md', "# Documentation développeur SymSvel\n\nÀ compléter.\n");
        $this->writeIfMissing($docs.'/.gitignore', "/.venv/\n/site/\n");
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
