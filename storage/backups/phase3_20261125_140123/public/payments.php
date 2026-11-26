<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_engine.php';
require_once __DIR__ . '/../includes/services/PaymentService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
require_once __DIR__ . '/../includes/services/WhatsAppService.php';
require_admin();

$pdo = db();
$user = current_user();
$error = null;

// Active tab selection
$activeTab = $_GET['tab'] ?? 'history'; // 'history' or 'dues'

// 1. Handle Record Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_payment') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $stage = trim($_POST['stage'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $method = $_POST['method'] ?? 'cash';
            $refNo = trim($_POST['reference_no'] ?? '') ?: null;
            $secretPin = trim($_POST['secret_pin'] ?? '');

            $paymentId = PaymentService::recordPayment(
                $studentId,
                $stage,
                $amount,
                $method,
                $refNo,
                (int)$user['id'],
                $secretPin
            );

            // Fetch newly generated receipt number
            $rcptNo = $pdo->query("SELECT receipt_no FROM payments WHERE id = {$paymentId}")->fetchColumn();

            flash('success', "Payment recorded successfully! Receipt No: {$rcptNo} (Amount: ₹" . number_format($amount, 2) . ")");
            redirect('public/payments.php?payment_success=' . (int)$paymentId);

        } elseif ($action === 'update_pin') {
            $currentPin = trim($_POST['current_pin'] ?? '');
            $newPin = trim($_POST['new_pin'] ?? '');

            if (!PaymentService::verifySecretPin($currentPin, (int)$user['id'])) {
                throw new InvalidArgumentException('Current secret PIN or password is incorrect.');
            }

            PaymentService::updateSecretPin($newPin, (int)$user['id']);
            flash('success', 'Payment secret PIN updated successfully.');
            redirect('public/payments.php');

        } elseif ($action === 'delete_payment') {
            $deletePaymentId = (int)($_POST['delete_payment_id'] ?? 0);
            $secretPin = trim($_POST['secret_pin'] ?? '');
            $deleteReason = trim($_POST['delete_reason'] ?? '');

            PaymentService::deletePayment($deletePaymentId, (int)$user['id'], $secretPin, $deleteReason);
            flash('success', 'Payment receipt cancelled and deleted successfully.');
            redirect('public/payments.php?tab=history#paymentsTabsSection');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// 2. Fetch Payment Metrics & Dues
$metrics = PaymentService::getPaymentMetrics();
$pendingDues = PaymentService::getAllPendingDues();

// 3. Filters for Payment History
$stageFilter = $_GET['stage'] ?? '';
$methodFilter = $_GET['method'] ?? '';
$fromFilter = $_GET['from'] ?? '';
$toFilter = $_GET['to'] ?? '';
$searchFilter = trim($_GET['search'] ?? '');

$filterParams = [
    'stage' => $stageFilter,
    'method' => $methodFilter,
    'from' => $fromFilter,
    'to' => $toFilter,
    'search' => $searchFilter,
    'limit' => 300
];

$payments = PaymentService::getPayments($filterParams);
$totalFilteredAmount = array_sum(array_column($payments, 'amount'));

// 4. Excel Export
if (($_GET['export'] ?? '') === 'excel') {
    PaymentService::exportToCsv($payments);
    exit;
}

// 5. Build Students JSON Map for instant, zero-delay frontend duplicate & due amount validation
$activeStudents = $pdo->query("
    SELECT id, name, form_no, registration_no, student_mobile, entry_date, status, form_status 
    FROM students 
    WHERE is_temporary = 0
    ORDER BY name ASC
")->fetchAll();

$studentsLedgerMap = [];
foreach ($activeStudents as $st) {
    $stId = (int)$st['id'];
    $ledger = PaymentService::getStudentFeeLedger($stId);
    $studentsLedgerMap[$stId] = [
        'id' => $stId,
        'name' => $st['name'],
        'reg_no' => $st['registration_no'] ?: $st['form_no'],
        'phone' => $st['student_mobile'],
        'total_course_fee' => $ledger['total_course_fee'],
        'total_fee_overall' => $ledger['total_fee_overall'] ?? ($ledger['total_course_fee'] + ($ledger['stages']['extension_fee']['required_amount'] ?? 0)),
        'total_paid' => $ledger['total_paid_overall'],
        'total_due' => $ledger['total_due_overall'],
        'is_fully_paid' => $ledger['is_fully_paid'],
        'stages' => $ledger['stages']
    ];
}
$studentsLedgerJson = json_encode($studentsLedgerMap);

$stageNames = [
    'form_fee' => 'Form Fee',
    'joining_fee' => '1st Joining Fee',
    'second_payment' => '2nd Payment',
    'extension_fee' => 'Extension Fee',
    'extension' => 'Extension Fee',
    'other' => 'Other Fee'
];

// Fetch newly recorded payment details for instant feedback
$successPayment = null;
if (!empty($_GET['payment_success'])) {
    $stmtSucc = $pdo->prepare("
        SELECT p.*, s.name as student_name, s.registration_no, s.form_no, s.student_mobile
        FROM payments p
        JOIN students s ON s.id = p.student_id
        WHERE p.id = ?
    ");
    $stmtSucc->execute([(int)$_GET['payment_success']]);
    $successPayment = $stmtSucc->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'Payments & Accounts';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.payment-form-grid {
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 24px;
}
@media (max-width: 960px) {
    .payment-form-grid { grid-template-columns: 1fr; }
}
.input-currency-group {
    position: relative;
    display: flex;
    align-items: center;
}
.input-currency-prefix {
    position: absolute;
    left: 12px;
    font-size: 14px;
    font-weight: 700;
    color: var(--muted);
    pointer-events: none;
}
.input-currency-group input {
    padding-left: 28px !important;
    font-weight: 700;
    font-size: 15px;
    font-variant-numeric: tabular-nums;
}
.student-fee-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px 14px;
    margin-bottom: 14px;
}
.fee-stage-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.payment-methods-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
.method-card-label {
    border: 1px solid var(--border-input);
    border-radius: var(--radius);
    padding: 9px 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-body);
    background: var(--card-bg);
    transition: all 0.15s ease;
    user-select: none;
    margin: 0;
}
.method-card-label:hover {
    border-color: var(--primary);
    background: var(--bg);
}
.method-card-label:has(input:checked) {
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
.otp-auth-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 6px;
}
.student-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--primary-light);
    color: var(--primary-hover);
    font-weight: 700;
    font-size: 12.5px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
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
.ledger-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 16px;
}
@media (max-width: 560px) {
    .ledger-summary-grid { grid-template-columns: 1fr; }
}
.ledger-stat-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 12px;
    text-align: center;
}
</style>

<!-- Page Toolbar -->
<div class="page-header">
    <p class="page-header-subtitle">Record stage-wise student fees, monitor outstanding dues, and generate official receipts.</p>
    <div class="action-group">
        <button type="button" class="btn btn-sm btn-secondary" onclick="openPinModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span>Security PIN</span>
        </button>
        <a href="<?= url('public/manual_receipt.php') ?>" class="btn btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
            <span>Manual / Temp Receipt</span>
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash-error"><strong>Error:</strong> <?= e($error) ?></div>
<?php endif; ?>

<!-- KPI Summary Cards -->
<div class="grid grid-4" style="margin-bottom: 20px;">
    <?= render_kpi_card(
        "Today's Collection",
        '₹' . number_format($metrics['today_collected']),
        'Collected today via Cash/Digital',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'success'
    ) ?>
    <?= render_kpi_card(
        'This Month Collection',
        '₹' . number_format($metrics['month_collected']),
        date('F Y') . ' total',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'primary'
    ) ?>
    <?= render_kpi_card(
        'Total All-Time Fees',
        '₹' . number_format($metrics['total_collected']),
        (int)$metrics['total_transactions'] . ' receipts generated',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>'
    ) ?>
    <?= render_kpi_card(
        'Outstanding Dues',
        '₹' . number_format($metrics['total_pending_dues']),
        (int)$metrics['pending_students_count'] . ' students pending',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        $metrics['total_pending_dues'] > 0 ? 'danger' : 'default'
    ) ?>
</div>

<!-- Success Feedback Banner (After Recording Payment) -->
<?php if ($successPayment): ?>
<div class="card" id="paymentSuccessNotice" style="background: var(--success-bg); border-color: var(--success-border);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; gap: 12px; align-items: center;">
            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--success); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 14.5px; color: #14532D;">Payment Recorded Successfully</div>
                <div style="font-size: 13px; color: #166534; margin-top: 2px;">
                    Student: <strong><?= e($successPayment['student_name']) ?></strong> &bull;
                    Stage: <strong><?= e($stageNames[$successPayment['stage']] ?? $successPayment['stage']) ?></strong> &bull;
                    Amount: <strong>₹<?= number_format($successPayment['amount'], 2) ?></strong> (<?= strtoupper($successPayment['method']) ?>) &bull;
                    Receipt: <code><?= e($successPayment['receipt_no']) ?></code>
                </div>
            </div>
        </div>
        <div class="action-group">
            <a href="<?= url('public/receipt.php?id=' . (int)$successPayment['id']) ?>" target="_blank" class="btn btn-sm btn-success">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                <span>Print Receipt</span>
            </a>
            <button type="button" class="btn btn-sm btn-secondary" onclick="dismissSuccessNotice()">Dismiss</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Record New Payment Panel -->
<div class="card" id="recordPaymentPanel">
    <div class="card-header-bar">
        <div>
            <h2>Record New Payment</h2>
            <small class="muted">Select a registered student to auto-check remaining dues and prevent duplicate stage payments.</small>
        </div>
        <div class="action-group">
            <span class="badge badge-green">Duplicate Guard Active</span>
            <span class="badge badge-purple">PIN Authorization Required</span>
        </div>
    </div>

    <form method="post" action="" id="paymentForm" onsubmit="return validatePaymentSubmission();">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_payment">

        <div class="payment-form-grid">
            <!-- Left Column: Student, Stage & Amount -->
            <div>
                <div class="form-group">
                    <label for="studentSelect">Select Student *</label>
                    <select name="student_id" id="studentSelect" required onchange="onStudentChange(this.value)">
                        <option value="">-- Choose Registered Student --</option>
                        <?php foreach ($activeStudents as $st): ?>
                            <option value="<?= $st['id'] ?>">
                                <?= e($st['name']) ?> — [<?= e($st['registration_no'] ?: $st['form_no']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Live Student Fee Summary Card (Populated by JS) -->
                <div id="studentFeeOverview" style="display: none;"></div>

                <div class="form-group">
                    <label for="stageSelect">Payment Stage *</label>
                    <select name="stage" id="stageSelect" required onchange="onStageChange()">
                        <option value="">-- Select Student First --</option>
                        <option value="joining_fee" id="opt_joining_fee">1st Payment on Joining (₹3,500)</option>
                        <option value="second_payment" id="opt_second_payment">2nd Payment (₹4,000)</option>
                        <option value="form_fee" id="opt_form_fee">Form Fee (₹500)</option>
                        <option value="extension_fee" id="opt_extension_fee">Extension Fee</option>
                        <option value="other" id="opt_other">Other / Miscellaneous Fee</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="amountInput">Amount to Collect (₹) *</label>
                    <div class="input-currency-group">
                        <span class="input-currency-prefix">₹</span>
                        <input type="number" step="0.01" name="amount" id="amountInput" placeholder="0.00" required oninput="validateAmountLimit()">
                    </div>
                    <div id="amountLimitNote" style="font-size: 12px; margin-top: 5px; font-weight: 600;"></div>
                </div>

                <div id="overPaymentAlert" class="flash flash-error" style="display: none; margin-top: 12px; margin-bottom: 0;"></div>
            </div>

            <!-- Right Column: Payment Method, Reference & Security PIN -->
            <div>
                <div class="form-group">
                    <label>Payment Method *</label>
                    <div class="payment-methods-grid">
                        <label class="method-card-label">
                            <input type="radio" name="method" value="cash" checked>
                            <span>Cash</span>
                        </label>
                        <label class="method-card-label">
                            <input type="radio" name="method" value="upi">
                            <span>UPI / GPay</span>
                        </label>
                        <label class="method-card-label">
                            <input type="radio" name="method" value="bank_transfer">
                            <span>Bank Transfer</span>
                        </label>
                        <label class="method-card-label">
                            <input type="radio" name="method" value="cheque">
                            <span>Cheque</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="refNoInput">Reference / Transaction / UTR No.</label>
                    <input type="text" name="reference_no" id="refNoInput" placeholder="e.g. UPI Txn ID or Cheque No.">
                </div>

                <!-- WhatsApp OTP & PIN Authorization Box -->
                <?php
                $waCfg = WhatsAppService::getConfig();
                $maskedList = [];
                if ($waCfg['mobile_1'] !== '') $maskedList[] = WhatsAppService::maskPhone($waCfg['mobile_1']);
                if ($waCfg['mobile_2'] !== '') $maskedList[] = WhatsAppService::maskPhone($waCfg['mobile_2']);
                ?>
                <div class="otp-auth-box">
                    <div class="otp-auth-head">
                        <span style="font-weight: 700; font-size: 13px; color: var(--text);">WhatsApp OTP / Security PIN *</span>
                        <button type="button" id="sendPayWaOtpBtn" onclick="sendPaymentWhatsAppOtp()" class="btn btn-sm btn-secondary">
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

                    <div id="payWaOtpStatus" style="display: none; font-size: 12px; font-weight: 600; padding: 8px 10px; border-radius: var(--radius); margin-bottom: 10px;"></div>

                    <div style="position: relative;">
                        <input type="text" name="secret_pin" id="secretPinInput" required placeholder="Enter 6-digit OTP or Master PIN" style="font-weight: 700; letter-spacing: 2px; padding-right: 38px;" autocomplete="off">
                        <button type="button" onclick="togglePinVisibility()" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted); padding: 4px;" title="Show / Hide PIN">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div style="margin-top: 16px;">
                    <button type="submit" class="btn btn-block" id="recordSubmitBtn">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Authorize &amp; Record Payment</span>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Tabbed Ledger Card: Payment History & Outstanding Dues -->
<div class="card" id="paymentsTabsSection">
    <div class="tabs-underline">
        <button type="button" id="tabBtnHistory" onclick="switchViewTab('history')" class="tab-underline-btn <?= $activeTab === 'history' ? 'active' : '' ?>">
            <span>Payments History</span>
            <span class="badge <?= $activeTab === 'history' ? 'badge-purple' : 'badge-gray' ?>"><?= count($payments) ?></span>
        </button>
        <button type="button" id="tabBtnDues" onclick="switchViewTab('dues')" class="tab-underline-btn <?= $activeTab === 'dues' ? 'active' : '' ?>">
            <span>Outstanding Dues</span>
            <span class="badge <?= count($pendingDues) > 0 ? 'badge-red' : 'badge-gray' ?>"><?= count($pendingDues) ?></span>
        </button>
    </div>

    <!-- TAB 1: OUTSTANDING DUES LIST -->
    <div id="duesTabPane" style="display: <?= $activeTab === 'dues' ? 'block' : 'none' ?>;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
            <div>
                <h3>Student Outstanding Dues</h3>
                <small class="muted">Students with pending joining, second installment, or extension fees.</small>
            </div>
            <div>
                <input type="text" id="duesSearchInput" placeholder="Search name or mobile..." onkeyup="filterDuesTable()" style="width: 240px; height: 34px; padding: 6px 10px; border: 1px solid var(--border-input); border-radius: var(--radius); font-size: 13px;">
            </div>
        </div>

        <div class="table-wrap">
            <table id="duesTable">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Fee Stage</th>
                        <th>Pending Amount</th>
                        <th>Due Date</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingDues)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 36px 20px; color: var(--success); font-weight: 600;">
                                All student fees are up to date. No pending dues.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendingDues as $due):
                            $inits = mb_substr($due['student_name'], 0, 1, 'UTF-8');
                        ?>
                            <tr class="due-row">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div class="student-avatar"><?= e(strtoupper($inits)) ?></div>
                                        <div>
                                            <div style="font-weight: 600; color: var(--text);" class="due-student-name"><?= e($due['student_name']) ?></div>
                                            <div class="muted due-student-phone" style="font-size: 12px;">
                                                <?= e($due['registration_no']) ?> &bull; <?= e($due['student_mobile']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-purple"><?= e($due['fee_label']) ?></span>
                                </td>
                                <td style="font-weight: 700; color: var(--warning-hover); font-variant-numeric: tabular-nums;">
                                    ₹<?= number_format($due['amount'], 2) ?>
                                </td>
                                <td>
                                    <?php if ($due['is_urgent']): ?>
                                        <span class="badge badge-red">Overdue</span>
                                        <div style="font-size: 11.5px; color: var(--danger); margin-top: 2px;"><?= e($due['due_date']) ?></div>
                                    <?php else: ?>
                                        <span style="font-variant-numeric: tabular-nums;"><?= e($due['due_date']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right" style="white-space: nowrap;">
                                    <div class="action-group" style="justify-content: flex-end;">
                                        <button type="button" class="btn btn-sm btn-success" onclick="quickPayFromDues(<?= $due['student_id'] ?>, '<?= e($due['stage']) ?>', <?= (float)$due['amount'] ?>)">
                                            Pay Now
                                        </button>

                                        <?php
                                        $waMsg = "નમસ્તે {$due['student_name']},\nદેવાંગી સીવણ ક્લાસીસ તરફથી જાણ કરવાની કે તમારી " . $due['fee_label'] . " પેટે ₹" . number_format($due['amount']) . " ભરવાના બાકી છે.\nમહેરબાની કરીને વહેલી તકે ક્લાસ પર આવીને જમા કરાવી આપશો.\nઆભાર!\nદેવાંગી સીવણ ક્લાસીસ";
                                        $waUrl = "https://api.whatsapp.com/send?phone=91" . preg_replace('/\D/', '', $due['student_mobile']) . "&text=" . urlencode($waMsg);
                                        ?>
                                        <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm btn-secondary" title="Send WhatsApp Reminder">
                                            Reminder
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 2: PAYMENT TRANSACTIONS HISTORY -->
    <div id="historyTabPane" style="display: <?= $activeTab === 'history' ? 'block' : 'none' ?>;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
            <div>
                <h3>Payment Transactions</h3>
                <div class="muted" style="font-size: 12.5px;">
                    Filtered Total: <strong style="color: var(--success);">₹<?= number_format($totalFilteredAmount, 2) ?></strong> (<?= count($payments) ?> receipts)
                </div>
            </div>
            <div class="action-group">
                <?= render_export_button('?export=excel&stage=' . urlencode($stageFilter) . '&from=' . urlencode($fromFilter) . '&to=' . urlencode($toFilter), 'Export CSV') ?>
            </div>
        </div>

        <!-- Clean Filter Bar -->
        <form method="get" action="?tab=history#paymentsTabsSection" class="filter-form" style="background: var(--bg); padding: 14px; border-radius: var(--radius-lg); border: 1px solid var(--border); margin-bottom: 16px;">
            <input type="hidden" name="tab" value="history">
            <div style="flex: 2 1 200px;">
                <label for="paySearchFilter">Search</label>
                <input type="text" id="paySearchFilter" name="search" value="<?= e($searchFilter) ?>" placeholder="Receipt no, name, mobile, reg no...">
            </div>
            <div style="flex: 1 1 150px;">
                <label for="payStageFilter">Stage</label>
                <select id="payStageFilter" name="stage" onchange="this.form.submit()">
                    <option value="">All Stages</option>
                    <option value="joining_fee" <?= $stageFilter === 'joining_fee' ? 'selected' : '' ?>>1st Joining Fee</option>
                    <option value="second_payment" <?= $stageFilter === 'second_payment' ? 'selected' : '' ?>>2nd Payment</option>
                    <option value="form_fee" <?= $stageFilter === 'form_fee' ? 'selected' : '' ?>>Form Fee</option>
                    <option value="extension_fee" <?= $stageFilter === 'extension_fee' ? 'selected' : '' ?>>Extension Fee</option>
                    <option value="other" <?= $stageFilter === 'other' ? 'selected' : '' ?>>Other Fee</option>
                </select>
            </div>
            <div style="flex: 1 1 130px;">
                <label for="payMethodFilter">Method</label>
                <select id="payMethodFilter" name="method" onchange="this.form.submit()">
                    <option value="">All Methods</option>
                    <option value="cash" <?= $methodFilter === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="upi" <?= $methodFilter === 'upi' ? 'selected' : '' ?>>UPI / GPay</option>
                    <option value="bank_transfer" <?= $methodFilter === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                    <option value="cheque" <?= $methodFilter === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>
            <div style="flex: 1 1 130px;">
                <label for="payFromFilter">From</label>
                <input type="date" id="payFromFilter" name="from" value="<?= e($fromFilter) ?>">
            </div>
            <div style="flex: 1 1 130px;">
                <label for="payToFilter">To</label>
                <input type="date" id="payToFilter" name="to" value="<?= e($toFilter) ?>">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">Filter</button>
                <?php if ($stageFilter !== '' || $methodFilter !== '' || $fromFilter !== '' || $toFilter !== '' || $searchFilter !== ''): ?>
                    <a href="<?= url('public/payments.php?tab=history#paymentsTabsSection') ?>" class="btn btn-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Receipt No</th>
                        <th>Student Name</th>
                        <th>Stage</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Ref / UTR</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="8" class="muted" style="text-align: center; padding: 36px 20px;">
                                No payment records match the selected criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <div style="font-weight: 600; color: var(--text);"><?= date('d M Y', strtotime($p['payment_date'])) ?></div>
                                    <div class="muted" style="font-size: 11.5px;"><?= date('h:i A', strtotime($p['payment_date'])) ?></div>
                                </td>
                                <td>
                                    <span class="receipt-code"><?= e($p['receipt_no']) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                        <span><?= e($p['student_name']) ?></span>
                                        <?php if (!empty($p['is_manual'])): ?>
                                            <span class="badge badge-amber">Manual</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="muted" style="font-size: 11.5px;">
                                        <?= e($p['registration_no'] ?: $p['form_no']) ?>
                                    </div>
                                    <?php if (!empty($p['notes'])): ?>
                                        <div style="font-size: 11.5px; color: var(--primary); margin-top: 2px;">
                                            <?= e($p['notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-gray">
                                        <?= e($stageNames[$p['stage']] ?? ucwords(str_replace('_', ' ', $p['stage']))) ?>
                                    </span>
                                </td>
                                <td style="font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; white-space: nowrap;">
                                    ₹<?= number_format($p['amount'], 2) ?>
                                </td>
                                <td>
                                    <span class="badge badge-gray"><?= strtoupper(e($p['method'])) ?></span>
                                </td>
                                <td>
                                    <code style="font-size: 12px; color: var(--muted);">
                                        <?= $p['reference_no'] ? e($p['reference_no']) : '-' ?>
                                    </code>
                                </td>
                                <td class="text-right" style="white-space: nowrap;">
                                    <div class="action-group" style="justify-content: flex-end;">
                                        <a href="<?= url('public/receipt.php?id=' . $p['id']) ?>" target="_blank" class="btn btn-sm btn-secondary" title="Print Receipt">
                                            Receipt
                                        </a>

                                        <?php
                                        $stageClean = $stageNames[$p['stage']] ?? $p['stage'];
                                        $rcptMsg = "*દેવાંગી સીવણ ક્લાસીસ - ફી રસીદ*\n---------------------------\nરસીદ નં: {$p['receipt_no']}\nવિદ્યાર્થી: {$p['student_name']}\nતારીખ: " . date('d-M-Y', strtotime($p['payment_date'])) . "\nતબક્કો: {$stageClean}\nરકમ: ₹" . number_format($p['amount'], 2) . "\nપદ્ધતિ: " . strtoupper($p['method']) . "\n---------------------------\nફી જમા થઈ ગયેલ છે. આભાર!\nદેવાંગી સીવણ ક્લાસીસ";
                                        $waRcptUrl = "https://api.whatsapp.com/send?phone=91" . preg_replace('/\D/', '', $p['student_mobile'] ?? '') . "&text=" . urlencode($rcptMsg);
                                        ?>
                                        <a href="<?= e($waRcptUrl) ?>" target="_blank" class="btn btn-sm btn-secondary" title="Share Receipt on WhatsApp">
                                            Share
                                        </a>

                                        <button type="button" class="btn btn-sm btn-icon btn-secondary" onclick="openStudentLedger(<?= $p['student_id'] ?>)" title="View Student Fee Statement">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                        </button>

                                        <button type="button" class="btn btn-sm btn-icon btn-danger-ghost" onclick='openDeletePaymentModal(<?= json_encode([
                                            "id" => (int)$p["id"],
                                            "receipt_no" => $p["receipt_no"],
                                            "student_name" => $p["student_name"],
                                            "amount" => (float)$p["amount"],
                                            "stage" => $stageClean,
                                            "date" => date("d-M-Y", strtotime($p["payment_date"]))
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Delete Payment Receipt">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== DELETE PAYMENT MODAL ==================== -->
<div class="custom-modal-overlay" id="deletePaymentModal">
    <div class="custom-modal" style="max-width: 460px;">
        <div class="custom-modal-header" style="background: var(--danger-bg); border-bottom-color: var(--danger-border);">
            <h3 style="color: #991B1B;">Delete Payment Receipt</h3>
            <button type="button" class="custom-modal-close" onclick="closeDeletePaymentModal()" aria-label="Close">&times;</button>
        </div>

        <form method="post" action="?tab=history#paymentsTabsSection">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_payment">
            <input type="hidden" name="delete_payment_id" id="delPaymentId">

            <div class="custom-modal-body">
                <div class="flash flash-danger" style="flex-direction: column; align-items: flex-start; gap: 4px; margin-bottom: 16px;">
                    <div>You are about to permanently cancel this payment receipt:</div>
                    <div style="font-size: 13px;">
                        &bull; Receipt No: <strong id="delReceiptNo"></strong><br>
                        &bull; Student: <strong id="delStudentName"></strong><br>
                        &bull; Amount: <strong id="delAmount"></strong> (<span id="delStage"></span>)
                    </div>
                </div>

                <div class="form-group">
                    <label for="delSecretPin">Security PIN *</label>
                    <input type="password" id="delSecretPin" name="secret_pin" required placeholder="Enter authorization PIN..." autocomplete="new-password">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="delReason">Reason for Deletion</label>
                    <input type="text" id="delReason" name="delete_reason" placeholder="e.g. Duplicate entry">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeletePaymentModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm &amp; Delete Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== CHANGE PIN MODAL ==================== -->
<div class="custom-modal-overlay" id="pinModal">
    <div class="custom-modal" style="max-width: 420px;">
        <div class="custom-modal-header">
            <h3>Change Payment Security PIN</h3>
            <button type="button" class="custom-modal-close" onclick="closePinModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_pin">

            <div class="custom-modal-body">
                <p class="muted" style="font-size: 12.5px; margin: 0 0 14px;">
                    This security PIN authorizes payment entries and cancellations. Default initial PIN: <strong>1234</strong>.
                </p>

                <div class="form-group">
                    <label for="curPinInput">Current PIN or Account Password *</label>
                    <input type="password" id="curPinInput" name="current_pin" required placeholder="Current PIN">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="newPinInput">New Security PIN (min 4 digits) *</label>
                    <input type="text" id="newPinInput" name="new_pin" minlength="4" required placeholder="e.g. 5678" style="font-weight: 700; letter-spacing: 2px;">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePinModal()">Cancel</button>
                <button type="submit" class="btn">Update PIN</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== STUDENT LEDGER MODAL ==================== -->
<div class="custom-modal-overlay" id="studentLedgerModal">
    <div class="custom-modal" style="max-width: 600px;">
        <div class="custom-modal-header">
            <h3>Student Fee Statement</h3>
            <button type="button" class="custom-modal-close" onclick="closeStudentLedger()" aria-label="Close">&times;</button>
        </div>
        <div class="custom-modal-body" id="ledgerModalContent"></div>
        <div class="custom-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeStudentLedger()">Close</button>
        </div>
    </div>
</div>

<!-- CLIENT JAVASCRIPT LOGIC -->
<script>
var studentsLedger = <?= $studentsLedgerJson ?>;
var currentMaxAllowed = null;

function onStudentChange(studentId) {
    var overviewBox = document.getElementById('studentFeeOverview');
    var stageSelect = document.getElementById('stageSelect');
    var amountInput = document.getElementById('amountInput');
    var limitNote = document.getElementById('amountLimitNote');
    var alertBox = document.getElementById('overPaymentAlert');

    alertBox.style.display = 'none';

    if (!studentId || !studentsLedger[studentId]) {
        overviewBox.style.display = 'none';
        resetStageOptions();
        amountInput.value = '';
        limitNote.innerText = '';
        currentMaxAllowed = null;
        return;
    }

    var data = studentsLedger[studentId];
    var stages = data.stages;

    var formStatusHtml = stages.form_fee.is_paid
        ? '<span class="badge badge-green">Form Fee: Paid</span>'
        : '<span class="badge badge-amber">Form Fee: ₹' + stages.form_fee.due_amount + ' Due</span>';

    var joiningStatusHtml = stages.joining_fee.is_paid
        ? '<span class="badge badge-green">1st Joining: Paid</span>'
        : '<span class="badge badge-amber">1st Joining: ₹' + stages.joining_fee.due_amount + ' Due</span>';

    var secondStatusHtml = stages.second_payment.is_paid
        ? '<span class="badge badge-green">2nd Payment: Paid</span>'
        : '<span class="badge badge-amber">2nd Payment: ₹' + stages.second_payment.due_amount + ' Due</span>';

    var extStatusHtml = '';
    if (stages.extension_fee.due_amount > 0) {
        extStatusHtml = '<span class="badge badge-amber">Extension: ₹' + stages.extension_fee.due_amount + ' Due</span>';
    } else if (stages.extension_fee.paid_amount > 0) {
        extStatusHtml = '<span class="badge badge-green">Extension: Paid (₹' + stages.extension_fee.paid_amount.toLocaleString('en-IN') + ')</span>';
    }

    var totalDueHtml = data.total_due > 0
        ? '<span class="badge badge-red" style="margin-left: auto;">Total Due: ₹' + data.total_due.toLocaleString('en-IN') + '</span>'
        : '<span class="badge badge-green" style="margin-left: auto;">All Fees Paid</span>';

    var extReq = stages.extension_fee ? (stages.extension_fee.paid_amount + stages.extension_fee.due_amount) : 0;
    var totalFeeAmount = data.total_fee_overall || (data.total_course_fee + extReq);
    var subFeeBreakdown = '';
    if (extReq > 0) {
        subFeeBreakdown = ' <span class="muted" style="font-size: 11px;">(Course: ₹' + data.total_course_fee.toLocaleString('en-IN') + ' + Extension: ₹' + extReq.toLocaleString('en-IN') + ')</span>';
    }

    overviewBox.innerHTML =
        '<div class="student-fee-card">' +
            '<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">' +
                '<strong style="font-size: 13px; color: var(--text);">' + escapeHtml(data.name) + ' (' + escapeHtml(data.reg_no) + ')</strong>' +
                '<div style="font-size: 12px; font-weight: 600; color: var(--text);">' +
                    'Paid: ₹' + data.total_paid.toLocaleString('en-IN') + ' / ₹' + totalFeeAmount.toLocaleString('en-IN') +
                    subFeeBreakdown +
                '</div>' +
            '</div>' +
            '<div class="fee-stage-pills">' +
                formStatusHtml +
                joiningStatusHtml +
                secondStatusHtml +
                extStatusHtml +
                totalDueHtml +
            '</div>' +
        '</div>';
    overviewBox.style.display = 'block';

    updateStageOption('opt_form_fee', stages.form_fee);
    updateStageOption('opt_joining_fee', stages.joining_fee);
    updateStageOption('opt_second_payment', stages.second_payment);
    updateStageOption('opt_extension_fee', stages.extension_fee);

    var preferredStage = '';
    if (stages.joining_fee.is_allowed) preferredStage = 'joining_fee';
    else if (stages.second_payment.is_allowed) preferredStage = 'second_payment';
    else if (stages.form_fee.is_allowed) preferredStage = 'form_fee';
    else if (stages.extension_fee.is_allowed) preferredStage = 'extension_fee';
    else preferredStage = 'other';

    stageSelect.value = preferredStage;
    onStageChange();
}

function updateStageOption(elementId, stageData) {
    var opt = document.getElementById(elementId);
    if (!opt) return;

    if (stageData.is_paid || !stageData.is_allowed) {
        opt.disabled = true;
        opt.innerText = opt.innerText.replace(' [ALREADY PAID]', '') + ' [ALREADY PAID]';
    } else {
        opt.disabled = false;
        opt.innerText = opt.innerText.replace(' [ALREADY PAID]', '');
    }
}

function resetStageOptions() {
    var opts = ['opt_form_fee', 'opt_joining_fee', 'opt_second_payment', 'opt_extension_fee'];
    opts.forEach(function(id) {
        var opt = document.getElementById(id);
        if (opt) {
            opt.disabled = false;
            opt.innerText = opt.innerText.replace(' [ALREADY PAID]', '');
        }
    });
}

function onStageChange() {
    var studentId = document.getElementById('studentSelect').value;
    var stage = document.getElementById('stageSelect').value;
    var amountInput = document.getElementById('amountInput');
    var limitNote = document.getElementById('amountLimitNote');
    var alertBox = document.getElementById('overPaymentAlert');
    alertBox.style.display = 'none';

    if (!studentId || !studentsLedger[studentId] || !stage) {
        amountInput.value = '';
        limitNote.innerText = '';
        currentMaxAllowed = null;
        return;
    }

    var stageInfo = studentsLedger[studentId].stages[stage];
    if (!stageInfo) {
        currentMaxAllowed = null;
        limitNote.innerText = '';
        return;
    }

    if (!stageInfo.is_allowed && stage !== 'other') {
        alert(stageInfo.blocked_reason || 'This fee stage is already paid and cannot be collected again.');
        amountInput.value = '';
        currentMaxAllowed = 0;
        return;
    }

    var due = parseFloat(stageInfo.due_amount);
    if (stage !== 'other' && due > 0) {
        amountInput.value = due;
        amountInput.max = due;
        currentMaxAllowed = due;
        limitNote.innerText = 'Maximum Due Allowed: ₹' + due.toLocaleString('en-IN');
        limitNote.style.color = 'var(--success)';
    } else if (stage === 'other') {
        amountInput.removeAttribute('max');
        currentMaxAllowed = null;
        limitNote.innerText = 'Enter custom amount for miscellaneous fee.';
        limitNote.style.color = 'var(--muted)';
    } else {
        amountInput.value = '';
        currentMaxAllowed = 0;
    }

    validateAmountLimit();
}

function validateAmountLimit() {
    var amountInput = document.getElementById('amountInput');
    var alertBox = document.getElementById('overPaymentAlert');
    var submitBtn = document.getElementById('recordSubmitBtn');
    var val = parseFloat(amountInput.value);

    if (currentMaxAllowed !== null && currentMaxAllowed > 0 && val > currentMaxAllowed) {
        alertBox.innerHTML = 'Amount exceeds maximum due balance of <strong>₹' + currentMaxAllowed.toLocaleString('en-IN') + '</strong> for this stage.';
        alertBox.style.display = 'flex';
        amountInput.style.borderColor = 'var(--danger)';
        submitBtn.disabled = true;
    } else {
        alertBox.style.display = 'none';
        amountInput.style.borderColor = 'var(--border-input)';
        submitBtn.disabled = false;
    }
}

function validatePaymentSubmission() {
    var studentId = document.getElementById('studentSelect').value;
    var stage = document.getElementById('stageSelect').value;
    var amount = parseFloat(document.getElementById('amountInput').value);
    var pin = document.getElementById('secretPinInput').value.trim();

    if (!studentId) {
        alert('Please select a student.');
        return false;
    }
    if (!stage) {
        alert('Please select a payment stage.');
        return false;
    }
    if (isNaN(amount) || amount <= 0) {
        alert('Please enter a valid amount greater than zero.');
        return false;
    }
    if (currentMaxAllowed !== null && amount > currentMaxAllowed) {
        alert('Amount cannot exceed maximum due balance (₹' + currentMaxAllowed + ').');
        return false;
    }
    if (!pin) {
        alert('Please enter the Security PIN or WhatsApp OTP.');
        document.getElementById('secretPinInput').focus();
        return false;
    }

    return true;
}

function switchViewTab(tab) {
    var duesPane = document.getElementById('duesTabPane');
    var histPane = document.getElementById('historyTabPane');
    var btnDues = document.getElementById('tabBtnDues');
    var btnHist = document.getElementById('tabBtnHistory');

    if (tab === 'dues') {
        duesPane.style.display = 'block';
        histPane.style.display = 'none';
        btnDues.classList.add('active');
        btnHist.classList.remove('active');
        history.replaceState(null, null, '?tab=dues');
    } else {
        duesPane.style.display = 'none';
        histPane.style.display = 'block';
        btnHist.classList.add('active');
        btnDues.classList.remove('active');
        history.replaceState(null, null, '?tab=history');
    }
}

function dismissSuccessNotice() {
    var el = document.getElementById('paymentSuccessNotice');
    if (el) {
        el.style.opacity = '0';
        el.style.transition = 'opacity 0.2s ease';
        setTimeout(function() { el.remove(); }, 200);
    }
    history.replaceState(null, null, 'payments.php?tab=history');
}

function quickPayFromDues(studentId, stage, amount) {
    document.getElementById('studentSelect').value = studentId;
    onStudentChange(studentId);
    document.getElementById('stageSelect').value = stage;
    onStageChange();
    document.getElementById('amountInput').value = amount;

    var panel = document.getElementById('recordPaymentPanel');
    if (panel) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    setTimeout(function() {
        document.getElementById('secretPinInput').focus();
    }, 450);
}

var payOtpCooldown = null;
function sendPaymentWhatsAppOtp() {
    var stId = document.getElementById('studentSelect').value;
    var stageSelect = document.getElementById('stageSelect');
    var amount = parseFloat(document.getElementById('amountInput').value);
    var refNo = document.getElementById('refNoInput').value.trim();
    var methodEl = document.querySelector('input[name="method"]:checked');
    var method = methodEl ? methodEl.value : 'cash';

    if (!stId || !studentsLedger[stId]) {
        alert('Please select a student first.');
        document.getElementById('studentSelect').focus();
        return;
    }
    if (!stageSelect.value) {
        alert('Please select a payment stage first.');
        stageSelect.focus();
        return;
    }
    if (isNaN(amount) || amount <= 0) {
        alert('Please enter a valid amount (> 0) first.');
        document.getElementById('amountInput').focus();
        return;
    }

    var studentName = studentsLedger[stId].name + ' (' + studentsLedger[stId].reg_no + ')';
    var stageLabel = stageSelect.options[stageSelect.selectedIndex].text;

    var btn = document.getElementById('sendPayWaOtpBtn');
    var statusBox = document.getElementById('payWaOtpStatus');
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
            context: 'payment',
            student_name: studentName,
            amount: amount,
            stage_label: stageLabel,
            method: method,
            reference_no: refNo
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            statusBox.style.background = 'var(--success-bg)';
            statusBox.style.color = '#166534';
            statusBox.innerText = data.message;
            document.getElementById('secretPinInput').focus();

            var sec = 60;
            if (payOtpCooldown) clearInterval(payOtpCooldown);
            payOtpCooldown = setInterval(function() {
                sec--;
                if (sec <= 0) {
                    clearInterval(payOtpCooldown);
                    btn.disabled = false;
                    btn.innerText = 'Resend OTP';
                } else {
                    btn.innerText = 'Resend (' + sec + 's)';
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
    .catch(function() {
        statusBox.style.background = 'var(--danger-bg)';
        statusBox.style.color = '#991B1B';
        statusBox.innerText = 'Network error: Unable to connect to server.';
        btn.disabled = false;
        btn.innerText = 'Send WhatsApp OTP';
    });
}

function togglePinVisibility() {
    var inp = document.getElementById('secretPinInput');
    inp.type = (inp.type === 'password') ? 'text' : 'password';
}

function filterDuesTable() {
    var term = document.getElementById('duesSearchInput').value.toLowerCase();
    var rows = document.querySelectorAll('.due-row');
    rows.forEach(function(r) {
        var name = r.querySelector('.due-student-name').innerText.toLowerCase();
        var phone = r.querySelector('.due-student-phone').innerText.toLowerCase();
        r.style.display = (name.includes(term) || phone.includes(term)) ? '' : 'none';
    });
}

function openDeletePaymentModal(data) {
    document.getElementById('delPaymentId').value = data.id;
    document.getElementById('delReceiptNo').innerText = data.receipt_no;
    document.getElementById('delStudentName').innerText = data.student_name;
    document.getElementById('delAmount').innerText = '₹' + parseFloat(data.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 });
    document.getElementById('delStage').innerText = data.stage;
    document.getElementById('deletePaymentModal').classList.add('active');
}

function closeDeletePaymentModal() {
    document.getElementById('deletePaymentModal').classList.remove('active');
}

function openPinModal() {
    document.getElementById('pinModal').classList.add('active');
}
function closePinModal() {
    document.getElementById('pinModal').classList.remove('active');
}

function openStudentLedger(studentId) {
    if (!studentsLedger[studentId]) return;
    var data = studentsLedger[studentId];
    var stages = data.stages;
    var extReq = stages.extension_fee ? (stages.extension_fee.paid_amount + stages.extension_fee.due_amount) : 0;
    var totalFeeAmount = data.total_fee_overall || (data.total_course_fee + extReq);

    var subFeeText = '';
    if (extReq > 0) {
        subFeeText = '<div class="muted" style="font-size: 10.5px; margin-top: 2px;">(Course: ₹' + data.total_course_fee.toLocaleString('en-IN') + ' + Ext: ₹' + extReq.toLocaleString('en-IN') + ')</div>';
    }

    var content =
        '<div style="border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 14px;">' +
            '<h2 style="margin: 0; font-size: 16px; color: var(--text);">' + escapeHtml(data.name) + '</h2>' +
            '<div class="muted" style="font-size: 12.5px; margin-top: 2px;">Reg/Form: ' + escapeHtml(data.reg_no) + ' &bull; ' + escapeHtml(data.phone) + '</div>' +
        '</div>' +
        '<div class="ledger-summary-grid">' +
            '<div class="ledger-stat-box">' +
                '<div class="muted" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">Total Fee</div>' +
                '<div style="font-size: 17px; font-weight: 700; color: var(--text); margin-top: 2px;">₹' + totalFeeAmount.toLocaleString('en-IN') + '</div>' +
                subFeeText +
            '</div>' +
            '<div class="ledger-stat-box" style="background: var(--success-bg); border-color: var(--success-border);">' +
                '<div style="font-size: 11px; color: #166534; font-weight: 700; text-transform: uppercase;">Total Paid</div>' +
                '<div style="font-size: 17px; font-weight: 700; color: var(--success); margin-top: 2px;">₹' + data.total_paid.toLocaleString('en-IN') + '</div>' +
            '</div>' +
            '<div class="ledger-stat-box" style="background: var(--danger-bg); border-color: var(--danger-border);">' +
                '<div style="font-size: 11px; color: #991B1B; font-weight: 700; text-transform: uppercase;">Total Due</div>' +
                '<div style="font-size: 17px; font-weight: 700; color: var(--danger); margin-top: 2px;">₹' + data.total_due.toLocaleString('en-IN') + '</div>' +
            '</div>' +
        '</div>' +
        '<div class="table-wrap">' +
        '<table>' +
            '<thead>' +
                '<tr><th>Fee Stage</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th></tr>' +
            '</thead>' +
            '<tbody>' +
                '<tr>' +
                    '<td><strong>Form Fee</strong></td>' +
                    '<td>₹' + stages.form_fee.required_amount + '</td>' +
                    '<td>₹' + stages.form_fee.paid_amount + '</td>' +
                    '<td>₹' + stages.form_fee.due_amount + '</td>' +
                    '<td>' + (stages.form_fee.is_paid ? '<span class="badge badge-green">Paid</span>' : '<span class="badge badge-red">Due</span>') + '</td>' +
                '</tr>' +
                '<tr>' +
                    '<td><strong>1st Joining Fee</strong></td>' +
                    '<td>₹' + stages.joining_fee.required_amount + '</td>' +
                    '<td>₹' + stages.joining_fee.paid_amount + '</td>' +
                    '<td>₹' + stages.joining_fee.due_amount + '</td>' +
                    '<td>' + (stages.joining_fee.is_paid ? '<span class="badge badge-green">Paid</span>' : '<span class="badge badge-red">Due</span>') + '</td>' +
                '</tr>' +
                '<tr>' +
                    '<td><strong>2nd Payment</strong></td>' +
                    '<td>₹' + stages.second_payment.required_amount + '</td>' +
                    '<td>₹' + stages.second_payment.paid_amount + '</td>' +
                    '<td>₹' + stages.second_payment.due_amount + '</td>' +
                    '<td>' + (stages.second_payment.is_paid ? '<span class="badge badge-green">Paid</span>' : '<span class="badge badge-red">Due</span>') + '</td>' +
                '</tr>' +
                (stages.extension_fee && (stages.extension_fee.paid_amount > 0 || stages.extension_fee.due_amount > 0) ?
                '<tr>' +
                    '<td><strong>Extension Fee</strong></td>' +
                    '<td>₹' + (stages.extension_fee.required_amount || (stages.extension_fee.paid_amount + stages.extension_fee.due_amount)) + '</td>' +
                    '<td>₹' + stages.extension_fee.paid_amount + '</td>' +
                    '<td>₹' + stages.extension_fee.due_amount + '</td>' +
                    '<td>' + (stages.extension_fee.due_amount <= 0 ? '<span class="badge badge-green">Paid</span>' : (stages.extension_fee.paid_amount > 0 ? '<span class="badge badge-amber">Partial</span>' : '<span class="badge badge-red">Due</span>')) + '</td>' +
                '</tr>' : '') +
                (stages.other && stages.other.paid_amount > 0 ?
                '<tr>' +
                    '<td><strong>Other Fee</strong></td>' +
                    '<td>₹' + stages.other.paid_amount + '</td>' +
                    '<td>₹' + stages.other.paid_amount + '</td>' +
                    '<td>₹0</td>' +
                    '<td><span class="badge badge-green">Paid</span></td>' +
                '</tr>' : '') +
            '</tbody>' +
        '</table>' +
        '</div>';

    document.getElementById('ledgerModalContent').innerHTML = content;
    document.getElementById('studentLedgerModal').classList.add('active');
}

function closeStudentLedger() {
    document.getElementById('studentLedgerModal').classList.remove('active');
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

window.onclick = function(event) {
    var pinM = document.getElementById('pinModal');
    var ledM = document.getElementById('studentLedgerModal');
    var delM = document.getElementById('deletePaymentModal');
    if (event.target === pinM) closePinModal();
    if (event.target === ledM) closeStudentLedger();
    if (event.target === delM) closeDeletePaymentModal();
};

<?php if (!empty($successPayment)): ?>
document.addEventListener('DOMContentLoaded', function() {
    var notice = document.getElementById('paymentSuccessNotice');
    if (notice) {
        notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
<?php endif; ?>

<?php if (!empty($_GET['student_id'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    var preStudentId = <?= (int)$_GET['student_id'] ?>;
    var preStage = '<?= e($_GET['stage'] ?? '') ?>';
    var stSelect = document.getElementById('studentSelect');
    if (stSelect) {
        stSelect.value = preStudentId;
        onStudentChange(preStudentId);
        if (preStage) {
            var stg = document.getElementById('stageSelect');
            if (stg) {
                var opt = stg.querySelector('option[value="' + preStage + '"]');
                if (opt && !opt.disabled) {
                    stg.value = preStage;
                    onStageChange();
                }
            }
        }
        var formPanel = document.getElementById('recordPaymentPanel');
        if (formPanel) formPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
