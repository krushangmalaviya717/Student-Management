<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_login();

$pdo = db();

// ---- KPI cards ----
$totalActive = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status IN ('active','extended')")->fetchColumn();
$totalWaiting = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status IN ('waiting_ready','waiting_future_date','waiting_date_not_confirmed')")->fetchColumn();
$totalCapacity = (int)$pdo->query("SELECT COALESCE(SUM(capacity),0) FROM batches WHERE is_active = 1")->fetchColumn();
$availableSeats = max(0, $totalCapacity - $totalActive);
$joiningToday = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE entry_date = CURDATE()")->fetchColumn();
$endingToday = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE final_expiry_date = CURDATE() AND status IN ('active','extended')")->fetchColumn();
$endingSoon = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE final_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status IN ('active','extended')")->fetchColumn();
$followUpsToday = (int)$pdo->query("SELECT COUNT(*) FROM call_logs WHERE follow_up_date = CURDATE()")->fetchColumn();
$pendingInvoices = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status = 'pending'")->fetchColumn();

// ---- Batch snapshot ----
$batches = $pdo->query("SELECT * FROM batches WHERE is_active = 1 ORDER BY start_time")->fetchAll();
foreach ($batches as &$b) {
    $b['active_count'] = count_active_students_in_batch((int)$b['id']);
    $b['available'] = max(0, $b['capacity'] - $b['active_count']);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM waiting_queue WHERE batch_id = ? AND state NOT IN ('joined','removed')");
    $stmt->execute([$b['id']]);
    $b['waiting_count'] = (int)$stmt->fetchColumn();

    $next = get_next_eligible_student_dashboard($pdo, (int)$b['id']);
    $b['next_student'] = $next ? $next['name'] : '-';
}
unset($b);

function get_next_eligible_student_dashboard(PDO $pdo, int $batchId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT s.name FROM waiting_queue wq JOIN students s ON s.id = wq.student_id
         WHERE wq.batch_id = ? AND wq.state IN ('waiting_ready','call_pending','called_call_later')
         ORDER BY wq.priority_date ASC LIMIT 1"
    );
    $stmt->execute([$batchId]);
    return $stmt->fetch() ?: null;
}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="grid grid-4">
    <div class="kpi"><div class="num"><?= $totalActive ?></div><div class="label">Active Students</div></div>
    <div class="kpi"><div class="num"><?= $totalWaiting ?></div><div class="label">In Waiting Queues</div></div>
    <div class="kpi"><div class="num"><?= $availableSeats ?></div><div class="label">Available Seats Today</div></div>
    <div class="kpi"><div class="num"><?= $joiningToday ?></div><div class="label">Joining Today</div></div>
    <div class="kpi"><div class="num"><?= $endingToday ?></div><div class="label">Course Ends Today</div></div>
    <div class="kpi"><div class="num"><?= $endingSoon ?></div><div class="label">Ending in 7 Days</div></div>
    <div class="kpi"><div class="num"><?= $pendingInvoices ?></div><div class="label">Pending Invoices</div></div>
    <div class="kpi"><div class="num"><?= $followUpsToday ?></div><div class="label">Follow-ups Due Today</div></div>
</div>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h2 class="mt-0" style="margin-bottom:0;">Batch Occupancy Snapshot</h2>
        <span class="badge badge-purple">Max Capacity: 8 Students / Batch</span>
    </div>
    <table>
        <tr><th>Batch</th><th>Active Occupancy</th><th>Capacity</th><th>Available Seats</th><th>Waiting Queue</th><th>Next Eligible Student</th><th>Action</th></tr>
        <?php foreach ($batches as $b): ?>
        <tr>
            <td><strong><?= e($b['name']) ?></strong></td>
            <td><strong><?= $b['active_count'] ?></strong> / 8</td>
            <td><?= $b['capacity'] ?></td>
            <td><?= $b['available'] > 0 ? '<span class="badge badge-green">'.$b['available'].' Seats Open</span>' : '<span class="badge badge-red">Batch Full</span>' ?></td>
            <td><span class="badge badge-gray"><?= $b['waiting_count'] ?> waiting</span></td>
            <td><?= $b['next_student'] !== '-' ? '<strong>'.e($b['next_student']).'</strong>' : '<span class="muted">None</span>' ?></td>
            <td><a href="<?= url('public/waiting_list.php?batch=' . (int)$b['id']) ?>" class="btn btn-sm btn-secondary">Manage Queue &rarr;</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2 class="mt-0">Quick Operational Actions</h2>
    <div style="display:flex; gap:12px; flex-wrap:wrap;">
        <a href="<?= url('public/student_register.php') ?>" class="btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:6px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>New Student Form Registration
        </a>
        <a href="<?= url('public/waiting_list.php') ?>" class="btn btn-secondary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:6px;"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>Waiting Queue Engine
        </a>
        <a href="<?= url('public/holidays.php') ?>" class="btn btn-secondary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:6px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>Declare Institute Holiday
        </a>
        <a href="<?= url('public/reports.php?report=pending_payments') ?>" class="btn btn-secondary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:6px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>Track Pending Payments
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
