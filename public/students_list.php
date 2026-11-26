<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/StudentService.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_login();

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'active_students';
$batchId = $_GET['batch_id'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$batches = BatchService::getAllBatches();

$baseFilters = [
    'q' => $q,
    'status' => $status,
    'batch_id' => $batchId,
];

if (($_GET['export'] ?? '') === 'excel') {
    $exportStudents = StudentService::getStudents(array_merge($baseFilters, ['limit' => 5000, 'offset' => 0]));
    StudentService::exportToCsv($exportStudents, $status);
    exit;
}

$totalStudents = StudentService::countStudents($baseFilters);
$totalPages = max(1, (int)ceil($totalStudents / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$students = StudentService::getStudents(array_merge($baseFilters, [
    'limit' => $perPage,
    'offset' => ($page - 1) * $perPage,
]));

$isActiveGroup = in_array($status, ['active_students', '', 'active', 'extended'], true);

$pageTitle = 'Students Directory';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.status-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}
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
</style>

<!-- Main View Mode Tabs -->
<div class="view-mode-bar">
    <a href="<?= url('public/students_list.php?status=active_students' . ($batchId ? '&batch_id=' . urlencode($batchId) : '')) ?>"
       class="view-mode-item <?= $isActiveGroup ? 'active' : '' ?>">
        <span class="status-dot" style="background: <?= $isActiveGroup ? '#FFFFFF' : 'var(--success)' ?>;"></span>
        <span>Active Students</span>
    </a>
    <a href="<?= url('public/students_list.php?status=paused' . ($batchId ? '&batch_id=' . urlencode($batchId) : '')) ?>"
       class="view-mode-item <?= $status === 'paused' ? 'active' : '' ?>">
        <span class="status-dot" style="background: <?= $status === 'paused' ? '#FFFFFF' : 'var(--warning)' ?>;"></span>
        <span>On Hold / Paused</span>
    </a>
    <a href="<?= url('public/students_list.php?status=course_completed' . ($batchId ? '&batch_id=' . urlencode($batchId) : '')) ?>"
       class="view-mode-item <?= $status === 'course_completed' ? 'active' : '' ?>">
        <span>Course Completed</span>
    </a>
    <a href="<?= url('public/students_list.php?status=temporary' . ($batchId ? '&batch_id=' . urlencode($batchId) : '')) ?>"
       class="view-mode-item <?= $status === 'temporary' ? 'active' : '' ?>">
        <span>Temporary / Walk-in</span>
    </a>
    <a href="<?= url('public/students_list.php?status=all' . ($batchId ? '&batch_id=' . urlencode($batchId) : '')) ?>"
       class="view-mode-item <?= $status === 'all' ? 'active' : '' ?>">
        <span>All Students</span>
    </a>
</div>

<!-- Horizontal Segmented Tab Bar for Batches -->
<div class="batch-tab-bar">
    <a href="<?= url('public/students_list.php?' . http_build_query(array_merge($_GET, ['batch_id' => '']))) ?>"
       class="batch-tab-item <?= $batchId === '' ? 'active' : '' ?>">
        All Batches
    </a>
    <?php foreach ($batches as $b): ?>
        <a href="<?= url('public/students_list.php?' . http_build_query(array_merge($_GET, ['batch_id' => $b['id']]))) ?>"
           class="batch-tab-item <?= (string)$batchId === (string)$b['id'] ? 'active' : '' ?>">
            <?= e($b['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Search & Sub-filter Card -->
<div class="card" style="padding: 16px 20px;">
    <form method="get" class="<?= $isActiveGroup ? 'filter-row' : 'filter-form' ?>">
        <input type="hidden" name="batch_id" value="<?= e($batchId) ?>">

        <div>
            <label for="studentSearchInput">Search (Name / Mobile / Reg. No / Form No)</label>
            <input type="text" id="studentSearchInput" name="q" placeholder="Type name, mobile, or registration no..." value="<?= e($q) ?>">
        </div>

        <?php if ($isActiveGroup): ?>
        <div>
            <label for="activeSubFilter">Active Breakdown</label>
            <select id="activeSubFilter" name="status" onchange="this.form.submit()">
                <option value="active_students" <?= in_array($status, ['active_students', ''], true) ? 'selected' : '' ?>>All Active (Regular + Extended)</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Regular Active Only</option>
                <option value="extended" <?= $status === 'extended' ? 'selected' : '' ?>>Extended Only</option>
            </select>
        </div>
        <?php else: ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>

        <div class="form-actions">
            <button class="btn" type="submit">Search</button>
            <?php if ($q !== '' || ($status !== 'active_students' && $status !== '') || $batchId !== ''): ?>
                <a href="<?= url('public/students_list.php') ?>" class="btn btn-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Directory Table Card -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>
                <?php if ($status === 'paused'): ?>
                    On Hold / Paused Students
                <?php elseif ($status === 'course_completed'): ?>
                    Course Completed Students
                <?php elseif ($status === 'temporary'): ?>
                    Temporary &amp; Walk-in Students
                <?php elseif ($status === 'all'): ?>
                    All Students Directory
                <?php else: ?>
                    Active Students
                <?php endif; ?>
                <span class="badge badge-gray" style="margin-left: 6px;"><?= $totalStudents ?></span>
            </h2>
            <small class="muted">
                <?php if ($status === 'paused'): ?>
                    Students whose course is temporarily on hold with banked remaining days.
                <?php elseif ($status === 'course_completed'): ?>
                    Students who have completed their course training.
                <?php else: ?>
                    To manage candidates awaiting batch allotment, visit the <a href="<?= url('public/waiting_list.php') ?>" style="font-weight: 600;">Waiting List</a>.
                <?php endif; ?>
            </small>
        </div>
        <div class="action-group">
            <?= render_export_button('', 'Export CSV') ?>
            <?php if (is_admin_or_above()): ?>
            <a href="<?= url('public/student_register.php') ?>" class="btn btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Add New Student</span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Reg. No</th>
                    <th>Form No</th>
                    <th>Student Name</th>
                    <th>Mobile / Contact</th>
                    <th>Batch</th>
                    <th>Status</th>
                    <th><?= $status === 'paused' ? 'Banked Days &amp; Ready Date' : ($status === 'course_completed' ? 'Completion Date' : 'Expiry Date') ?></th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s): ?>
                <tr>
                    <td><code><?= e($s['registration_no'] ?? '-') ?></code></td>
                    <td><code><?= e($s['form_no']) ?></code></td>
                    <td>
                        <strong><?= e($s['name']) ?></strong>
                        <?php if (!empty($s['is_temporary'])): ?>
                            <span class="badge badge-amber" style="margin-left: 4px;">Temp</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="tel:<?= e($s['student_mobile']) ?>" class="tel-link">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <span><?= e($s['student_mobile']) ?></span>
                        </a>
                    </td>
                    <td><?= e($s['batch_name'] ?? (!empty($s['is_temporary']) ? 'Walk-in / Any' : '-')) ?></td>
                    <td>
                        <?php if (!empty($s['is_temporary'])): ?>
                            <span class="badge badge-amber">Temporary</span>
                        <?php else: ?>
                            <?= render_status_badge($s['status']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($s['is_temporary'])): ?>
                            <span class="muted" style="font-size: 12px;">Walk-in Record</span>
                        <?php elseif ($s['is_paused'] || $s['status'] === 'paused'): ?>
                            <div style="font-weight: 600; color: var(--warning-hover);">
                                <?= (int)$s['paused_remaining_days'] ?> days banked
                            </div>
                            <div class="muted" style="font-size: 12px;">
                                <?= !empty($s['resume_ready_date']) ? 'Ready: ' . format_date($s['resume_ready_date']) : 'Ready date pending' ?>
                            </div>
                        <?php else: ?>
                            <span style="font-variant-numeric: tabular-nums;"><?= format_date($s['final_expiry_date']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right" style="white-space: nowrap;">
                        <?php if (is_admin_or_above()): ?>
                        <div class="action-group" style="justify-content: flex-end;">
                            <a href="<?= url('public/student_profile.php?id=' . (int)$s['id']) ?>" class="btn btn-sm">Profile</a>
                            <a href="<?= url('public/manual_receipt.php?student_id=' . (int)$s['id']) ?>" class="btn btn-sm btn-secondary" title="Issue Receipt">+ Receipt</a>
                        </div>
                        <?php else: ?>
                            <span class="muted" style="font-size: 12px;">View Only</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?>
                <tr>
                    <td colspan="8" class="muted" style="text-align: center; padding: 32px 20px;">
                        <?php if ($status === 'paused'): ?>
                            No students are currently on hold.
                        <?php elseif ($status === 'course_completed'): ?>
                            No completed student records found.
                        <?php else: ?>
                            No matching students found. Check the <a href="<?= url('public/waiting_list.php') ?>">Waiting List</a> for queued candidates.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($totalStudents, $page, $perPage, $_GET) ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
