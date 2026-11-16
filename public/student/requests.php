<?php
$pageTitle = 'રિક્વેસ્ટ અને અરજી - Devangi Sewing Classes';
$activePage = 'requests';
require_once __DIR__ . '/includes/header.php';
require_student_login();

reload_student_session();
$s = current_student();
$studentId = (int)$s['id'];

$preType = $_GET['type'] ?? 'leave';
$preCourseName = $_GET['course_name'] ?? '';

$msg = null;
$error = null;

// Submit new leave / extension / re-course request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $type = $_POST['leave_type'] ?? 'leave';
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $endDate = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (empty($startDate) || empty($reason)) {
        $error = 'મહેરબાની કરીને તારીખ અને રિક્વેસ્ટની વિગત / કારણ દાખલ કરો.';
    } else {
        $startDt = new DateTime($startDate);
        $endDt = !empty($endDate) ? new DateTime($endDate) : clone $startDt;
        
        $totalDays = max(1, $startDt->diff($endDt)->days + 1);
        if ($type === 're_course') {
            $totalDays = 45; // standard course duration
        }

        $stmt = db()->prepare(
            "INSERT INTO student_leave_requests (student_id, leave_type, start_date, end_date, total_days, reason, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([
            $studentId,
            $type,
            $startDate,
            !empty($endDate) ? $endDate : $startDate,
            $totalDays,
            $reason
        ]);

        $msg = 'તમારી રિક્વેસ્ટ સફળતાપૂર્વક સબમિટ થઈ ગઈ છે! સંસ્થા દ્વારા તપાસીને જાણ કરવામાં આવશે.';
    }
}

// Fetch all requests submitted by this student
$stmtReqs = db()->prepare(
    "SELECT * FROM student_leave_requests 
     WHERE student_id = ? 
     ORDER BY created_at DESC"
);
$stmtReqs->execute([$studentId]);
$requests = $stmtReqs->fetchAll();
?>

<!-- Request Form Card -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-send-plus-fill me-2 text-primary"></i> Submit New Request</span>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success py-2 small rounded-3 mb-3"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2 small rounded-3 mb-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="submit_request" value="1">

        <div class="form-group mb-3">
            <label class="form-label small fw-bold">Request Type</label>
            <select name="leave_type" id="reqTypeSelect" class="form-control-app" onchange="onRequestTypeChange(this.value)">
                <option value="leave" <?= ($preType === 'leave') ? 'selected' : '' ?>>Leave Application (રજા)</option>
                <option value="pause" <?= ($preType === 'pause') ? 'selected' : '' ?>>Pause / Hold Course (મોકૂફ)</option>
                <option value="extension" <?= ($preType === 'extension') ? 'selected' : '' ?>>Course Extension (દિવસો વધારવા)</option>
                <option value="re_course" <?= ($preType === 're_course') ? 'selected' : '' ?>>Re-Course / Advanced Training (રી-કોર્સ)</option>
            </select>
        </div>

        <div class="row g-2 mb-2" id="dateRangeRow">
            <div class="col-6">
                <div class="form-group">
                    <label class="form-label small" id="startDateLbl">Start Date</label>
                    <input type="date" name="start_date" class="form-control-app" required value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="col-6" id="endDateCol">
                <div class="form-group">
                    <label class="form-label small">End Date</label>
                    <input type="date" name="end_date" class="form-control-app" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
        </div>

        <div class="form-group mb-3">
            <label class="form-label small fw-bold" id="reasonLbl">Reason / Details</label>
            <textarea name="reason" id="reasonInput" class="form-control-app" rows="3" placeholder="Explain reason for leave, pause, or request details..." required><?php
                if (!empty($preCourseName)) {
                    echo "હું " . htmlspecialchars($preCourseName) . " માં એડમિશન / રી-કોર્સ કરવા ઈચ્છું છું.";
                }
            ?></textarea>
        </div>

        <button type="submit" class="btn-app btn-primary">
            <i class="bi bi-send-fill me-1"></i> Submit Request
        </button>
    </form>
</div>

<!-- Request History Card -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-clock-history me-2 text-muted"></i> Request History</span>
    </div>

    <?php if (empty($requests)): ?>
        <div class="text-center text-muted py-4 small">
            No requests submitted yet.
        </div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($requests as $r): ?>
                <?php
                $typeBadge = [
                    'leave' => ['lbl' => 'Leave', 'cls' => 'bg-info text-dark'],
                    'pause' => ['lbl' => 'Pause / Hold', 'cls' => 'bg-warning text-dark'],
                    'extension' => ['lbl' => 'Extension', 'cls' => 'bg-warning text-dark'],
                    're_course' => ['lbl' => 'Re-Course', 'cls' => 'bg-primary text-white']
                ][$r['leave_type']] ?? ['lbl' => $r['leave_type'], 'cls' => 'bg-secondary text-white'];
                ?>
                <div class="list-group-item px-0 py-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <span class="badge <?= $typeBadge['cls'] ?> me-1"><?= $typeBadge['lbl'] ?></span>
                            <span class="fw-bold text-dark small">
                                <?= date('d-M-Y', strtotime($r['start_date'])) ?>
                                <?php if ($r['leave_type'] !== 're_course' && !empty($r['end_date'])): ?>
                                    થી <?= date('d-M-Y', strtotime($r['end_date'])) ?> (<?= $r['total_days'] ?> દિવસ)
                                <?php endif; ?>
                            </span>
                        </div>
                        <div>
                            <?php if ($r['status'] === 'pending'): ?>
                                <span class="badge bg-warning text-dark">Pending</span>
                            <?php elseif ($r['status'] === 'approved'): ?>
                                <span class="badge bg-success">Approved</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Rejected</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="small text-muted mb-1"><strong>Details:</strong> <?= htmlspecialchars($r['reason']) ?></div>
                    <?php if ($r['admin_note']): ?>
                        <div class="small text-primary bg-light p-2 rounded mt-1 border">
                            <strong>Admin Response:</strong> <?= htmlspecialchars($r['admin_note']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function onRequestTypeChange(val) {
    var endCol = document.getElementById('endDateCol');
    var startLbl = document.getElementById('startDateLbl');
    var reasonLbl = document.getElementById('reasonLbl');
    var reasonInput = document.getElementById('reasonInput');

    if (val === 're_course') {
        endCol.style.display = 'none';
        startLbl.innerText = 'અંદાજિત કઈ તારીખથી શરૂ કરવું છે?';
        reasonLbl.innerText = 'કયો કોર્સ કરવો છે? (વિગત)';
        if (!reasonInput.value) {
            reasonInput.placeholder = 'દા.ત. એડવાન્સ ડિઝાઇનર બ્લાઉઝ કોર્સ અથવા રિવિઝન પ્રેક્ટિસ...';
        }
    } else if (val === 'pause') {
        endCol.style.display = 'block';
        startLbl.innerText = 'કઈ તારીખથી સ્ટોપ કરવું છે?';
        var endLbl = endCol.querySelector('label');
        if (endLbl) endLbl.innerText = 'કઈ તારીખ પછી પાછા આવશો? (અંદાજિત)';
        reasonLbl.innerText = 'સ્ટોપ કરવાનું કારણ';
        reasonInput.placeholder = 'લાંબા સમય સુધી ન આવી શકવાનું કારણ લખો...';
    } else {
        endCol.style.display = 'block';
        startLbl.innerText = 'ક્યારથી (Start Date)';
        reasonLbl.innerText = 'કારણ (Reason)';
        reasonInput.placeholder = 'રજા પાડવાનું અથવા કોર્સ લંબાવવાનું કારણ જણાવો...';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    onRequestTypeChange(document.getElementById('reqTypeSelect').value);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>