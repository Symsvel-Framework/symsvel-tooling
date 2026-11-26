<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Process;

/** Native argv for the standard Windows Composer and Node.js installers.
 * Never interpolate user arguments into cmd.exe. Unix argv is unchanged.
 */
final class NativeCommand
{
    /** @param list<string> $command @return list<string> */
    public static function resolve(array $command): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || $command === []) {
            return $command;
        }
        $name = strtolower(basename(str_replace('\\', '/', $command[0])));
        $arguments = array_slice($command, 1);
        if ($name === 'composer') {
            $phar = self::find('composer.phar');
            if ($phar !== null) {
                return [PhpCliExecutableResolver::resolve(), $phar, ...$arguments];
            }
            $executable = self::find('composer.exe');
            if ($executable !== null) {
                return [$executable, ...$arguments];
            }
            throw new \RuntimeException('Composer Windows introuvable : installez Composer-Setup (composer.phar sur le PATH).');
        }
        if (in_array($name, ['npm', 'npm.cmd', 'npm.bat'], true)) {
            $wrapper = $name === 'npm' ? self::find('npm.cmd') : $command[0];
            if ($wrapper === null || !is_file($wrapper)) {
                throw new \RuntimeException('npm.cmd introuvable : installez Node.js pour Windows.');
            }
            $directory = dirname($wrapper);
            $script = $directory.'/node_modules/npm/bin/npm-cli.js';
            $node = is_file($directory.'/node.exe') ? $directory.'/node.exe' : self::find('node.exe');
            if (!is_file($script) || $node === null) {
                throw new \RuntimeException('Installation npm Windows non reconnue (node.exe et npm-cli.js requis).');
            }

            return [$node, $script, ...$arguments];
        }

        return $command;
    }

    private static function find(string $file): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $directory = trim($directory, '"');
            if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|\\\\\\\\)~', $directory)) {
                continue;
            }
            $candidate = rtrim($directory, '/\\').'/'.$file;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
