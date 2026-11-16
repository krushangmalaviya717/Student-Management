<?php
$pageTitle = 'સૂચના બોર્ડ - Devangi Sewing Classes';
$activePage = 'notices';
require_once __DIR__ . '/includes/header.php';
require_student_login();

reload_student_session();
$s = current_student();

// Fetch notices for student's batch or all batches
$stmtNotices = db()->prepare(
    "SELECT * FROM student_notices 
     WHERE batch_id IS NULL OR batch_id = ? 
     ORDER BY created_at DESC"
);
$stmtNotices->execute([$s['joined_batch_id']]);
$notices = $stmtNotices->fetchAll();

$priorityBadges = [
    'normal' => '<span class="badge bg-secondary">Notice</span>',
    'important' => '<span class="badge bg-warning text-dark">Important</span>',
    'urgent' => '<span class="badge bg-danger">Urgent</span>',
];
?>

<div class="card-app">
    <div class="card-title">
        <span><i class="bi bi-megaphone-fill me-2 text-primary"></i> Notice Board &amp; Announcements</span>
    </div>

    <?php if (empty($notices)): ?>
        <div class="text-center text-muted py-4 small">
            No active notices at this time.
        </div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($notices as $n): ?>
                <div class="list-group-item px-0 py-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <?= $priorityBadges[$n['priority']] ?? '<span class="badge bg-secondary">સૂચના</span>' ?>
                        <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d-m-Y', strtotime($n['created_at'])) ?></small>
                    </div>
                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($n['title']) ?></h6>
                    <div class="small text-muted" style="white-space: pre-line;"><?= htmlspecialchars($n['content']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
