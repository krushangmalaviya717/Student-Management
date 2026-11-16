<?php
$pageTitle = 'Student Leave & Extension Requests';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';

// Handle Approval / Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($requestId > 0 && in_array($action, ['approved', 'rejected'])) {
        $stmt = db()->prepare(
            "UPDATE student_leave_requests 
             SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() 
             WHERE id = ?"
        );
        $stmt->execute([$action, $adminNote, current_user()['id'], $requestId]);

        flash('success', "Request marked as " . strtoupper($action));
        redirect('public/student_requests.php');
        exit;
    }
}

// Fetch all requests
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

require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2>Student Requests (Leaves & Extensions)</h2>
    </div>
    <div class="card-body">
        <?php if (empty($requests)): ?>
            <p class="text-muted">No student requests submitted yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
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
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                            <tr>
                                <td>#<?= $req['id'] ?></td>
                                <td>
                                    <strong><?= e($req['student_name']) ?></strong><br>
                                    <small class="text-muted"><?= e($req['registration_no'] ?: $req['form_no']) ?></small>
                                </td>
                                <td><?= e($req['student_mobile']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $req['leave_type'] === 'leave' ? 'info' : ($req['leave_type'] === 're_course' ? 'primary' : 'warning') ?>">
                                        <?= strtoupper(str_replace('_', ' ', $req['leave_type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d-m-Y', strtotime($req['start_date'])) ?> to <?= date('d-m-Y', strtotime($req['end_date'])) ?><br>
                                    <small class="text-muted">(<?= $req['total_days'] ?> Days)</small>
                                </td>
                                <td><?= e($req['reason']) ?></td>
                                <td>
                                    <?php if ($req['status'] === 'pending'): ?>
                                        <span class="badge badge-warning">PENDING</span>
                                    <?php elseif ($req['status'] === 'approved'): ?>
                                        <span class="badge badge-success">APPROVED</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">REJECTED</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($req['admin_note'] ?: '-') ?></td>
                                <td>
                                    <?php if ($req['status'] === 'pending'): ?>
                                        <form method="POST" action="" style="display:inline-block;">
                                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                            <input type="text" name="admin_note" placeholder="Admin note..." style="width:140px; height:30px; font-size:12px; margin-bottom:6px; display:block;">
                                            <div style="display:flex; gap:4px;">
                                                <button type="submit" name="action" value="approved" class="btn btn-sm" style="background:#16A34A; color:#fff; border:none; padding:3px 8px; font-size:11.5px;">Approve</button>
                                                <button type="submit" name="action" value="rejected" class="btn btn-sm btn-danger" style="padding:3px 8px; font-size:11.5px;">Reject</button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <small class="text-muted">Reviewed by <?= e($req['reviewer_name'] ?: 'Admin') ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
