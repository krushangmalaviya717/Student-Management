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
    $b['next_student_id'] = $next ? (int)$next['id'] : null;
}
unset($b);

function get_next_eligible_student_dashboard(PDO $pdo, int $batchId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name FROM waiting_queue wq JOIN students s ON s.id = wq.student_id
         WHERE wq.batch_id = ? AND wq.state IN ('waiting_ready','call_pending','called_call_later')
         ORDER BY wq.priority_date ASC LIMIT 1"
    );
    $stmt->execute([$batchId]);
    return $stmt->fetch() ?: null;
}

$batchesOverviewUrl = is_admin_or_above()
    ? url('public/batches.php')
    : url('public/reports.php?report=next_student_by_batch');

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.dash-bar-bg {
    background: var(--border);
    border-radius: 999px;
    height: 6px;
    overflow: hidden;
    margin-top: 5px;
    width: 110px;
}
.dash-bar-fill {
    height: 100%;
    border-radius: 999px;
}
.kpi-link {
    display: block;
    text-decoration: none;
    color: inherit;
    border-radius: var(--radius-lg);
}
.kpi-link .kpi-card {
    height: 100%;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.kpi-link:hover .kpi-card {
    border-color: var(--primary-border);
    box-shadow: var(--shadow-sm);
    transform: translateY(-1px);
}
.kpi-link:hover .kpi-label {
    color: var(--primary);
}
.dash-cell-link {
    color: var(--text);
    font-weight: 600;
    text-decoration: none;
    transition: color 0.15s ease;
}
.dash-cell-link:hover {
    color: var(--primary);
    text-decoration: underline;
}
a.badge {
    text-decoration: none;
    transition: opacity 0.15s ease;
}
a.badge:hover {
    opacity: 0.85;
}
</style>

<!-- Top Operational Action Header -->
<div class="page-header">
    <p class="page-header-subtitle">Live overview of batch capacity, waiting queues, upcoming expiries, and pending invoices.</p>
    <div class="action-group">
        <a href="<?= url('public/waiting_list.php') ?>" class="btn btn-sm btn-secondary">Waiting Queue</a>
        <?php if (is_admin_or_above()): ?>
        <a href="<?= url('public/holidays.php') ?>" class="btn btn-sm btn-secondary">Declare Holiday</a>
        <?php endif; ?>
        <a href="<?= url('public/reports.php?report=pending_payments') ?>" class="btn btn-sm btn-secondary">Pending Payments</a>
        <?php if (is_admin_or_above()): ?>
        <a href="<?= url('public/student_register.php') ?>" class="btn btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>New Registration</span>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Row 1: Capacity & Enrollment Summary -->
<div class="grid grid-4" style="margin-bottom: 16px;">
    <a href="<?= url('public/students_list.php?status=active_students') ?>" class="kpi-link" title="View active students">
        <?= render_kpi_card(
            'Active Students',
            $totalActive,
            'Currently enrolled in batches',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
            'primary'
        ) ?>
    </a>
    <a href="<?= e($batchesOverviewUrl) ?>" class="kpi-link" title="View batch capacities and open seats">
        <?= render_kpi_card(
            'Available Seats Today',
            $availableSeats,
            'Out of ' . $totalCapacity . ' active capacity',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            $availableSeats > 0 ? 'success' : 'default'
        ) ?>
    </a>
    <a href="<?= url('public/waiting_list.php') ?>" class="kpi-link" title="Open waiting queues">
        <?= render_kpi_card(
            'In Waiting Queues',
            $totalWaiting,
            'Pending batch allotment',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            $totalWaiting > 0 ? 'warning' : 'default'
        ) ?>
    </a>
    <a href="<?= url('public/students_list.php?status=active_students') ?>" class="kpi-link" title="View enrolled students">
        <?= render_kpi_card(
            'Joining Today',
            $joiningToday,
            'Scheduled batch starts',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'
        ) ?>
    </a>
</div>

<!-- Row 2: Deadlines & Follow-ups -->
<div class="grid grid-4" style="margin-bottom: 20px;">
    <a href="<?= url('public/reports.php?report=expiring_students&expiry_filter=today') ?>" class="kpi-link" title="View courses ending today">
        <?= render_kpi_card(
            'Course Ends Today',
            $endingToday,
            'Last class day today',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            $endingToday > 0 ? 'warning' : 'default'
        ) ?>
    </a>
    <a href="<?= url('public/reports.php?report=expiring_students&expiry_filter=7_days') ?>" class="kpi-link" title="View courses ending in the next 7 days">
        <?= render_kpi_card(
            'Ending in 7 Days',
            $endingSoon,
            'Upcoming seat vacancies',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>'
        ) ?>
    </a>
    <a href="<?= url('public/reports.php?report=extensions') ?>" class="kpi-link" title="View extension invoices">
        <?= render_kpi_card(
            'Pending Invoices',
            $pendingInvoices,
            'Unpaid extension invoices',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            $pendingInvoices > 0 ? 'danger' : 'default'
        ) ?>
    </a>
    <a href="<?= url('public/waiting_list.php') ?>" class="kpi-link" title="Open queue call follow-ups">
        <?= render_kpi_card(
            'Follow-ups Due Today',
            $followUpsToday,
            'Scheduled queue calls',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>'
        ) ?>
    </a>
</div>

<!-- Batch Occupancy Snapshot -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Batch Occupancy Snapshot</h2>
            <small class="muted">Live seat availability and next eligible candidate across active batches.</small>
        </div>
        <div class="action-group">
            <a href="<?= e($batchesOverviewUrl) ?>" class="badge badge-purple" title="Manage batches"><?= count($batches) ?> Active Batches</a>
            <a href="<?= e($batchesOverviewUrl) ?>" class="badge badge-gray" title="View total batch capacity"><?= $totalCapacity ?> Total Capacity</a>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Batch</th>
                    <th>Occupancy</th>
                    <th>Capacity</th>
                    <th>Availability</th>
                    <th>Waiting Queue</th>
                    <th>Next Eligible Student</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $b):
                    $cap = (int)$b['capacity'];
                    $occ = (int)$b['active_count'];
                    $pct = $cap > 0 ? min(100, round(($occ / $cap) * 100)) : 0;
                    $barColor = $pct >= 100 ? 'var(--danger)' : ($pct >= 75 ? 'var(--warning)' : 'var(--success)');
                    $batchStudentsUrl = url('public/students_list.php?status=active_students&batch_id=' . (int)$b['id']);
                    $batchQueueUrl = url('public/waiting_list.php?batch=' . (int)$b['id']);
                ?>
                <tr>
                    <td>
                        <a href="<?= e($batchStudentsUrl) ?>" class="dash-cell-link" title="View active students in <?= e($b['name']) ?>">
                            <?= e($b['name']) ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?= e($batchStudentsUrl) ?>" style="display: inline-block; text-decoration: none;" title="View <?= $occ ?> enrolled student(s)">
                            <div style="font-weight: 600; color: var(--text); font-variant-numeric: tabular-nums;">
                                <?= $occ ?> / <?= $cap ?> <span class="muted" style="font-weight: 500; font-size: 11.5px;">(<?= $pct ?>%)</span>
                            </div>
                            <div class="dash-bar-bg">
                                <div class="dash-bar-fill" style="width: <?= $pct ?>%; background: <?= $barColor ?>;"></div>
                            </div>
                        </a>
                    </td>
                    <td style="font-variant-numeric: tabular-nums;">
                        <a href="<?= e($batchesOverviewUrl) ?>" class="dash-cell-link" style="font-weight: 500;" title="Manage batch capacity"><?= $cap ?></a>
                    </td>
                    <td>
                        <?php if ($b['available'] > 0): ?>
                            <a href="<?= e($batchQueueUrl) ?>" class="badge badge-green" title="Allot open seat from waiting queue"><?= (int)$b['available'] ?> Seats Open</a>
                        <?php else: ?>
                            <a href="<?= e($batchQueueUrl) ?>" class="badge badge-red" title="View batch queue">Full</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= e($batchQueueUrl) ?>" class="badge badge-gray" title="Open batch waiting list"><?= (int)$b['waiting_count'] ?> waiting</a>
                    </td>
                    <td>
                        <?php if ($b['next_student'] !== '-' && $b['next_student_id']): ?>
                            <a href="<?= url('public/student_profile.php?id=' . (int)$b['next_student_id']) ?>" class="dash-cell-link" title="Open student profile">
                                <?= e($b['next_student']) ?>
                            </a>
                        <?php else: ?>
                            <span class="muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <a href="<?= e($batchQueueUrl) ?>" class="btn btn-sm btn-secondary">Manage Queue &rarr;</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$batches): ?>
                <tr>
                    <td colspan="7" class="muted" style="text-align: center; padding: 28px;">
                        No active batches configured.
                        <?php if (is_admin_or_above()): ?>
                            <a href="<?= url('public/batches.php') ?>" style="margin-left: 6px; font-weight: 600;">Add Batch &rarr;</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
