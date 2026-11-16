<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/queue_engine.php';
require_login();

$pdo = db();
$batches = $pdo->query("SELECT * FROM batches WHERE is_active = 1 ORDER BY start_time")->fetchAll();
$activeBatchId = (int)($_GET['batch'] ?? ($batches[0]['id'] ?? 0));

// Dynamically refresh and recalculate expected joining dates for the active batch
recalculate_expected_joining_dates($activeBatchId);

$queue = get_batch_queue($activeBatchId);
$capacity = get_batch_capacity($activeBatchId);
$activeCount = count_active_students_in_batch($activeBatchId);
$available = max(0, $capacity - $activeCount);
$next = get_next_eligible_student($activeBatchId);

// Check if any active student has their last day within next 2 days
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

// Excel Export Handler (Requirement 3: Reusable ExportService)
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

<!-- Horizontal Segmented Tab Bar for Batches -->
<div class="batch-tab-bar">
    <?php foreach ($batches as $b): ?>
        <a href="?batch=<?= $b['id'] ?>" class="batch-tab-item <?= $b['id'] == $activeBatchId ? 'active' : '' ?>">
            <?= e($b['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid grid-3">
    <div class="kpi"><div class="num"><?= $available ?> / <?= $capacity ?></div><div class="label">Available Seats</div></div>
    <div class="kpi"><div class="num"><?= count($queue) ?></div><div class="label">Total in Queue</div></div>
    <div class="kpi"><div class="num" style="font-size:16px;"><?= $next ? e($next['name']) : '-' ?></div><div class="label">Next Eligible Student</div></div>
</div>

<?php if (!empty($soonExpiring)): ?>
<div style="background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; padding:12px 18px; border-radius:8px; margin-bottom:16px;">
    <div style="font-weight:700; color:#92400e; font-size:13.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        ૧-૨ દિવસમાં સીટ ખાલી થવાની અગાઉથી જાણ (Upcoming Vacancies Notice):
    </div>
    <div style="font-size:13px; color:#78350f; line-height:1.6;">
        <?php foreach ($soonExpiring as $se): ?>
            <div>
                &bull; વિદ્યાર્થી <strong><?= e($se['name']) ?></strong> નો છેલ્લો દિવસ <strong><?= format_date($se['final_expiry_date']) ?></strong> (<?= $se['days_left'] == 0 ? 'આજે છેલ્લો દિવસ' : ($se['days_left'] == 1 ? 'આવતીકાલે છેલ્લો દિવસ' : '૨ દિવસમાં') ?>) છે. 
                તારીખ <strong><?= date('d-M-Y', strtotime($se['final_expiry_date'] . ' + 1 day')) ?></strong> થી જગ્યા ખાલી થશે. 
                વેઇટિંગ લિસ્ટના આગામી વિદ્યાર્થીને અગાઉથી કોલ કરીને જાણ કરી શકો છો.
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <h2 class="mt-0" style="margin-bottom:0;">Queue Candidates</h2>
        <?= render_export_button('?batch=' . $activeBatchId . '&export=excel', 'Export to Excel (CSV)') ?>
    </div>
    <table>
        <tr><th>#</th><th>Name</th><th>Reg/Form</th><th>Mobile</th><th>Confirmed On</th><th>Expected Joining</th><th>State</th><th>Quick Operations</th></tr>
        <?php foreach ($queue as $i => $row): ?>
        <tr>
            <td><strong>#<?= $i + 1 ?></strong></td>
            <td><strong><?= e($row['name']) ?></strong></td>
            <td><code><?= e($row['registration_no'] ?? $row['form_no']) ?></code></td>
            <td><a href="tel:<?= e($row['student_mobile']) ?>" style="font-weight:600; color:var(--text); text-decoration:none;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:4px; color:var(--secondary);"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg><?= e($row['student_mobile']) ?></a></td>
            <td><?= format_date($row['priority_date']) ?></td>
            <td>
                <?php
                $expDate = $row['expected_joining_date'] ?? null;
                $todayStr = date('Y-m-d');
                $vacName = $row['vacating_student_name'] ?? null;
                $lastDay = $row['vacating_student_last_day'] ?? null;
                $daysUntil = (int)($row['days_until_vacancy'] ?? 0);
                $isVacantNow = !empty($row['is_currently_vacant']);
                $batchPassedToday = !empty($row['batch_passed_today']);

                if (empty($expDate) || ($row['state'] ?? '') === 'waiting_date_not_confirmed'):
                ?>
                    <span class="badge badge-gray" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">Pending Date</span>
                <?php elseif ($isVacantNow && $batchPassedToday): ?>
                    <!-- Vacant seat, but today's batch time has already passed! Earliest is Tomorrow -->
                    <div>
                        <span class="badge" style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-weight:700; padding:5px 9px; display:inline-flex; align-items:center; gap:5px; border-radius:6px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            આવતીકાલથી ઉપલબ્ધ (Tomorrow - <?= format_date($expDate) ?>)
                        </span>
                    </div>
                    <div style="font-size:11.5px; margin-top:3px; color:#1e3a8a; line-height:1.4;">
                        આજની બેચ પૂર્ણ થઈ ગયેલ છે &bull; <strong>સીટ ખાલી છે</strong>
                    </div>
                <?php elseif ($isVacantNow && !$batchPassedToday): ?>
                    <!-- Vacant seat, and today's batch hasn't started yet! Can join today -->
                    <div>
                        <span class="badge" style="background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; font-weight:700; padding:5px 9px; display:inline-flex; align-items:center; gap:5px; border-radius:6px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            આજથી ઉપલબ્ધ (Available Today)
                        </span>
                    </div>
                    <div style="font-size:11.5px; margin-top:3px; color:#059669;">
                        આજની બેચ શરૂ થવાની બાકી છે &bull; <strong>હાલ સીટ ખાલી છે</strong>
                    </div>
                <?php elseif ($daysUntil <= 2): ?>
                    <!-- 1-2 Days In Advance Alert -->
                    <div>
                        <span class="badge" style="background:#fffbeb; color:#b45309; border:1px solid #fcd34d; font-weight:700; padding:5px 9px; display:inline-flex; align-items:center; gap:5px; border-radius:6px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            <?= $daysUntil == 1 ? 'આવતીકાલથી ઉપલબ્ધ (' . format_date($expDate) . ')' : '૨ દિવસમાં ઉપલબ્ધ (' . format_date($expDate) . ' થી)' ?>
                        </span>
                    </div>
                    <div style="font-size:11.5px; margin-top:3px; color:#92400e; line-height:1.4;">
                        <?= $vacName ? 'વિદ્યાર્થી <strong>' . e($vacName) . '</strong> નો છેલ્લો દિવસ: ' . format_date($lastDay) : '' ?>
                    </div>
                <?php else: ?>
                    <div>
                        <span class="badge badge-purple" style="font-weight:700;"><?= format_date($expDate) ?> થી</span>
                    </div>
                    <div class="muted" style="font-size:11.5px; margin-top:2px;">
                        <?= $vacName ? 'વિદ્યાર્થી <strong>' . e($vacName) . '</strong> નો છેલ્લો દિવસ: ' . format_date($lastDay) : '(' . $daysUntil . ' દિવસ પછી)' ?>
                    </div>
                <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $stateBadge[$row['state']] ?? 'gray' ?>"><?= ucwords(str_replace('_',' ',$row['state'])) ?></span></td>
            <td>
                <a href="<?= url('public/student_profile.php?id=' . (int)$row['student_id']) ?>" class="btn btn-sm">Full Profile & Call</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$queue): ?><tr><td colspan="8" class="muted" style="text-align:center; padding:24px;">No candidates currently waiting in this batch queue.</td></tr><?php endif; ?>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
