<?php
$pageTitle = 'ફી અને પહોંચ - Devangi Sewing Classes';
$activePage = 'fees';
require_once __DIR__ . '/includes/header.php';
require_student_login();

reload_student_session();
$s = current_student();
$studentId = (int)$s['id'];

// Get all payments made by this student
$stmtPayments = db()->prepare(
    "SELECT * FROM payments 
     WHERE student_id = ? 
     ORDER BY payment_date DESC"
);
$stmtPayments->execute([$studentId]);
$payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

// Calculate total paid
$totalPaid = 0;
$extPaidFromPayments = 0;
foreach ($payments as $p) {
    if ($p['status'] === 'success') {
        $totalPaid += (float)$p['amount'];
        if (in_array($p['stage'], ['extension_fee', 'extension'])) {
            $extPaidFromPayments += (float)$p['amount'];
        }
    }
}

// Student's locked course fee structure (protects from subsequent setting price increases)
$formFeeAmt = !empty($s['form_fee_amount']) ? (float)$s['form_fee_amount'] : (float)get_setting('form_fee', 500);
$joiningFeeAmt = !empty($s['joining_fee_amount']) ? (float)$s['joining_fee_amount'] : (float)get_setting('joining_fee', 3500);
$secondPaymentAmt = !empty($s['second_payment_amount']) ? (float)$s['second_payment_amount'] : (float)get_setting('second_payment_amount', 4000);
$totalCourseFee = !empty($s['total_course_fee']) ? (float)$s['total_course_fee'] : ($formFeeAmt + $joiningFeeAmt + $secondPaymentAmt);

// Query extensions joined with invoices
$extQuery = db()->prepare("
    SELECT 
        e.id,
        e.extension_days,
        e.final_amount,
        e.created_at,
        i.id as inv_id,
        i.invoice_no,
        i.total_amount as inv_amount,
        i.payment_status as inv_status,
        i.payment_id
    FROM extensions e
    LEFT JOIN invoices i ON i.extension_id = e.id
    WHERE e.student_id = ?
    ORDER BY e.id ASC
");
$extQuery->execute([$studentId]);
$extensions = $extQuery->fetchAll(PDO::FETCH_ASSOC);

$totalExtensionDays = 0;
$extTotalAmt = 0;
$extPaidDays = 0;
$extPaidAmt = 0;
$extPendingDays = 0;
$extPendingAmt = 0;

foreach ($extensions as $ext) {
    $days = (int)$ext['extension_days'];
    $amt = (float)$ext['final_amount'];
    $totalExtensionDays += $days;
    $extTotalAmt += $amt;
    if ($ext['inv_status'] === 'paid' || !empty($ext['payment_id'])) {
        $extPaidDays += $days;
        $extPaidAmt += $amt;
    } else {
        $extPendingDays += $days;
        $extPendingAmt += $amt;
    }
}

// Fallback if no extensions table rows but student has dates
if ($totalExtensionDays === 0 && !empty($s['base_expiry_date']) && !empty($s['final_expiry_date'])) {
    $bDate = new DateTime($s['base_expiry_date']);
    $fDate = new DateTime($s['final_expiry_date']);
    if ($fDate > $bDate) {
        $diff = $bDate->diff($fDate)->days;
        if ($diff > 0) $totalExtensionDays = $diff;
    }
}

// Pending invoices query
$stmtPendingInv = db()->prepare("
    SELECT i.*, e.extension_days 
    FROM invoices i
    LEFT JOIN extensions e ON e.id = i.extension_id
    WHERE i.student_id = ? AND (i.payment_status != 'paid' OR i.payment_status IS NULL)
    ORDER BY i.id DESC
");
$stmtPendingInv->execute([$studentId]);
$pendingInvoices = $stmtPendingInv->fetchAll(PDO::FETCH_ASSOC);

$extDue = 0;
foreach ($pendingInvoices as $pi) {
    $extDue += (float)$pi['total_amount'];
}

$totalFeeOverall = $totalCourseFee + $extTotalAmt;
if ($totalFeeOverall < $totalPaid + $extDue) {
    $totalFeeOverall = $totalPaid + $extDue;
}
$totalDueOverall = max(0.0, $totalFeeOverall - $totalPaid);

// Stage Labels for Receipts
$stageLabels = [
    'form_fee' => 'Registration Fee (₹' . number_format($formFeeAmt, 0) . ')',
    'joining_fee' => '1st Installment / Joining Fee (₹' . number_format($joiningFeeAmt, 0) . ')',
    'second_payment' => '2nd Installment (₹' . number_format($secondPaymentAmt, 0) . ')',
    'extension_fee' => 'Course Extension Fee',
    'extension' => 'Course Extension Fee',
    'other' => 'Course Fee'
];
?>

<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-wallet2 me-2"></i> Fee &amp; Payment Summary</span>
    </div>

    <!-- 3-box summary grid matching profile.php -->
    <div class="row text-center mb-3 g-2">
        <div class="col-4">
            <div class="p-2 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                <div style="font-size: 0.72rem; color: #64748b; font-weight: 500;">Total Fee</div>
                <div class="fw-bold small" style="color: #0f172a; font-size: 0.95rem;">₹<?= number_format($totalFeeOverall) ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="p-2 rounded" style="background: #ecfdf5; border: 1px solid #a7f3d0;">
                <div style="font-size: 0.72rem; color: #047857; font-weight: 600;">Total Paid</div>
                <div class="fw-bold small" style="color: #047857; font-size: 0.95rem;">₹<?= number_format($totalPaid) ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="p-2 rounded" style="background: <?= $totalDueOverall > 0 ? '#fff7ed' : '#f8fafc' ?>; border: 1px solid <?= $totalDueOverall > 0 ? '#fed7aa' : '#e2e8f0' ?>;">
                <div style="font-size: 0.72rem; color: <?= $totalDueOverall > 0 ? '#c2410c' : '#64748b' ?>; font-weight: 500;">Balance Due</div>
                <div class="fw-bold small" style="color: <?= $totalDueOverall > 0 ? '#c2410c' : '#059669' ?>; font-size: 0.95rem;">₹<?= number_format($totalDueOverall) ?></div>
            </div>
        </div>
    </div>

    <?php if ($totalDueOverall > 0): ?>
    <div class="p-2 px-3 rounded-3 mb-3 d-flex align-items-center" style="background: #fffbeb; border: 1px solid #fde68a;">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5" style="color: #d97706 !important;"></i>
        <div class="small" style="color: #92400e;">
            <strong>ધ્યાન આપો:</strong> આપની <strong>₹<?= number_format($totalDueOverall, 2) ?></strong> ની ફી બાકી છે. કૃપા કરીને કાઉન્ટર પર જમા કરાવો.
        </div>
    </div>
    <?php endif; ?>

    <!-- Course Fee Breakdown Rows -->
    <div class="info-row">
        <span class="info-label">Registration Fee (₹<?= number_format($formFeeAmt, 0) ?>)</span>
        <span class="info-value">
            <?php
            $regPaid = false;
            foreach ($payments as $p) {
                if ($p['stage'] === 'form_fee' && $p['status'] === 'success') { $regPaid = true; break; }
            }
            echo $regPaid ? '<span class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> Paid (જમા)</span>' : '<span class="text-danger fw-bold">Pending (બાકી)</span>';
            ?>
        </span>
    </div>

    <div class="info-row">
        <span class="info-label">1st Installment (₹<?= number_format($joiningFeeAmt, 0) ?>)</span>
        <span class="info-value">
            <?php
            $joinPaid = false;
            foreach ($payments as $p) {
                if ($p['stage'] === 'joining_fee' && $p['status'] === 'success') { $joinPaid = true; break; }
            }
            echo $joinPaid ? '<span class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> Paid (જમા)</span>' : '<span class="text-secondary">Pending (બાકી)</span>';
            ?>
        </span>
    </div>

    <div class="info-row">
        <span class="info-label">2nd Installment (₹<?= number_format($secondPaymentAmt, 0) ?>)</span>
        <span class="info-value">
            <?php
            $secPaid = false;
            foreach ($payments as $p) {
                if ($p['stage'] === 'second_payment' && $p['status'] === 'success') { $secPaid = true; break; }
            }
            echo $secPaid ? '<span class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> Paid (જમા)</span>' : '<span class="text-secondary">Pending (બાકી)</span>';
            ?>
        </span>
    </div>

    <?php if ($totalExtensionDays > 0 || $extTotalAmt > 0): ?>
    <div class="info-row">
        <div>
            <span class="info-label d-block">Course Extension Fee (<?= $totalExtensionDays ?> Days: ₹<?= number_format($extTotalAmt, 0) ?>)</span>
            <?php if ($extPendingAmt > 0 && $extPaidAmt > 0): ?>
                <span class="text-muted" style="font-size: 0.75rem;">₹<?= number_format($extPaidAmt, 0) ?> ચૂકવેલ (<?= $extPaidDays ?> દિવસ)</span>
            <?php endif; ?>
        </div>
        <span class="info-value text-end">
            <?php if ($extPendingAmt > 0): ?>
                <span class="badge" style="background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; font-weight: 700; padding: 4px 8px;">
                    <i class="bi bi-clock-history me-1"></i> ₹<?= number_format($extPendingAmt, 0) ?> બાકી (<?= $extPendingDays ?> દિવસ)
                </span>
            <?php else: ?>
                <span class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> Paid (જમા)</span>
            <?php endif; ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<!-- Pending Invoices Section (if any) -->
<?php if (!empty($pendingInvoices)): ?>
<div class="card-app" style="border-left: 4px solid #f59e0b;">
    <div class="card-title text-warning" style="color: #b45309 !important;">
        <span><i class="bi bi-clock-history me-2"></i> બાકી ઇન્વોઇસ / Pending Invoices</span>
    </div>

    <div class="list-group list-group-flush">
        <?php foreach ($pendingInvoices as $inv): ?>
            <div class="list-group-item px-0 py-2 border-bottom">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold text-dark"><?= htmlspecialchars($inv['invoice_no']) ?></div>
                        <div class="small text-muted">
                            <?= !empty($inv['extension_days']) ? 'Course Extension (' . (int)$inv['extension_days'] . ' Days)' : 'Fee Adjustment' ?> • <?= date('d-M-Y', strtotime($inv['invoice_date'])) ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold text-danger fs-6">₹<?= number_format($inv['total_amount'], 2) ?></div>
                        <span class="badge bg-warning text-dark small" style="font-size: 0.72rem;"><i class="bi bi-hourglass-split me-1"></i> Pending (બાકી)</span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="small text-muted mt-2 pt-2 border-top">
        <i class="bi bi-info-circle me-1"></i> આ રકમ એકેડેમી ખાતે ચૂકવ્યા બાદ તમારી પહોંચ અહીં ઉપલબ્ધ થશે.
    </div>
</div>
<?php endif; ?>

<!-- List of Receipts Card -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-file-earmark-text me-2"></i> Payment Receipts</span>
    </div>

    <?php if (empty($payments)): ?>
        <div class="text-center text-muted py-4 small">
            હજુ સુધી કોઈ ફી ચૂકવણી થયેલ નથી.
        </div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($payments as $p): ?>
                <div class="list-group-item px-0 py-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($stageLabels[$p['stage']] ?? $p['stage']) ?></div>
                            <div class="small text-muted">Receipt No: <strong><?= htmlspecialchars($p['receipt_no']) ?></strong></div>
                            <div class="small text-muted"><?= date('d-M-Y h:i A', strtotime($p['payment_date'])) ?> | <?= strtoupper($p['method']) ?></div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success fs-5">₹<?= number_format($p['amount'], 2) ?></div>
                            <a href="/public/student/receipt.php?id=<?= $p['id'] ?>" class="btn btn-sm mt-1 py-1 px-3 small fw-bold" style="background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe; border-radius: 20px;">
                                <i class="bi bi-receipt me-1"></i> View Receipt
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
