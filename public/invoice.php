<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo = db();
$invoiceId = (int)($_GET['id'] ?? 0);
$extensionId = (int)($_GET['extension_id'] ?? 0);
$invoiceNo = trim($_GET['invoice_no'] ?? '');

if ($invoiceId > 0) {
    $stmt = $pdo->prepare(
        "SELECT inv.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, s.address,
                b.name AS batch_name, u.name AS creator_name,
                ext.extension_days, ext.old_expiry_date, ext.new_expiry_date, ext.reason AS extension_reason,
                ext.calculated_amount, ext.final_amount, ext.is_manual_override, ext.override_reason,
                p.receipt_no, p.method AS payment_method, p.reference_no, p.payment_date
         FROM invoices inv
         JOIN students s ON s.id = inv.student_id
         LEFT JOIN batches b ON b.id = s.joined_batch_id OR b.id = s.preferred_batch_id
         LEFT JOIN users u ON u.id = inv.collected_by
         LEFT JOIN extensions ext ON ext.id = inv.extension_id
         LEFT JOIN payments p ON p.id = inv.payment_id
         WHERE inv.id = ?"
    );
    $stmt->execute([$invoiceId]);
} elseif ($extensionId > 0) {
    $stmt = $pdo->prepare(
        "SELECT inv.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, s.address,
                b.name AS batch_name, u.name AS creator_name,
                ext.extension_days, ext.old_expiry_date, ext.new_expiry_date, ext.reason AS extension_reason,
                ext.calculated_amount, ext.final_amount, ext.is_manual_override, ext.override_reason,
                p.receipt_no, p.method AS payment_method, p.reference_no, p.payment_date
         FROM invoices inv
         JOIN students s ON s.id = inv.student_id
         LEFT JOIN batches b ON b.id = s.joined_batch_id OR b.id = s.preferred_batch_id
         LEFT JOIN users u ON u.id = inv.collected_by
         LEFT JOIN extensions ext ON ext.id = inv.extension_id
         LEFT JOIN payments p ON p.id = inv.payment_id
         WHERE inv.extension_id = ?"
    );
    $stmt->execute([$extensionId]);
} elseif ($invoiceNo !== '') {
    $stmt = $pdo->prepare(
        "SELECT inv.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, s.address,
                b.name AS batch_name, u.name AS creator_name,
                ext.extension_days, ext.old_expiry_date, ext.new_expiry_date, ext.reason AS extension_reason,
                ext.calculated_amount, ext.final_amount, ext.is_manual_override, ext.override_reason,
                p.receipt_no, p.method AS payment_method, p.reference_no, p.payment_date
         FROM invoices inv
         JOIN students s ON s.id = inv.student_id
         LEFT JOIN batches b ON b.id = s.joined_batch_id OR b.id = s.preferred_batch_id
         LEFT JOIN users u ON u.id = inv.collected_by
         LEFT JOIN extensions ext ON ext.id = inv.extension_id
         LEFT JOIN payments p ON p.id = inv.payment_id
         WHERE inv.invoice_no = ?"
    );
    $stmt->execute([$invoiceNo]);
} else {
    die('Invoice not specified.');
}

$invoice = $stmt->fetch();
if (!$invoice) {
    die('Invoice not found.');
}

// Fetch itemized line items
$lineItems = [];
if ($invoice['extension_id']) {
    $liStmt = $pdo->prepare(
        "SELECT eli.*, ecr.effective_from, ecr.effective_to
         FROM extension_line_items eli
         LEFT JOIN extension_charge_rates ecr ON ecr.id = eli.charge_rate_id
         WHERE eli.extension_id = ?
         ORDER BY eli.id ASC"
    );
    $liStmt->execute([$invoice['extension_id']]);
    $lineItems = $liStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= e($invoice['invoice_no']) ?> - Devangi Sewing Classes</title>
<style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f1f5f9;
        color: #1e293b;
        margin: 0;
        padding: 20px;
    }
    .invoice-container {
        max-width: 800px;
        margin: 0 auto;
        background: #fff;
        border: 2px solid #0f172a;
        border-radius: 8px;
        padding: 30px 40px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
    }
    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid #0f172a;
        padding-bottom: 15px;
        margin-bottom: 20px;
    }
    .header h1 {
        margin: 0;
        font-size: 24px;
        color: #0f172a;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    .header .tagline {
        margin: 4px 0 0;
        font-size: 13px;
        color: #64748b;
    }
    .invoice-meta {
        text-align: right;
    }
    .invoice-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .badge-paid { background: #dcfce7; color: #166534; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    .info-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 12px 16px;
    }
    .info-box div { margin-bottom: 5px; }
    .info-box div:last-child { margin-bottom: 0; }
    .info-label {
        font-weight: 600;
        color: #475569;
        display: inline-block;
        width: 140px;
    }
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }
    .items-table th, .items-table td {
        border: 1px solid #cbd5e1;
        padding: 10px 14px;
        text-align: left;
        font-size: 14px;
    }
    .items-table th {
        background: #f1f5f9;
        font-weight: 600;
        color: #334155;
    }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .total-row td {
        font-weight: 700;
        font-size: 15px;
        background: #f8fafc;
    }
    .override-alert {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-left: 4px solid #2563eb;
        padding: 10px 14px;
        border-radius: 4px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    .footer-signatures {
        display: grid;
        grid-template-columns: 1fr 1fr;
        margin-top: 40px;
        padding-top: 20px;
    }
    .sign-box { text-align: center; }
    .sign-line {
        width: 180px;
        border-top: 1px dashed #475569;
        margin: 40px auto 6px;
    }
    .sign-label {
        font-size: 13px;
        color: #475569;
        font-weight: 500;
    }
    .actions-bar {
        max-width: 800px;
        margin: 0 auto 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 36px;
        background: #4f46e5;
        color: #ffffff;
        border: 1px solid transparent;
        padding: 0 16px;
        border-radius: 6px;
        cursor: pointer;
        text-decoration: none;
        font-size: 13.5px;
        font-weight: 600;
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    .btn:hover { background: #4338ca; }
    .btn-secondary {
        background: #ffffff;
        color: #334155;
        border-color: #cbd5e1;
    }
    .btn-secondary:hover {
        background: #f8fafc;
        border-color: #94a3b8;
    }
    @media screen and (max-width: 640px) {
        .invoice-container { padding: 20px; }
        .header { flex-direction: column; gap: 12px; }
        .invoice-meta { text-align: left; }
        .info-grid, .footer-signatures { grid-template-columns: 1fr; }
    }
    @media print {
        body { background: #fff; padding: 0; }
        .invoice-container { box-shadow: none; border: 1.5px solid #000; padding: 20px 30px; }
        .actions-bar { display: none !important; }
    }
</style>
</head>
<body>

<div class="actions-bar">
    <a href="javascript:history.back()" class="btn btn-secondary">&larr; Back</a>
    <div>
        <button onclick="window.print()" class="btn" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>Print Invoice
        </button>
    </div>
</div>

<div class="invoice-container">
    <div class="header">
        <div>
            <h1>DEVANGI SEWING CLASSES</h1>
            <div class="tagline">Stitching & Fashion Designing Training Institute</div>
        </div>
        <div class="invoice-meta">
            <div class="invoice-title">EXTENSION INVOICE</div>
            <div><strong>Invoice No:</strong> <?= e($invoice['invoice_no']) ?></div>
            <div><strong>Date:</strong> <?= date('d-M-Y', strtotime($invoice['invoice_date'])) ?></div>
            <div style="margin-top:4px;">
                <span class="badge <?= $invoice['payment_status'] === 'paid' ? 'badge-paid' : 'badge-pending' ?>">
                    Status: <?= strtoupper($invoice['payment_status']) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-box">
            <div><span class="info-label">Student Name:</span> <strong><?= e($invoice['student_name']) ?></strong></div>
            <div><span class="info-label">Registration No:</span> <?= e($invoice['registration_no'] ?: '-') ?></div>
            <div><span class="info-label">Form No:</span> <?= e($invoice['form_no']) ?></div>
            <div><span class="info-label">Contact:</span> <?= e($invoice['student_mobile']) ?></div>
            <div><span class="info-label">Batch:</span> <?= e($invoice['batch_name'] ?: 'General') ?></div>
        </div>

        <div class="info-box">
            <div><span class="info-label">Extension Days:</span> <strong>+<?= (int)$invoice['extension_days'] ?> Days</strong></div>
            <div><span class="info-label">Previous Expiry:</span> <?= format_date($invoice['old_expiry_date']) ?></div>
            <div><span class="info-label">New Expiry Date:</span> <strong><?= format_date($invoice['new_expiry_date']) ?></strong></div>
            <?php if ($invoice['extension_reason']): ?>
            <div><span class="info-label">Extension Note:</span> <?= e($invoice['extension_reason']) ?></div>
            <?php endif; ?>
            <div><span class="info-label">Approved By:</span> <?= e($invoice['creator_name'] ?? 'Admin') ?></div>
        </div>
    </div>

    <?php if ($invoice['is_manual_override']): ?>
    <div class="override-alert">
        <strong>Manual Amount Adjustment Applied:</strong><br>
        Standard Calculated Amount: ₹<?= number_format($invoice['calculated_amount'], 2) ?><br>
        Approved Final Amount: ₹<?= number_format($invoice['final_amount'], 2) ?><br>
        <?php if ($invoice['override_reason']): ?>Reason: <?= e($invoice['override_reason']) ?><?php endif; ?>
    </div>
    <?php endif; ?>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:40px;">Sr.</th>
                <th>Description / Rate Period</th>
                <th class="text-center" style="width:90px;">Days</th>
                <th class="text-right" style="width:120px;">Rate / Day</th>
                <th class="text-right" style="width:130px;">Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($lineItems): ?>
                <?php $i = 1; foreach ($lineItems as $li): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td>
                        <strong>Additional Course Extension</strong>
                        <br><small style="color:#64748b;">Effective Rate Period: <?= date('d-M-Y', strtotime($li['effective_from'])) ?> <?= $li['effective_to'] ? 'to ' . date('d-M-Y', strtotime($li['effective_to'])) : 'onwards' ?></small>
                    </td>
                    <td class="text-center"><?= (int)$li['days'] ?></td>
                    <td class="text-right">₹<?= number_format($li['rate_per_day'], 2) ?></td>
                    <td class="text-right">₹<?= number_format($li['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td>1</td>
                    <td>Course Extension (<?= (int)$invoice['extension_days'] ?> days)</td>
                    <td class="text-center"><?= (int)$invoice['extension_days'] ?></td>
                    <td class="text-right">-</td>
                    <td class="text-right">₹<?= number_format($invoice['total_amount'], 2) ?></td>
                </tr>
            <?php endif; ?>

            <tr class="total-row">
                <td colspan="4" class="text-right">Total Payable Amount:</td>
                <td class="text-right" style="color:#0f172a; font-size:16px;">₹<?= number_format($invoice['total_amount'], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($invoice['payment_status'] === 'paid' && $invoice['receipt_no']): ?>
    <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 15px; font-size:13px; margin-bottom:20px;">
        <strong>Payment Information:</strong> Paid on <?= date('d-M-Y', strtotime($invoice['payment_date'])) ?> via <?= strtoupper($invoice['payment_method']) ?>. Receipt No: <strong><?= e($invoice['receipt_no']) ?></strong> <?= $invoice['reference_no'] ? '(Ref: ' . e($invoice['reference_no']) . ')' : '' ?>.
    </div>
    <?php endif; ?>

    <div class="footer-signatures">
        <div class="sign-box">
            <div class="sign-line"></div>
            <div class="sign-label">Student Signature</div>
        </div>
        <div class="sign-box">
            <div class="sign-line"></div>
            <div class="sign-label">Authorized Signatory<br><small>(<?= e($invoice['creator_name'] ?? 'Admin') ?>)</small></div>
        </div>
    </div>
</div>

</body>
</html>
