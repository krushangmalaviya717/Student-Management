<?php
/**
 * ApiAuth
 * API Authentication & Authorization Guard.
 * Supports Session authentication (for browser AJAX) and Bearer Token (for external clients).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/ApiResponse.php';

class ApiAuth
{
    /**
     * Ensure request is authenticated. Returns user data or aborts with 401.
     */
    public static function requireAuth(): array
    {
        // Option 1: Active Session
        if (is_logged_in()) {
            return current_user();
        }

        // Option 2: Bearer Token or API Key
        $token = self::getBearerToken();
        $apiKey = $token ?? $_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
        $configuredKey = defined('API_SECRET_KEY') ? API_SECRET_KEY : '';

        if (!empty($configuredKey) && !empty($apiKey) && hash_equals($configuredKey, $apiKey)) {
            return [
                'id' => 1,
                'name' => 'API Client',
                'role_name' => 'super_admin'
            ];
        }

        ApiResponse::error('Unauthorized. Valid session or API key required.', 401);
    }

    private static function getBearerToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if ($authHeader && preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
