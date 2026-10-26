<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

final class ModuleMaker
{
    public function create(string $workspace, string $name, string $vendor = 'symsvel-framework'): string
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $name)) {
            throw new \InvalidArgumentException('Nom de module invalide : utilisez des lettres minuscules, chiffres et tirets.');
        }
        if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $vendor)) {
            throw new \InvalidArgumentException('Vendor Composer invalide.');
        }
        $root = rtrim($workspace, '/').'/modules';
        if (!is_dir($workspace)) {
            throw new \RuntimeException('Workspace introuvable.');
        }
        $destination = $root.'/'.$name;
        if (file_exists($destination) || is_link($destination)) {
            throw new \RuntimeException('Le module existe déjà : '.$destination);
        }

        $class = implode('', array_map('ucfirst', explode('-', $name)));
        $namespace = 'SymSvel\\Module\\'.$class;
        $files = [
            'composer.json' => json_encode([
                'name' => $vendor.'/module-'.$name,
                'description' => 'SymSvel module '.$class,
                'type' => 'symfony-bundle',
                'license' => 'proprietary',
                'require' => ['php' => '>=8.4', 'symfony/framework-bundle' => '^8.1'],
                'autoload' => ['psr-4' => [$namespace.'\\' => 'back/src/']],
                'extra' => ['symsvel-module' => ['manifest' => 'module.json']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).'\n',
            'module.json' => json_encode([
                'schema' => 1,
                'name' => $class,
                'dependencies' => new \stdClass(),
                'components' => ['back' => 'back'],
                'documentation' => ['dev' => 'docs/dev'],
                'extensions' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).'\n',
            'back/src/'.$class.'Bundle.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$namespace.";\n\nuse Symfony\\Component\\HttpKernel\\Bundle\\AbstractBundle;\n\nfinal class ".$class."Bundle extends AbstractBundle\n{\n}\n",
            'README.md' => '# '.$class."\n\nModule SymSvel (manifest schema 1).\n",
            'docs/dev/index.md' => '# '.$class."\n\nDocumentation développeur.\n",
            '.gitignore' => "/vendor/\n/node_modules/\n/.phpunit.result.cache\n",
        ];

        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Impossible de créer modules/.');
        }
        if (!mkdir($destination, 0775)) {
            throw new \RuntimeException('Impossible de créer le module.');
        }
        try {
            foreach ($files as $path => $content) {
                $target = $destination.'/'.$path;
                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Impossible de créer '.$directory);
                }
                if (file_put_contents($target, $content) === false) {
                    throw new \RuntimeException('Impossible d’écrire '.$target);
                }
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse(array_keys($files)) as $path) {
                @unlink($destination.'/'.$path);
            }
            foreach (['back/src', 'back', 'docs/dev', 'docs'] as $directory) {
                @rmdir($destination.'/'.$directory);
            }
            @rmdir($destination);
            throw $exception;
        }
        return $destination;
    }
}
