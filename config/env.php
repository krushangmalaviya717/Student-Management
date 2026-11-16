<?php
/**
 * Lightweight Environment (.env) Loader
 * Loads key-value pairs from .env into $_ENV, $_SERVER, and getenv().
 */

if (!function_exists('load_env')) {
    function load_env(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Ignore comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split on first =
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // Remove surrounding quotes if present
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }

            // Put into environment if not already explicitly set
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
            }
            if (!array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $val;
            }
            putenv("$key=$val");
        }
    }
}

if (!function_exists('env')) {
    /**
     * Get an environment variable with optional default fallback
     */
    function env(string $key, $default = null)
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($val === false || $val === null) {
            return $default;
        }

        switch (strtolower(trim((string)$val))) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }

        return $val;
    }
}

// Auto-load .env from root directory if it exists
load_env(dirname(__DIR__) . '/.env');
