<?php
require_once __DIR__ . '/functions.php';

/**
 * Record a payment (form fee / joining fee / second payment / other).
 * For 'form_fee', also triggers form confirmation (Registration No. + queue entry).
 */
function record_payment(
    int $studentId, string $stage, float $amount, string $method,
    ?string $referenceNo, int $collectedBy, ?int $extensionId = null
): int {
    require_once __DIR__ . '/queue_engine.php';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $receiptNo = generate_receipt_no();
        $pdo->prepare(
            "INSERT INTO payments (student_id, stage, extension_id, amount, method, reference_no, receipt_no, status, collected_by, payment_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'success', ?, NOW())"
        )->execute([$studentId, $stage, $extensionId, $amount, $method, $referenceNo, $receiptNo, $collectedBy]);
        $paymentId = (int)$pdo->lastInsertId();

        if ($extensionId) {
            $pdo->prepare("UPDATE invoices SET payment_status = 'paid', payment_id = ? WHERE extension_id = ?")
                ->execute([$paymentId, $extensionId]);
        }

        log_audit('payment', $paymentId, 'create', null, [
            'student_id' => $studentId, 'stage' => $stage, 'amount' => $amount, 'receipt_no' => $receiptNo,
        ]);

        $pdo->commit();

        if ($stage === 'form_fee') {
            confirm_form_payment($studentId, $collectedBy);
        }

        return $paymentId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Sum of payments received for a student, by stage. */
function get_student_payment_summary(int $studentId): array
{
    $stmt = db()->prepare(
        "SELECT stage, SUM(amount) AS total, COUNT(*) AS count
         FROM payments WHERE student_id = ? AND status = 'success' GROUP BY stage"
    );
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll();
    $summary = [];
    foreach ($rows as $r) {
        $summary[$r['stage']] = ['total' => (float)$r['total'], 'count' => (int)$r['count']];
    }
    return $summary;
}
