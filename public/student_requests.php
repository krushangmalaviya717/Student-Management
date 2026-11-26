<?php
$pageTitle = 'Student Leave & Extension Requests';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($requestId > 0 && in_array($action, ['approved', 'rejected'], true)) {
        $stmt = db()->prepare(
            "UPDATE student_leave_requests 
             SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() 
             WHERE id = ?"
        );
        $stmt->execute([$action, $adminNote, current_user()['id'], $requestId]);
        log_audit('student_request', $requestId, $action, null, ['admin_note' => $adminNote]);

        flash('success', 'Request marked as ' . strtoupper($action) . '.');
        redirect('public/student_requests.php');
    }
}

$stmt = db()->prepare(
    "SELECT r.*, s.name AS student_name, s.student_mobile, s.registration_no, s.form_no,
            u.name AS reviewer_name
     FROM student_leave_requests r
     JOIN students s ON r.student_id = s.id
     LEFT JOIN users u ON r.reviewed_by = u.id
     ORDER BY r.created_at DESC"
);
$stmt->execute();
$requests = $stmt->fetchAll();

$pendingCount = 0;
foreach ($requests as $reqItem) {
    if (($reqItem['status'] ?? '') === 'pending') {
        $pendingCount++;
    }
}

require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.req-review-form {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 175px;
}
.req-review-form input[type="text"] {
    height: 30px;
    padding: 4px 8px;
    font-size: 12.5px;
}
</style>

<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Student Requests (Leaves &amp; Extensions)</h2>
            <small class="muted">Leave and course extension requests submitted from the Student Portal.</small>
        </div>
        <div class="action-group">
            <?php if ($pendingCount > 0): ?>
                <span class="badge badge-amber"><?= $pendingCount ?> Pending</span>
            <?php endif; ?>
            <span class="badge badge-gray"><?= count($requests) ?> Total</span>
        </div>
    </div>

    <?php if (empty($requests)): ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--muted);">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 8px; opacity: 0.45;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <div style="font-weight: 600; color: var(--text);">No student requests submitted yet</div>
            <div style="font-size: 12.5px; margin-top: 2px;">Leave and extension requests submitted from the Student Portal will appear here.</div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Mobile</th>
                        <th>Type</th>
                        <th>Dates / Days</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Admin Note</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req):
                        $typeBadge = $req['leave_type'] === 'leave' ? 'badge-blue' : ($req['leave_type'] === 're_course' ? 'badge-purple' : 'badge-amber');
                    ?>
                        <tr>
                            <td class="muted" style="font-variant-numeric: tabular-nums;">#<?= (int)$req['id'] ?></td>
                            <td>
                                <div style="font-weight: 600; color: var(--text);">
                                    <a href="<?= url('public/student_profile.php?id=' . (int)$req['student_id']) ?>" style="color: inherit; text-decoration: none;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='inherit'"><?= e($req['student_name']) ?></a>
                                </div>
                                <small class="muted"><?= e($req['registration_no'] ?: $req['form_no']) ?></small>
                            </td>
                            <td>
                                <a href="tel:<?= e($req['student_mobile']) ?>" style="color: var(--text-body); font-weight: 500; white-space: nowrap;">
                                    <?= e($req['student_mobile']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge <?= $typeBadge ?>">
                                    <?= strtoupper(str_replace('_', ' ', e($req['leave_type']))) ?>
                                </span>
                            </td>
                            <td style="white-space: nowrap;">
                                <div style="font-weight: 500; color: var(--text);">
                                    <?= date('d-m-Y', strtotime($req['start_date'])) ?> to <?= date('d-m-Y', strtotime($req['end_date'])) ?>
                                </div>
                                <small class="muted"><?= (int)$req['total_days'] ?> Days</small>
                            </td>
                            <td style="max-width: 240px;"><?= e($req['reason']) ?></td>
                            <td>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <span class="badge badge-amber">PENDING</span>
                                <?php elseif ($req['status'] === 'approved'): ?>
                                    <span class="badge badge-green">APPROVED</span>
                                <?php else: ?>
                                    <span class="badge badge-red">REJECTED</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 180px;">
                                <?= $req['admin_note'] ? e($req['admin_note']) : '<span class="muted">-</span>' ?>
                            </td>
                            <td class="text-right">
                                <?php if ($req['status'] === 'pending'): ?>
                                    <form method="POST" action="" class="req-review-form" style="margin-left: auto;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                        <input type="text" name="admin_note" placeholder="Admin note...">
                                        <div class="action-group" style="justify-content: flex-end;">
                                            <button type="submit" name="action" value="approved" class="btn btn-sm btn-success">Approve</button>
                                            <button type="submit" name="action" value="rejected" class="btn btn-sm btn-danger-ghost">Reject</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                                        <small class="muted">Reviewed by <?= e($req['reviewer_name'] ?: 'Admin') ?></small>
                                        <?php if ($req['status'] === 'approved' && $req['leave_type'] !== 'leave'): ?>
                                            <a href="<?= url('public/student_profile.php?id=' . (int)$req['student_id']) ?>" class="btn btn-sm btn-secondary">Open Profile to Extend &rarr;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
