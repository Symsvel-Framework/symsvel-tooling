<?php
declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

use Symfony\Component\Process\Process;

final class WorkspaceInitializer
{
    public function initialize(string $root): void
    {
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
        $this->writeIfMissing($secrets.'/.env.example', "# Variables privées SymSvel, à compléter selon les besoins\n");
        $this->writeIfMissing($secrets.'/.gitignore', "*\n!.gitignore\n!.env.example\n");
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
