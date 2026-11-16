<?php
$pageTitle = 'વેઇટિંગ સ્ટેટસ - Devangi Sewing Classes';
$activePage = 'queue';
require_once __DIR__ . '/includes/header.php';
require_student_login();

reload_student_session();
$s = current_student();
$studentId = (int)$s['id'];

// Check if student has already joined or completed
$isJoined = !empty($s['entry_date']) || !empty($s['joined_batch_id']) || in_array($s['status'], ['active', 'extended', 'course_completed', 'joining_confirmed']);

$msg = null;
$error = null;

// Handle availability update request ONLY if student is still in waiting queue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_availability'])) {
    if ($isJoined) {
        $error = 'તમે ક્લાસમાં જોડાઈ ચૂક્યા છો, જેથી વેઇટિંગ સ્ટેટસ બદલી શકાશે નહીં.';
    } else {
        $state = $_POST['availability_state'] ?? 'ready';
        $futureDate = $_POST['future_date'] ?? null;

        if ($state === 'future_date' && empty($futureDate)) {
            $error = 'મહેરબાની કરીને આગામી જોઇનિંગ તારીખ પસંદ કરો.';
        } else {
            $pdo = db();
            if ($state !== 'future_date') {
                $futureDate = null;
            }

            // Map status
            $statusMap = [
                'ready' => 'waiting_ready',
                'future_date' => 'waiting_future_date',
                'date_not_confirmed' => 'waiting_date_not_confirmed'
            ];
            $newStatus = $statusMap[$state] ?? 'waiting_ready';

            // Update student record
            $stmt = $pdo->prepare("UPDATE students SET availability_state = ?, availability_ready_date = ?, status = ? WHERE id = ?");
            $stmt->execute([$state, $futureDate, $newStatus, $studentId]);

            // Update waiting queue records
            $stmtQ = $pdo->prepare("UPDATE waiting_queue SET state = ?, future_date = ? WHERE student_id = ? AND state LIKE 'waiting%'");
            $stmtQ->execute([$newStatus, $futureDate, $studentId]);

            $msg = 'તમારું સ્ટેટસ સફળતાપૂર્વક અપડેટ થયું છે!';
            reload_student_session();
            $s = current_student();
        }
    }
}

// Fetch waiting queue entries for this student
$stmtQList = db()->prepare(
    "SELECT w.*, b.name AS batch_name, b.start_time, b.end_time
     FROM waiting_queue w
     JOIN batches b ON w.batch_id = b.id
     WHERE w.student_id = ?
     ORDER BY w.id ASC"
);
$stmtQList->execute([$studentId]);
$queueEntries = $stmtQList->fetchAll();

// Fetch call logs for this student
$stmtCalls = db()->prepare(
    "SELECT c.*, b.name AS offered_batch_name
     FROM call_logs c
     LEFT JOIN batches b ON c.offered_batch_id = b.id
     WHERE c.student_id = ?
     ORDER BY c.call_datetime DESC"
);
$stmtCalls->execute([$studentId]);
$callLogs = $stmtCalls->fetchAll();
?>

<?php if ($msg): ?>
    <div class="alert alert-success py-2 small rounded-3 mb-3"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger py-2 small rounded-3 mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($isJoined): ?>
<!-- Already Enrolled / Active Student Notice -->
<div class="card-app text-center py-4">
    <div class="mb-3 text-success" style="font-size: 3rem;"><i class="bi bi-patch-check-fill"></i></div>
    <h5 class="fw-bold text-dark mb-1">Admission Confirmed & Enrolled!</h5>
    <p class="text-muted small mb-3">તમે સંસ્થામાં જોડાઈ ચૂક્યા છો, તેથી તમારે હવે વેઇટિંગ સ્ટેટસમાં કોઈ ફેરફાર કરવાની જરૂર નથી.</p>
    
    <div class="p-3 bg-light rounded-3 mb-3 text-start small border">
        <div class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-muted">ફાળવેલ બેચ:</span>
            <strong><?= htmlspecialchars($s['joined_batch_name'] ?: 'નિયમિત બેચ') ?></strong>
        </div>
        <div class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-muted">જોડાવાની તારીખ:</span>
            <strong><?= !empty($s['entry_date']) ? date('d-M-Y', strtotime($s['entry_date'])) : 'N/A' ?></strong>
        </div>
        <div class="d-flex justify-content-between py-1">
            <span class="text-muted">હાલનું સ્ટેટસ:</span>
            <span class="badge bg-success"><?= ($s['status'] === 'course_completed') ? 'કોર્સ પૂર્ણ (Completed)' : 'અભ્યાસ ચાલુ છે (Active)' ?></span>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-center">
        <a href="/public/student/dashboard.php" class="btn btn-primary rounded-pill px-4">Go to Dashboard</a>
        <a href="/public/student/profile.php" class="btn btn-outline-secondary rounded-pill px-3">My Profile</a>
    </div>
</div>

<?php else: ?>

<!-- Queue Status Overview Card for WAITING students -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-hourglass-split me-2"></i> Waiting Queue Status</span>
        <span class="badge-app badge-waiting">
            <?= htmlspecialchars($s['status']) ?>
        </span>
    </div>

    <div class="info-row">
        <span class="info-label">Preferred Batch</span>
        <span class="info-value"><?= htmlspecialchars($s['preferred_batch_name'] ?: ($s['any_batch'] ? 'Any Batch (ગમે તે બેચ)' : 'નક્કી નથી')) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Availability Status</span>
        <span class="info-value">
            <?php
            if ($s['availability_state'] === 'ready') echo 'Ready to Join';
            elseif ($s['availability_state'] === 'future_date') echo 'Future Date: ' . date('d-m-Y', strtotime($s['availability_ready_date']));
            else echo 'Pending Confirmation';
            ?>
        </span>
    </div>
</div>

<!-- Availability Update Form -->
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-pencil-square me-2"></i> તમારી પ્રાપ્યતા (Availability) અપડેટ કરો</span>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="update_availability" value="1">

        <div class="form-group">
            <label class="form-label">હાલમાં તમે ક્લાસમાં જોડાવા માટે તૈયાર છો?</label>
            <select name="availability_state" id="availState" class="form-control-app" onchange="toggleFutureDate(this.value)">
                <option value="ready" <?= $s['availability_state'] === 'ready' ? 'selected' : '' ?>>હા, હું તૈયાર છું (Ready to Join)</option>
                <option value="future_date" <?= $s['availability_state'] === 'future_date' ? 'selected' : '' ?>>ચોક્કસ આગામી તારીખથી જોડાઈશ (Future Date)</option>
                <option value="date_not_confirmed" <?= $s['availability_state'] === 'date_not_confirmed' ? 'selected' : '' ?>>હજુ Pending Confirmation (Date Not Confirmed)</option>
            </select>
        </div>

        <div class="form-group" id="futureDateGroup" style="display: <?= $s['availability_state'] === 'future_date' ? 'block' : 'none' ?>;">
            <label class="form-label">કઈ તારીખથી જોડાઈ શકશો?</label>
            <input type="date" name="future_date" class="form-control-app" value="<?= htmlspecialchars($s['availability_ready_date'] ?: '') ?>">
        </div>

        <button type="submit" class="btn-app">
            <i class="bi bi-save me-1"></i> સ્ટેટસ સેવ કરો
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Call History Log -->
<?php if ($callLogs): ?>
<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-telephone-inbound me-2"></i> સંસ્થા તરફથી આવેલ કોલ હિસ્ટ્રી</span>
    </div>
    <div class="list-group list-group-flush">
        <?php foreach ($callLogs as $c): ?>
            <div class="list-group-item px-0 py-2">
                <div class="d-flex justify-content-between">
                    <span class="fw-bold small"><?= date('d-m-Y h:i A', strtotime($c['call_datetime'])) ?></span>
                    <span class="badge bg-secondary"><?= htmlspecialchars($c['outcome']) ?></span>
                </div>
                <?php if ($c['offered_batch_name']): ?>
                    <div class="small text-muted">ઓફર કરેલ બેચ: <strong><?= htmlspecialchars($c['offered_batch_name']) ?></strong></div>
                <?php endif; ?>
                <?php if ($c['conversation_note']): ?>
                    <div class="small text-dark mt-1 bg-light p-2 rounded"><?= htmlspecialchars($c['conversation_note']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
function toggleFutureDate(val) {
    var grp = document.getElementById('futureDateGroup');
    if (grp) {
        grp.style.display = (val === 'future_date') ? 'block' : 'none';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>