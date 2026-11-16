<?php
$pageTitle = 'મારી પ્રોફાઇલ - Devangi Sewing Classes';
$activePage = 'profile';
require_once __DIR__ . '/includes/header.php';
require_student_login();

reload_student_session();
$s = current_student();
$studentId = (int)$s['id'];

// Check joined & course completion status
$isPaused = (!empty($s['is_paused']) || $s['status'] === 'paused');
$isJoined = !empty($s['entry_date']) || !empty($s['joined_batch_id']) || in_array($s['status'], ['active', 'extended', 'course_completed', 'joining_confirmed', 'paused']);
$isCourseCompleted = ($s['status'] === 'course_completed' || ($s['course_status'] ?? '') === 'completed');

// Query extensions joined with invoices
$extQuery = db()->prepare("
    SELECT 
        e.id,
        e.extension_days,
        e.final_amount,
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

if ($totalExtensionDays === 0 && !empty($s['base_expiry_date']) && !empty($s['final_expiry_date'])) {
    $bDate = new DateTime($s['base_expiry_date']);
    $fDate = new DateTime($s['final_expiry_date']);
    if ($fDate > $bDate) {
        $diff = $bDate->diff($fDate)->days;
        if ($diff > 0) $totalExtensionDays = $diff;
    }
}

// Fee details
$stmtFees = db()->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_paid, COUNT(*) AS tx_count
    FROM payments 
    WHERE student_id = ? AND status = 'success'
");
$stmtFees->execute([$studentId]);
$feeSummary = $stmtFees->fetch();
$totalPaid = (float)($feeSummary['total_paid'] ?? 0);

// Standard total course fee
$formFeeAmt = (float)get_setting('form_fee', 500);
$joiningFeeAmt = (float)get_setting('joining_fee', 3500);
$secondPaymentAmt = (float)get_setting('second_payment_amount', 4000);
$totalCourseFee = $formFeeAmt + $joiningFeeAmt + $secondPaymentAmt;

// Calculate any unpaid invoices
$invStmt = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE student_id = ? AND (payment_status != 'paid' OR payment_status IS NULL)");
$invStmt->execute([$studentId]);
$extDue = (float)$invStmt->fetchColumn();

$totalFeeOverall = $totalCourseFee + $extTotalAmt;
if ($totalFeeOverall < $totalPaid + $extDue) {
    $totalFeeOverall = $totalPaid + $extDue;
}
$totalDueOverall = max(0.0, $totalFeeOverall - $totalPaid);

$msg = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newPassword) || strlen($newPassword) < 4) {
        $error = 'નવો પાસવર્ડ ઓછામાં ઓછો 4 અક્ષરનો હોવો જોઈએ.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'નવો પાસવર્ડ અને કન્ફર્મ પાસવર્ડ સરખા નથી.';
    } else {
        // Verify current password
        $stmt = db()->prepare("SELECT password_hash FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $hash = $stmt->fetchColumn();

        $validCurrent = false;
        if (!empty($hash) && password_verify($oldPassword, $hash)) {
            $validCurrent = true;
        } elseif ($oldPassword === $s['student_mobile']) {
            $validCurrent = true;
        }

        if (!$validCurrent) {
            $error = 'જૂનો પાસવર્ડ ખોટો છે.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            db()->prepare("UPDATE students SET password_hash = ? WHERE id = ?")->execute([$newHash, $studentId]);
            $msg = 'પાસવર્ડ સફળતાપૂર્વક બદલાઈ ગયો છે!';
        }
    }
}
?>

<!-- 1. Main Student Avatar & Badge Card -->
<div class="card-app text-center">
    <?php if (!empty($s['photo_path'])): ?>
        <img src="<?= htmlspecialchars($s['photo_path']) ?>" alt="Photo" class="rounded-circle shadow-sm border mb-2" style="width: 95px; height: 95px; object-fit: cover;">
    <?php else: ?>
        <div class="d-inline-flex align-items-center justify-content-center bg-light text-primary rounded-circle shadow-sm border mb-2" style="width: 95px; height: 95px;">
            <i class="bi bi-person-fill display-4"></i>
        </div>
    <?php endif; ?>
    <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($s['name']) ?></h4>
    <div class="text-muted small">Registration No: <strong class="text-dark font-monospace"><?= htmlspecialchars($s['registration_no'] ?: $s['form_no']) ?></strong></div>
    <div class="mt-2">
        <?php if ($isCourseCompleted): ?>
            <span class="badge bg-success px-3 py-1 rounded-pill"><i class="bi bi-mortarboard-fill me-1"></i> Course Completed</span>
        <?php elseif ($isPaused): ?>
            <span class="badge bg-warning text-dark px-3 py-1 rounded-pill"><i class="bi bi-pause-circle-fill me-1"></i> On Hold (Paused)</span>
        <?php elseif ($isJoined): ?>
            <span class="badge bg-primary px-3 py-1 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i> Active <?= $totalExtensionDays > 0 ? '(+' . $totalExtensionDays . ' Days Ext)' : '' ?></span>
        <?php else: ?>
            <span class="badge bg-warning text-dark px-3 py-1 rounded-pill"><i class="bi bi-hourglass-split me-1"></i> Waiting List</span>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Course & Batch Details Card with Extended Days -->
<div class="card-app" id="batchDetails">
    <div class="card-title">
        <span><i class="bi bi-mortarboard-fill me-2 text-primary"></i> Course &amp; Batch Details</span>
        <?php if ($isCourseCompleted): ?>
            <a href="/public/student/certificate.php" target="_blank" class="btn btn-sm btn-outline-primary" style="font-size: 12px; font-weight: 500; border-radius: 6px; padding: 4px 10px;">
                <i class="bi bi-award me-1"></i> Certificate
            </a>
        <?php endif; ?>
    </div>

    <div class="info-row">
        <span class="info-label">Course Name</span>
        <span class="info-value fw-bold text-dark">બેઝિક ટુ એડવાન્સ સિલાઈ &amp; ફેશન ડિઝાઇનિંગ</span>
    </div>
    <div class="info-row">
        <span class="info-label">Assigned Batch</span>
        <span class="info-value"><strong><?= htmlspecialchars($s['joined_batch_name'] ?: ($s['preferred_batch_name'] ?: 'ગમે તે બેચ (Any)')) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Batch Timing</span>
        <span class="info-value">
            <?php if (!empty($s['start_time']) && !empty($s['end_time'])): ?>
                <?= date('h:i A', strtotime($s['start_time'])) ?> થી <?= date('h:i A', strtotime($s['end_time'])) ?>
            <?php else: ?>
                નિયમિત બેચ સમય
            <?php endif; ?>
        </span>
    </div>
    <div class="info-row">
        <span class="info-label">Joining Date</span>
        <span class="info-value"><?= !empty($s['entry_date']) ? date('d-M-Y', strtotime($s['entry_date'])) : 'તારીખ નક્કી નથી' ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Course Duration</span>
        <span class="info-value">45 Days (Standard)</span>
    </div>

    <!-- Extended Days Row -->
    <div class="info-row">
        <span class="info-label">Extended Days</span>
        <span class="info-value">
            <?php if ($totalExtensionDays > 0): ?>
                <strong class="text-dark">+<?= $totalExtensionDays ?> Days Extension</strong>
            <?php else: ?>
                <span class="text-muted">None (0 Days)</span>
            <?php endif; ?>
        </span>
    </div>

    <?php if ($totalExtensionDays > 0 && !empty($s['base_expiry_date'])): ?>
    <div class="info-row">
        <span class="info-label">મૂળ પૂર્ણ તારીખ (Base End Date)</span>
        <span class="info-value text-muted"><?= date('d-M-Y', strtotime($s['base_expiry_date'])) ?></span>
    </div>
    <?php endif; ?>

    <div class="info-row">
        <span class="info-label">છેલ્લો દિવસ (Last Class Day)</span>
        <span class="info-value fw-bold text-success fs-6"><?= !empty($s['final_expiry_date']) ? date('d-M-Y', strtotime($s['final_expiry_date'])) : 'N/A' ?></span>
    </div>
    <div class="text-end text-muted small pe-1 pb-1" style="font-size: 0.72rem; margin-top: -6px;">
        (આ તમારી તાલીમનો છેલ્લો દિવસ રહેશે &bull; Last day to attend class)
    </div>

    <?php if ($isPaused): ?>
    <div class="info-row">
        <span class="info-label">જમા (Banked) દિવસો</span>
        <span class="info-value">
            <strong class="text-warning fs-6" style="color:#b45309 !important;"><?= (int)($s['paused_remaining_days'] ?? 0) ?> દિવસો જમા છે</strong>
        </span>
    </div>
    <div class="info-row">
        <span class="info-label">મોકૂફ થયા તારીખ</span>
        <span class="info-value text-muted"><?= !empty($s['paused_at']) ? date('d-M-Y', strtotime($s['paused_at'])) : 'N/A' ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">પાછા આવવાની સંભવિત તારીખ</span>
        <span class="info-value fw-bold text-primary"><?= !empty($s['resume_ready_date']) ? date('d-M-Y', strtotime($s['resume_ready_date'])) . ' પછી' : 'હજુ નક્કી નથી' ?></span>
    </div>
    <?php endif; ?>

    <div class="info-row">
        <span class="info-label">Course Status</span>
        <span class="info-value">
            <?php if ($isCourseCompleted): ?>
                <strong class="text-success">Course Completed</strong>
            <?php elseif ($isPaused): ?>
                <strong class="text-warning" style="color:#b45309 !important;">On Hold (Paused)</strong>
            <?php elseif ($totalExtensionDays > 0): ?>
                <strong class="text-warning">Active (Extended)</strong>
            <?php elseif ($isJoined): ?>
                <strong class="text-primary">Active (In Progress)</strong>
            <?php else: ?>
                <strong class="text-muted">વેઇટિંગ કતારમાં</strong>
            <?php endif; ?>
        </span>
    </div>
</div>

<!-- 3. Fee & Payment Summary Card -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-wallet2 me-2 text-success"></i> Fee &amp; Payment Summary</span>
        <a href="/public/student/fees.php" class="btn btn-sm" style="font-size: 12px; font-weight: 600; border-radius: 20px; padding: 4px 12px; color: #1e3a8a; border: 1px solid #bfdbfe; background: #eff6ff;">
            <i class="bi bi-receipt me-1"></i> View Receipts &rarr;
        </a>
    </div>

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

    <?php if ($totalExtensionDays > 0): ?>
    <div class="info-row">
        <span class="info-label">Extension Details</span>
        <span class="info-value text-dark small">
            <?php if ($extPendingAmt > 0): ?>
                <span class="badge" style="background:#fffbeb; color:#b45309; border:1px solid #fcd34d; font-weight:700;"><i class="bi bi-clock-history me-1"></i> ₹<?= number_format($extPendingAmt, 0) ?> બાકી (<?= $extPendingDays ?> દિવસ)</span>
                <?php if ($extPaidAmt > 0): ?>
                    <span class="text-muted ms-1">• ₹<?= number_format($extPaidAmt, 0) ?> જમા (<?= $extPaidDays ?> દિવસ)</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-success fw-bold"><i class="bi bi-check-circle-fill me-1"></i> <?= $totalExtensionDays ?> દિવસ માટે સંપૂર્ણ ફી જમા</span>
            <?php endif; ?>
        </span>
    </div>
    <?php endif; ?>

    <div class="info-row">
        <span class="info-label">Payment Status</span>
        <span class="info-value">
            <?php if ($totalDueOverall <= 0): ?>
                <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i> Full Paid (સંપૂર્ણ ફી જમા)</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark" style="font-weight:700;"><i class="bi bi-exclamation-triangle-fill me-1"></i> બાકી રકમ: ₹<?= number_format($totalDueOverall, 2) ?></span>
            <?php endif; ?>
        </span>
    </div>
</div>

<!-- 4. Personal Information Card -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-person-lines-fill me-2 text-primary"></i> અંગત વિગતો</span>
    </div>

    <div class="info-row">
        <span class="info-label"><i class="bi bi-telephone me-1 text-muted"></i> સ્ટુડન્ટ મોબાઈલ</span>
        <span class="info-value font-monospace"><strong><?= htmlspecialchars($s['student_mobile']) ?></strong></span>
    </div>
    <?php if ($s['husband_father_mobile']): ?>
    <div class="info-row">
        <span class="info-label"><i class="bi bi-person-heart me-1 text-muted"></i> પતિ / પિતાનો મોબાઈલ</span>
        <span class="info-value font-monospace"><?= htmlspecialchars($s['husband_father_mobile']) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($s['home_mobile']) && $s['home_mobile'] !== $s['student_mobile']): ?>
    <div class="info-row">
        <span class="info-label"><i class="bi bi-telephone-plus me-1 text-muted"></i> ઘરનો ફોન નંબર</span>
        <span class="info-value font-monospace"><?= htmlspecialchars($s['home_mobile']) ?></span>
    </div>
    <?php endif; ?>
    <div class="info-row">
        <span class="info-label"><i class="bi bi-geo-alt me-1 text-muted"></i> સરનામું</span>
        <span class="info-value"><?= htmlspecialchars($s['address']) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label"><i class="bi bi-book me-1 text-muted"></i> અભ્યાસ (Education)</span>
        <span class="info-value"><?= htmlspecialchars($s['education'] ?: 'ઉલ્લેખ નથી') ?></span>
    </div>
    <div class="info-row">
        <span class="info-label"><i class="bi bi-scissors me-1 text-muted"></i> સીવણનો અગાઉનો અનુભવ</span>
        <span class="info-value"><?= $s['knows_sewing'] ? 'હા (' . htmlspecialchars($s['previous_sewing_work'] ?: 'અનુભવ છે') . ')' : 'ના (નવા શીખનાર)' ?></span>
    </div>
    <div class="info-row">
        <span class="info-label"><i class="bi bi-info-circle me-1 text-muted"></i> ક્લાસ વિશે ક્યાંથી જાણ્યું</span>
        <span class="info-value"><?= htmlspecialchars($s['hear_about_us'] ?: 'સીધા સંપર્ક દ્વારા') ?></span>
    </div>
</div>

<!-- 5. Change Password Card -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-shield-lock-fill me-2 text-danger"></i> પાસવર્ડ બદલો</span>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success py-2 small rounded-3 mb-3"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2 small rounded-3 mb-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="change_password" value="1">
        
        <div class="form-group mb-2">
            <label class="form-label small">હાલનો / જૂનો પાસવર્ડ</label>
            <input type="password" name="old_password" class="form-control-app" required placeholder="હાલનો પાસવર્ડ દાખલ કરો">
        </div>

        <div class="form-group mb-2">
            <label class="form-label small">નવો પાસવર્ડ</label>
            <input type="password" name="new_password" class="form-control-app" required placeholder="નવો પાસવર્ડ દાખલ કરો">
        </div>

        <div class="form-group mb-3">
            <label class="form-label small">નવો પાસવર્ડ ફરીથી દાખલ કરો</label>
            <input type="password" name="confirm_password" class="form-control-app" required placeholder="ફરીથી નવો પાસવર્ડ દાખલ કરો">
        </div>

        <button type="submit" class="btn-app btn-outline-app">
            <i class="bi bi-check-circle me-1"></i> પાસવર્ડ સેવ કરો
        </button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>