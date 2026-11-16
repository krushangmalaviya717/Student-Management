<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_engine.php';
require_once __DIR__ . '/../includes/services/PaymentService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
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

            flash('success', "પેમેન્ટ સફળતાપૂર્વક જમા થઈ ગયું છે! રસીદ નંબર: {$rcptNo} (રકમ: ₹" . number_format($amount, 2) . ")");
            redirect('public/payments.php?payment_success=' . (int)$paymentId);

        } elseif ($action === 'update_pin') {
            $currentPin = trim($_POST['current_pin'] ?? '');
            $newPin = trim($_POST['new_pin'] ?? '');

            if (!PaymentService::verifySecretPin($currentPin, (int)$user['id'])) {
                throw new InvalidArgumentException('હાલનો સિક્રેટ પાસવર્ડ / PIN ખોટો છે!');
            }

            PaymentService::updateSecretPin($newPin, (int)$user['id']);
            flash('success', 'નવો પેમેન્ટ સિક્રેટ PIN સફળતાપૂર્વક અપડેટ થઈ ગયો છે.');
            redirect('public/payments.php');

        } elseif ($action === 'delete_payment') {
            $deletePaymentId = (int)($_POST['delete_payment_id'] ?? 0);
            $secretPin = trim($_POST['secret_pin'] ?? '');
            $deleteReason = trim($_POST['delete_reason'] ?? '');

            PaymentService::deletePayment($deletePaymentId, (int)$user['id'], $secretPin, $deleteReason);
            flash('success', 'પેમેન્ટ સફળતાપૂર્વક રદ (ડિલીટ) કરવામાં આવ્યું છે.');
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
    'form_fee' => 'Form Fee (ફોર્મ ફી)',
    'joining_fee' => '1st Payment (જોઈનિંગ ફી)',
    'second_payment' => '2nd Payment (બીજો હપ્તો)',
    'extension_fee' => 'Extension Fee (વધારાના દિવસો)',
    'extension' => 'Extension Fee (વધારાના દિવસો)',
    'other' => 'Other (અન્ય ફી)'
];

// Fetch newly recorded payment details for instant high-visibility feedback
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
/* ==========================================================================
   HIGH-END MODERN SAAS DESIGN SYSTEM FOR PAYMENTS
   ========================================================================== */

/* Top Action Header */
.payments-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}
.payments-title h1 {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    margin: 0 0 4px 0;
}
.payments-title p {
    font-size: 13.5px;
    color: #64748b;
    margin: 0;
}
.header-action-group {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

/* Polished KPI Cards */
.pay-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 1024px) {
    .pay-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    .pay-kpi-grid { grid-template-columns: 1fr; }
}

.pay-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04), 0 1px 2px -1px rgba(0, 0, 0, 0.02);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.pay-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07);
}
.pay-kpi-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.pay-kpi-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.pay-kpi-val {
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.pay-kpi-lbl {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    margin-top: 4px;
}
.pay-kpi-sub {
    font-size: 11.5px;
    color: #94a3b8;
    margin-top: 4px;
    font-weight: 500;
}

/* Payment Panel Card */
.payment-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.03);
    margin-bottom: 28px;
    overflow: hidden;
}
.payment-panel-head {
    padding: 18px 24px;
    background: linear-gradient(180deg, #fafafa 0%, #ffffff 100%);
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.payment-panel-head h2 {
    font-size: 17px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.payment-panel-body {
    padding: 24px;
}

/* Two-column Form Grid */
.payment-form-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 24px;
}
@media (max-width: 950px) {
    .payment-form-grid { grid-template-columns: 1fr; }
}

/* Form Controls with Refined Focus States */
.form-field-group {
    margin-bottom: 16px;
}
.form-field-group label {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.form-input-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    color: #0f172a;
    background: #ffffff;
    transition: all 0.15s ease;
}
.form-input-control:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

/* Currency Input Group */
.input-currency-group {
    position: relative;
    display: flex;
    align-items: center;
}
.input-currency-prefix {
    position: absolute;
    left: 14px;
    font-size: 16px;
    font-weight: 800;
    color: #64748b;
    pointer-events: none;
}
.input-currency-group input {
    padding-left: 32px !important;
    font-weight: 800;
    font-size: 16px;
    font-variant-numeric: tabular-nums;
}

/* Student Live Fee Card */
.student-fee-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin-top: 14px;
    animation: fadeIn 0.2s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.fee-stage-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.fee-pill {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.pill-completed {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.pill-pending {
    background: #fff7ed;
    color: #9a3412;
    border: 1px solid #fed7aa;
}
.pill-total-due {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
    margin-left: auto;
}

/* Payment Mode Grid */
.payment-methods-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
.method-card-label {
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    background: #ffffff;
    transition: all 0.15s ease;
    user-select: none;
    margin: 0;
}
.method-card-label input {
    margin: 0;
    cursor: pointer;
}
.method-card-label:has(input:checked) {
    border-color: #6366f1;
    background: #f5f3ff;
    color: #4338ca;
    box-shadow: 0 0 0 1px #6366f1;
}

/* Security Auth Card */
.security-auth-card {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: 16px;
    margin-top: 18px;
}
.security-auth-title {
    font-size: 13px;
    font-weight: 700;
    color: #92400e;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
}

/* Tab Switching Component */
.view-tabs-bar {
    display: flex;
    gap: 6px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 20px;
}
.view-tab-btn {
    padding: 10px 18px;
    font-size: 13.5px;
    font-weight: 700;
    color: #64748b;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.15s ease;
}
.view-tab-btn:hover {
    color: #0f172a;
}
.view-tab-btn.active {
    color: #4338ca;
    border-bottom-color: #4338ca;
}
.tab-badge {
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
}

/* Table Enhancements */
.modern-table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    font-size: 13px;
}
.modern-table th {
    background: #f8fafc;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 12px 14px;
    border-bottom: 1px solid #e2e8f0;
}
.modern-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.modern-table tr:hover td {
    background: #fafbfc;
}

/* Avatar Initials */
.student-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #eef2ff;
    color: #4338ca;
    font-weight: 700;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* Modals */
.custom-modal-overlay.active {
    display: flex !important;
}
</style>

<!-- Top Header Section -->
<div class="payments-header">
    <div class="payments-title">
        <h1>Payments & Accounts (ફી હિસાબ & પેમેન્ટ)</h1>
        <p>સુરક્ષિત પેમેન્ટ એન્ટ્રી, ડુપ્લિકેટ સુરક્ષા, ઓટોમેટેડ રસીદ અને બાકી ફી વ્યવસ્થાપન.</p>
    </div>

    <div class="header-action-group">
        <button type="button" class="btn btn-secondary btn-sm" onclick="openPinModal()" style="display: inline-flex; align-items: center; gap: 6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Security PIN (સિક્રેટ પિન)
        </button>
        <a href="#recordPaymentPanel" class="btn btn-sm" style="background: #4338ca; color:#fff; display: inline-flex; align-items: center; gap: 6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            + Record Payment
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash-error" style="margin-bottom: 22px;">
        <strong>ભૂલ (Error):</strong> <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Polished KPI Stat Cards -->
<div class="pay-kpi-grid">
    <div class="pay-kpi-card">
        <div class="pay-kpi-header">
            <div class="pay-kpi-lbl">Today's Collection</div>
            <div class="pay-kpi-icon" style="background: #ecfdf5; color: #16a34a;">💰</div>
        </div>
        <div class="pay-kpi-val" style="color: #16a34a;">₹<?= number_format($metrics['today_collected']) ?></div>
        <div class="pay-kpi-sub">આજની તારીખે જમા થયેલ રોકડ/UPI</div>
    </div>

    <div class="pay-kpi-card">
        <div class="pay-kpi-header">
            <div class="pay-kpi-lbl">This Month Collection</div>
            <div class="pay-kpi-icon" style="background: #eef2ff; color: #4f46e5;">📈</div>
        </div>
        <div class="pay-kpi-val" style="color: #4338ca;">₹<?= number_format($metrics['month_collected']) ?></div>
        <div class="pay-kpi-sub"><?= date('F Y') ?> નો કુલ સંગ્રહ</div>
    </div>

    <div class="pay-kpi-card">
        <div class="pay-kpi-header">
            <div class="pay-kpi-lbl">Total All-Time Fees</div>
            <div class="pay-kpi-icon" style="background: #f1f5f9; color: #0f172a;">🏛</div>
        </div>
        <div class="pay-kpi-val">₹<?= number_format($metrics['total_collected']) ?></div>
        <div class="pay-kpi-sub">કુલ <?= $metrics['total_transactions'] ?> પેમેન્ટ રસીદો જનરેટ થયેલ</div>
    </div>

    <div class="pay-kpi-card">
        <div class="pay-kpi-header">
            <div class="pay-kpi-lbl">Outstanding Dues</div>
            <div class="pay-kpi-icon" style="background: #fff7ed; color: #ea580c;">⏳</div>
        </div>
        <div class="pay-kpi-val" style="color: #dc2626;">₹<?= number_format($metrics['total_pending_dues']) ?></div>
        <div class="pay-kpi-sub"><?= $metrics['pending_students_count'] ?> વિદ્યાર્થીઓ તરફથી લેવાની બાકી</div>
    </div>
</div>

<!-- =========================================================================
     SUCCESS FEEDBACK BANNER (IF PAYMENT WAS JUST RECORDED)
     ========================================================================= -->
<?php if ($successPayment): ?>
<div class="card" id="paymentSuccessNotice" style="background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 2px solid #86efac; border-radius: 12px; padding: 22px 24px; margin-bottom: 24px; box-shadow: 0 4px 14px rgba(22, 163, 74, 0.12);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; gap: 14px; align-items: center;">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: #16a34a; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; box-shadow: 0 2px 8px rgba(22, 163, 74, 0.35);">
                ✓
            </div>
            <div>
                <div style="font-weight: 800; font-size: 17px; color: #14532d;">
                    પેમેન્ટ સફળતાપૂર્વક જમા થયેલ છે! (Payment Recorded Successfully)
                </div>
                <div style="font-size: 13.5px; color: #166534; margin-top: 4px;">
                    વિદ્યાર્થી: <strong><?= e($successPayment['student_name']) ?></strong> &bull; 
                    તબક્કો: <strong><?= e($stageNames[$successPayment['stage']] ?? $successPayment['stage']) ?></strong> &bull;
                    રકમ: <strong style="font-size: 16px; color: #15803d;">₹<?= number_format($successPayment['amount'], 2) ?></strong> (<?= strtoupper($successPayment['method']) ?>) &bull; 
                    રસીદ નં: <strong style="font-family: monospace; background: #fff; padding: 2px 8px; border-radius: 4px; border: 1px solid #bbf7d0; color: #14532d;"><?= e($successPayment['receipt_no']) ?></strong>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="<?= url('public/receipt.php?id=' . (int)$successPayment['id']) ?>" target="_blank" class="btn" style="background: #16a34a; color: #fff; font-weight: 700; padding: 10px 18px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.12); text-decoration: none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                સત્તાવાર રસીદ જુઓ / પ્રિન્ટ કરો &rarr;
            </a>
            <button type="button" class="btn btn-secondary" onclick="dismissSuccessNotice()" style="padding: 10px 16px; border-radius: 8px; font-weight: 600;">
                ✕ બંધ કરો
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- =========================================================================
     CARD: HIGH-END INTERACTIVE PAYMENT FORM (PANEL)
     ========================================================================= -->
<div class="payment-panel" id="recordPaymentPanel">
    <div class="payment-panel-head">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4338ca" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Record New Payment (નવું પેમેન્ટ ઉમેરો)
        </h2>
        <div style="display: flex; align-items: center; gap: 8px;">
            <span class="badge" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-size: 11.5px; font-weight: 700;">
                🔒 Zero Duplicate Guarantee
            </span>
            <span class="badge" style="background: #f5f3ff; color: #5b21b6; border: 1px solid #ddd6fe; font-size: 11.5px; font-weight: 700;">
                🛡 PIN Verification Required
            </span>
        </div>
    </div>

    <div class="payment-panel-body">
        <form method="post" action="" id="paymentForm" onsubmit="return validatePaymentSubmission();">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_payment">

            <div class="payment-form-grid">
                <!-- Left Column: Student, Stage & Amount -->
                <div>
                    <!-- Student Selector -->
                    <div class="form-field-group">
                        <label>Select Student (વિદ્યાર્થી પસંદ કરો) *</label>
                        <select name="student_id" id="studentSelect" required onchange="onStudentChange(this.value)" class="form-input-control" style="font-weight: 600;">
                            <option value="">-- વિદ્યાર્થી સિલેક્ટ કરો (Choose Student) --</option>
                            <?php foreach ($activeStudents as $st): ?>
                                <option value="<?= $st['id'] ?>">
                                    <?= e($st['name']) ?> — [<?= e($st['registration_no'] ?: $st['form_no']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Live Student Fee Summary Card (Populated by JS) -->
                    <div id="studentFeeOverview" style="display: none;"></div>

                    <!-- Stage Selector -->
                    <div class="form-field-group" style="margin-top: 14px;">
                        <label>Payment Stage (પેમેન્ટ તબક્કો) *</label>
                        <select name="stage" id="stageSelect" required onchange="onStageChange()" class="form-input-control" style="font-weight: 600;">
                            <option value="">-- પહેલા વિદ્યાર્થી પસંદ કરો --</option>
                            <option value="joining_fee" id="opt_joining_fee">1st Payment on Joining (એન્ટ્રી ફી - ₹3,500)</option>
                            <option value="second_payment" id="opt_second_payment">2nd Payment (બીજો હપ્તો - ₹4,000)</option>
                            <option value="form_fee" id="opt_form_fee">Form Fee (ફોર્મ ફી - ₹500)</option>
                            <option value="extension_fee" id="opt_extension_fee">Extension Fee (વધારાના દિવસોની ફી)</option>
                            <option value="other" id="opt_other">Other / Miscellaneous Fee</option>
                        </select>
                    </div>

                    <!-- Amount Group (With strict limit) -->
                    <div class="form-field-group">
                        <label>Amount to Collect (ભરવાપાત્ર રકમ) *</label>
                        <div class="input-currency-group">
                            <span class="input-currency-prefix">₹</span>
                            <input type="number" step="0.01" name="amount" id="amountInput" placeholder="0.00" required oninput="validateAmountLimit()" class="form-input-control">
                        </div>
                        <div id="amountLimitNote" style="font-size: 12px; margin-top: 4px; font-weight: 600;"></div>
                    </div>

                    <!-- Overpayment Warning Banner -->
                    <div id="overPaymentAlert" style="display: none; background: #fef2f2; border: 1px solid #f87171; border-radius: 8px; padding: 10px 14px; color: #991b1b; font-size: 13px; font-weight: 600; margin-top: 10px;"></div>
                </div>

                <!-- Right Column: Payment Method, Reference & Security PIN -->
                <div>
                    <!-- Payment Methods Radio Cards -->
                    <div class="form-field-group">
                        <label>Payment Method (ચૂકવણી પદ્ધતિ) *</label>
                        <div class="payment-methods-grid">
                            <label class="method-card-label">
                                <input type="radio" name="method" value="cash" checked>
                                <span>💵 Cash (રોકડ)</span>
                            </label>
                            <label class="method-card-label">
                                <input type="radio" name="method" value="upi">
                                <span>📱 UPI / GPay</span>
                            </label>
                            <label class="method-card-label">
                                <input type="radio" name="method" value="bank_transfer">
                                <span>🏦 Bank Transfer</span>
                            </label>
                            <label class="method-card-label">
                                <input type="radio" name="method" value="cheque">
                                <span>📜 Cheque</span>
                            </label>
                        </div>
                    </div>

                    <!-- Reference / UTR No -->
                    <div class="form-field-group">
                        <label>Reference / Transaction / UTR No.</label>
                        <input type="text" name="reference_no" id="refNoInput" placeholder="દા.ત. UPI Txn ID અથવા Cheque No." class="form-input-control">
                    </div>

                    <!-- Security Authorization Box -->
                    <div class="security-auth-card">
                        <div class="security-auth-title">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Security PIN Authorization (સિક્રેટ પિન)
                        </div>
                        <div style="font-size: 12px; color: #92400e; margin-bottom: 8px;">
                            પેમેન્ટ અધિકૃત કરવા માટે સિક્રેટ PIN (1234) અથવા તમારો પાસવર્ડ લખો.
                        </div>
                        <div style="position: relative;">
                            <input type="password" name="secret_pin" id="secretPinInput" required placeholder="PIN દાખલ કરો..." class="form-input-control" style="border-color: #f59e0b; font-weight: 800; letter-spacing: 3px; padding-right: 40px;" autocomplete="new-password">
                            <button type="button" onclick="togglePinVisibility()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; font-size: 15px;" title="Show/Hide">
                                👁
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div style="margin-top: 18px;">
                        <button type="submit" class="btn" id="recordSubmitBtn" style="width: 100%; padding: 12px; font-weight: 700; font-size: 14.5px; background: #4338ca; color:#fff; border-radius: 8px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(67, 56, 202, 0.2);">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            Authorize & Record Payment (પેમેન્ટ જમા કરો)
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     SEGMENTED TABS: OUTSTANDING DUES VS PAYMENT HISTORY
     ========================================================================= -->
<div class="view-tabs-bar" id="paymentsTabsSection">
    <button type="button" id="tabBtnHistory" onclick="switchViewTab('history')" class="view-tab-btn <?= $activeTab === 'history' ? 'active' : '' ?>" style="cursor: pointer; border: none; font-family: inherit;">
        <span>📋 Payments History (પેમેન્ટ વ્યવહારો)</span>
        <span class="tab-badge" style="background: <?= $activeTab === 'history' ? '#e0e7ff; color:#3730a3;' : '#f1f5f9; color:#64748b;' ?>"><?= count($payments) ?></span>
    </button>
    <button type="button" id="tabBtnDues" onclick="switchViewTab('dues')" class="view-tab-btn <?= $activeTab === 'dues' ? 'active' : '' ?>" style="cursor: pointer; border: none; font-family: inherit;">
        <span>⚠️ Outstanding Dues (બાકી ફી ની યાદી)</span>
        <span class="tab-badge" style="background: <?= count($pendingDues) > 0 ? '#fee2e2; color:#991b1b;' : '#f1f5f9; color:#64748b;' ?>"><?= count($pendingDues) ?></span>
    </button>
</div>

<!-- =========================================================================
     TAB 1: OUTSTANDING DUES LIST
     ========================================================================= -->
<div id="duesTabPane" style="display: <?= $activeTab === 'dues' ? 'block' : 'none' ?>;">
<div class="card" style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <div>
            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">
                Student Outstanding Dues (બાકી ફી વાળા વિદ્યાર્થીઓ)
            </h3>
            <div class="muted" style="font-size: 12.5px; margin-top: 2px;">
                જે વિદ્યાર્થીઓની જોઈનિંગ ફી, બીજો હપ્તો કે વધારાના દિવસોની ફી બાકી છે તેમની યાદી.
            </div>
        </div>

        <div>
            <input type="text" id="duesSearchInput" placeholder="શોધો: નામ અથવા મોબાઇલ..." onkeyup="filterDuesTable()" style="font-size: 13px; padding: 6px 12px; width: 240px; border-radius: 6px; border: 1px solid #cbd5e1;">
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="modern-table" id="duesTable">
            <thead>
                <tr>
                    <th>Student Name & Reg</th>
                    <th>Fee Stage (બાકી ફી નો પ્રકાર)</th>
                    <th>Pending Amount</th>
                    <th>Due Date</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingDues)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #166534; font-weight: 600;">
                            🎉 ઉત્તમ! હાલમાં કોઈ પણ વિદ્યાર્થીની ફી બાકી નથી.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingDues as $due): ?>
                        <tr class="due-row">
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php
                                    $inits = mb_substr($due['student_name'], 0, 1, 'UTF-8');
                                    ?>
                                    <div class="student-avatar"><?= e(strtoupper($inits)) ?></div>
                                    <div>
                                        <div style="font-weight: 700; color: #0f172a;" class="due-student-name"><?= e($due['student_name']) ?></div>
                                        <div style="font-size: 12px; color: #64748b;" class="due-student-phone">
                                            <?= e($due['registration_no']) ?> • 📞 <?= e($due['student_mobile']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="background:#f5f3ff; color:#6d28d9; border: 1px solid #ddd6fe; font-size: 12px; font-weight: 600;">
                                    <?= e($due['fee_label']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 800; font-size: 15px; color: #b45309;">
                                    ₹<?= number_format($due['amount'], 2) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($due['is_urgent']): ?>
                                    <span class="badge badge-red" style="font-weight: 700;">Overdue (મુદત વીતી ગઈ)</span>
                                    <div style="font-size: 11px; color: #dc2626; margin-top: 2px;"><?= e($due['due_date']) ?></div>
                                <?php else: ?>
                                    <span style="font-size: 12.5px; color: #475569; font-weight: 500;"><?= e($due['due_date']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 6px; align-items: center;">
                                    <!-- 1-Click Pay Now Button -->
                                    <button type="button" class="btn btn-sm" style="background: #16a34a; color:#fff; font-size: 12px; padding: 5px 12px; font-weight: 700; border-radius: 6px;" onclick="quickPayFromDues(<?= $due['student_id'] ?>, '<?= e($due['stage']) ?>', <?= (float)$due['amount'] ?>)">
                                        ⚡ Pay Now
                                    </button>

                                    <!-- 1-Click WhatsApp Reminder -->
                                    <?php
                                    $waMsg = "નમસ્તે {$due['student_name']},\nદેવાંગી સીવણ ક્લાસીસ તરફથી જાણ કરવાની કે તમારી " . $due['fee_label'] . " પેટે ₹" . number_format($due['amount']) . " ભરવાના બાકી છે.\nમહેરબાની કરીને વહેલી તકે ક્લાસ પર આવીને જમા કરાવી આપશો.\nઆભાર!\nદેવાંગી સીવણ ક્લાસીસ";
                                    $waUrl = "https://api.whatsapp.com/send?phone=91" . preg_replace('/\D/', '', $due['student_mobile']) . "&text=" . urlencode($waMsg);
                                    ?>
                                    <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 10px; color: #16a34a; font-weight: 600;" title="Send WhatsApp Reminder">
                                        💬 Reminder
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
</div> <!-- Close duesTabPane -->

<!-- =========================================================================
     TAB 2: PAYMENT TRANSACTIONS HISTORY
     ========================================================================= -->
<div id="historyTabPane" style="display: <?= $activeTab === 'history' ? 'block' : 'none' ?>;">
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <div>
            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">
                Payment Transactions (પેમેન્ટ વ્યવહારો)
            </h3>
            <div class="muted" style="font-size: 12.5px; margin-top: 2px;">
                કુલ રકમ: <strong style="color:#16a34a; font-size:14px;">₹<?= number_format($totalFilteredAmount, 2) ?></strong> (<?= count($payments) ?> રસીદો)
            </div>
        </div>

        <div>
            <a href="?export=excel&stage=<?= urlencode($stageFilter) ?>&from=<?= urlencode($fromFilter) ?>&to=<?= urlencode($toFilter) ?>" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </a>
        </div>
    </div>

    <!-- Clean Filter Bar -->
    <form method="get" action="?tab=history#paymentsTabsSection" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid #e2e8f0;">
        <input type="hidden" name="tab" value="history">
        <div style="flex: 1; min-width: 200px;">
            <input type="text" name="search" value="<?= e($searchFilter) ?>" placeholder="શોધો: રસીદ નં, નામ, મોબાઇલ, રેગ..." style="width: 100%; margin: 0; font-size: 13px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
        </div>
        <div style="min-width: 180px;">
            <select name="stage" style="width: 100%; margin: 0; font-size: 13px; padding: 8px 12px; border-radius: 6px;" onchange="this.form.submit()">
                <option value="">બધા તબક્કા (All Stages)</option>
                <option value="joining_fee" <?= $stageFilter === 'joining_fee' ? 'selected' : '' ?>>1st Joining Fee</option>
                <option value="second_payment" <?= $stageFilter === 'second_payment' ? 'selected' : '' ?>>2nd Payment (બીજો હપ્તો)</option>
                <option value="form_fee" <?= $stageFilter === 'form_fee' ? 'selected' : '' ?>>Form Fee (રૂ. 500)</option>
                <option value="extension_fee" <?= $stageFilter === 'extension_fee' ? 'selected' : '' ?>>Extension Fee</option>
                <option value="other" <?= $stageFilter === 'other' ? 'selected' : '' ?>>Other Fee</option>
            </select>
        </div>
        <div style="min-width: 140px;">
            <select name="method" style="width: 100%; margin: 0; font-size: 13px; padding: 8px 12px; border-radius: 6px;" onchange="this.form.submit()">
                <option value="">બધી પદ્ધતિ (All)</option>
                <option value="cash" <?= $methodFilter === 'cash' ? 'selected' : '' ?>>Cash (રોકડ)</option>
                <option value="upi" <?= $methodFilter === 'upi' ? 'selected' : '' ?>>UPI / GPay</option>
                <option value="bank_transfer" <?= $methodFilter === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                <option value="cheque" <?= $methodFilter === 'cheque' ? 'selected' : '' ?>>Cheque</option>
            </select>
        </div>
        <div style="display: flex; gap: 4px; align-items: center;">
            <input type="date" name="from" value="<?= e($fromFilter) ?>" title="From Date" style="margin: 0; font-size: 12px; padding: 7px; border-radius: 6px;">
            <span style="color:#94a3b8;">-</span>
            <input type="date" name="to" value="<?= e($toFilter) ?>" title="To Date" style="margin: 0; font-size: 12px; padding: 7px; border-radius: 6px;">
        </div>
        <button type="submit" class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px; border-radius: 6px;">Filter</button>
        <?php if ($stageFilter !== '' || $methodFilter !== '' || $fromFilter !== '' || $toFilter !== '' || $searchFilter !== ''): ?>
            <a href="<?= url('public/payments.php?tab=history#paymentsTabsSection') ?>" class="btn-link" style="font-size: 13px;">Reset</a>
        <?php endif; ?>
    </form>

    <div style="overflow-x: auto;">
        <table class="modern-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Receipt No</th>
                    <th>Student Name</th>
                    <th>Stage (તબક્કો)</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Ref / UTR</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">
                            કોઈ પેમેન્ટ રેકોર્ડ મળ્યો નથી.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <div style="font-size: 13px; font-weight: 700; color: #1e293b;">
                                    <?= date('d M Y', strtotime($p['payment_date'])) ?>
                                </div>
                                <div style="font-size: 11px; color: #94a3b8;">
                                    <?= date('h:i A', strtotime($p['payment_date'])) ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-family: monospace; font-weight: 800; color: #4338ca; background: #eef2ff; padding: 3px 8px; border-radius: 6px; border: 1px solid #c7d2fe; font-size: 12px;">
                                    <?= e($p['receipt_no']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #0f172a; font-size: 13.5px;"><?= e($p['student_name']) ?></div>
                                <div style="font-size: 11.5px; color: #64748b;">
                                    <?= e($p['registration_no'] ?: $p['form_no']) ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; font-size: 11.5px; font-weight: 600;">
                                    <?= e($stageNames[$p['stage']] ?? ucwords(str_replace('_', ' ', $p['stage']))) ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 800; font-size: 14.5px; color: #0f172a; font-variant-numeric: tabular-nums;">
                                    ₹<?= number_format($p['amount'], 2) ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; font-size: 11px; text-transform: uppercase; font-weight: 700;">
                                    <?= e($p['method']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 12px; color: #64748b; font-family: monospace;">
                                    <?= $p['reference_no'] ? e($p['reference_no']) : '-' ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 5px; align-items: center;">
                                    <!-- Official Print Receipt -->
                                    <a href="<?= url('public/receipt.php?id=' . $p['id']) ?>" target="_blank" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 4px 8px;" title="Print Receipt">
                                        📄 Receipt
                                    </a>

                                    <!-- 1-Click WhatsApp Receipt Share -->
                                    <?php
                                    $stageClean = $stageNames[$p['stage']] ?? $p['stage'];
                                    $rcptMsg = "*દેવાંગી સીવણ ક્લાસીસ - ફી રસીદ*\n---------------------------\nરસીદ નં: {$p['receipt_no']}\nવિદ્યાર્થી: {$p['student_name']}\nતારીખ: " . date('d-M-Y', strtotime($p['payment_date'])) . "\nતબક્કો: {$stageClean}\nરકમ: ₹" . number_format($p['amount'], 2) . "\nપદ્ધતિ: " . strtoupper($p['method']) . "\n---------------------------\nફી જમા થઈ ગયેલ છે. આભાર!\nદેવાંગી સીવણ ક્લાસીસ";
                                    $waRcptUrl = "https://api.whatsapp.com/send?phone=91" . preg_replace('/\D/', '', $p['student_mobile'] ?? '') . "&text=" . urlencode($rcptMsg);
                                    ?>
                                    <a href="<?= e($waRcptUrl) ?>" target="_blank" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 4px 8px; color: #16a34a;" title="WhatsApp પર રસીદ શેર કરો">
                                        💬 Share
                                    </a>

                                    <!-- Student Statement Modal -->
                                    <button type="button" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 4px 7px;" onclick="openStudentLedger(<?= $p['student_id'] ?>)" title="વિદ્યાર્થી હિસાબ સ્ટેટમેન્ટ">
                                        📊
                                    </button>

                                    <!-- Delete / Cancel Payment Button -->
                                    <button type="button" class="btn btn-sm" style="font-size: 12px; padding: 4px 8px; background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; border-radius: 6px;" onclick='openDeletePaymentModal(<?= json_encode([
                                        "id" => (int)$p["id"],
                                        "receipt_no" => $p["receipt_no"],
                                        "student_name" => $p["student_name"],
                                        "amount" => (float)$p["amount"],
                                        "stage" => $stageClean,
                                        "date" => date("d-M-Y", strtotime($p["payment_date"]))
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="પેમેન્ટ રદ (ડિલીટ) કરો">
                                        🗑️
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
</div> <!-- Close historyTabPane -->

<!-- ==================== DELETE PAYMENT MODAL ==================== -->
<div class="custom-modal-overlay" id="deletePaymentModal">
    <div class="custom-modal" style="max-width: 480px; border-radius: 12px; border-top: 4px solid #dc2626;">
        <div class="custom-modal-header" style="border-bottom: 1px solid #fee2e2; background: #fef2f2;">
            <h3 style="color: #991b1b; display: flex; align-items: center; gap: 8px; margin: 0; font-size: 16px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                Delete Payment Receipt (પેમેન્ટ રસીદ રદ કરો)
            </h3>
            <button type="button" class="custom-modal-close" onclick="closeDeletePaymentModal()">&times;</button>
        </div>

        <form method="post" action="?tab=history#paymentsTabsSection">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_payment">
            <input type="hidden" name="delete_payment_id" id="delPaymentId">

            <div class="custom-modal-body" style="padding: 20px;">
                <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                    <div style="font-size: 13.5px; color: #9f1239; line-height: 1.6;">
                        ચેતવણી: તમે નીચેની પેમેન્ટ રસીદ રદ કરવા જઈ રહ્યા છો:
                    </div>
                    <div style="margin-top: 8px; font-size: 14px; font-weight: 700; color: #881337;">
                        &bull; રસીદ નં: <span id="delReceiptNo" style="font-family: monospace;"></span><br>
                        &bull; વિદ્યાર્થી: <span id="delStudentName"></span><br>
                        &bull; રકમ: <span id="delAmount" style="color: #dc2626;"></span> (<span id="delStage"></span>)
                    </div>
                </div>

                <div class="form-field-group">
                    <label style="color: #991b1b; font-weight: 700;">Security PIN (રદ કરવા માટે સિક્રેટ પિન દાખલ કરો) *</label>
                    <input type="password" name="secret_pin" required placeholder="Security PIN..." class="form-input-control" style="border-color: #f87171; letter-spacing: 2px;" autocomplete="new-password">
                </div>

                <div class="form-field-group" style="margin-top: 12px;">
                    <label>રદ કરવાનું કારણ (Reason for Deletion)</label>
                    <input type="text" name="delete_reason" placeholder="દા.ત. ભૂલથી ડુપ્લિકેટ એન્ટ્રી થઈ ગયેલ હતી" class="form-input-control">
                </div>
            </div>

            <div class="custom-modal-footer" style="display: flex; justify-content: space-between; gap: 10px; padding: 14px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0;">
                <button type="button" class="btn btn-secondary" onclick="closeDeletePaymentModal()">Cancel (રદ ન કરો)</button>
                <button type="submit" class="btn" style="background: #dc2626; color: #fff; font-weight: 700;">
                    🗑️ Confirm &amp; Delete Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== CHANGE PIN MODAL ==================== -->
<div class="custom-modal-overlay" id="pinModal">
    <div class="custom-modal" style="max-width: 440px; border-radius: 12px;">
        <div class="custom-modal-header">
            <h3>Change Payment Secret PIN</h3>
            <button type="button" class="custom-modal-close" onclick="closePinModal()">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_pin">

            <div class="custom-modal-body">
                <div style="font-size: 13px; color: #64748b; margin-bottom: 14px; line-height: 1.4;">
                    આ સિક્રેટ PIN દરેક વખતે પેમેન્ટ સેવ કરતાં પહેલાં દાખલ કરવો પડશે જેથી કોઈ અનઅધિકૃત વ્યક્તિ પેમેન્ટ એડ ન કરી શકે. ડિફોલ્ટ PIN: <strong>1234</strong>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="font-weight: 700; font-size: 12.5px; display: block; margin-bottom: 5px; color:#334155;">હાલનો PIN અથવા એકાઉન્ટ પાસવર્ડ *</label>
                    <input type="password" name="current_pin" required placeholder="Current PIN" class="form-input-control">
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="font-weight: 700; font-size: 12.5px; display: block; margin-bottom: 5px; color:#334155;">નવો સિક્રેટ PIN (ઓછામાં ઓછા 4 આંકડા) *</label>
                    <input type="text" name="new_pin" minlength="4" required placeholder="દા.ત. 5678" class="form-input-control" style="font-weight: 800; letter-spacing: 3px;">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePinModal()">Cancel</button>
                <button type="submit" class="btn" style="background:#4338ca; color:#fff;">Update PIN (સેવ કરો)</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== STUDENT LEDGER MODAL ==================== -->
<div class="custom-modal-overlay" id="studentLedgerModal">
    <div class="custom-modal" style="max-width: 580px; border-radius: 12px;">
        <div class="custom-modal-header">
            <h3>Student Fee Statement (વિદ્યાર્થી ફી સ્ટેટમેન્ટ)</h3>
            <button type="button" class="custom-modal-close" onclick="closeStudentLedger()">&times;</button>
        </div>
        <div class="custom-modal-body" id="ledgerModalContent" style="max-height: 75vh; overflow-y: auto;">
            <!-- Rendered by JS -->
        </div>
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

    // 1. Render Student Fee Overview Bar
    var formStatusHtml = stages.form_fee.is_paid 
        ? '<span class="fee-pill pill-completed">✔ Form Fee: Paid</span>' 
        : '<span class="fee-pill pill-pending">❌ Form Fee: ₹' + stages.form_fee.due_amount + ' Due</span>';

    var joiningStatusHtml = stages.joining_fee.is_paid 
        ? '<span class="fee-pill pill-completed">✔ 1st Joining: Paid</span>' 
        : '<span class="fee-pill pill-pending">❌ 1st Joining: ₹' + stages.joining_fee.due_amount + ' Due</span>';

    var secondStatusHtml = stages.second_payment.is_paid 
        ? '<span class="fee-pill pill-completed">✔ 2nd Payment: Paid</span>' 
        : '<span class="fee-pill pill-pending">❌ 2nd Payment: ₹' + stages.second_payment.due_amount + ' Due</span>';

    var extStatusHtml = '';
    if (stages.extension_fee.due_amount > 0) {
        extStatusHtml = '<span class="fee-pill pill-pending">⚠️ Extension: ₹' + stages.extension_fee.due_amount + ' Due</span>';
    } else if (stages.extension_fee.paid_amount > 0) {
        extStatusHtml = '<span class="fee-pill pill-completed">✔ Extension: Paid (₹' + stages.extension_fee.paid_amount.toLocaleString('en-IN') + ')</span>';
    }

    var totalDueHtml = data.total_due > 0 
        ? '<span class="fee-pill pill-total-due">કુલ બાકી: ₹' + data.total_due.toLocaleString('en-IN') + '</span>'
        : '<span class="fee-pill pill-completed" style="margin-left: auto;">✔ તમામ ફી ભરાઈ ગયેલ છે</span>';

    var extReq = stages.extension_fee ? (stages.extension_fee.paid_amount + stages.extension_fee.due_amount) : 0;
    var totalFeeAmount = data.total_fee_overall || (data.total_course_fee + extReq);
    var subFeeBreakdown = '';
    if (extReq > 0) {
        subFeeBreakdown = ' <span style="font-size: 11px; color: #64748b; font-weight: normal;">(કોર્સ: ₹' + data.total_course_fee.toLocaleString('en-IN') + ' + એક્સટેન્શન: ₹' + extReq.toLocaleString('en-IN') + ')</span>';
    }

    overviewBox.innerHTML = 
        '<div class="student-fee-card">' +
            '<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">' +
                '<strong style="font-size: 13.5px; color: #0f172a;">' + escapeHtml(data.name) + ' (' + escapeHtml(data.reg_no) + ')</strong>' +
                '<div>' +
                    '<span style="font-size: 12px; font-weight: 600; color: #0f172a;">જમા થયેલ: ₹' + data.total_paid.toLocaleString('en-IN') + ' / ₹' + totalFeeAmount.toLocaleString('en-IN') + '</span>' +
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

    // 2. DUPLICATE PREVENTION: Disable already-paid options in stage select
    updateStageOption('opt_form_fee', stages.form_fee);
    updateStageOption('opt_joining_fee', stages.joining_fee);
    updateStageOption('opt_second_payment', stages.second_payment);
    updateStageOption('opt_extension_fee', stages.extension_fee);

    // 3. Auto-select first available unpaid stage
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
        opt.innerText = opt.innerText.replace(' [પહેલેથી ભરાઈ ગયેલ છે - ALREADY PAID]', '') + ' [પહેલેથી ભરાઈ ગયેલ છે - ALREADY PAID]';
    } else {
        opt.disabled = false;
        opt.innerText = opt.innerText.replace(' [પહેલેથી ભરાઈ ગયેલ છે - ALREADY PAID]', '');
    }
}

function resetStageOptions() {
    var opts = ['opt_form_fee', 'opt_joining_fee', 'opt_second_payment', 'opt_extension_fee'];
    opts.forEach(function(id) {
        var opt = document.getElementById(id);
        if (opt) {
            opt.disabled = false;
            opt.innerText = opt.innerText.replace(' [પહેલેથી ભરાઈ ગયેલ છે - ALREADY PAID]', '');
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
        alert(stageInfo.blocked_reason || 'આ તબક્કાની ફી પહેલેથી ભરાઈ ગયેલ છે! ફરીથી ભરી શકાશે નહીં.');
        amountInput.value = '';
        currentMaxAllowed = 0;
        return;
    }

    // Auto-fill amount with exact due balance
    var due = parseFloat(stageInfo.due_amount);
    if (stage !== 'other' && due > 0) {
        amountInput.value = due;
        amountInput.max = due;
        currentMaxAllowed = due;
        limitNote.innerText = 'મહત્તમ બાકી રકમ (Max Due): ₹' + due.toLocaleString('en-IN');
        limitNote.style.color = '#16a34a';
    } else if (stage === 'other') {
        amountInput.removeAttribute('max');
        currentMaxAllowed = null;
        limitNote.innerText = 'અન્ય ફી માટે ઇચ્છિત રકમ દાખલ કરો.';
        limitNote.style.color = '#64748b';
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
        alertBox.innerHTML = '⚠️ <strong>મર્યાદા કરતાં વધુ રકમ!</strong> આ તબક્કા માટે બાકી રકમ <strong>₹' + currentMaxAllowed.toLocaleString('en-IN') + '</strong> છે. તેનાથી વધુ પેમેન્ટ લઈ શકાશે નહીં!';
        alertBox.style.display = 'block';
        amountInput.style.borderColor = '#dc2626';
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
    } else {
        alertBox.style.display = 'none';
        amountInput.style.borderColor = '#cbd5e1';
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
    }
}

function validatePaymentSubmission() {
    var studentId = document.getElementById('studentSelect').value;
    var stage = document.getElementById('stageSelect').value;
    var amount = parseFloat(document.getElementById('amountInput').value);
    var pin = document.getElementById('secretPinInput').value.trim();

    if (!studentId) {
        alert('મહેરબાની કરીને વિદ્યાર્થી પસંદ કરો.');
        return false;
    }
    if (!stage) {
        alert('મહેરબાની કરીને પેમેન્ટ તબક્કો પસંદ કરો.');
        return false;
    }
    if (isNaN(amount) || amount <= 0) {
        alert('અમાન્ય રકમ! શૂન્ય કરતાં મોટી રકમ દાખલ કરો.');
        return false;
    }
    if (currentMaxAllowed !== null && amount > currentMaxAllowed) {
        alert('આ તબક્કા માટે બાકી રકમ (₹' + currentMaxAllowed + ') કરતાં વધુ પેમેન્ટ સ્વીકારી શકાશે નહીં.');
        return false;
    }
    if (!pin) {
        alert('સિક્યોરિટી પાસવર્ડ / PIN દાખલ કરવો ફરજિયાત છે.');
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
        el.style.transform = 'translateY(-10px)';
        el.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        setTimeout(function() { el.remove(); }, 250);
    }
    history.replaceState(null, null, 'payments.php?tab=history');
}

function quickPayFromDues(studentId, stage, amount) {
    document.getElementById('studentSelect').value = studentId;
    onStudentChange(studentId);
    document.getElementById('stageSelect').value = stage;
    onStageChange();
    document.getElementById('amountInput').value = amount;
    
    // Smooth scroll and center the form nicely
    var panel = document.getElementById('recordPaymentPanel');
    if (panel) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    setTimeout(function() {
        document.getElementById('secretPinInput').focus();
    }, 450);
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
        if (name.includes(term) || phone.includes(term)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
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
        subFeeText = '<div style="font-size: 10.5px; color: #64748b; margin-top: 1px;">(કોર્સ: ₹' + data.total_course_fee.toLocaleString('en-IN') + ' + એક્સટેન્શન: ₹' + extReq.toLocaleString('en-IN') + ')</div>';
    }

    var content = 
        '<div style="border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">' +
            '<h2 style="margin: 0; font-size: 18px; color: #0f172a;">' + escapeHtml(data.name) + '</h2>' +
            '<div style="font-size: 13px; color: #64748b; margin-top: 2px;">Reg/Form: ' + escapeHtml(data.reg_no) + ' • 📞 ' + escapeHtml(data.phone) + '</div>' +
        '</div>' +
        '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 18px;">' +
            '<div style="background: #f8fafc; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0;">' +
                '<div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform:uppercase;">કુલ ફી (Total Fee)</div>' +
                '<div style="font-size: 17px; font-weight: 800; color: #0f172a; margin-top:2px;">₹' + totalFeeAmount.toLocaleString('en-IN') + '</div>' +
                subFeeText +
            '</div>' +
            '<div style="background: #f0fdf4; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #bbf7d0;">' +
                '<div style="font-size: 11px; color: #166534; font-weight: 700; text-transform:uppercase;">કુલ જમા થયેલ</div>' +
                '<div style="font-size: 17px; font-weight: 800; color: #16a34a; margin-top:2px;">₹' + data.total_paid.toLocaleString('en-IN') + '</div>' +
            '</div>' +
            '<div style="background: #fef2f2; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #fecaca;">' +
                '<div style="font-size: 11px; color: #991b1b; font-weight: 700; text-transform:uppercase;">કુલ બાકી રકમ</div>' +
                '<div style="font-size: 17px; font-weight: 800; color: #dc2626; margin-top:2px;">₹' + data.total_due.toLocaleString('en-IN') + '</div>' +
            '</div>' +
        '</div>' +
        '<table class="modern-table">' +
            '<thead>' +
                '<tr><th>ફી નો તબક્કો</th><th>કુલ રકમ</th><th>જમા થયેલ</th><th>બાકી રકમ</th><th>સ્ટેટસ</th></tr>' +
            '</thead>' +
            '<tbody>' +
                '<tr>' +
                    '<td><strong>Form Fee (ફોર્મ ફી)</strong></td>' +
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
                    '<td><strong>2nd Payment (બીજો હપ્તો)</strong></td>' +
                    '<td>₹' + stages.second_payment.required_amount + '</td>' +
                    '<td>₹' + stages.second_payment.paid_amount + '</td>' +
                    '<td>₹' + stages.second_payment.due_amount + '</td>' +
                    '<td>' + (stages.second_payment.is_paid ? '<span class="badge badge-green">Paid</span>' : '<span class="badge badge-red">Due</span>') + '</td>' +
                '</tr>' +
                (stages.extension_fee && (stages.extension_fee.paid_amount > 0 || stages.extension_fee.due_amount > 0) ? 
                '<tr>' +
                    '<td><strong>Extension Fee (વધારાના દિવસોની ફી)</strong></td>' +
                    '<td>₹' + (stages.extension_fee.required_amount || (stages.extension_fee.paid_amount + stages.extension_fee.due_amount)) + '</td>' +
                    '<td>₹' + stages.extension_fee.paid_amount + '</td>' +
                    '<td>₹' + stages.extension_fee.due_amount + '</td>' +
                    '<td>' + (stages.extension_fee.due_amount <= 0 ? '<span class="badge badge-green">Paid</span>' : (stages.extension_fee.paid_amount > 0 ? '<span class="badge badge-amber">Partial</span>' : '<span class="badge badge-red">Due</span>')) + '</td>' +
                '</tr>' : '') +
                (stages.other && stages.other.paid_amount > 0 ? 
                '<tr>' +
                    '<td><strong>Other Fee (અન્ય ફી)</strong></td>' +
                    '<td>₹' + stages.other.paid_amount + '</td>' +
                    '<td>₹' + stages.other.paid_amount + '</td>' +
                    '<td>₹0</td>' +
                    '<td><span class="badge badge-green">Paid</span></td>' +
                '</tr>' : '') +
            '</tbody>' +
        '</table>';

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
