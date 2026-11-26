<?php
/**
 * DEVANGI SEWING CLASSES - Student Management System
 * Core Application Configuration & Bootstrapper.
 */

// Step 1: Load environment variables
require_once __DIR__ . '/env.php';

// Step 2: Environment Auto-Detection (Local vs Hostinger Live Server)
$isLiveServer = (
    (!empty($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'devangistudent.com') !== false) ||
    (!empty($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'devangistudent.com') !== false) ||
    (strpos(__DIR__, 'public_html') !== false)
);

if ($isLiveServer) {
    // Live Server (Hostinger - devangistudent.com)
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'u594881892_devangistudent');
    define('DB_USER', 'u594881892_devangistudent');
    define('DB_PASS', 'Krushang@972');
    define('DB_CHARSET', 'utf8mb4');

    define('APP_NAME', 'Devangi Sewing Classes - Student Management');
    define('APP_ENV', 'production');
    define('APP_DEBUG', false);
} else {
    // Local Development (XAMPP / Localhost)
    define('DB_HOST', env('DB_HOST', '127.0.0.1'));
    define('DB_PORT', env('DB_PORT', '3306'));
    define('DB_NAME', env('DB_NAME', 'devangi_sms'));
    define('DB_USER', env('DB_USER', 'root'));
    define('DB_PASS', (string) env('DB_PASS', 'Krushang@972'));
    define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

    define('APP_NAME', env('APP_NAME', 'Devangi Sewing Classes - Student Management (Local)'));
    define('APP_ENV', 'local');
    define('APP_DEBUG', true);
}

define('APP_TIMEZONE', env('APP_TIMEZONE', 'Asia/Kolkata'));
define('API_SECRET_KEY', (string) env('API_SECRET_KEY', 'devangi_secret_api_key_2026'));

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_HOST'])) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseFolder = preg_replace('#/(public|api|config|includes).*$#', '', $scriptDir);
    $baseFolder = ($baseFolder === '/' || $baseFolder === '.') ? '' : $baseFolder;
    define('APP_URL', "$protocol://" . $_SERVER['HTTP_HOST'] . $baseFolder);
} else {
    define('APP_URL', env('APP_URL', 'http://localhost:8000'));
}

define('UPLOAD_DIR', __DIR__ . '/../' . ltrim(env('UPLOAD_PATH', 'uploads/photos/'), '/'));
define('UPLOAD_URL', '/' . ltrim(env('UPLOAD_PATH', 'uploads/photos/'), '/'));
define('MAX_PHOTO_SIZE_MB', (int) env('UPLOAD_MAX_SIZE_MB', 5));

date_default_timezone_set(APP_TIMEZONE);

// Step 4: Centralized Error and Exception Handling (Requirement 5)
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Global exception handler
set_exception_handler(function (Throwable $e) {
    error_log("[Application Exception] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'error' => APP_DEBUG ? $e->getMessage() : 'An unexpected server error occurred.',
            'code' => $e->getCode(),
            'trace' => APP_DEBUG ? $e->getTraceAsString() : null
        ]);
        exit;
    }

    if (!APP_DEBUG) {
        http_response_code(500);
        include __DIR__ . '/../public/500.php';
        exit;
    }

    // In debug mode, show formatted error
    http_response_code(500);
    echo '<div style="font-family:sans-serif;padding:24px;background:#fff1f2;border:1px solid #f43f5e;border-radius:8px;margin:20px;color:#881337;">';
    echo '<h2 style="margin-top:0;">Application Error</h2>';
    echo '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ' (Line ' . $e->getLine() . ')</p>';
    echo '<pre style="background:#ffe4e6;padding:12px;border-radius:4px;overflow-x:auto;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</div>';
    exit;
});

// Step 5: Secure Sessions (Requirement 8)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Step 6: Include Database PDO Factory
require_once __DIR__ . '/database.php';
