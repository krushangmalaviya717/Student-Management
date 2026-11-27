<?php
$pageTitle = 'ડેશબોર્ડ - Devangi Classes';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/header.php';
require_student_login();

reload_student_session();
$s = current_student();
$studentId = (int)$s['id'];

// Handle ready date submission from student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_my_ready_date'])) {
    $readyDate = trim($_POST['ready_date'] ?? '');
    if (!empty($readyDate)) {
        require_once __DIR__ . '/../../includes/services/StudentService.php';
        StudentService::updateResumeReadyDate($studentId, $readyDate, 'વિદ્યાર્થી દ્વારા પોર્ટલ પરથી નોંધાવેલ');
        flash('success', 'તમારી ફરી ક્લાસ આવવા માટેની તારીખ (' . date('d M, Y', strtotime($readyDate)) . ') સફળતાપૂર્વક નોંધાઈ ગઈ છે.');
        redirect('public/student/dashboard.php');
    }
}

// Check joined, paused & completion status accurately
$isPaused = (!empty($s['is_paused']) || $s['status'] === 'paused');
$isJoined = !empty($s['entry_date']) || !empty($s['joined_batch_id']) || in_array($s['status'], ['active', 'extended', 'course_completed', 'joining_confirmed', 'paused']);
$isCourseCompleted = ($s['status'] === 'course_completed' || ($s['course_status'] ?? '') === 'completed');

// Fetch total extension days
$stmtExt = db()->prepare("SELECT COALESCE(SUM(extension_days), 0) FROM extensions WHERE student_id = ?");
$stmtExt->execute([$studentId]);
$totalExtensionDays = (int)$stmtExt->fetchColumn();

// Fallback check from base and final expiry dates
if ($totalExtensionDays === 0 && !empty($s['base_expiry_date']) && !empty($s['final_expiry_date'])) {
    $bDate = new DateTime($s['base_expiry_date']);
    $fDate = new DateTime($s['final_expiry_date']);
    if ($fDate > $bDate) {
        $diff = $bDate->diff($fDate)->days;
        if ($diff > 0) $totalExtensionDays = $diff;
    }
}

// Fee calculation
$stmtFees = db()->prepare("SELECT SUM(amount) FROM payments WHERE student_id = ? AND status = 'success'");
$stmtFees->execute([$studentId]);
$totalPaid = (float)($stmtFees->fetchColumn() ?: 0);

// Balance due check
$invStmt = db()->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE student_id = ? AND (payment_status != 'paid' OR payment_status IS NULL)");
$invStmt->execute([$studentId]);
$balanceDue = (float)$invStmt->fetchColumn();

$stmtNotice = db()->prepare("SELECT * FROM student_notices WHERE batch_id IS NULL OR batch_id = ? ORDER BY created_at DESC LIMIT 1");
$stmtNotice->execute([$s['joined_batch_id']]);
$latestNotice = $stmtNotice->fetch();

$statusLabels = [
    'draft' => ['લુક' => 'badge-draft', 'ટેક્સ્ટ' => 'Draft'],
    'confirmed' => ['લુક' => 'badge-draft', 'ટેક્સ્ટ' => 'Confirmed'],
    'waiting_ready' => ['લુક' => 'badge-waiting', 'ટેક્સ્ટ' => 'Waiting (Ready)'],
    'waiting_future_date' => ['લુક' => 'badge-waiting', 'ટેક્સ્ટ' => 'Waiting (Future Date)'],
    'waiting_date_not_confirmed' => ['લુક' => 'badge-waiting', 'ટેક્સ્ટ' => 'Waiting (Pending Date)'],
    'joining_confirmed' => ['લુક' => 'badge-active', 'ટેક્સ્ટ' => 'Joining Confirmed'],
    'active' => ['લુક' => 'badge-active', 'ટેક્સ્ટ' => 'Active'],
    'extended' => ['લુક' => 'badge-active', 'ટેક્સ્ટ' => 'Active (Extended)'],
    'course_completed' => ['લુક' => 'badge-completed', 'ટેક્સ્ટ' => 'Completed'],
    'cancelled' => ['લુક' => 'badge-draft', 'ટેક્સ્ટ' => 'Cancelled'],
];

if ($isCourseCompleted) {
    $currStatus = ['લુક' => 'badge-completed', 'ટેક્સ્ટ' => 'Course Completed'];
} elseif ($isPaused) {
    $currStatus = ['લુક' => 'badge-waiting', 'ટેક્સ્ટ' => 'On Hold (Paused)'];
} elseif ($isJoined) {
    if ($totalExtensionDays > 0) {
        $currStatus = ['લુક' => 'badge-active', 'ટેક્સ્ટ' => 'Active (+' . $totalExtensionDays . ' Days Ext.)'];
    } else {
        $currStatus = ['લુક' => 'badge-active', 'ટેક્સ્ટ' => 'Active'];
    }
} else {
    $currStatus = $statusLabels[$s['status']] ?? ['લુક' => 'badge-draft', 'ટેક્સ્ટ' => $s['status']];
}

$percentComplete = 0;
$daysRemaining = 0;
if ($s['entry_date'] && $s['final_expiry_date']) {
    $start = new DateTime($s['entry_date']);
    $end = new DateTime($s['final_expiry_date']);
    $today = new DateTime();
    $totalDuration = max(1, $start->diff($end)->days);
    
    if ($today >= $end || $isCourseCompleted) {
        $percentComplete = 100;
        $daysRemaining = 0;
    } else {
        $daysCompleted = $today < $start ? 0 : min($totalDuration, $start->diff($today)->days);
        $percentComplete = min(100, round(($daysCompleted / $totalDuration) * 100));
        $daysRemaining = $today > $end ? 0 : $today->diff($end)->days;
    }
}
?>

<!-- Notice Banner -->
<?php if ($latestNotice): ?>
<div style="background: #FFFBEB; border-left: 4px solid #F59E0B; padding: 12px 20px; margin-bottom: 20px; border-radius: 8px;">
    <div class="fw-bold text-dark" style="font-size: 0.9rem;"><i class="bi bi-bell-fill text-warning me-2"></i><?= htmlspecialchars($latestNotice['title']) ?></div>
    <div class="text-muted mt-1" style="font-size: 0.8rem;"><?= nl2br(htmlspecialchars($latestNotice['content'])) ?></div>
</div>
<?php endif; ?>

<!-- PWA Install Banner on Dashboard -->
<div id="pwaInstallCard" class="pwa-install-card mb-3">
    <div class="d-flex align-items-center gap-2">
        <div style="background: rgba(255,255,255,0.18); width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;">
            <i class="bi bi-phone-fill"></i>
        </div>
        <div>
            <div class="fw-bold" style="font-size: 0.95rem;">મોબાઈલ એપ ઇન્સ્ટોલ કરો</div>
            <div class="opacity-75" style="font-size: 0.78rem;">ઝડપી એક્સેસ માટે હોમ સ્ક્રીન પર રાખો</div>
        </div>
    </div>
    <button type="button" class="pwa-install-big-btn pwa-install-trigger" style="padding: 11px 14px; font-size: 0.95rem;" onclick="triggerPwaInstall();">
        <i class="bi bi-download"></i>
        <span>એપ ઇન્સ્ટોલ કરો (Install App)</span>
    </button>
</div>

<!-- Hero Card -->
<div class="hero-card">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
            <div class="hero-subtitle">Welcome,</div>
            <div class="hero-title"><?= htmlspecialchars($s['name']) ?></div>
            <div class="mt-1" style="font-size: 0.8rem; font-family: monospace; color: #cbd5e1;">
                ID: <?= htmlspecialchars($s['registration_no'] ?: $s['form_no']) ?>
            </div>
        </div>
        <span class="badge-app <?= $currStatus['લુક'] ?>">
            <?= $currStatus['ટેક્સ્ટ'] ?>
        </span>
    </div>
</div>

<!-- ================= COURSE PAUSED / ON-HOLD SPECIAL SECTION ================= -->
<?php if ($isPaused): ?>
<div class="card-app" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 1.5px solid #fde68a; position: relative; overflow: hidden; margin-bottom: 20px;">
    <div style="position: absolute; right: -10px; top: -10px; opacity: 0.1; font-size: 7rem; color: #b45309;">
        <i class="bi bi-pause-circle-fill"></i>
    </div>
    <div class="d-flex align-items-center gap-3 mb-3">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: #fff; color: #b45309; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; border: 1px solid #fcd34d;">
            <i class="bi bi-pause-fill"></i>
        </div>
        <div>
            <h5 class="fw-bold mb-1" style="color: #92400e;">Course Currently On Hold</h5>
            <div class="text-muted small">તમારી વિનંતી મુજબ કોર્સ રોકેલ છે. બાકી દિવસો સુરક્ષિત છે.</div>
        </div>
    </div>
    
    <div class="row g-2 mb-3">
        <div class="col-4">
            <div class="p-2 bg-white rounded border text-center">
                <div class="text-muted" style="font-size: 0.72rem;">Paused On</div>
                <div class="fw-bold text-dark small"><?= !empty($s['paused_at']) ? date('d M, Y', strtotime($s['paused_at'])) : 'N/A' ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="p-2 bg-white rounded border text-center">
                <div class="text-muted" style="font-size: 0.72rem;">Banked Days</div>
                <div class="fw-bold text-warning small" style="font-size:0.95rem; color:#b45309 !important;"><?= (int)($s['paused_remaining_days'] ?? 0) ?> Days Safe</div>
            </div>
        </div>
        <div class="col-4">
            <div class="p-2 bg-white rounded border text-center">
                <div class="text-muted" style="font-size: 0.72rem;">Expected Resume</div>
                <div class="fw-bold text-success small"><?= !empty($s['resume_ready_date']) ? date('d M, Y', strtotime($s['resume_ready_date'])) : 'Not Set' ?></div>
            </div>
        </div>
    </div>

    <div class="p-3 bg-white rounded border">
        <form method="post" class="d-flex flex-column gap-2">
            <label class="fw-bold small text-dark"><i class="bi bi-calendar-check text-primary me-1"></i> તમે કઈ તારીખ પછી ફરી શરૂ કરવા તૈયાર છો? (Resume Date)</label>
            <div class="d-flex gap-2">
                <input type="date" name="ready_date" class="form-control form-control-sm" value="<?= htmlspecialchars($s['resume_ready_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>" required>
                <button type="submit" name="update_my_ready_date" class="btn btn-sm btn-primary text-nowrap px-3">Update Date</button>
            </div>
            <div class="text-muted" style="font-size: 0.75rem;">
                <i class="bi bi-info-circle me-1"></i> તમે જણાવેલ તારીખ પછી બેચમાં જગ્યા ખાલી થતાં તમને જોઈન કરવામાં આવશે.
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ================= COURSE COMPLETED SPECIAL SECTION ================= -->
<?php if ($isCourseCompleted): ?>
<div class="card-app" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1.5px solid #cbd5e1; position: relative; overflow: hidden; margin-bottom: 20px;">
    <div style="position: absolute; right: -10px; top: -10px; opacity: 0.07; font-size: 7rem; color: #4338ca;">
        <i class="bi bi-mortarboard-fill"></i>
    </div>
    <div class="d-flex align-items-center gap-3 mb-3">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: #eef2ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0;">
            <i class="bi bi-mortarboard"></i>
        </div>
        <div>
            <h5 class="fw-bold mb-1" style="color: #1e1b4b;">Congratulations! Course Completed</h5>
            <div class="text-muted small">તમે સફળતાપૂર્વક સિલાઈ અને ફેશન ડિઝાઈનિંગ કોર્સ પૂર્ણ કર્યો છે.</div>
        </div>
    </div>
    
    <div class="row g-2 mb-3">
        <div class="col-4">
            <div class="p-2 bg-white rounded border text-center">
                <div class="text-muted" style="font-size: 0.72rem;">Start Date</div>
                <div class="fw-bold text-dark small"><?= !empty($s['entry_date']) ? date('d M, Y', strtotime($s['entry_date'])) : 'N/A' ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="p-2 bg-white rounded border text-center">
                <div class="text-muted" style="font-size: 0.72rem;">Completion Date</div>
                <div class="fw-bold text-success small"><?= !empty($s['final_expiry_date']) ? date('d M, Y', strtotime($s['final_expiry_date'])) : date('d M, Y') ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="p-2 bg-white rounded border text-center">
                <div class="text-muted" style="font-size: 0.72rem;">Duration</div>
                <div class="fw-bold text-primary small">45 <?= $totalExtensionDays > 0 ? '(+' . $totalExtensionDays . ' Ext)' : '' ?> Days</div>
            </div>
        </div>
    </div>

    <!-- Certificate Button -->
    <a href="/public/student/certificate.php" target="_blank" class="btn btn-primary w-100 fw-bold py-2 shadow-sm" style="background: #4338ca; border-color: #4338ca;">
        <i class="bi bi-award-fill me-2"></i> View Certificate
    </a>
</div>

<!-- Re-Course & Advance Learning Options -->
<div class="card-app" id="reCourseSection">
    <div class="card-title">
        <span><i class="bi bi-arrow-repeat text-primary me-2"></i> ફરીથી કોર્સ / એડવાન્સ ટ્રેનિંગ (Re-Course)</span>
    </div>
    <p class="text-muted small mb-3">
        જો તમે કોર્સ પૂર્ણ કર્યા બાદ વધુ પ્રેક્ટિસ માટે <strong>રિવિઝન કોર્સ</strong> અથવા <strong>એડવાન્સ ડિઝાઇનિંગ</strong> કરવા માંગો છો, તો નીચેથી પસંદ કરીને અરજી કરી શકો છો:
    </p>
    
    <div class="list-group mb-2">
        <div class="list-group-item p-3 border rounded-3 mb-2 bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong class="d-block text-dark small">૧. રિવિઝન & સ્પેશિયલ પ્રેક્ટિસ કોર્સ</strong>
                    <small class="text-muted" style="font-size: 0.75rem;">ફરીથી હેન્ડ્સ-ઓન પ્રેક્ટિસ અને ફિનિશિંગ માટે</small>
                </div>
                <a href="/public/student/requests.php?type=re_course&course_name=Revision+Practice+Course" class="btn btn-sm btn-outline-primary rounded-pill px-3">અરજી કરો</a>
            </div>
        </div>
        <div class="list-group-item p-3 border rounded-3 mb-2 bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong class="d-block text-dark small">૨. એડવાન્સ ડિઝાઇનર બ્લાઉઝ માસ્ટરી</strong>
                    <small class="text-muted" style="font-size: 0.75rem;">પ્રિન્સેસ કટ, પેડેડ, સબ્યાસાચી નેક ડિઝાઇન</small>
                </div>
                <a href="/public/student/requests.php?type=re_course&course_name=Advanced+Designer+Blouse" class="btn btn-sm btn-outline-primary rounded-pill px-3">અરજી કરો</a>
            </div>
        </div>
        <div class="list-group-item p-3 border rounded-3 bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong class="d-block text-dark small">૩. એડવાન્સ કુર્તી & ડ્રેસ ડિઝાઇનિંગ</strong>
                    <small class="text-muted" style="font-size: 0.75rem;">અનારકલી, નાયરા કટ, પાર્ટી વેર & ગાઉન</small>
                </div>
                <a href="/public/student/requests.php?type=re_course&course_name=Advanced+Kurti+And+Dress" class="btn btn-sm btn-outline-primary rounded-pill px-3">અરજી કરો</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Course Progress Card (If active / ongoing) -->
<?php if ($isJoined && !$isCourseCompleted): ?>
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-clock-history text-primary me-2"></i> Course Progress</span>
        <span class="text-primary fw-bold" style="font-size: 0.85rem; background: var(--primary-light); padding: 4px 10px; border-radius: 20px;">
            <?= $daysRemaining ?> Days Left
        </span>
    </div>

    <div class="progress-wrapper">
        <div class="progress-bg">
            <div class="progress-fill" style="width: <?= $percentComplete ?>%;"></div>
        </div>
        <div class="progress-stats">
            <span>Started: <?= date('d M, Y', strtotime($s['entry_date'])) ?></span>
            <span>Last Class: <strong><?= date('d M, Y', strtotime($s['final_expiry_date'])) ?></strong></span>
        </div>
    </div>
    
    <!-- 3-Column Detailed Day Breakdown -->
    <div class="row text-center mt-3 pt-3 g-2" style="border-top: 1px solid #F1F5F9;">
        <div class="col-4" style="border-right: 1px solid #F1F5F9;">
            <div class="text-muted mb-1" style="font-size: 0.72rem;">Base Duration</div>
            <div class="fw-bold text-dark small">45 Days</div>
        </div>
        <div class="col-4" style="border-right: 1px solid #F1F5F9;">
            <div class="text-muted mb-1" style="font-size: 0.72rem;">Extension</div>
            <div class="fw-bold <?= $totalExtensionDays > 0 ? 'text-warning' : 'text-muted' ?> small">
                <?= $totalExtensionDays > 0 ? '+' . $totalExtensionDays . ' Days Ext.' : 'None' ?>
            </div>
        </div>
        <div class="col-4">
            <div class="text-muted mb-1" style="font-size: 0.72rem;">Total Validity</div>
            <div class="fw-bold text-success small"><?= 45 + $totalExtensionDays ?> Days</div>
        </div>
    </div>

    <?php if ($totalExtensionDays > 0): ?>
    <div class="p-2 mt-2 rounded bg-warning-subtle text-dark small text-center border border-warning-subtle">
        <i class="bi bi-info-circle-fill text-warning me-1"></i>
        તમારા કોર્સમાં <strong>+<?= $totalExtensionDays ?> દિવસનું એક્સટેન્શન</strong> માન્ય રાખેલ છે. તાલીમનો છેલ્લો દિવસ: <strong><?= date('d-M-Y', strtotime($s['final_expiry_date'])) ?></strong> (Last Class Day) રહેશે.
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Quick Actions Grid -->
<div class="action-grid">
    <?php if ($isCourseCompleted): ?>
        <a href="/public/student/certificate.php" target="_blank" class="action-card cert">
            <div class="action-icon-wrapper"><i class="bi bi-award"></i></div>
            <div class="action-label">Certificate</div>
        </a>
    <?php else: ?>
        <a href="/public/student/profile.php" class="action-card profile">
            <div class="action-icon-wrapper"><i class="bi bi-person-badge"></i></div>
            <div class="action-label">My Profile</div>
        </a>
    <?php endif; ?>

    <a href="/public/student/fees.php" class="action-card fees">
        <div class="action-icon-wrapper"><i class="bi bi-wallet2"></i></div>
        <div class="action-label">Fees &amp; Receipts</div>
    </a>

    <?php if ($isCourseCompleted): ?>
        <a href="#reCourseSection" class="action-card req">
            <div class="action-icon-wrapper"><i class="bi bi-arrow-repeat"></i></div>
            <div class="action-label">Re-Course</div>
        </a>
    <?php else: ?>
        <a href="/public/student/requests.php" class="action-card req">
            <div class="action-icon-wrapper"><i class="bi bi-calendar2-range"></i></div>
            <div class="action-label">Leave &amp; Requests</div>
        </a>
    <?php endif; ?>

    <a href="/public/student/profile.php#batchDetails" class="action-card batch">
        <div class="action-icon-wrapper"><i class="bi bi-clock-history"></i></div>
        <div class="action-label">Batch &amp; Schedule</div>
    </a>
</div>

<!-- Basic Detail Row -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-person-lines-fill"></i> Profile Summary</span>
        <a href="/public/student/profile.php" class="text-primary small text-decoration-none fw-bold">View Full &rarr;</a>
    </div>
    <div class="info-row">
        <span class="info-label">Mobile Number</span>
        <span class="info-value font-monospace"><?= htmlspecialchars($s['student_mobile']) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Assigned Batch</span>
        <span class="info-value"><strong><?= htmlspecialchars($s['joined_batch_name'] ?: ($s['preferred_batch_name'] ?: 'ગમે તે બેચ')) ?></strong></span>
    </div>
    <div class="info-row">
        <span class="info-label">Extension</span>
        <span class="info-value fw-bold text-dark"><?= $totalExtensionDays > 0 ? '+' . $totalExtensionDays . ' Days Ext.' : 'None' ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Total Fee Paid</span>
        <span class="info-value fw-bold text-success">₹<?= number_format($totalPaid) ?></span>
    </div>
    <?php if ($balanceDue > 0): ?>
    <div class="info-row">
        <span class="info-label">Balance Due</span>
        <span class="info-value fw-bold text-danger">₹<?= number_format($balanceDue) ?> (બાકી)</span>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>