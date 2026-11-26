<?php
/**
 * AJAX Endpoint: Send WhatsApp OTP with Fee Details to 2 Admin Numbers
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/WhatsAppService.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !is_admin_or_above()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Please login again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $data = $_POST;
}

// Verify CSRF token from header or body
$csrfToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals(csrf_token(), (string)$csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Session expired (CSRF mismatch). Please reload the page.']);
    exit;
}

$user = current_user();

try {
    $studentName = trim((string)($data['student_name'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $stageLabel = trim((string)($data['stage_label'] ?? 'Course Fee'));
    $method = trim((string)($data['method'] ?? 'cash'));
    $referenceNo = trim((string)($data['reference_no'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));
    $context = trim((string)($data['context'] ?? 'payment'));

    if ($studentName === '') {
        throw new InvalidArgumentException('Please select or enter a student name first.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Please enter a valid amount greater than zero.');
    }

    $result = WhatsAppService::sendOtpToAdmins([
        'context' => $context,
        'student_name' => $studentName,
        'amount' => $amount,
        'stage_label' => $stageLabel,
        'method' => $method,
        'reference_no' => $referenceNo,
        'notes' => $notes,
        'collector_name' => $user['name'] ?? 'Admin',
    ], (int)$user['id']);

    $numsText = implode(' & ', $result['masked_numbers']);
    $msg = "OTP sent via WhatsApp to {$numsText} (valid for 5 minutes).";
    if (!empty($result['warnings'])) {
        $msg .= ' [Note: ' . implode('; ', $result['warnings']) . ']';
    }

    echo json_encode([
        'success' => true,
        'otp_id' => $result['otp_id'],
        'masked_numbers' => $result['masked_numbers'],
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
