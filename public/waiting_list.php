<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/queue_engine.php';
require_login();

$pdo = db();
$batches = $pdo->query("SELECT * FROM batches WHERE is_active = 1 ORDER BY start_time")->fetchAll();
$activeBatchId = (int)($_GET['batch'] ?? ($batches[0]['id'] ?? 0));

recalculate_expected_joining_dates($activeBatchId);

$queue = get_batch_queue($activeBatchId);
$capacity = get_batch_capacity($activeBatchId);
$activeCount = count_active_students_in_batch($activeBatchId);
$available = max(0, $capacity - $activeCount);
$next = get_next_eligible_student($activeBatchId);

$stmtActive = $pdo->prepare(
    "SELECT id, name, student_mobile, final_expiry_date
     FROM students
     WHERE joined_batch_id = ? AND status IN ('active','extended')
     ORDER BY final_expiry_date ASC"
);
$stmtActive->execute([$activeBatchId]);
$batchActiveStudents = $stmtActive->fetchAll();

$soonExpiring = [];
$todayStr = date('Y-m-d');
foreach ($batchActiveStudents as $act) {
    if (!empty($act['final_expiry_date'])) {
        $todayDt = new DateTime($todayStr);
        $expDt = new DateTime($act['final_expiry_date']);
        $daysDiff = (int)$todayDt->diff($expDt)->format('%r%a');
        if ($daysDiff >= 0 && $daysDiff <= 2) {
            $soonExpiring[] = array_merge($act, ['days_left' => $daysDiff]);
        }
    }
}

if (($_GET['export'] ?? '') === 'excel') {
    require_once __DIR__ . '/../includes/services/ExportService.php';
    $batchName = 'Batch_' . $activeBatchId;
    foreach ($batches as $b) {
        if ($b['id'] == $activeBatchId) {
            $batchName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $b['name']);
            break;
        }
    }
    $filename = 'Waiting_List_' . $batchName . '_' . date('Y-m-d') . '.csv';
    $headers = ['Queue Position', 'Student Name', 'Reg / Form No', 'Mobile', 'Confirmed On', 'Expected Joining Date', 'State'];
    $rows = [];
    foreach ($queue as $i => $row) {
        $rows[] = [
            '#' . ($i + 1),
            $row['name'],
            $row['registration_no'] ?: $row['form_no'],
            $row['student_mobile'],
            format_date($row['priority_date']),
            (!empty($row['is_currently_vacant']) && !empty($row['batch_passed_today'])
                ? 'Available Tomorrow (' . format_date($row['expected_joining_date']) . ')'
                : ($row['expected_joining_date'] <= date('Y-m-d') ? 'Available Today (' . format_date($row['expected_joining_date']) . ')' : format_date($row['expected_joining_date']))),
            ucwords(str_replace('_', ' ', $row['state']))
        ];
    }
    ExportService::downloadCsv($filename, $headers, $rows);
}

$stateBadge = [
    'waiting_ready' => 'green', 'waiting_future_date' => 'amber', 'waiting_date_not_confirmed' => 'gray',
    'call_pending' => 'purple', 'called_confirmed' => 'green', 'called_call_later' => 'amber', 'called_not_available' => 'red',
];

$pageTitle = 'Waiting List';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.tel-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
}
.tel-link svg {
    color: var(--secondary);
    flex-shrink: 0;
}
.vacancy-notice {
    background: var(--warning-bg);
    border: 1px solid var(--warning-border);
    border-left: 3px solid var(--warning);
    padding: 14px 18px;
    border-radius: var(--radius-lg);
    margin-bottom: 20px;
}
.vacancy-notice-title {
    font-weight: 700;
    color: #92400E;
    font-size: 13.5px;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.vacancy-notice-list {
    font-size: 13px;
    color: #78350F;
    line-height: 1.6;
}
.eta-sub {
    font-size: 11.5px;
    margin-top: 4px;
    line-height: 1.4;
}
</style>

<!-- Horizontal Segmented Tab Bar for Batches -->
<div class="batch-tab-bar">
    <?php foreach ($batches as $b): ?>
        <a href="?batch=<?= (int)$b['id'] ?>" class="batch-tab-item <?= $b['id'] == $activeBatchId ? 'active' : '' ?>">
            <?= e($b['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- KPI Summary -->
<div class="grid grid-3" style="margin-bottom: 20px;">
    <?= render_kpi_card(
        'Available Seats',
        $available . ' / ' . $capacity,
        'Open spots in selected batch',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        $available > 0 ? 'success' : 'default'
    ) ?>
    <?= render_kpi_card(
        'Total in Queue',
        count($queue),
        'Candidates awaiting admission',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        count($queue) > 0 ? 'warning' : 'default'
    ) ?>
    <?= render_kpi_card(
        'Next Eligible Student',
        $next ? $next['name'] : 'None',
        'Top priority ready candidate',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        $next ? 'primary' : 'default'
    ) ?>
</div>

<?php if (!empty($soonExpiring)): ?>
<div class="vacancy-notice">
    <div class="vacancy-notice-title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>Upcoming Vacancies (Next 1–2 Days)</span>
    </div>
    <div class="vacancy-notice-list">
        <?php foreach ($soonExpiring as $se): ?>
            <div>
                &bull; <strong><?= e($se['name']) ?></strong> finishes on <strong><?= format_date($se['final_expiry_date']) ?></strong>
                (<?= $se['days_left'] == 0 ? 'Last day today' : ($se['days_left'] == 1 ? 'Last day tomorrow' : 'In 2 days') ?>).
                Seat opens from <strong><?= date('d-M-Y', strtotime($se['final_expiry_date'] . ' + 1 day')) ?></strong>.
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Queue Candidates</h2>
            <small class="muted">Priority-ordered candidates waiting to join the selected batch.</small>
        </div>
        <div class="action-group">
            <?= render_export_button('?batch=' . $activeBatchId . '&export=excel', 'Export CSV') ?>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Reg / Form</th>
                    <th>Mobile</th>
                    <th>Confirmed On</th>
                    <th>Expected Joining</th>
                    <th>State</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queue as $i => $row): ?>
                <tr>
                    <td style="font-variant-numeric: tabular-nums;"><strong>#<?= $i + 1 ?></strong></td>
                    <td><strong><?= e($row['name']) ?></strong></td>
                    <td><code><?= e($row['registration_no'] ?? $row['form_no']) ?></code></td>
                    <td>
                        <a href="tel:<?= e($row['student_mobile']) ?>" class="tel-link">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <span><?= e($row['student_mobile']) ?></span>
                        </a>
                    </td>
                    <td style="white-space: nowrap; font-variant-numeric: tabular-nums;"><?= format_date($row['priority_date']) ?></td>
                    <td>
                        <?php
                        $expDate = $row['expected_joining_date'] ?? null;
                        $vacName = $row['vacating_student_name'] ?? null;
                        $lastDay = $row['vacating_student_last_day'] ?? null;
                        $daysUntil = (int)($row['days_until_vacancy'] ?? 0);
                        $isVacantNow = !empty($row['is_currently_vacant']);
                        $batchPassedToday = !empty($row['batch_passed_today']);

                        if (empty($expDate) || ($row['state'] ?? '') === 'waiting_date_not_confirmed'):
                        ?>
                            <span class="badge badge-gray">Pending Date</span>
                        <?php elseif ($isVacantNow && $batchPassedToday): ?>
                            <span class="badge badge-blue">Available Tomorrow (<?= format_date($expDate) ?>)</span>
                            <div class="eta-sub" style="color: #1E40AF;">Today's batch ended &bull; Seat vacant</div>
                        <?php elseif ($isVacantNow && !$batchPassedToday): ?>
                            <span class="badge badge-green">Available Today</span>
                            <div class="eta-sub" style="color: #166534;">Batch not started yet &bull; Seat vacant</div>
                        <?php elseif ($daysUntil <= 2): ?>
                            <span class="badge badge-amber">
                                <?= $daysUntil == 1 ? 'Tomorrow (' . format_date($expDate) . ')' : 'In 2 Days (' . format_date($expDate) . ')' ?>
                            </span>
                            <?php if ($vacName): ?>
                            <div class="eta-sub" style="color: #92400E;">
                                <?= e($vacName) ?> finishes on <?= format_date($lastDay) ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-purple">From <?= format_date($expDate) ?></span>
                            <div class="eta-sub muted">
                                <?= $vacName ? e($vacName) . ' finishes on ' . format_date($lastDay) : 'In ' . $daysUntil . ' days' ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $stateBadge[$row['state']] ?? 'gray' ?>">
                            <?= ucwords(str_replace('_', ' ', $row['state'])) ?>
                        </span>
                    </td>
                    <td class="text-right" style="white-space: nowrap;">
                        <?php if (is_admin_or_above()): ?>
                            <a href="<?= url('public/student_profile.php?id=' . (int)$row['student_id']) ?>" class="btn btn-sm btn-secondary">Profile &amp; Call</a>
                        <?php else: ?>
                            <span class="muted" style="font-size: 12px;">View Only</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$queue): ?>
                <tr>
                    <td colspan="8" class="muted" style="text-align: center; padding: 32px 20px;">No candidates currently waiting in this batch queue.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
