<?php
/**
 * REST API: /api/payments.php
 */
require_once __DIR__ . '/ApiAuth.php';
require_once __DIR__ . '/../includes/services/PaymentService.php';

$user = ApiAuth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (isset($_GET['metrics'])) {
            $metrics = PaymentService::getPaymentMetrics();
            ApiResponse::json($metrics, 200, 'Payment metrics fetched');
        } else {
            $filters = [
                'stage' => $_GET['stage'] ?? '',
                'method' => $_GET['method'] ?? '',
                'search' => $_GET['search'] ?? '',
                'limit' => (int)($_GET['limit'] ?? 100),
            ];
            $payments = PaymentService::getPayments($filters);
            ApiResponse::json([
                'count' => count($payments),
                'payments' => $payments
            ], 200, 'Payments fetched');
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $studentId = (int)($input['student_id'] ?? 0);
        $stage = $input['stage'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        $paymentMethod = $input['method'] ?? 'cash';
        $refNo = $input['reference_no'] ?? null;
        $extId = !empty($input['extension_id']) ? (int)$input['extension_id'] : null;

        if ($studentId <= 0 || empty($stage) || $amount <= 0) {
            ApiResponse::error('student_id, stage, and valid amount are required', 400);
        }

        $paymentId = PaymentService::recordPayment(
            $studentId,
            $stage,
            $amount,
            $paymentMethod,
            $refNo,
            (int)$user['id'],
            $extId
        );

        ApiResponse::json(['payment_id' => $paymentId], 201, 'Payment recorded successfully');
    } else {
        ApiResponse::error('Method not allowed', 405);
    }
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 400);
}
