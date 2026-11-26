<?php
$pageTitle = 'Manage Student Notices';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';

$batches = db()->query("SELECT id, name FROM batches WHERE is_active = 1")->fetchAll();

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

if (isset($_GET['delete'])) {
    $noticeId = (int)$_GET['delete'];
    db()->prepare("DELETE FROM student_notices WHERE id = ?")->execute([$noticeId]);
    flash('success', "Notice deleted.");
    redirect('public/notices_admin.php');
    exit;
}

$notices = db()->query(
    "SELECT n.*, b.name AS batch_name, u.name AS creator_name 
     FROM student_notices n 
     LEFT JOIN batches b ON n.batch_id = b.id
     LEFT JOIN users u ON n.created_by = u.id
     ORDER BY n.created_at DESC"
)->fetchAll();

require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.notice-feed {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.notice-item {
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    background: var(--card-bg);
}
.notice-item-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}
.notice-item-title {
    margin: 0;
    font-size: 14.5px;
    font-weight: 700;
    color: var(--text);
}
.notice-item-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 10px;
}
.notice-item-body {
    margin: 0;
    font-size: 13.5px;
    color: var(--text-body);
    line-height: 1.55;
}
.empty-state-box {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted);
}
.empty-state-box svg {
    margin-bottom: 8px;
    opacity: 0.45;
}
</style>

<div class="layout-split">
    <!-- Create Notice Form -->
    <div class="card">
        <div class="card-header-bar" style="margin-bottom: 14px;">
            <div>
                <h2>Post New Notice</h2>
                <small class="muted">Publish an announcement to the Student Portal.</small>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="create_notice" value="1">

            <div class="form-group">
                <label for="notice_title">Notice Title *</label>
                <input type="text" id="notice_title" name="title" required placeholder="e.g. Diwali Vacation Notice">
            </div>

            <div class="form-group">
                <label for="notice_batch">Target Batch</label>
                <select id="notice_batch" name="batch_id">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="notice_priority">Priority Level</label>
                <select id="notice_priority" name="priority">
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>

            <div class="form-group">
                <label for="notice_content">Notice Content / Message *</label>
                <textarea id="notice_content" name="content" rows="4" required placeholder="Enter full announcement message..."></textarea>
            </div>

            <div class="form-actions" style="margin-top: 16px;">
                <button type="submit" class="btn btn-block">Publish Notice</button>
            </div>
        </form>
    </div>

    <!-- Notices List -->
    <div class="card">
        <div class="card-header-bar">
            <div>
                <h2>Published Student Notices</h2>
                <small class="muted">All active announcements visible to students.</small>
            </div>
            <span class="badge badge-purple"><?= count($notices) ?> Published</span>
        </div>

        <?php if (empty($notices)): ?>
            <div class="empty-state-box">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <div style="font-weight: 600; color: var(--text);">No notices posted yet</div>
                <div style="font-size: 12.5px; margin-top: 2px;">Use the form on the left to publish your first student notice.</div>
            </div>
        <?php else: ?>
            <div class="notice-feed">
                <?php foreach ($notices as $n):
                    $prioBadge = $n['priority'] === 'urgent' ? 'badge-red' : ($n['priority'] === 'important' ? 'badge-amber' : 'badge-gray');
                ?>
                    <article class="notice-item">
                        <div class="notice-item-head">
                            <h3 class="notice-item-title"><?= e($n['title']) ?></h3>
                            <div class="action-group">
                                <span class="badge <?= $prioBadge ?>"><?= strtoupper(e($n['priority'])) ?></span>
                                <a href="?delete=<?= (int)$n['id'] ?>" onclick="return confirm('Delete this notice?')" class="btn btn-sm btn-danger-ghost">Delete</a>
                            </div>
                        </div>
                        <div class="notice-item-meta">
                            <span>Target: <strong><?= e($n['batch_name'] ?: 'All Batches') ?></strong></span>
                            <span>&bull;</span>
                            <span>Posted: <?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></span>
                            <?php if (!empty($n['creator_name'])): ?>
                                <span>&bull;</span>
                                <span>By <?= e($n['creator_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="notice-item-body"><?= nl2br(e($n['content'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
