<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Process;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/** Resolve an absolute PHP CLI executable, including outside a normal shell. */
final class PhpCliExecutableResolver
{
    public static function resolve(?PhpExecutableFinder $finder = null): string
    {
        $configured = getenv('SYMSVEL_PHP_CLI');
        if (is_string($configured) && $configured !== '') {
            $candidate = $configured;
            // A bare executable name is supported; paths must be absolute.
            if (!self::isAbsolute($candidate) && preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]*$/D', $candidate) === 1) {
                $candidate = (new ExecutableFinder())->find($candidate);
            }
            if (!self::isExecutable($candidate) || !self::isPhpCli($candidate)) {
                throw new \RuntimeException('SYMSVEL_PHP_CLI must designate an executable PHP CLI (PHP 8.4 or newer).');
            }

            return $candidate;
        }

        $candidate = ($finder ?? new PhpExecutableFinder())->find(false);
        if (!self::isExecutable($candidate) || !self::isPhpCli($candidate)) {
            throw new \RuntimeException('Unable to resolve an executable PHP CLI (PHP 8.4 or newer). Configure SYMSVEL_PHP_CLI with its absolute path.');
        }

        return $candidate;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || (DIRECTORY_SEPARATOR === '\\' && strlen($path) > 2
                && $path[1] === ':' && in_array($path[2], ['\\', '/'], true));
    }

    private static function isExecutable(string|false|null $candidate): bool
    {
        return is_string($candidate) && self::isAbsolute($candidate)
            && is_file($candidate) && is_executable($candidate);
    }

    private static function isPhpCli(string $candidate): bool
    {
        if ($candidate === PHP_BINARY && in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
            return PHP_VERSION_ID >= 80400;
        }

        // A wrong executable could report success without running PHP at all.
        try {
            $probe = new Process([$candidate, '-r', 'if (PHP_SAPI === "cli" && PHP_VERSION_ID >= 80400) { echo "symsvel-php-cli"; }']);
            $probe->setTimeout(5);
            $probe->run();

            return $probe->isSuccessful() && $probe->getOutput() === 'symsvel-php-cli';
        } catch (\Throwable) {
            return false;
        }
    }
}
