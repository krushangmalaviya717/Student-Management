<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/PaymentService.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_once __DIR__ . '/../includes/services/WhatsAppService.php';
require_admin();

$pdo = db();
$user = current_user();
$error = null;
$createdPayment = null;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_manual_receipt') {
        try {
            // 1. PIN verification
            $secretPin = trim($_POST['secret_pin'] ?? '');
            if (!PaymentService::verifySecretPin($secretPin, (int)$user['id'])) {
                throw new InvalidArgumentException('Entered security PIN or OTP is invalid.');
            }

            // 2. Check if linked to an existing student
            $existingStudentId = (int)($_POST['existing_student_id'] ?? 0);
            $studentId = 0;
            $studentName = trim($_POST['student_name'] ?? '');
            $rawMobile = trim($_POST['student_mobile'] ?? '');
            $address = trim($_POST['address'] ?? '') ?: 'Surat / Local';
            $registrationNo = trim($_POST['registration_no'] ?? '') ?: null;
            $formNo = trim($_POST['form_no'] ?? '');
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $batchIdVal = ($batchId > 0) ? $batchId : null;

            // Clean & sanitize mobile number
            $cleanMobile = preg_replace('/\D/', '', $rawMobile);
            if (strlen($cleanMobile) === 11 && str_starts_with($cleanMobile, '0')) {
                $cleanMobile = substr($cleanMobile, 1);
            } elseif (strlen($cleanMobile) === 12 && str_starts_with($cleanMobile, '91')) {
                $cleanMobile = substr($cleanMobile, 2);
            }
            if (strlen($cleanMobile) !== 10) {
                throw new InvalidArgumentException('Please enter a valid 10-digit mobile number.');
            }
            $studentMobile = $cleanMobile;

            if ($studentName === '') {
                throw new InvalidArgumentException('Student full name is required.');
            }

            // 3. Payment & Receipt details
            $receiptNo = trim($_POST['receipt_no'] ?? '');
            if ($receiptNo === '') {
                $receiptNo = generate_receipt_no();
            } else {
                $chkRcpt = $pdo->prepare("SELECT id FROM payments WHERE receipt_no = ?");
                $chkRcpt->execute([$receiptNo]);
                if ($chkRcpt->fetch()) {
                    throw new InvalidArgumentException("Receipt number '{$receiptNo}' is already in use. Please enter a different number.");
                }
            }

            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) {
                throw new InvalidArgumentException('Please enter a valid amount greater than 0.');
            }

            $stage = trim($_POST['stage'] ?? 'other');
            $allowedStages = ['form_fee', 'joining_fee', 'second_payment', 'extension_fee', 'other'];
            if (!in_array($stage, $allowedStages, true)) {
                $stage = 'other';
            }

            $particulars = trim($_POST['particulars'] ?? '');
            if ($particulars === '') {
                $particulars = 'Walk-in Student Fee Receipt';
            }

            $method = trim($_POST['method'] ?? 'cash');
            $allowedMethods = ['cash', 'upi', 'bank_transfer', 'cheque', 'card', 'other'];
            if (!in_array($method, $allowedMethods, true)) {
                $method = 'cash';
            }

            $refNo = trim($_POST['reference_no'] ?? '') ?: null;

            $paymentDateInput = trim($_POST['payment_date'] ?? '');
            $paymentDate = $paymentDateInput ? date('Y-m-d H:i:s', strtotime($paymentDateInput)) : date('Y-m-d H:i:s');

            $redirectAction = $_POST['redirect_action'] ?? 'print';

            // 4. Database Transaction
            $pdo->beginTransaction();

            if ($existingStudentId > 0) {
                $stChk = $pdo->prepare("SELECT id, name FROM students WHERE id = ?");
                $stChk->execute([$existingStudentId]);
                $stRow = $stChk->fetch();
                if (!$stRow) {
                    throw new RuntimeException('Selected student record was not found.');
                }
                $studentId = (int)$stRow['id'];
            } else {
                if ($formNo === '') {
                    $formNo = 'TMP-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
                } else {
                    $chkForm = $pdo->prepare("SELECT id FROM students WHERE form_no = ?");
                    $chkForm->execute([$formNo]);
                    if ($chkForm->fetch()) {
                        $formNo .= '-' . rand(10, 99);
                    }
                }

                $snapFormFee = (float)get_setting('form_fee', 500);
                $snapJoiningFee = (float)get_setting('joining_fee', 3500);
                $snapSecondPayment = (float)get_setting('second_payment_amount', 4000);
                $snapTotalCourseFee = $snapFormFee + $snapJoiningFee + $snapSecondPayment;

                $stmtStudent = $pdo->prepare("
                    INSERT INTO students (
                        form_no, registration_no, form_submit_date, form_status,
                        name, student_mobile, address,
                        joined_batch_id, preferred_batch_id,
                        status, is_temporary, created_by, created_at,
                        form_fee_amount, joining_fee_amount, second_payment_amount, total_course_fee
                    ) VALUES (
                        ?, ?, ?, 'confirmed',
                        ?, ?, ?,
                        ?, ?,
                        'draft', 1, ?, NOW(),
                        ?, ?, ?, ?
                    )
                ");
                $stmtStudent->execute([
                    $formNo,
                    $registrationNo,
                    $paymentDate,
                    $studentName,
                    $studentMobile,
                    $address,
                    $batchIdVal,
                    $batchIdVal,
                    (int)$user['id'],
                    $snapFormFee,
                    $snapJoiningFee,
                    $snapSecondPayment,
                    $snapTotalCourseFee
                ]);
                $studentId = (int)$pdo->lastInsertId();
            }

            $stmtPayment = $pdo->prepare("
                INSERT INTO payments (
                    student_id, stage, amount, method, reference_no,
                    receipt_no, status, is_manual, notes, collected_by, payment_date, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, 'success', 1, ?, ?, ?, NOW()
                )
            ");
            $stmtPayment->execute([
                $studentId,
                $stage,
                $amount,
                $method,
                $refNo,
                $receiptNo,
                $particulars,
                (int)$user['id'],
                $paymentDate
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            log_audit('manual_receipt', $paymentId, 'create_manual_receipt', null, [
                'receipt_no' => $receiptNo,
                'student_name' => $studentName,
                'student_mobile' => $studentMobile,
                'amount' => $amount,
                'method' => $method,
                'is_existing_student' => ($existingStudentId > 0),
                'particulars' => $particulars,
                'created_by' => $user['id']
            ]);

            $pdo->commit();

            flash('success', "Manual receipt generated successfully! Receipt No: {$receiptNo} (Student: {$studentName}, Amount: ₹" . number_format($amount, 2) . ")");

            if ($redirectAction === 'print') {
                redirect('public/receipt.php?id=' . $paymentId . '&autoprint=1');
            } else {
                redirect('public/manual_receipt.php?created_id=' . $paymentId);
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    } elseif ($action === 'delete_manual_receipt') {
        try {
            $deletePaymentId = (int)($_POST['delete_payment_id'] ?? 0);
            $secretPin = trim($_POST['secret_pin'] ?? '');
            $deleteReason = trim($_POST['delete_reason'] ?? 'Manual receipt deleted by admin');

            PaymentService::deletePayment($deletePaymentId, (int)$user['id'], $secretPin, $deleteReason);
            flash('success', 'Manual receipt cancelled and deleted successfully.');
            redirect('public/manual_receipt.php');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$createdId = (int)($_GET['created_id'] ?? 0);
if ($createdId > 0) {
    $stmtC = $pdo->prepare("
        SELECT p.*, s.name as student_name, s.student_mobile, s.registration_no, s.form_no
        FROM payments p
        JOIN students s ON s.id = p.student_id
        WHERE p.id = ?
    ");
    $stmtC->execute([$createdId]);
    $createdPayment = $stmtC->fetch();
}

$batches = BatchService::getAllBatches();

$allStudents = $pdo->query("
    SELECT id, name, form_no, registration_no, student_mobile, address,
           COALESCE(joined_batch_id, preferred_batch_id) as batch_id, is_temporary
    FROM students
    WHERE is_temporary = 0
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$preselectedStudent = null;
$preselectedStudentId = (int)($_GET['student_id'] ?? 0);
if ($preselectedStudentId > 0) {
    foreach ($allStudents as $st) {
        if ((int)$st['id'] === $preselectedStudentId) {
            $preselectedStudent = $st;
            break;
        }
    }
}

$suggestedReceiptNo = generate_receipt_no();
$suggestedFormNo = 'TMP-' . date('ymd') . '-' . rand(100, 999);
$suggestedRegNo = 'TMP-' . date('Y') . '-' . rand(1000, 9999);

$manualReceipts = $pdo->query("
    SELECT p.*, s.name as student_name, s.student_mobile, s.registration_no, s.form_no,
           b.name as batch_name, u.name as collector_name
    FROM payments p
    JOIN students s ON s.id = p.student_id
    LEFT JOIN batches b ON b.id = s.joined_batch_id
    LEFT JOIN users u ON u.id = p.collected_by
    WHERE p.is_manual = 1 OR s.is_temporary = 1
    ORDER BY p.id DESC
    LIMIT 50
")->fetchAll();

$pageTitle = 'Manual / Temporary Receipt';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.mode-switch-bar {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    background: var(--surface-subtle);
    padding: 4px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
}
.mode-switch-btn {
    flex: 1;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.15s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.mode-switch-btn.active {
    background: var(--card-bg);
    color: var(--primary-hover);
    box-shadow: var(--shadow-xs);
}
.mode-switch-btn:not(.active) {
    background: transparent;
    color: var(--muted);
}
.form-split-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 28px;
}
@media (max-width: 900px) {
    .form-split-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}
.currency-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.currency-symbol {
    position: absolute;
    left: 12px;
    font-weight: 700;
    color: var(--muted);
    font-size: 14px;
}
.currency-input {
    padding-left: 28px !important;
    font-weight: 700;
    font-size: 16px !important;
    font-variant-numeric: tabular-nums;
}
.chip-group {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.chip-btn {
    background: var(--surface-subtle);
    border: 1px solid var(--border-input);
    color: var(--text-body);
    padding: 4px 10px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.15s ease;
}
.chip-btn:hover {
    background: var(--primary-light);
    color: var(--primary-hover);
    border-color: var(--primary-border);
}
.words-preview-card {
    background: var(--bg);
    border: 1px dashed var(--border-input);
    border-radius: var(--radius);
    padding: 9px 12px;
    margin-top: 10px;
    font-size: 12px;
    color: var(--muted);
}
.words-preview-val {
    font-weight: 600;
    color: var(--text);
    font-size: 13px;
    margin-top: 2px;
}
.method-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
@media (max-width: 600px) {
    .method-grid { grid-template-columns: repeat(2, 1fr); }
}
.method-label {
    border: 1px solid var(--border-input);
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 8px 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-body);
    margin: 0;
    transition: all 0.15s ease;
}
.method-label:hover {
    border-color: var(--primary);
    background: var(--bg);
}
.method-label:has(input[type="radio"]:checked) {
    border-color: var(--primary);
    background: var(--primary-light);
    color: var(--primary-hover);
}
.otp-auth-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 14px 16px;
    margin-top: 16px;
}
.receipt-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-weight: 700;
    color: var(--primary-hover);
    background: var(--primary-light);
    padding: 2px 7px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--primary-border);
    font-size: 12px;
}
</style>

<!-- Page Toolbar -->
<div class="page-header">
    <p class="page-header-subtitle">Generate printable A4 fee receipts for walk-in/temporary students or existing student accounts.</p>
    <div class="action-group">
        <a href="<?= url('public/payments.php') ?>" class="btn btn-sm btn-secondary">&larr; Back to Payments</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash-error"><strong>Error:</strong> <?= e($error) ?></div>
<?php endif; ?>

<?php if ($createdPayment): ?>
<div class="card" style="background: var(--success-bg); border-color: var(--success-border);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--success); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 14.5px; color: #14532D;">Receipt Generated Successfully</div>
                <div style="font-size: 13px; color: #166534; margin-top: 2px;">
                    Student: <strong><?= e($createdPayment['student_name']) ?></strong> &bull;
                    Amount: <strong>₹<?= number_format($createdPayment['amount'], 2) ?></strong> &bull;
                    Receipt No: <code><?= e($createdPayment['receipt_no']) ?></code>
                </div>
            </div>
        </div>
        <div class="action-group">
            <a href="<?= url('public/receipt.php?id=' . (int)$createdPayment['id']) ?>" target="_blank" class="btn btn-sm btn-success">
                Print Receipt &rarr;
            </a>
            <?php
            $waMsg = "*દેવાંગી સીવણ ક્લાસીસ - ફી રસીદ*\n---------------------------\nરસીદ નં: {$createdPayment['receipt_no']}\nવિદ્યાર્થી: {$createdPayment['student_name']}\nતારીખ: " . date('d-M-Y', strtotime($createdPayment['payment_date'])) . "\nરકમ: ₹" . number_format($createdPayment['amount'], 2) . "\nપદ્ધતિ: " . strtoupper($createdPayment['method']) . "\n---------------------------\nફી જમા થઈ ગયેલ છે. આભાર!\nદેવાંગી સીવણ ક્લાસીસ";
            $waUrl = "https://api.whatsapp.com/send?phone=91" . preg_replace('/\D/', '', $createdPayment['student_mobile'] ?? '') . "&text=" . urlencode($waMsg);
            ?>
            <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm btn-secondary">Share on WhatsApp</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main Creation Card -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Create Manual Receipt</h2>
            <small class="muted">Generates an official A4 double-copy receipt (Student Copy + Office Copy).</small>
        </div>
        <span class="badge badge-purple">A4 Double Copy</span>
    </div>

    <form method="post" action="" id="manualReceiptForm" onsubmit="return validateManualReceipt();">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_manual_receipt">
        <input type="hidden" name="existing_student_id" id="existingStudentIdInput" value="<?= $preselectedStudent ? (int)$preselectedStudent['id'] : '0' ?>">

        <!-- Mode Switcher -->
        <div class="mode-switch-bar">
            <button type="button" class="mode-switch-btn <?= !$preselectedStudent ? 'active' : '' ?>" id="modeBtnNew" onclick="switchStudentMode('new')">
                Walk-in / Temporary Student
            </button>
            <button type="button" class="mode-switch-btn <?= $preselectedStudent ? 'active' : '' ?>" id="modeBtnExisting" onclick="switchStudentMode('existing')">
                Existing Registered Student
            </button>
        </div>

        <!-- Existing Student Selector -->
        <div id="existingStudentGroup" style="<?= $preselectedStudent ? '' : 'display: none;' ?> margin-bottom: 20px; background: var(--primary-light); border: 1px solid var(--primary-border); border-radius: var(--radius-lg); padding: 14px 16px;">
            <label for="existingStudentDropdown" style="color: var(--primary-hover);">Select Registered Student</label>
            <select id="existingStudentDropdown" onchange="onExistingStudentSelected(this.value)">
                <option value="">-- Choose Student --</option>
                <?php foreach ($allStudents as $st): ?>
                    <option value="<?= $st['id'] ?>" <?= ($preselectedStudent && $preselectedStudent['id'] == $st['id']) ? 'selected' : '' ?>>
                        <?= e($st['name']) ?> — [<?= e($st['registration_no'] ?: $st['form_no']) ?>] (<?= e($st['student_mobile']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-split-grid">
            <!-- LEFT COLUMN: Student Details -->
            <div>
                <div class="section-title">1. Student Details</div>

                <div class="form-group">
                    <label for="studentNameInput">Student Full Name *</label>
                    <input type="text" name="student_name" id="studentNameInput" required value="<?= $preselectedStudent ? e($preselectedStudent['name']) : '' ?>" placeholder="e.g. Pooja Patel" autofocus>
                </div>

                <div class="form-group">
                    <label for="studentMobileInput">Student Mobile Number *</label>
                    <input type="tel" name="student_mobile" id="studentMobileInput" required maxlength="14" value="<?= $preselectedStudent ? e($preselectedStudent['student_mobile']) : '' ?>" placeholder="10-digit mobile number">
                </div>

                <div class="form-group">
                    <label for="addressInput">Address / City</label>
                    <input type="text" name="address" id="addressInput" value="<?= $preselectedStudent ? e($preselectedStudent['address']) : '' ?>" placeholder="e.g. Varachha, Surat">
                </div>

                <div class="form-group">
                    <label for="batchSelect">Assigned Batch / Timings</label>
                    <select name="batch_id" id="batchSelect">
                        <option value="0">-- Walk-in / Temporary / Not Assigned --</option>
                        <?php foreach ($batches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($preselectedStudent && $preselectedStudent['batch_id'] == $b['id']) ? 'selected' : '' ?>>
                                <?= e($b['name']) ?> (<?= date('h:i A', strtotime($b['start_time'])) ?> - <?= date('h:i A', strtotime($b['end_time'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row form-group">
                    <div>
                        <label for="regNoInput">Registration No</label>
                        <input type="text" name="registration_no" id="regNoInput" value="<?= $preselectedStudent ? e($preselectedStudent['registration_no']) : e($suggestedRegNo) ?>" style="font-family: monospace; font-weight: 600;">
                    </div>
                    <div>
                        <label for="formNoInput">Form No</label>
                        <input type="text" name="form_no" id="formNoInput" value="<?= $preselectedStudent ? e($preselectedStudent['form_no']) : e($suggestedFormNo) ?>" style="font-family: monospace;">
                    </div>
                </div>

                <div id="tempStudentNoticeBox" class="flash flash-info" style="margin-bottom: 0; font-size: 12.5px;">
                    This student will be recorded as <strong>Temporary</strong> so main admission waiting lists are unaffected.
                </div>
            </div>

            <!-- RIGHT COLUMN: Payment & Receipt Details -->
            <div>
                <div class="section-title">2. Payment &amp; Receipt Details</div>

                <div class="row form-group">
                    <div>
                        <label for="receiptNoInput">Receipt No *</label>
                        <input type="text" name="receipt_no" id="receiptNoInput" required value="<?= e($suggestedReceiptNo) ?>" style="font-family: monospace; font-weight: 700; color: var(--primary-hover);">
                    </div>
                    <div>
                        <label for="paymentDateInput">Payment Date &amp; Time *</label>
                        <input type="datetime-local" id="paymentDateInput" name="payment_date" value="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="stageSelect">Fee Category *</label>
                    <select name="stage" id="stageSelect" onchange="onFeeCategoryChange(this.value)">
                        <option value="other" selected>Custom / Workshop / Activity Fee</option>
                        <option value="form_fee">Registration / Form Confirmation Fee (₹500)</option>
                        <option value="joining_fee">1st Installment / Joining Fee (₹3,500)</option>
                        <option value="second_payment">2nd Installment (₹4,000)</option>
                        <option value="extension_fee">Course Extension Fee</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="particularsInput">Fee Particulars / Description *</label>
                    <input type="text" name="particulars" id="particularsInput" value="Manual Walk-in Student Fee Receipt" required>
                </div>

                <div class="form-group">
                    <label for="amountInput">Amount to Collect (₹) *</label>
                    <div class="currency-input-wrap">
                        <span class="currency-symbol">₹</span>
                        <input type="number" step="0.01" min="1" name="amount" id="amountInput" required placeholder="0.00" class="currency-input" oninput="updateAmountWords(this.value)">
                    </div>

                    <div class="chip-group">
                        <button type="button" class="chip-btn" onclick="setQuickAmount(500, 'form_fee', 'Form Confirmation Fee')">₹500 (Form)</button>
                        <button type="button" class="chip-btn" onclick="setQuickAmount(1000, 'other', 'Workshop / Advance Fee')">₹1,000</button>
                        <button type="button" class="chip-btn" onclick="setQuickAmount(2000, 'other', 'Special Stitching Masterclass')">₹2,000</button>
                        <button type="button" class="chip-btn" onclick="setQuickAmount(3500, 'joining_fee', '1st Course Installment')">₹3,500 (1st)</button>
                        <button type="button" class="chip-btn" onclick="setQuickAmount(4000, 'second_payment', '2nd Course Installment')">₹4,000 (2nd)</button>
                        <button type="button" class="chip-btn" onclick="setQuickAmount(7500, 'other', 'Full Course Tuition Fee')">₹7,500 (Full)</button>
                        <button type="button" class="chip-btn" onclick="setQuickAmount(8000, 'other', 'Full Course Complete Tuition')">₹8,000</button>
                    </div>

                    <div class="words-preview-card">
                        <span style="font-size: 11px; text-transform: uppercase; font-weight: 700;">Amount in Words</span>
                        <div class="words-preview-val" id="amountInWordsText">Zero Rupees Only</div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Payment Method *</label>
                    <div class="method-grid">
                        <label class="method-label"><input type="radio" name="method" value="cash" checked><span>Cash</span></label>
                        <label class="method-label"><input type="radio" name="method" value="upi"><span>UPI / GPay</span></label>
                        <label class="method-label"><input type="radio" name="method" value="bank_transfer"><span>Bank Transfer</span></label>
                        <label class="method-label"><input type="radio" name="method" value="cheque"><span>Cheque</span></label>
                        <label class="method-label"><input type="radio" name="method" value="card"><span>Card</span></label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="manualRefInput">Transaction / Reference / UTR No (Optional)</label>
                    <input type="text" id="manualRefInput" name="reference_no" placeholder="e.g. UPI Txn ID or Cheque No.">
                </div>

                <!-- WhatsApp OTP Verification Box -->
                <?php
                $waCfg = WhatsAppService::getConfig();
                $maskedList = [];
                if ($waCfg['mobile_1'] !== '') $maskedList[] = WhatsAppService::maskPhone($waCfg['mobile_1']);
                if ($waCfg['mobile_2'] !== '') $maskedList[] = WhatsAppService::maskPhone($waCfg['mobile_2']);
                ?>
                <div class="otp-auth-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
                        <span style="font-weight: 700; font-size: 13px; color: var(--text);">WhatsApp OTP / Security PIN *</span>
                        <button type="button" id="sendWaOtpBtn" onclick="sendManualWhatsAppOtp()" class="btn btn-sm btn-secondary">
                            Send WhatsApp OTP
                        </button>
                    </div>

                    <div class="muted" style="font-size: 12px; margin-bottom: 10px;">
                        <?php if (!empty($maskedList)): ?>
                            OTP will be sent to authorized numbers: <strong><?= e(implode(', ', $maskedList)) ?></strong>.
                        <?php else: ?>
                            WhatsApp numbers not configured. <a href="<?= url('public/settings.php') ?>" style="font-weight: 600;">Configure in Settings</a> or use Master PIN.
                        <?php endif; ?>
                    </div>

                    <div id="waOtpStatusBox" style="display: none; font-size: 12px; font-weight: 600; padding: 8px 10px; border-radius: var(--radius); margin-bottom: 10px;"></div>

                    <div style="position: relative;">
                        <input type="text" name="secret_pin" id="secretPinInput" required placeholder="Enter 6-digit OTP or Master PIN" style="font-weight: 700; letter-spacing: 2px; padding-right: 38px;" autocomplete="off">
                        <button type="button" onclick="togglePinVisibility()" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted); padding: 4px;" title="Show / Hide PIN">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="action-group" style="margin-top: 18px;">
                    <button type="submit" name="redirect_action" value="print" class="btn" style="flex: 1;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        <span>Generate &amp; Print Receipt</span>
                    </button>
                    <button type="submit" name="redirect_action" value="list" class="btn btn-secondary">
                        Save &amp; View List
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Recent Manual & Temporary Receipts Table -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Recent Manual / Temporary Receipts</h2>
            <small class="muted">Showing latest <?= count($manualReceipts) ?> manual or walk-in receipts.</small>
        </div>
        <div class="action-group">
            <input type="text" id="recentSearchInput" placeholder="Search recent receipts..." onkeyup="filterRecentReceipts()" style="height: 32px; padding: 5px 10px; font-size: 13px; border: 1px solid var(--border-input); border-radius: var(--radius); width: 220px;">
        </div>
    </div>

    <div class="table-wrap">
        <?php if (empty($manualReceipts)): ?>
            <div class="muted" style="padding: 36px 20px; text-align: center;">
                No manual or temporary receipts generated yet.
            </div>
        <?php else: ?>
            <table id="recentReceiptsTable">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Receipt No</th>
                        <th>Student Name &amp; Phone</th>
                        <th>Particulars / Category</th>
                        <th>Amount</th>
                        <th>Mode</th>
                        <th>Collected By</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($manualReceipts as $r): ?>
                    <tr class="receipt-row">
                        <td style="white-space: nowrap;">
                            <div style="font-weight: 600; color: var(--text);"><?= date('d M Y', strtotime($r['payment_date'])) ?></div>
                            <div class="muted" style="font-size: 11.5px;"><?= date('h:i A', strtotime($r['payment_date'])) ?></div>
                        </td>
                        <td>
                            <span class="receipt-code"><?= e($r['receipt_no']) ?></span>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span><?= e($r['student_name']) ?></span>
                                <?php if (!empty($r['is_temporary'])): ?>
                                    <span class="badge badge-amber">Temp</span>
                                <?php endif; ?>
                            </div>
                            <div class="muted" style="font-size: 11.5px;">
                                <?= e($r['student_mobile']) ?> &bull; <?= e($r['registration_no'] ?: $r['form_no']) ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 500; color: var(--text);"><?= e($r['notes'] ?: ucwords(str_replace('_', ' ', $r['stage']))) ?></div>
                            <div class="muted" style="font-size: 11.5px;"><?= e($r['batch_name'] ?: 'Temporary / Walk-in') ?></div>
                        </td>
                        <td style="font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; white-space: nowrap;">
                            ₹<?= number_format($r['amount'], 2) ?>
                        </td>
                        <td>
                            <span class="badge badge-gray"><?= strtoupper(e($r['method'])) ?></span>
                        </td>
                        <td class="muted" style="font-size: 12.5px;"><?= e($r['collector_name'] ?? 'Admin') ?></td>
                        <td class="text-right" style="white-space: nowrap;">
                            <div class="action-group" style="justify-content: flex-end;">
                                <a href="<?= url('public/receipt.php?id=' . (int)$r['id']) ?>" target="_blank" class="btn btn-sm btn-secondary" title="Print Official Receipt">
                                    Print
                                </a>

                                <?php
                                $msg = "*દેવાંગી સીવણ ક્લાસીસ - ફી રસીદ*\n---------------------------\nરસીદ નં: {$r['receipt_no']}\nવિદ્યાર્થી: {$r['student_name']}\nતારીખ: " . date('d-M-Y', strtotime($r['payment_date'])) . "\nરકમ: ₹" . number_format($r['amount'], 2) . "\nપદ્ધતિ: " . strtoupper($r['method']) . "\n---------------------------\nફી જમા થઈ ગયેલ છે. આભાર!\nદેવાંગી સીવણ ક્લાસીસ";
                                $wa = "https://api.whatsapp.com/send?phone=91" . preg_replace('/\D/', '', $r['student_mobile'] ?? '') . "&text=" . urlencode($msg);
                                ?>
                                <a href="<?= e($wa) ?>" target="_blank" class="btn btn-sm btn-secondary" title="Share on WhatsApp">
                                    Share
                                </a>

                                <button type="button" class="btn btn-sm btn-icon btn-danger-ghost" onclick='openDeleteModal(<?= json_encode([
                                    "id" => (int)$r["id"],
                                    "receipt_no" => $r["receipt_no"],
                                    "student_name" => $r["student_name"],
                                    "amount" => (float)$r["amount"]
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Delete Manual Receipt">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Delete Confirmation with PIN -->
<div class="custom-modal-overlay" id="deleteReceiptModal">
    <div class="custom-modal" style="max-width: 440px;">
        <div class="custom-modal-header" style="background: var(--danger-bg); border-bottom-color: var(--danger-border);">
            <h3 style="color: #991B1B;">Delete Manual Receipt</h3>
            <button type="button" class="custom-modal-close" onclick="closeDeleteModal()" aria-label="Close">&times;</button>
        </div>

        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_manual_receipt">
            <input type="hidden" name="delete_payment_id" id="delPaymentId">

            <div class="custom-modal-body">
                <p style="font-size: 13.5px; color: var(--text-body); margin: 0 0 14px;">
                    You are about to delete receipt <strong id="delRcptNo" style="font-family: monospace;"></strong>
                    (Student: <strong id="delStudentName"></strong>, Amount: <strong id="delAmount"></strong>).
                </p>

                <div class="form-group">
                    <label for="delManualReason">Reason *</label>
                    <input type="text" id="delManualReason" name="delete_reason" required placeholder="e.g. Duplicate entry or incorrect amount">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="delManualPin">Security PIN *</label>
                    <input type="password" id="delManualPin" name="secret_pin" required placeholder="Enter authorization PIN..." autocomplete="new-password">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
const studentsMap = <?= json_encode(array_column($allStudents, null, 'id')) ?>;
const suggestedRegNo = <?= json_encode($suggestedRegNo) ?>;
const suggestedFormNo = <?= json_encode($suggestedFormNo) ?>;

function switchStudentMode(mode) {
    const btnNew = document.getElementById('modeBtnNew');
    const btnExisting = document.getElementById('modeBtnExisting');
    const groupExisting = document.getElementById('existingStudentGroup');
    const hiddenIdInput = document.getElementById('existingStudentIdInput');
    const noticeBox = document.getElementById('tempStudentNoticeBox');

    if (mode === 'new') {
        btnNew.classList.add('active');
        btnExisting.classList.remove('active');
        groupExisting.style.display = 'none';
        hiddenIdInput.value = '0';
        document.getElementById('existingStudentDropdown').value = '';

        document.getElementById('studentNameInput').value = '';
        document.getElementById('studentMobileInput').value = '';
        document.getElementById('addressInput').value = '';
        document.getElementById('batchSelect').value = '0';
        document.getElementById('regNoInput').value = suggestedRegNo;
        document.getElementById('formNoInput').value = suggestedFormNo;

        noticeBox.innerHTML = 'This student will be recorded as <strong>Temporary</strong> so main admission waiting lists are unaffected.';
        noticeBox.className = 'flash flash-info';
    } else {
        btnExisting.classList.add('active');
        btnNew.classList.remove('active');
        groupExisting.style.display = 'flex';

        noticeBox.innerHTML = '<strong>Linked to Existing Student</strong> — receipt will be recorded directly in their student account.';
        noticeBox.className = 'flash flash-success';
    }
}

function onExistingStudentSelected(studentId) {
    if (!studentId || !studentsMap[studentId]) return;
    const s = studentsMap[studentId];

    document.getElementById('existingStudentIdInput').value = s.id;
    document.getElementById('studentNameInput').value = s.name || '';
    document.getElementById('studentMobileInput').value = s.student_mobile || '';
    document.getElementById('addressInput').value = s.address || '';
    document.getElementById('batchSelect').value = s.batch_id || '0';
    document.getElementById('regNoInput').value = s.registration_no || '';
    document.getElementById('formNoInput').value = s.form_no || '';
}

function numberToWordsINR(amount) {
    amount = parseFloat(amount);
    if (isNaN(amount) || amount <= 0) return 'Zero Rupees Only';

    const words = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'
    ];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    function convertSection(num) {
        let str = '';
        if (num >= 100) {
            str += words[Math.floor(num / 100)] + ' Hundred ';
            num %= 100;
        }
        if (num >= 20) {
            str += tens[Math.floor(num / 10)] + ' ';
            num %= 10;
        }
        if (num > 0) {
            str += words[num] + ' ';
        }
        return str;
    }

    let n = Math.floor(amount);
    let str = '';

    if (n >= 10000000) {
        str += convertSection(Math.floor(n / 10000000)) + 'Crore ';
        n %= 10000000;
    }
    if (n >= 100000) {
        str += convertSection(Math.floor(n / 100000)) + 'Lakh ';
        n %= 100000;
    }
    if (n >= 1000) {
        str += convertSection(Math.floor(n / 1000)) + 'Thousand ';
        n %= 1000;
    }
    if (n > 0) {
        str += convertSection(n);
    }

    return (str.trim() + ' Rupees Only');
}

function updateAmountWords(val) {
    const textElem = document.getElementById('amountInWordsText');
    if (val && parseFloat(val) > 0) {
        textElem.innerText = numberToWordsINR(val);
    } else {
        textElem.innerText = 'Zero Rupees Only';
    }
}

function setQuickAmount(amt, stage, particular) {
    const amtInput = document.getElementById('amountInput');
    amtInput.value = amt;
    updateAmountWords(amt);

    const stageSelect = document.getElementById('stageSelect');
    if (stageSelect && stage) {
        stageSelect.value = stage;
    }

    const partInput = document.getElementById('particularsInput');
    if (partInput && particular) {
        partInput.value = particular;
    }
}

function onFeeCategoryChange(stage) {
    const partInput = document.getElementById('particularsInput');
    const amtInput = document.getElementById('amountInput');

    if (stage === 'form_fee') {
        partInput.value = 'Registration / Form Confirmation Fee';
        if (!amtInput.value || amtInput.value == 0) {
            amtInput.value = 500;
            updateAmountWords(500);
        }
    } else if (stage === 'joining_fee') {
        partInput.value = '1st Tuition Installment / Joining Fee';
        if (!amtInput.value || amtInput.value == 0) {
            amtInput.value = 3500;
            updateAmountWords(3500);
        }
    } else if (stage === 'second_payment') {
        partInput.value = '2nd Tuition Installment';
        if (!amtInput.value || amtInput.value == 0) {
            amtInput.value = 4000;
            updateAmountWords(4000);
        }
    } else if (stage === 'extension_fee') {
        partInput.value = 'Course Extension Attendance Fee';
    } else {
        partInput.value = 'Manual Walk-in Student Fee Receipt';
    }
}

let manualOtpCooldown = null;
function sendManualWhatsAppOtp() {
    const name = document.getElementById('studentNameInput').value.trim();
    const amount = parseFloat(document.getElementById('amountInput').value);
    const stageSelect = document.getElementById('stageSelect');
    const stageLabel = stageSelect.options[stageSelect.selectedIndex]?.text || 'Manual Fee';
    const particulars = document.getElementById('particularsInput').value.trim();
    const methodEl = document.querySelector('input[name="method"]:checked');
    const method = methodEl ? methodEl.value : 'cash';
    const refInput = document.querySelector('input[name="reference_no"]');
    const referenceNo = refInput ? refInput.value.trim() : '';

    if (!name) {
        alert('Please enter the student name first.');
        document.getElementById('studentNameInput').focus();
        return;
    }
    if (isNaN(amount) || amount <= 0) {
        alert('Please enter a valid amount (> 0) first.');
        document.getElementById('amountInput').focus();
        return;
    }

    const btn = document.getElementById('sendWaOtpBtn');
    const statusBox = document.getElementById('waOtpStatusBox');
    btn.disabled = true;
    btn.innerText = 'Sending...';
    statusBox.style.display = 'block';
    statusBox.style.background = 'var(--primary-light)';
    statusBox.style.color = 'var(--primary-hover)';
    statusBox.innerText = 'Sending WhatsApp OTP to authorized numbers...';

    fetch('<?= url('api/send_payment_otp.php') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: '<?= e(csrf_token()) ?>',
            context: 'manual_receipt',
            student_name: name,
            amount: amount,
            stage_label: stageLabel,
            notes: particulars,
            method: method,
            reference_no: referenceNo
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            statusBox.style.background = 'var(--success-bg)';
            statusBox.style.color = '#166534';
            statusBox.innerText = data.message;
            document.getElementById('secretPinInput').focus();

            let sec = 60;
            if (manualOtpCooldown) clearInterval(manualOtpCooldown);
            manualOtpCooldown = setInterval(() => {
                sec--;
                if (sec <= 0) {
                    clearInterval(manualOtpCooldown);
                    btn.disabled = false;
                    btn.innerText = 'Resend OTP';
                } else {
                    btn.innerText = `Resend (${sec}s)`;
                }
            }, 1000);
        } else {
            statusBox.style.background = 'var(--danger-bg)';
            statusBox.style.color = '#991B1B';
            statusBox.innerText = data.error || 'Failed to send OTP.';
            btn.disabled = false;
            btn.innerText = 'Send WhatsApp OTP';
        }
    })
    .catch(() => {
        statusBox.style.background = 'var(--danger-bg)';
        statusBox.style.color = '#991B1B';
        statusBox.innerText = 'Network error: Unable to connect to server.';
        btn.disabled = false;
        btn.innerText = 'Send WhatsApp OTP';
    });
}

function togglePinVisibility() {
    const p = document.getElementById('secretPinInput');
    p.type = (p.type === 'password') ? 'text' : 'password';
}

function filterRecentReceipts() {
    const q = document.getElementById('recentSearchInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#recentReceiptsTable tbody tr.receipt-row');

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
}

function validateManualReceipt() {
    const name = document.getElementById('studentNameInput').value.trim();
    const rawMobile = document.getElementById('studentMobileInput').value.trim();
    const cleanMobile = rawMobile.replace(/\D/g, '');
    const amount = parseFloat(document.getElementById('amountInput').value);
    const pin = document.getElementById('secretPinInput').value.trim();

    if (!name) {
        alert('Please enter the student name.');
        document.getElementById('studentNameInput').focus();
        return false;
    }
    if (!cleanMobile || cleanMobile.length < 10) {
        alert('Please enter a valid 10-digit mobile number.');
        document.getElementById('studentMobileInput').focus();
        return false;
    }
    if (isNaN(amount) || amount <= 0) {
        alert('Please enter a valid amount greater than zero.');
        document.getElementById('amountInput').focus();
        return false;
    }
    if (!pin) {
        alert('Please enter the Security PIN or WhatsApp OTP.');
        document.getElementById('secretPinInput').focus();
        return false;
    }
    return true;
}

function openDeleteModal(data) {
    document.getElementById('delPaymentId').value = data.id;
    document.getElementById('delRcptNo').innerText = data.receipt_no;
    document.getElementById('delStudentName').innerText = data.student_name;
    document.getElementById('delAmount').innerText = '₹' + parseFloat(data.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 });
    document.getElementById('deleteReceiptModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteReceiptModal').classList.remove('active');
}

window.addEventListener('click', function(e) {
    const delModal = document.getElementById('deleteReceiptModal');
    if (e.target === delModal) closeDeleteModal();
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
