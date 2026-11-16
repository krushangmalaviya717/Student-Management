<?php
$pageTitle = 'Manage Student Notices';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';

// Fetch batches for filter dropdown
$batches = db()->query("SELECT id, name FROM batches WHERE is_active = 1")->fetchAll();

// Handle creating new notice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_notice'])) {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $batchId = !empty($_POST['batch_id']) ? (int)$_POST['batch_id'] : null;
    $priority = $_POST['priority'] ?? 'normal';

    if (!empty($title) && !empty($content)) {
        $stmt = db()->prepare(
            "INSERT INTO student_notices (title, content, batch_id, priority, created_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$title, $content, $batchId, $priority, current_user()['id']]);

        flash('success', "Notice published successfully!");
        redirect('public/notices_admin.php');
        exit;
    }
}

// Handle deleting notice
if (isset($_GET['delete'])) {
    $noticeId = (int)$_GET['delete'];
    db()->prepare("DELETE FROM student_notices WHERE id = ?")->execute([$noticeId]);
    flash('success', "Notice deleted.");
    redirect('public/notices_admin.php');
    exit;
}

// Fetch all notices
$notices = db()->query(
    "SELECT n.*, b.name AS batch_name, u.name AS creator_name 
     FROM student_notices n 
     LEFT JOIN batches b ON n.batch_id = b.id
     LEFT JOIN users u ON n.created_by = u.id
     ORDER BY n.created_at DESC"
)->fetchAll();

require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="row">
    <!-- Create Notice Form -->
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <h3>Post New Notice</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="create_notice" value="1">
                    
                    <div class="form-group mb-3">
                        <label class="form-label">Notice Title</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Diwali Vacation Notice">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Target Batch</label>
                        <select name="batch_id" class="form-control">
                            <option value="">All Batches (તમામ સ્ટુડન્ટ્સ)</option>
                            <?php foreach ($batches as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Priority Level</label>
                        <select name="priority" class="form-control">
                            <option value="normal">Normal (સામાન્ય)</option>
                            <option value="important">Important (અગત્યનું)</option>
                            <option value="urgent">Urgent (અતિ અગત્યનું)</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Notice Content / Message</label>
                        <textarea name="content" class="form-control" rows="4" required placeholder="સૂચનાની વિગત લખો..."></textarea>
                    </div>

                    <div style="margin-top:16px;">
                        <button type="submit" class="btn btn-primary w-100">Publish Notice</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Notices List -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3>Published Student Notices</h3>
            </div>
            <div class="card-body">
                <?php if (empty($notices)): ?>
                    <p class="text-muted">No notices posted yet.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($notices as $n): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <h5 class="mb-0 fw-bold"><?= e($n['title']) ?></h5>
                                    <div>
                                        <span class="badge badge-<?= $n['priority'] === 'urgent' ? 'danger' : ($n['priority'] === 'important' ? 'warning' : 'secondary') ?>">
                                            <?= strtoupper($n['priority']) ?>
                                        </span>
                                        <a href="?delete=<?= $n['id'] ?>" onclick="return confirm('Delete this notice?')" class="btn btn-sm btn-outline-danger ms-2">Delete</a>
                                    </div>
                                </div>
                                <div class="text-muted small mb-2">
                                    Target: <strong><?= e($n['batch_name'] ?: 'All Batches') ?></strong> | Posted: <?= date('d-m-Y h:i A', strtotime($n['created_at'])) ?>
                                </div>
                                <p class="mb-0 text-dark"><?= nl2br(e($n['content'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
