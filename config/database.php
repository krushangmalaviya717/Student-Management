<?php
/**
 * Database Connection Manager (PDO Singleton)
 */
require_once __DIR__ . '/env.php';

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = defined('DB_HOST') ? DB_HOST : env('DB_HOST', '127.0.0.1');
            $port = defined('DB_PORT') ? DB_PORT : env('DB_PORT', '3306');
            $name = defined('DB_NAME') ? DB_NAME : env('DB_NAME', 'devangi_sms');
            $user = defined('DB_USER') ? DB_USER : env('DB_USER', 'root');
            $pass = defined('DB_PASS') ? DB_PASS : env('DB_PASS', '');
            $charset = defined('DB_CHARSET') ? DB_CHARSET : env('DB_CHARSET', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                $isDebug = (bool) env('APP_DEBUG', true);
                http_response_code(500);

                // If this is an API call, return JSON
                if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'error' => 'Database connection failed.',
                        'details' => $isDebug ? $e->getMessage() : null
                    ]);
                    exit;
                }

                die('Database connection failed. Please check your .env or config settings. ' .
                    ($isDebug ? '(' . htmlspecialchars($e->getMessage()) . ')' : ''));
            }
        }

        return self::$instance;
    }
}

/**
 * Global helper function to get PDO instance
 */
if (!function_exists('db')) {
    function db(): PDO
    {
        return Database::getConnection();
    }
}
