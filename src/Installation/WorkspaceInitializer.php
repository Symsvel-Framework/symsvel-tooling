<?php
declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

use Symfony\Component\Process\Process;

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
        $sharedGroup = getenv('SYMSVEL_SETUP_SHARED_GROUP');
        if (is_string($sharedGroup) && $sharedGroup !== '') {
            (new SetupGroupAccess())->configure($root, $sharedGroup);
        }
        $this->writeIfMissing($secrets.'/.env.example', "# Variables privées SymSvel, à compléter selon les besoins\n");
        $venv = $docs.'/.venv';
        if (!is_file($venv.'/bin/mkdocs')) {
            $this->run(['python3', '-m', 'venv', $venv], $root);
            $this->run([$venv.'/bin/python', '-m', 'pip', 'install', 'mkdocs'], $root);
        }
        $this->run([$venv.'/bin/mkdocs', 'build', '--clean', '--config-file', $docs.'/mkdocs.yml'], $docs);
        if (!is_file($docs.'/site/index.html')) {
            throw new \RuntimeException('Build MkDocs incomplet : site/index.html absent.');
        }
    }

    private function writeIfMissing(string $path, string $content): void
    {
        if (!file_exists($path) && file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Impossible de créer '.$path);
        }
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(600);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: trim($process->getOutput()));
        }
    }
}
