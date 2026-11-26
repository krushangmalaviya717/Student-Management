<?php
/**
 * API Index & System Status
 * GET /api/
 */
require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/../config/config.php';

ApiResponse::json([
    'service' => 'Devangi Sewing Classes - Student Management API',
    'version' => '1.0.0',
    'status' => 'healthy',
    'environment' => env('APP_ENV', 'local'),
    'endpoints' => [
        'GET  /api/students.php' => 'List or filter students (?q=, ?status=, ?batch_id=, ?id=)',
        'POST /api/students.php' => 'Student actions (action=mark_completed, action=reopen_course)',
        'GET  /api/batches.php'  => 'List batches (?active_only=1, ?stats=1, ?id=)',
        'POST /api/batches.php'  => 'Batch management (action=create, action=toggle_status, action=delete)',
        'GET  /api/payments.php' => 'List payments (?stage=, ?method=, ?search=, ?metrics=1)',
        'POST /api/payments.php' => 'Record new student payment',
        'GET  /api/holidays.php' => 'List institute holidays (?batch_id=)',
        'POST /api/holidays.php' => 'Declare holidays (action=single|range|delete)',
    ],
    'authentication' => [
        'session' => 'Automatic via active browser login session',
        'bearer_token' => 'Header: Authorization: Bearer <API_SECRET_KEY>',
        'api_key_header' => 'Header: X-API-Key: <API_SECRET_KEY>'
    ]
], 200, 'Devangi SMS API Gateway');
