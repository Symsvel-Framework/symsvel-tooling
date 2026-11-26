<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Installation;

/** Owns only the unmodified default MkDocs presentation of the local site. */
final class DevDocumentationLayout
{
    private const LEGACY_CONFIG = "site_name: SymSvel - Documentation développeur\nsite_url: ''\ndocs_dir: docs\nsite_dir: site\nuse_directory_urls: false\nplugins: []\n";
    private const LEGACY_REQUIREMENTS = "mkdocs>=1.6,<2\n";
    public const REQUIREMENTS = "mkdocs>=1.6,<2\nmkdocs-material>=9.6,<10\n";
    private const LEGACY_MATERIAL_CONFIG = <<<'YAML'
site_name: SymSvel - Documentation développeur
site_url: ''
docs_dir: docs
site_dir: site
use_directory_urls: false
theme:
  name: material
  language: fr
  font: false
  features:
    - toc.follow
    - navigation.top
plugins:
  - search
  - offline
markdown_extensions:
  - toc:
      title: Sommaire
      permalink: true
YAML;
    public const CONFIG = <<<'YAML'
site_name: SymSvel - Documentation développeur
site_url: ''
docs_dir: docs
site_dir: site
use_directory_urls: false
theme:
  name: material
  language: fr
  font: false
  logo: symsvel/assets/theme/mark.png
  favicon: symsvel/assets/theme/mark.png
  palette:
    - scheme: default
      primary: custom
      accent: custom
      toggle:
        icon: material/weather-night
        name: Passer au mode sombre
    - scheme: slate
      primary: custom
      accent: custom
      toggle:
        icon: material/weather-sunny
        name: Passer au mode clair
  features:
    - toc.follow
    - navigation.top
    - content.code.copy
plugins:
  - search
  - offline
extra_css:
  - symsvel/assets/theme/symsvel.css
markdown_extensions:
  - admonition
  - pymdownx.details
  - pymdownx.highlight
  - pymdownx.superfences
  - toc:
      title: Sommaire
      permalink: true
YAML;

    /** @param array<string, array<string,string>> $sections @param array<string,string> $titles */
    public function synchronize(string $root, array $sections, array $titles): void
    {
        $config = $root.'/mkdocs.yml';
        $requirements = $root.'/requirements.txt';
        if (!is_file($config) || is_link($config) || !is_file($requirements) || is_link($requirements)) {
            return;
        }
        $previous = file_get_contents($config);
        $dependencies = file_get_contents($requirements);
        if (!is_string($previous) || !is_string($dependencies)) {
            throw new \RuntimeException('Configuration documentaire illisible.');
        }
        $owned = in_array($previous, [
            self::LEGACY_CONFIG,
            self::LEGACY_CONFIG."nav:\n  - Accueil: index.md\n",
            self::LEGACY_MATERIAL_CONFIG."\n",
            self::CONFIG."\n",
        ], true);
        if (preg_match('/^# symsvel-layout: ([a-f0-9]{64})\n/', $previous, $match)) {
            $owned = hash_equals($match[1], hash('sha256', substr($previous, strlen($match[0]))));
        }
        // Migrate the default pair only; never replace a project's theme,
        // custom nav or Python dependencies without its owner's consent.
        if (!$owned || !in_array($dependencies, [self::LEGACY_REQUIREMENTS, self::REQUIREMENTS], true)) {
            return;
        }

        $nav = [
            '  - Accueil: index.md',
            '  - Guides SymSvel: symsvel/index.md',
        ];
        foreach ($titles as $section => $title) {
            if (($sections[$section] ?? []) === []) {
                continue;
            }
            $nav[] = '  - '.$this->quote($title).':';
            $nav[] = '      - Vue d’ensemble: '.$this->quote('symsvel/'.$section.'/index.md');
            foreach ($sections[$section] as $relative => $pageTitle) {
                $nav[] = '      - '.$this->quote($pageTitle).': '.$this->quote('symsvel/'.$relative);
            }
        }
        $projectPages = $this->projectPages($root.'/docs');
        if ($projectPages !== []) {
            $nav[] = '  - Documentation du projet:';
            foreach ($projectPages as $path) {
                // MkDocs determines the title from the source Markdown.
                $nav[] = '      - '.$this->quote($path);
            }
        }
        $body = self::CONFIG."\nnav:\n".implode("\n", $nav)."\n";
        $next = '# symsvel-layout: '.hash('sha256', $body)."\n".$body;
        if ($dependencies !== self::REQUIREMENTS
            && file_put_contents($requirements, self::REQUIREMENTS, LOCK_EX) === false) {
            throw new \RuntimeException('Impossible de mettre à jour les dépendances MkDocs.');
        }
        if ($previous !== $next && file_put_contents($config, $next, LOCK_EX) === false) {
            throw new \RuntimeException('Impossible de publier la navigation MkDocs.');
        }
    }

    /** @return list<string> */
    private function projectPages(string $docs): array
    {
        $directory = new \RecursiveDirectoryIterator($docs, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, static function (\SplFileInfo $entry) use ($docs): bool {
            return !$entry->isLink()
                && !str_starts_with($entry->getFilename(), '.')
                && $entry->getPathname() !== $docs.'/symsvel';
        });
        $pages = [];
        foreach (new \RecursiveIteratorIterator($filter) as $entry) {
            if (!$entry->isFile() || !in_array(strtolower($entry->getExtension()), ['md', 'markdown'], true)) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($docs) + 1);
            if ($relative !== 'index.md') {
                $pages[] = $relative;
            }
        }
        sort($pages, SORT_STRING);

        return $pages;
    }

    private function quote(string $value): string
    {
        // JSON double-quoted strings are also valid YAML scalars.
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
