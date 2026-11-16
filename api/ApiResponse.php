<?php
/**
 * ApiResponse
 * Standardized JSON responses for all REST API endpoints.
 */

class ApiResponse
{
    /**
     * Return successful JSON response
     */
    public static function json($data = null, int $statusCode = 200, string $message = 'Success'): void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Return error JSON response
     */
    public static function error(string $message, int $statusCode = 400, $details = null): void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'details' => $details,
            'timestamp' => time()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
