<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

final class ModuleMaker
{
    public function create(string $workspace, string $name, string $vendor = 'symsvel-framework', array $scopes = ['back', 'dev']): string
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $name)) {
            throw new \InvalidArgumentException('Nom de module invalide : utilisez des lettres minuscules, chiffres et tirets.');
        }
        if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $vendor)) {
            throw new \InvalidArgumentException('Vendor Composer invalide.');
        }
        $allowed = ['back', 'bo', 'app', 'front', 'dev', 'admin', 'user'];
        $scopes = array_values(array_unique($scopes));
        if (array_diff($scopes, $allowed) !== []) {
            throw new \\InvalidArgumentException('Scope inconnu : '.implode(', ', array_diff($scopes, $allowed)));
        }
        $scopes = array_values(array_unique(array_merge(['back', 'dev'], $scopes)));
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
        $components = [];
        $documentation = [];
        foreach (['back', 'bo', 'app', 'front'] as $scope) {
            if (in_array($scope, $scopes, true)) {
                $components[$scope] = $scope;
            }
        }
        foreach (['dev', 'admin', 'user'] as $scope) {
            if (in_array($scope, $scopes, true)) {
                $documentation[$scope] = 'docs/'.$scope;
            }
        }
        $files = [
            'composer.json' => json_encode([
                'name' => $vendor.'/module-'.$name,
                'description' => 'SymSvel module '.$class,
                'type' => 'symfony-bundle',
                'license' => 'proprietary',
                'require' => ['php' => '>=8.4', 'symfony/framework-bundle' => '^8.1'],
                'autoload' => ['psr-4' => [$namespace.'\\' => 'back/src/']],
                'autoload-dev' => ['psr-4' => [$namespace.'\\Tests\\' => 'back/tests/']],
                'require-dev' => ['phpunit/phpunit' => '^13.4'],
                'scripts' => ['test' => 'phpunit -c back/phpunit.xml.dist', 'qa' => ['@test']],
                'extra' => ['symsvel-module' => ['manifest' => 'module.json']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            'module.json' => json_encode([
                'schema' => 1,
                'name' => $class,
                'dependencies' => new \stdClass(),
                'components' => $components,
                'documentation' => $documentation,
                'extensions' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            'back/src/'.$class.'Bundle.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$namespace.";\n\nuse Symfony\\Component\\HttpKernel\\Bundle\\AbstractBundle;\n\nfinal class ".$class."Bundle extends AbstractBundle\n{\n}\n",
            'README.md' => '# '.$class."\n\nModule SymSvel (manifest schema 1).\n",
            'docs/dev/index.md' => '# '.$class."\n\nDocumentation développeur.\n",
            '.gitignore' => "/vendor/\n/node_modules/\n/.phpunit.result.cache\n",
        ];

        foreach (['admin', 'user'] as $scope) {
            if (isset($documentation[$scope])) {
                $files['docs/'.$scope.'/index.md'] = '# '.$class."\\n\\nDocumentation ".$scope.".\\n";
            }
        }
        foreach (['bo', 'app', 'front'] as $scope) {
            if (isset($components[$scope])) {
                $files[$scope.'/src/lib/.gitkeep'] = '';
                $files[$scope.'/tests/.gitkeep'] = '';
            }
        }
        $files['back/phpunit.xml.dist'] = '<?xml version="1.0" encoding="UTF-8"?>'."\\n".'<phpunit bootstrap="../vendor/autoload.php" colors="true"><testsuites><testsuite name="Unit"><directory>tests/Unit</directory></testsuite><testsuite name="Integration"><directory>tests/Integration</directory></testsuite><testsuite name="Functional"><directory>tests/Functional</directory></testsuite></testsuites></phpunit>'."\\n";
        $files['.github/workflows/qa.yml'] = "name: QA\\non: [push, pull_request]\\njobs:\\n  test:\\n    runs-on: ubuntu-latest\\n    steps:\\n      - uses: actions/checkout@v4\\n      - uses: shivammathur/setup-php@v2\\n        with:\\n          php-version: '8.4'\\n      - run: composer install --no-interaction --prefer-dist\\n      - run: composer qa\\n";
        $files['back/tests/Unit/.gitkeep'] = '';
        $files['back/tests/Integration/.gitkeep'] = '';
        $files['back/tests/Functional/.gitkeep'] = '';
        $files['back/src/Controller/.gitkeep'] = '';
        $files['back/src/Entity/.gitkeep'] = '';
        $files['back/src/Repository/.gitkeep'] = '';
        $files['back/config/.gitkeep'] = '';
        $files['Migration/.gitkeep'] = '';
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
            $directories = array_unique(array_map('dirname', array_keys($files)));
            usort($directories, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($directories as $directory) {
                while ($directory !== '.' && $directory !== '') {
                    @rmdir($destination.'/'.$directory);
                    $directory = dirname($directory);
                }
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
