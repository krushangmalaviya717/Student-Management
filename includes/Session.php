<?php
/**
 * Application State & Session Manager
 * Encapsulates session state, flash messages, user context, and CSRF protection.
 */

class Session
{
    /**
     * Set a session key-value pair
     */
    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Get a session value with fallback default
     */
    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if a session key exists
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a session key
     */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Flash messages: push a message to flash queue for next page load
     */
    public static function flash(string $type, string $message): void
    {
        if (!isset($_SESSION['_flashes'])) {
            $_SESSION['_flashes'] = [];
        }
        $_SESSION['_flashes'][] = [
            'type' => $type,
            'message' => $message,
            'timestamp' => time()
        ];
    }

    /**
     * Retrieve and clear all queued flash messages
     */
    public static function getFlashes(): array
    {
        // Support legacy $_SESSION['flash'] as well
        $flashes = [];
        if (!empty($_SESSION['flash'])) {
            $flashes = array_merge($flashes, $_SESSION['flash']);
            unset($_SESSION['flash']);
        }
        if (!empty($_SESSION['_flashes'])) {
            $flashes = array_merge($flashes, $_SESSION['_flashes']);
            unset($_SESSION['_flashes']);
        }
        return $flashes;
    }

    /**
     * Get or create CSRF token
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token from POST or header
     */
    public static function verifyCsrf(?string $token = null): bool
    {
        $token = $token ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        return !empty($token) && hash_equals($sessionToken, $token);
    }

    /**
     * Current authenticated user array or null
     */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Login user into session
     */
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
    }

    /**
     * Logout and destroy session
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}
