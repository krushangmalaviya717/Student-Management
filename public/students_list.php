<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/StudentService.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_login();

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'active_students';
$batchId = $_GET['batch_id'] ?? '';

// Fetch active batches for horizontal navigation
$batches = BatchService::getAllBatches();

// Fetch filtered students via StudentService (Requirement 2: Separation of business logic)
$students = StudentService::getStudents([
    'q' => $q,
    'status' => $status,
    'batch_id' => $batchId,
    'limit' => 200
]);

// Excel / CSV Export Handler (Requirement 3: Reusable export, no duplicate code)
if (($_GET['export'] ?? '') === 'excel') {
    StudentService::exportToCsv($students, $status);
    exit;
}

$pageTitle = 'Students Directory';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<!-- Main View Mode Tabs (Active / Completed / All) -->
<div class="view-mode-bar">
    <a href="<?= url('public/students_list.php?status=active_students' . ($batchId ? '&batch_id=' . $batchId : '')) ?>" 
       class="view-mode-item <?= in_array($status, ['active_students', '', 'active', 'extended'], true) ? 'active' : '' ?>">
       <span class="status-dot status-dot-active" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#16a34a; margin-right:4px;"></span> Active Students (હાલ ચાલુ)
    </a>
    <a href="<?= url('public/students_list.php?status=paused' . ($batchId ? '&batch_id=' . $batchId : '')) ?>" 
       class="view-mode-item <?= $status === 'paused' ? 'active' : '' ?>">
       <span class="status-dot status-dot-paused" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#f59e0b; margin-right:4px;"></span> On Hold / Paused (સ્ટોપ / મોકૂફ)
    </a>
    <a href="<?= url('public/students_list.php?status=course_completed' . ($batchId ? '&batch_id=' . $batchId : '')) ?>" 
       class="view-mode-item <?= $status === 'course_completed' ? 'active' : '' ?>">
       Course Completed (કોર્સ પૂર્ણ / છૂટા થયેલા)
    </a>
    <a href="<?= url('public/students_list.php?status=all' . ($batchId ? '&batch_id=' . $batchId : '')) ?>" 
       class="view-mode-item <?= $status === 'all' ? 'active' : '' ?>">
       All Students (બધા જ વિદ્યાર્થીઓ)
    </a>
</div>

<!-- Horizontal Segmented Tab Bar for Batches -->
<div class="batch-tab-bar">
    <a href="<?= url('public/students_list.php?' . http_build_query(array_merge($_GET, ['batch_id' => '']))) ?>" 
       class="batch-tab-item <?= $batchId === '' ? 'active' : '' ?>">
       All Batches (બધી જ બેચ)
    </a>
    <?php foreach ($batches as $b): ?>
        <a href="<?= url('public/students_list.php?' . http_build_query(array_merge($_GET, ['batch_id' => $b['id']]))) ?>" 
           class="batch-tab-item <?= (string)$batchId === (string)$b['id'] ? 'active' : '' ?>">
           <?= e($b['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <input type="hidden" name="batch_id" value="<?= e($batchId) ?>">
        
        <div>
            <label style="margin-top:0;">Search (name / mobile / reg. no / form no)</label>
            <input type="text" name="q" placeholder="Type name or mobile..." value="<?= e($q) ?>">
        </div>

        <div>
            <label style="margin-top:0;">Status Filter</label>
            <select name="status">
                <option value="active_students" <?= in_array($status, ['active_students', ''], true) ? 'selected' : '' ?>>Active Students (એક્ટિવ સ્ટુડન્ટ્સ)</option>
                <option value="paused" <?= $status === 'paused' ? 'selected' : '' ?>>On Hold / Paused (સ્ટોપ / મોકૂફ)</option>
                <option value="course_completed" <?= $status === 'course_completed' ? 'selected' : '' ?>>Course Completed (કોર્સ પૂર્ણ / છૂટા થયેલા)</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active Only (નિયમિત ચાલુ)</option>
                <option value="extended" <?= $status === 'extended' ? 'selected' : '' ?>>Extended Only (એક્સટેન્ડેડ)</option>
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Students (બધા જ સ્ટુડન્ટ્સ - હિસ્ટ્રી સહિત)</option>
            </select>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Search / Filter</button>
            <?php if ($q !== '' || ($status !== 'active_students' && $status !== '') || $batchId !== ''): ?>
                <a href="<?= url('public/students_list.php') ?>" class="btn btn-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 class="mt-0" style="margin-bottom:2px;">
                <?php if ($status === 'paused'): ?>
                    On Hold / Paused Students (મોકૂફ / હોલ્ડ પર રહેલા વિદ્યાર્થીઓ)
                <?php elseif ($status === 'course_completed'): ?>
                    Course Completed Students (કોર્સ પૂર્ણ / છૂટા થયેલા વિદ્યાર્થીઓ)
                <?php elseif ($status === 'all'): ?>
                    All Students Directory (તમામ વિદ્યાર્થીઓ)
                <?php else: ?>
                    Active Students (હાલ ચાલુ વિદ્યાર્થીઓ)
                <?php endif; ?>
            </h2>
            <small class="muted">
                <?php if ($status === 'paused'): ?>
                    અંગત કારણોસર અભ્યાસ મોકૂફ રાખેલ વિદ્યાર્થીઓની યાદી. જ્યારે વિદ્યાર્થી તૈયાર થાય ત્યારે તેમને બેચ ફાળવી શકાશે.
                <?php elseif ($status === 'course_completed'): ?>
                    સંસ્થામાંથી કોર્સ સફળતાપૂર્વક પૂર્ણ કરી ચૂકેલા વિદ્યાર્થીઓની યાદી.
                <?php else: ?>
                    વેઇટિંગ વાળા વિદ્યાર્થીઓ જોવા માટે ડાબી બાજુનું <a href="<?= url('public/waiting_list.php') ?>" style="color:var(--primary); font-weight:600;">Waiting List</a> પેજ જુઓ.
                <?php endif; ?>
            </small>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <?= render_export_button('', 'Export to Excel (CSV)') ?>
            <a href="<?= url('public/student_register.php') ?>" class="btn btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>Add New Student
            </a>
        </div>
    </div>
    <table>
        <tr>
            <th>Reg. No</th>
            <th>Form No</th>
            <th>Student Name</th>
            <th>Mobile / Contact</th>
            <th>Batch</th>
            <th>Status</th>
            <th><?= $status === 'paused' ? 'Banked Days &amp; Ready Date' : ($status === 'course_completed' ? 'Completion Date' : 'Expiry Date') ?></th>
            <th>Quick Operations</th>
        </tr>
        <?php foreach ($students as $s): ?>
        <tr>
            <td><code><?= e($s['registration_no'] ?? '-') ?></code></td>
            <td><code><?= e($s['form_no']) ?></code></td>
            <td><strong><?= e($s['name']) ?></strong></td>
            <td><a href="tel:<?= e($s['student_mobile']) ?>" style="font-weight:600; color:var(--text); text-decoration:none;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:4px; color:var(--secondary);"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg><?= e($s['student_mobile']) ?></a></td>
            <td><?= e($s['batch_name'] ?? '-') ?></td>
            <td><?= render_status_badge($s['status']) ?></td>
            <td>
                <?php if ($s['is_paused'] || $s['status'] === 'paused'): ?>
                    <div style="font-weight:600; color:#b45309;">
                        <?= (int)$s['paused_remaining_days'] ?> દિવસ જમા
                    </div>
                    <div style="font-size:12px; color:var(--muted);">
                        <?= !empty($s['resume_ready_date']) ? 'તૈયાર: ' . format_date($s['resume_ready_date']) : 'તારીખ પેન્ડિંગ' ?>
                    </div>
                <?php else: ?>
                    <?= format_date($s['final_expiry_date']) ?>
                <?php endif; ?>
            </td>
            <td>
                <a href="<?= url('public/student_profile.php?id=' . (int)$s['id']) ?>" class="btn btn-sm">View 360° Profile &rarr;</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?>
        <tr>
            <td colspan="8" class="muted" style="text-align:center; padding:24px;">
                <?php if ($status === 'paused'): ?>
                    હાલ કોઈ વિદ્યાર્થી હોલ્ડ / મોકૂફ પર નથી.
                <?php elseif ($status === 'course_completed'): ?>
                    હજુ સુધી કોઈ વિદ્યાર્થીનો કોર્સ પૂર્ણ થયેલ નથી.
                <?php else: ?>
                    કોઈ એક્ટિવ સ્ટુડન્ટ મળ્યા નથી. (વેઇટિંગ વાળા વિદ્યાર્થીઓ માટે <a href="<?= url('public/waiting_list.php') ?>">Waiting List</a> જુઓ)
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
