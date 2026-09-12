<?php

namespace App\Config;

class Env
{
    private static array $variables = [];
    private static bool $loaded = false;

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        if ($path === null) {
            $possiblePaths = [
                dirname(__DIR__, 2) . '/.env',
                '/var/www/edvora.chat/.env'
            ];
            foreach ($possiblePaths as $p) {
                if (file_exists($p)) {
                    $path = $p;
                    break;
                }
            }
        }

        if ($path && file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    self::$variables[$key] = $value;
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$variables[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
