<?php
/**
 * Environment Configuration Loader
 * HRIS Capstone System
 */
if (!function_exists('hris_load_env')) {
    function hris_load_env(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $searchPaths = [
            __DIR__ . '/../.env',
            __DIR__ . '/.env',
            dirname(__DIR__, 2) . '/.env',
        ];

        foreach ($searchPaths as $path) {
            if (is_file($path) && is_readable($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '#') === 0) {
                        continue;
                    }
                    if (strpos($line, '=') !== false) {
                        list($name, $value) = explode('=', $line, 2);
                        $name = trim($name);
                        $value = trim($value);
                        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                            $value = substr($value, 1, -1);
                        }
                        if (getenv($name) === false) {
                            putenv("{$name}={$value}");
                        }
                        if (!isset($_ENV[$name])) {
                            $_ENV[$name] = $value;
                        }
                        if (!isset($_SERVER[$name])) {
                            $_SERVER[$name] = $value;
                        }
                    }
                }
                break;
            }
        }
    }
}
hris_load_env();
