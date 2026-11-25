<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

use SymSvel\Tooling\Documentation\ComposerDocumentationAssembler;

/**
 * Copies validated developer Markdown into a reserved, generated MkDocs subtree.
 * Neither admin nor user documentation is exported to a static site.
 */
final class DevDocumentationPublisher
{
    private const GENERATED_MARKER = '.generated-by-symsvel';
    private const SECTIONS = [
        '01-getting-started' => 'Getting Started',
        '02-development' => 'Developing your application',
        '03-modules' => 'Modules',
        '04-updating' => 'Updating your application',
        '05-deployment' => 'Deploying your application',
        '06-reference' => 'Reference',
    ];
    private const INITIAL_PLACEHOLDER = "# Documentation développeur SymSvel\n\nÀ compléter.\n";

    public function __construct(
        private readonly ComposerDocumentationAssembler $assembler = new ComposerDocumentationAssembler(),
    ) {
    }

    /** @return array{pages: int, path: string} */
    public function synchronize(string $workspace): array
    {
        $workspace = realpath($workspace) ?: throw new \RuntimeException('Workspace documentaire introuvable.');
        $root = $workspace.'/dev-doc';
        $docs = $root.'/docs';
        $generated = $docs.'/symsvel';
        if (!is_dir($root) || is_link($root) || !is_dir($docs) || is_link($docs)
            || is_link($generated) || (file_exists($generated) && !is_dir($generated))) {
            throw new \RuntimeException('Destination MkDocs invalide ou symbolique.');
        }

        $backend = $workspace.'/backend';
        $pages = is_dir($backend) ? $this->assembler->developerPages($backend) : [];
        if ($pages === [] && is_dir($backend.'/vendor/symsvel-framework')) {
            throw new \RuntimeException('Aucune source documentaire développeur détectée dans le framework installé.');
        }

        $stage = $docs.'/.symsvel-docs-'.bin2hex(random_bytes(6));
        if (!mkdir($stage, 0775)) {
            throw new \RuntimeException('Impossible de préparer les pages MkDocs.');
        }
        try {
            $index = [
                '# SymSvel Framework',
                '',
                'Installez votre environnement, développez votre application et déployez-la.',
                '',
            ];
            $sections = array_fill_keys(array_keys(self::SECTIONS), []);
            $destinations = [];
            foreach ($pages as $page) {
                if ($page['source'] === 'module') {
                    $section = '03-modules';
                    $relative = $section.'/'.$page['owner'].'/'.$page['file'];
                } else {
                    $section = explode('/', $page['file'], 2)[0];
                    if (!isset(self::SECTIONS[$section]) || !str_contains($page['file'], '/')) {
                        throw new \RuntimeException('Rubrique de guide inconnue : '.$page['file']);
                    }
                    $relative = $page['file'];
                    if ($relative === $section.'/index.md') {
                        throw new \RuntimeException('Index de rubrique réservé : '.$relative);
                    }
                }
                if (isset($destinations[$relative])) {
                    throw new \RuntimeException('Collision de guides développeur : '.$relative);
                }
                $destinations[$relative] = true;
                $destination = $stage.'/'.$relative;
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Impossible de créer '.$directory);
                }
                if (file_put_contents($destination, $page['markdown'], LOCK_EX) === false) {
                    throw new \RuntimeException('Impossible de publier '.$relative);
                }
                $title = str_replace(["\r", "\n", '[', ']'], [' ', ' ', '', ''], $page['title']);
                $sections[$section][$relative] = $title;
            }
            foreach (self::SECTIONS as $section => $title) {
                if ($sections[$section] === []) {
                    continue;
                }
                ksort($sections[$section], SORT_STRING);
                $index[] = '## '.$title;
                $index[] = '';
                $sectionIndex = ['# '.$title, ''];
                foreach ($sections[$section] as $relative => $pageTitle) {
                    $href = implode('/', array_map('rawurlencode', explode('/', $relative)));
                    $index[] = '- ['.$pageTitle.']('.$href.')';
                    $sectionIndex[] = '- ['.$pageTitle.']('.substr($href, strlen($section) + 1).')';
                }
                $index[] = '';
                $sectionIndex[] = '';
                if (file_put_contents($stage.'/'.$section.'/index.md', implode("\n", $sectionIndex), LOCK_EX) === false) {
                    throw new \RuntimeException('Impossible de publier l’index de rubrique.');
                }
            }
            if ($pages === []) {
                $index[] = 'Aucun guide disponible. Installez les composants de la distribution correspondante.';
            }
            $index[] = '';
            if (file_put_contents($stage.'/index.md', implode("\n", $index), LOCK_EX) === false
                || file_put_contents($stage.'/'.self::GENERATED_MARKER, "SymSvel Tooling - generated docs\n", LOCK_EX) === false) {
                throw new \RuntimeException('Impossible de créer l’index documentaire généré.');
            }

            if (is_dir($generated)) {
                $marker = $generated.'/'.self::GENERATED_MARKER;
                if (!is_file($marker) || is_link($marker)
                    || file_get_contents($marker) !== "SymSvel Tooling - generated docs\n") {
                    throw new \RuntimeException('Répertoire docs/symsvel personnalisé : refus de le remplacer.');
                }
                $this->removeGeneratedTree($generated);
            }
            if (!rename($stage, $generated)) {
                throw new \RuntimeException('Publication des guides développeur impossible.');
            }
        } finally {
            if (is_dir($stage)) {
                $this->removeGeneratedTree($stage);
            }
        }

        $home = $docs.'/index.md';
        if (!is_link($home) && (!file_exists($home) || file_get_contents($home) === self::INITIAL_PLACEHOLDER)) {
            file_put_contents($home,
                "# Documentation développeur SymSvel\n\n".
                "Du premier projet au déploiement : guides pratiques et références pour les composants installés.\n\n".
                "**[Parcourir les guides et références SymSvel](symsvel/index.md)**\n",
                LOCK_EX,
            );
        }

        // Older generated workspaces explicitly restrict the nav to one page.
        // Upgrade only that exact bootstrap config, never a customized one.
        $configuration = $root.'/mkdocs.yml';
        $legacy = "site_name: SymSvel - Documentation développeur\nsite_url: ''\ndocs_dir: docs\nsite_dir: site\nuse_directory_urls: false\nplugins: []\nnav:\n  - Accueil: index.md\n";
        if (is_file($configuration) && !is_link($configuration) && file_get_contents($configuration) === $legacy) {
            file_put_contents($configuration, str_replace("nav:\n  - Accueil: index.md\n", '', $legacy), LOCK_EX);
        }

        $ignore = $root.'/.gitignore';
        if (is_file($ignore) && !is_link($ignore)) {
            $existing = file_get_contents($ignore);
            if (is_string($existing) && !str_contains($existing, '/docs/symsvel/')) {
                file_put_contents($ignore, rtrim($existing)."\n/docs/symsvel/\n", LOCK_EX);
            }
        }

        return ['pages' => count($pages), 'path' => $generated];
    }

    private function removeGeneratedTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink()) {
                throw new \RuntimeException('Lien symbolique interdit dans les guides générés : '.$path);
            }
            if ($entry->isDir()) {
                if (!rmdir($path)) {
                    throw new \RuntimeException('Impossible de nettoyer '.$path);
                }
            } elseif (!unlink($path)) {
                throw new \RuntimeException('Impossible de nettoyer '.$path);
            }
        }
        if (!rmdir($directory)) {
            throw new \RuntimeException('Impossible de nettoyer '.$directory);
        }
    }
}
