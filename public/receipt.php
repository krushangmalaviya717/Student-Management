<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// If student is logged in, redirect them to the secure single student e-receipt view
if (!is_logged_in() && isset($_SESSION['student']['id'])) {
    $targetId = (int)($_GET['id'] ?? 0);
    $targetNo = trim($_GET['receipt_no'] ?? '');
    if ($targetId > 0) {
        redirect('public/student/receipt.php?id=' . $targetId);
    } elseif ($targetNo !== '') {
        redirect('public/student/receipt.php?receipt_no=' . urlencode($targetNo));
    } else {
        redirect('public/student/fees.php');
    }
    exit;
}

require_login();

$pdo = db();
$paymentId = (int)($_GET['id'] ?? 0);
$receiptNo = trim($_GET['receipt_no'] ?? '');

if ($paymentId > 0) {
    $stmt = $pdo->prepare(
        "SELECT p.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, s.address, s.is_temporary,
                b.name AS batch_name, u.name AS collector_name
         FROM payments p
         JOIN students s ON s.id = p.student_id
         LEFT JOIN batches b ON b.id = COALESCE(s.joined_batch_id, s.preferred_batch_id)
         LEFT JOIN users u ON u.id = p.collected_by
         WHERE p.id = ?"
    );
    $stmt->execute([$paymentId]);
} elseif ($receiptNo !== '') {
    $stmt = $pdo->prepare(
        "SELECT p.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, s.address, s.is_temporary,
                b.name AS batch_name, u.name AS collector_name
         FROM payments p
         JOIN students s ON s.id = p.student_id
         LEFT JOIN batches b ON b.id = COALESCE(s.joined_batch_id, s.preferred_batch_id)
         LEFT JOIN users u ON u.id = p.collected_by
         WHERE p.receipt_no = ?"
    );
    $stmt->execute([$receiptNo]);
} else {
    die('Receipt not specified.');
}

$payment = $stmt->fetch();
if (!$payment) {
    die('Receipt not found.');
}

// Function to convert amount to Indian Rupees in words
function number_to_words_inr(float $number): string
{
    $no = floor($number);
    $point = round($number - $no, 2) * 100;
    $words = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
        19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty',
        50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty',
        90 => 'Ninety'
    ];
    $digits = ['', 'Hundred', 'Thousand', 'Lakh', 'Crore'];

    $str = [];
    $digits_length = strlen((string)$no);
    $i = 0;
    while ($i < $digits_length) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number) {
            $counter = count($str);
            $plural = ($counter && $number > 9) ? '' : '';
            $hundred = ($counter == 1 && isset($str[0]) && $str[0]) ? ' and ' : '';
            $str[] = ($number < 21) ? $words[$number] . ' ' . $digits[$counter] . $plural . ' ' . $hundred
                : $words[floor($number / 10) * 10] . ' ' . $words[$number % 10] . ' ' . $digits[$counter] . $plural . ' ' . $hundred;
        } else {
            $str[] = '';
        }
    }
    $str = array_reverse($str);
    $result = implode('', $str);
    $points = ($point) ? " and " . ($words[floor($point / 10) * 10] . " " . $words[$point = $point % 10]) . " Paise" : '';
    $final = trim($result) . " Rupees" . $points . " Only";
    return $final ?: 'Zero Rupees Only';
}

$stageLabels = [
    'form_fee' => 'Registration / Form Confirmation Fee',
    'joining_fee' => 'Joining Fee / First Installment',
    'second_payment' => 'Second Payment / 10-Day Installment',
    'extension_fee' => 'Course Extension Fee',
    'other' => 'Course / Activity Fee',
];

$stageDescriptions = [
    'form_fee' => 'Initial admission form confirmation (Non-refundable upon cancellation)',
    'joining_fee' => 'First course tuition installment payable upon joining batch',
    'second_payment' => 'Course fee installment within first 10 days of classes',
    'extension_fee' => 'Approved batch attendance extension charge',
    'other' => 'Institutional fee payment record',
];

$stageKey = $payment['stage'] ?? '';
$stageTitle = $stageLabels[$stageKey] ?? ucwords(str_replace('_', ' ', $stageKey));
$stageDesc = $stageDescriptions[$stageKey] ?? '';

// If custom notes/particulars are provided (e.g. manual/temporary receipt), display appropriately
if (!empty($payment['notes'])) {
    if ($stageKey === 'other' || !isset($stageLabels[$stageKey])) {
        $stageTitle = $payment['notes'];
        $stageDesc = '';
    } else {
        $stageDesc = $payment['notes'];
    }
}

$businessName = get_setting('business_name', 'Devangi Sewing Classes');
$instituteTagline = get_setting('institute_tagline', 'Stitching & Fashion Designing Training Institute');
$institutePhone = trim((string)get_setting('institute_phone', ''));
$instituteAddress = trim((string)get_setting('institute_address', ''));
$receiptTermsRaw = (string)get_setting('receipt_terms_text', "• Form confirmation fee is non-refundable upon cancellation.\n• Official computer-generated receipt for institutional records.");
$receiptTermsLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $receiptTermsRaw))));

/**
 * Reusable function to render a minimal receipt card
 */
function render_receipt_card(array $payment, string $copyType, string $stageTitle, string $stageDesc, string $businessName, string $instituteTagline = '', string $institutePhone = '', string $instituteAddress = '', array $receiptTermsLines = []): void
{
    $isStudent = ($copyType === 'STUDENT COPY');
    $badgeClass = $isStudent ? 'badge-student' : 'badge-office';
?>
<div class="receipt-card">
    <div class="card-top-group">
        <!-- Header -->
        <div class="receipt-header">
            <div class="header-left">
                <h1 class="inst-title"><?= e($businessName) ?></h1>
                <?php if ($instituteTagline !== ''): ?>
                    <p class="inst-tagline"><?= e($instituteTagline) ?></p>
                <?php endif; ?>
                <?php if ($institutePhone !== '' || $instituteAddress !== ''): ?>
                    <p class="inst-tagline" style="font-size: 11px; margin-top: 2px;">
                        <?= e(implode(' | ', array_filter([$institutePhone, $instituteAddress]))) ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="header-right">
                <div class="receipt-badge">FEE RECEIPT</div>
                <div class="copy-badge <?= $badgeClass ?>"><?= e($copyType) ?></div>
            </div>
        </div>

        <!-- Meta Details Grid -->
        <div class="meta-grid">
            <div class="meta-col">
                <div class="meta-row">
                    <span class="meta-lbl">Receipt No:</span>
                    <span class="meta-val font-mono fw-bold"><?= e($payment['receipt_no']) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-lbl">Payment Date:</span>
                    <span class="meta-val"><?= date('d-M-Y, h:i A', strtotime($payment['payment_date'])) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-lbl">Payment Mode:</span>
                    <span class="meta-val fw-bold">
                        <?= strtoupper(e($payment['method'])) ?>
                        <?php if (!empty($payment['reference_no'])): ?>
                            <span class="ref-no">(Ref: <?= e($payment['reference_no']) ?>)</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="meta-row">
                    <span class="meta-lbl">Fee Category:</span>
                    <span class="meta-val"><?= e($stageTitle) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-lbl">Received By:</span>
                    <span class="meta-val"><?= e($payment['collector_name'] ?? 'Admin Office') ?></span>
                </div>
            </div>

            <div class="meta-col">
                <div class="meta-row">
                    <span class="meta-lbl">Student Name:</span>
                    <span class="meta-val fw-bold student-name"><?= e($payment['student_name']) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-lbl">Registration No:</span>
                    <span class="meta-val font-mono fw-bold"><?= e($payment['registration_no'] ?: (!empty($payment['is_temporary']) ? 'Temporary Record' : 'Pending Confirmation')) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-lbl">Form Number:</span>
                    <span class="meta-val font-mono"><?= e($payment['form_no'] ?: (!empty($payment['is_temporary']) ? 'Manual Entry' : 'N/A')) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-lbl">Assigned Batch:</span>
                    <span class="meta-val"><?= e($payment['batch_name'] ?: (!empty($payment['is_temporary']) ? 'Walk-in / Temporary' : 'Not Assigned / Any')) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-lbl">Student Mobile:</span>
                    <span class="meta-val"><?= e($payment['student_mobile']) ?></span>
                </div>
            </div>
        </div>

        <!-- Particulars Table -->
        <table class="receipt-table">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;">#</th>
                    <th>Fee Particulars / Description</th>
                    <th style="width: 150px; text-align: right;">Amount (₹)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="text-align: center; color: #64748b; font-weight: 600;">1</td>
                    <td>
                        <div class="item-title"><?= e($stageTitle) ?></div>
                        <?php if (!empty($stageDesc)): ?>
                            <div class="item-desc"><?= e($stageDesc) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="amount-val">₹<?= number_format($payment['amount'], 2) ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="2" class="total-label">Total Amount Paid (INR):</td>
                    <td class="total-val">₹<?= number_format($payment['amount'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Amount in Words -->
        <div class="words-line">
            <span class="words-lbl">Amount in Words:</span>
            <span class="words-text"><?= number_to_words_inr((float)$payment['amount']) ?></span>
        </div>
    </div>

    <!-- Authorized Signatory & Institutional Notes (No Student Signature) -->
    <div class="card-bottom-group">
        <div class="receipt-footer">
            <div class="note-col text-left">
                <?php foreach ($receiptTermsLines as $termLine): ?>
                    <?php $cleanLine = ltrim($termLine, "•-* \t"); ?>
                    <p>• <?= e($cleanLine) ?></p>
                <?php endforeach; ?>
            </div>
            <div class="sign-col text-right">
                <div class="sign-space"></div>
                <div class="sign-line ml-auto"></div>
                <div class="sign-label">Authorized Signatory (<?= e($payment['collector_name'] ?? 'Admin') ?>)</div>
            </div>
        </div>
    </div>
</div>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt <?= e($payment['receipt_no']) ?> - <?= e($businessName) ?></title>
<style>
    *, *::before, *::after {
        box-sizing: border-box;
    }
    body {
        margin: 0;
        padding: 20px;
        background: #f1f5f9;
        color: #0f172a;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 13px;
        line-height: 1.4;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Top Action Bar (hidden on print) */
    .actions-bar {
        max-width: 840px;
        margin: 0 auto 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }
    .actions-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .page-hint {
        font-size: 13px;
        color: #64748b;
    }
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 36px;
        padding: 0 16px;
        font-size: 13.5px;
        font-weight: 600;
        border-radius: 6px;
        cursor: pointer;
        text-decoration: none;
        border: 1px solid transparent;
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    .btn-secondary {
        background: #ffffff;
        color: #334155;
        border-color: #cbd5e1;
    }
    .btn-secondary:hover {
        background: #f8fafc;
        border-color: #94a3b8;
    }
    .btn-primary {
        background: #4f46e5;
        color: #ffffff;
    }
    .btn-primary:hover {
        background: #4338ca;
    }
    @media screen and (max-width: 640px) {
        .meta-grid { grid-template-columns: 1fr; }
        .receipt-header { flex-direction: column; gap: 10px; }
        .header-right { align-items: flex-start; text-align: left; }
        .receipt-footer { flex-direction: column; align-items: flex-start; gap: 16px; }
        .sign-col { text-align: left; width: 100%; }
        .sign-line.ml-auto { margin-left: 0; }
    }

    /* Sheet Container on Screen */
    .receipt-sheet {
        max-width: 840px;
        margin: 0 auto;
        background: #ffffff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
    }

    /* Receipt Card */
    .receipt-card {
        border: 1.5px solid #0f172a;
        border-radius: 6px;
        padding: 20px 24px;
        background: #ffffff;
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 480px;
    }

    .card-top-group {
        width: 100%;
    }
    .card-bottom-group {
        width: 100%;
        margin-top: 24px;
    }

    /* Header */
    .receipt-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding-bottom: 12px;
        border-bottom: 1.5px solid #0f172a;
        margin-bottom: 14px;
    }
    .inst-title {
        margin: 0;
        font-size: 21px;
        font-weight: 800;
        letter-spacing: 0.8px;
        color: #0f172a;
        text-transform: uppercase;
    }
    .inst-tagline {
        margin: 3px 0 0;
        font-size: 12px;
        color: #64748b;
        font-weight: 500;
        letter-spacing: 0.2px;
    }
    .header-right {
        text-align: right;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 5px;
    }
    .receipt-badge {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.8px;
        color: #475569;
        text-transform: uppercase;
    }
    .copy-badge {
        display: inline-block;
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        padding: 3px 12px;
        border-radius: 4px;
    }
    .badge-student {
        background: #0f172a;
        color: #ffffff;
    }
    .badge-office {
        background: #f1f5f9;
        color: #0f172a;
        border: 1.5px solid #0f172a;
    }

    /* Meta Grid */
    .meta-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        column-gap: 32px;
        row-gap: 8px;
        padding: 4px 0 14px;
        font-size: 13px;
        border-bottom: 1px solid #e2e8f0;
        margin-bottom: 14px;
    }
    .meta-col {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .meta-row {
        display: flex;
        align-items: baseline;
    }
    .meta-lbl {
        width: 120px;
        flex-shrink: 0;
        color: #64748b;
        font-size: 12.5px;
        font-weight: 500;
    }
    .meta-val {
        color: #0f172a;
        font-size: 13px;
        word-break: break-word;
    }
    .student-name {
        font-size: 14px;
        color: #0f172a;
    }
    .ref-no {
        font-size: 11.5px;
        color: #475569;
        font-weight: normal;
    }
    .font-mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }
    .fw-bold { font-weight: 700; }
    .fw-semibold { font-weight: 600; }

    /* Table */
    .receipt-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
        font-size: 13px;
    }
    .receipt-table th {
        background: #f8fafc;
        border-top: 1.5px solid #0f172a;
        border-bottom: 1.5px solid #0f172a;
        padding: 8px 10px;
        font-size: 12px;
        font-weight: 700;
        color: #1e293b;
        text-align: left;
        letter-spacing: 0.4px;
    }
    .receipt-table td {
        padding: 10px 10px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: middle;
    }
    .item-title {
        font-weight: 600;
        color: #0f172a;
        font-size: 13.5px;
    }
    .item-desc {
        font-size: 11.5px;
        color: #64748b;
        margin-top: 2px;
    }
    .amount-val {
        text-align: right;
        font-weight: 700;
        font-size: 14px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        color: #0f172a;
    }
    .total-row td {
        border-top: 1.5px solid #0f172a;
        border-bottom: 1.5px solid #0f172a;
        background: #f8fafc;
        padding: 10px 10px;
    }
    .total-label {
        text-align: right;
        font-weight: 700;
        color: #0f172a;
        font-size: 13px;
    }
    .total-val {
        text-align: right;
        font-weight: 800;
        font-size: 15px;
        color: #0f172a;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }

    /* Words Line */
    .words-line {
        display: flex;
        align-items: baseline;
        gap: 8px;
        font-size: 12.5px;
        margin-bottom: 6px;
        padding: 7px 12px;
        background: #f8fafc;
        border-left: 3.5px solid #0f172a;
        border-radius: 0 4px 4px 0;
    }
    .words-lbl {
        color: #64748b;
        font-weight: 600;
        flex-shrink: 0;
    }
    .words-text {
        font-style: italic;
        color: #0f172a;
        font-weight: 600;
    }

    /* Footer & Authorized Signatory */
    .receipt-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding-top: 8px;
    }
    .sign-col {
        width: 240px;
    }
    .sign-space {
        height: 38px;
    }
    .sign-line {
        border-top: 1px dashed #475569;
        margin-bottom: 5px;
        width: 200px;
    }
    .ml-auto {
        margin-left: auto;
    }
    .sign-label {
        font-size: 12px;
        color: #475569;
        font-weight: 600;
    }
    .note-col {
        flex: 1;
        padding-right: 24px;
    }
    .note-col p {
        margin: 2px 0;
        font-size: 11px;
        color: #64748b;
        line-height: 1.4;
    }
    .text-left { text-align: left; }
    .text-right { text-align: right; }

    /* Very Faint Cut Guide Line (No text, minimal & subtle) */
    .cut-divider {
        width: 100%;
        margin: 18px 0;
        border-top: 1px dashed #cbd5e1;
        height: 0;
    }

    /* Print Styles: Exact A4 Single Sheet Guarantee with Full Page Utilization */
    @page {
        size: A4 portrait;
        margin: 8mm 10mm;
    }

    @media print {
        html, body {
            background: #ffffff !important;
            padding: 0 !important;
            margin: 0 !important;
            height: 100% !important;
            font-size: 12px !important;
        }
        .actions-bar {
            display: none !important;
        }
        .receipt-sheet {
            max-width: 100% !important;
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            border: none !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
        }
        .receipt-card {
            border: 1.5px solid #000000 !important;
            box-shadow: none !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            padding: 14px 18px !important;
            border-radius: 4px !important;
            height: 130mm !important;
            max-height: 131mm !important;
            min-height: 128mm !important;
            box-sizing: border-box !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
        }
        .card-top-group {
            width: 100% !important;
        }
        .card-bottom-group {
            width: 100% !important;
            margin-top: auto !important;
        }
        .receipt-header {
            padding-bottom: 8px !important;
            margin-bottom: 8px !important;
            border-bottom: 1.5px solid #000000 !important;
        }
        .inst-title {
            font-size: 18px !important;
        }
        .inst-tagline {
            font-size: 10.5px !important;
        }
        .meta-grid {
            padding: 2px 0 8px !important;
            margin-bottom: 8px !important;
            font-size: 11.5px !important;
            row-gap: 5px !important;
        }
        .meta-lbl {
            font-size: 11px !important;
            width: 110px !important;
        }
        .meta-val {
            font-size: 11.5px !important;
        }
        .student-name {
            font-size: 12.5px !important;
        }
        .receipt-table {
            margin-bottom: 6px !important;
            font-size: 11.5px !important;
        }
        .receipt-table th {
            padding: 5px 8px !important;
            font-size: 11px !important;
            border-top: 1.5px solid #000000 !important;
            border-bottom: 1.5px solid #000000 !important;
        }
        .receipt-table td {
            padding: 7px 8px !important;
        }
        .item-title {
            font-size: 12px !important;
        }
        .item-desc {
            font-size: 10px !important;
        }
        .amount-val {
            font-size: 12.5px !important;
        }
        .total-row td {
            border-top: 1.5px solid #000000 !important;
            border-bottom: 1.5px solid #000000 !important;
            padding: 6px 8px !important;
        }
        .total-label {
            font-size: 11.5px !important;
        }
        .total-val {
            font-size: 13px !important;
        }
        .words-line {
            margin-bottom: 4px !important;
            padding: 4px 8px !important;
            font-size: 11px !important;
            border-left: 3px solid #000000 !important;
        }
        .sign-space {
            height: 32px !important;
        }
        .sign-line {
            margin-bottom: 3px !important;
            border-top: 1px dashed #000000 !important;
            width: 170px !important;
        }
        .sign-label {
            font-size: 10.5px !important;
            color: #000000 !important;
        }
        .note-col p {
            font-size: 9.5px !important;
            color: #222222 !important;
        }
        .cut-divider {
            width: 100% !important;
            margin: 3mm 0 !important;
            border-top: 1px dashed #d1d5db !important;
            height: 0 !important;
        }
        .badge-student {
            background: #000000 !important;
            color: #ffffff !important;
        }
        .badge-office {
            background: #ffffff !important;
            color: #000000 !important;
            border: 1.5px solid #000000 !important;
        }
    }
</style>
</head>
<body>

<div class="actions-bar">
    <div class="actions-left">
        <a href="javascript:history.back()" class="btn btn-secondary">&larr; Back</a>
        <span class="page-hint">Print preview (Both copies evenly fill full A4 sheet)</span>
    </div>
    <div>
        <button onclick="window.print()" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Print Receipt (A4)
        </button>
    </div>
</div>

<div class="receipt-sheet">
    <!-- 1. STUDENT COPY (Top Half of A4) -->
    <?php render_receipt_card($payment, 'STUDENT COPY', $stageTitle, $stageDesc, $businessName, $instituteTagline, $institutePhone, $instituteAddress, $receiptTermsLines); ?>

    <!-- Very Faint Cutting Guide Line (Without text, ultra subtle) -->
    <div class="cut-divider"></div>

    <!-- 2. OFFICE COPY (Bottom Half of A4) -->
    <?php render_receipt_card($payment, 'OFFICE COPY', $stageTitle, $stageDesc, $businessName, $instituteTagline, $institutePhone, $instituteAddress, $receiptTermsLines); ?>
</div>

<?php if (!empty($_GET['print']) || !empty($_GET['autoprint'])): ?>
<script>
window.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => { window.print(); }, 400);
});
</script>
<?php endif; ?>

</body>
</html>