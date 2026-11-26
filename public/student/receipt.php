<?php
$pageTitle = 'પેમેન્ટ પહોંચ - Devangi Classes';
$activePage = 'fees';
require_once __DIR__ . '/includes/header.php';
require_student_login();

reload_student_session();
$s = current_student();
$studentId = (int)$s['id'];

$paymentId = (int)($_GET['id'] ?? 0);
$receiptNo = trim($_GET['receipt_no'] ?? '');

$pdo = db();
if ($paymentId > 0) {
    $stmt = $pdo->prepare(
        "SELECT p.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, s.address,
                b.name AS batch_name, b.start_time, b.end_time
         FROM payments p
         JOIN students s ON s.id = p.student_id
         LEFT JOIN batches b ON b.id = COALESCE(s.joined_batch_id, s.preferred_batch_id)
         WHERE p.id = ? AND p.student_id = ?"
    );
    $stmt->execute([$paymentId, $studentId]);
} elseif ($receiptNo !== '') {
    $stmt = $pdo->prepare(
        "SELECT p.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, s.address,
                b.name AS batch_name, b.start_time, b.end_time
         FROM payments p
         JOIN students s ON s.id = p.student_id
         LEFT JOIN batches b ON b.id = COALESCE(s.joined_batch_id, s.preferred_batch_id)
         WHERE p.receipt_no = ? AND p.student_id = ?"
    );
    $stmt->execute([$receiptNo, $studentId]);
} else {
    flash('error', 'પહોંચ નંબર દર્શાવેલ નથી.');
    redirect('public/student/fees.php');
    exit;
}

$payment = $stmt->fetch();
if (!$payment) {
    flash('error', 'પહોંચ મળી નથી અથવા તમે આ પહોંચ જોવા માટે અધિકૃત નથી.');
    redirect('public/student/fees.php');
    exit;
}

// Function to convert amount to Indian Rupees in words
if (!function_exists('number_to_words_inr')) {
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
}

$stageLabels = [
    'form_fee' => 'Registration / Form Confirmation Fee',
    'joining_fee' => 'First Installment (Joining Fee)',
    'second_payment' => 'Second Installment (Course Fee)',
    'extension_fee' => 'Course Extension Fee',
    'other' => 'Course Fee',
];

$stageKey = $payment['stage'] ?? '';
$stageTitle = $stageLabels[$stageKey] ?? ucwords(str_replace('_', ' ', $stageKey));
$businessName = get_setting('business_name', 'Devangi Sewing Classes');
$instituteTagline = get_setting('institute_tagline', 'Stitching & Fashion Designing Training Institute');
$institutePhone = trim((string)get_setting('institute_phone', ''));
$instituteAddress = trim((string)get_setting('institute_address', ''));
?>

<div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="/public/student/fees.php" class="btn btn-sm btn-outline-secondary px-3" style="font-size: 13px; font-weight: 500; border-radius: 6px;">
        &larr; Back to Fees
    </a>
</div>

<!-- ================= OFFICIAL INSTITUTIONAL PAYMENT RECEIPT ================= -->
<div style="max-width: 580px; margin: 0 auto 35px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08); overflow: hidden;">
    
    <!-- Top Navy Brand Bar -->
    <div style="background: linear-gradient(135deg, #0b132b 0%, #1e3a8a 100%); padding: 26px 24px 20px 24px; text-align: center; color: #ffffff; border-bottom: 3px solid #f59e0b;">
        <div style="width: 44px; height: 44px; margin: 0 auto 10px auto; background: rgba(255, 255, 255, 0.12); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fde047; border: 1px solid rgba(255, 255, 255, 0.2);">
            <i class="bi bi-patch-check-fill"></i>
        </div>
        <h3 style="margin: 0 0 4px 0; font-size: 19px; font-weight: 700; color: #ffffff; letter-spacing: 0.5px;">
            <?= htmlspecialchars(strtoupper($businessName)) ?>
        </h3>
        <div style="font-size: 12px; color: #cbd5e1; margin-bottom: 12px;">
            <?= htmlspecialchars($instituteTagline) ?>
            <?php if ($institutePhone !== '' || $instituteAddress !== ''): ?>
                <br><span style="font-size: 11px;"><?= htmlspecialchars(implode(' | ', array_filter([$institutePhone, $instituteAddress]))) ?></span>
            <?php endif; ?>
        </div>
        <div>
            <span style="display: inline-block; background: rgba(255, 255, 255, 0.15); color: #fef08a; border: 1px solid rgba(254, 240, 138, 0.4); font-size: 10.5px; font-weight: 700; letter-spacing: 1px; padding: 4px 14px; border-radius: 20px; text-transform: uppercase;">
                ✦ OFFICIAL PAYMENT RECEIPT ✦
            </span>
        </div>
    </div>

    <!-- Receipt Meta Header -->
    <div style="background: #f8fafc; padding: 12px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 12.5px;">
        <div>
            <span style="color: #64748b;">Receipt No:</span>
            <strong style="color: #0f172a; font-family: monospace; font-size: 13.5px; margin-left: 4px;"><?= htmlspecialchars($payment['receipt_no']) ?></strong>
        </div>
        <div>
            <span style="color: #64748b;">Date &amp; Time:</span>
            <strong style="color: #1e293b; margin-left: 4px;"><?= date('d M, Y - h:i A', strtotime($payment['payment_date'])) ?></strong>
        </div>
    </div>

    <!-- Amount Paid Section -->
    <div style="padding: 22px 24px; border-bottom: 1px solid #f1f5f9; background: #ffffff;">
        <div style="background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1.5px solid #a7f3d0; border-radius: 12px; padding: 16px 20px;">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span style="font-size: 11px; font-weight: 700; color: #047857; text-transform: uppercase; letter-spacing: 0.6px;">
                    TOTAL AMOUNT PAID
                </span>
                <span style="display: inline-flex; align-items: center; gap: 4px; background: #047857; color: #ffffff; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; letter-spacing: 0.5px;">
                    <i class="bi bi-check-circle-fill"></i> PAID
                </span>
            </div>
            <div style="font-size: 32px; font-weight: 800; color: #047857; letter-spacing: -0.5px; margin-bottom: 4px;">
                ₹<?= number_format((float)$payment['amount'], 2) ?>
            </div>
            <div style="font-size: 12px; color: #065f46; font-style: italic;">
                <?= htmlspecialchars(number_to_words_inr((float)$payment['amount'])) ?>
            </div>
        </div>
    </div>

    <!-- Structured Student & Fee Information -->
    <div style="padding: 18px 24px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <tbody>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #64748b; width: 45%;">Student Name</td>
                    <td style="padding: 10px 0; color: #0f172a; font-weight: 700; text-align: right;"><?= htmlspecialchars($payment['student_name']) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #64748b;">Registration No</td>
                    <td style="padding: 10px 0; color: #1e3a8a; font-family: monospace; font-weight: 700; text-align: right;"><?= htmlspecialchars($payment['registration_no'] ?: $payment['form_no']) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #64748b;">Fee Particulars</td>
                    <td style="padding: 10px 0; color: #0f172a; font-weight: 600; text-align: right;"><?= htmlspecialchars($stageTitle) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #64748b;">Payment Mode</td>
                    <td style="padding: 10px 0; color: #0f172a; font-weight: 700; text-align: right; text-transform: uppercase;">
                        <span style="background: #f1f5f9; padding: 2px 8px; border-radius: 4px; border: 1px solid #cbd5e1;"><?= htmlspecialchars($payment['method']) ?></span>
                    </td>
                </tr>
                <?php if (!empty($payment['reference_no'])): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #64748b;">Reference / UTR No</td>
                    <td style="padding: 10px 0; color: #0f172a; font-family: monospace; font-weight: 600; text-align: right;"><?= htmlspecialchars($payment['reference_no']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($payment['batch_name'])): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #64748b;">Assigned Batch</td>
                    <td style="padding: 10px 0; color: #0f172a; font-weight: 500; text-align: right;"><?= htmlspecialchars($payment['batch_name']) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="padding: 10px 0; color: #64748b;">Mobile Number</td>
                    <td style="padding: 10px 0; color: #334155; font-weight: 600; font-family: monospace; text-align: right;"><?= htmlspecialchars($payment['student_mobile']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Institutional Footer & Verification -->
    <div style="background: #f8fafc; padding: 14px 24px; border-top: 1px solid #e2e8f0; font-size: 11.5px; color: #64748b; line-height: 1.6; text-align: center;">
        <div style="color: #047857; font-weight: 600; margin-bottom: 2px;">
            <i class="bi bi-shield-check me-1"></i> Verified Official Digital Record &bull; <?= htmlspecialchars($businessName) ?>
        </div>
        <div>This is an official computer-generated receipt. No physical signature is required.</div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
