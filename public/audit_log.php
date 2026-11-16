<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

$pdo = db();
$entityType = trim($_GET['entity_type'] ?? '');
$action = trim($_GET['action_name'] ?? '');
$userId = (int)($_GET['user_id'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$sql = "SELECT a.*, u.name AS user_name, r.display_name AS role_name
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE 1=1";
$params = [];

if ($entityType !== '') {
    $sql .= " AND a.entity_type = ?";
    $params[] = $entityType;
}
if ($action !== '') {
    $sql .= " AND a.action = ?";
    $params[] = $action;
}
if ($userId > 0) {
    $sql .= " AND a.user_id = ?";
    $params[] = $userId;
}
if ($from !== '') {
    $sql .= " AND a.created_at >= ?";
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $sql .= " AND a.created_at <= ?";
    $params[] = $to . ' 23:59:59';
}

$sql .= " ORDER BY a.created_at DESC LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Users for filter dropdown
$users = $pdo->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll();

$pageTitle = 'Audit Trail & Activity Log';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
        <div>
            <h2 class="mt-0" style="margin-bottom:4px;">System Audit Trail</h2>
            <div class="muted">Permanent, tamper-evident log of administrative actions, status changes, payments, holidays, and extensions.</div>
        </div>
        <div>
            <span class="badge badge-purple"><?= count($logs) ?> log entries</span>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:20px;">
    <form method="get" class="row3">
        <div>
            <label style="margin-top:0;">Entity Type</label>
            <select name="entity_type">
                <option value="">All Entities</option>
                <option value="student" <?= $entityType === 'student' ? 'selected' : '' ?>>Student</option>
                <option value="payment" <?= $entityType === 'payment' ? 'selected' : '' ?>>Payment</option>
                <option value="extension" <?= $entityType === 'extension' ? 'selected' : '' ?>>Extension</option>
                <option value="holiday" <?= $entityType === 'holiday' ? 'selected' : '' ?>>Holiday</option>
                <option value="queue" <?= $entityType === 'queue' ? 'selected' : '' ?>>Waiting Queue</option>
                <option value="batch" <?= $entityType === 'batch' ? 'selected' : '' ?>>Batch</option>
                <option value="settings" <?= $entityType === 'settings' ? 'selected' : '' ?>>Settings</option>
                <option value="user" <?= $entityType === 'user' ? 'selected' : '' ?>>User</option>
                <option value="photo" <?= $entityType === 'photo' ? 'selected' : '' ?>>Photo</option>
            </select>
        </div>

        <div>
            <label style="margin-top:0;">Action</label>
            <input type="text" name="action_name" value="<?= e($action) ?>" placeholder="e.g. create, update, joining...">
        </div>

        <div>
            <label style="margin-top:0;">Performed By</label>
            <select name="user_id">
                <option value="0">All Users</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="margin-top:0;">From Date</label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>

        <div>
            <label style="margin-top:0;">To Date</label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Filter Logs</button>
            <a href="<?= url('public/audit_log.php') ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Log Entries Table -->
<div class="card">
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th style="width:150px;">Timestamp</th>
                    <th>User</th>
                    <th>Entity</th>
                    <th>Action</th>
                    <th>IP Address</th>
                    <th>Change Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= date('d-M-Y H:i:s', strtotime($l['created_at'])) ?></td>
                    <td>
                        <strong><?= e($l['user_name'] ?? 'System / Anonymous') ?></strong>
                        <?php if ($l['role_name']): ?>
                            <br><small class="muted"><?= e($l['role_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-gray"><?= strtoupper($l['entity_type']) ?></span>
                        <?php if ($l['entity_id']): ?>
                            <br><small>ID: <?= (int)$l['entity_id'] ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-purple"><?= e($l['action']) ?></span>
                    </td>
                    <td><small><?= e($l['ip_address'] ?? '-') ?></small></td>
                    <td style="max-width:380px;">
                        <?php 
                        $before = json_decode($l['before_values'] ?? '', true);
                        $after = json_decode($l['after_values'] ?? '', true);
                        ?>
                        <?php if ($after): ?>
                            <details style="font-size:12px; cursor:pointer;">
                                <summary style="color:#2563eb; font-weight:600;">View JSON Details (<?= count($after) ?> fields)</summary>
                                <div style="background:#f8fafc; padding:8px; border-radius:4px; margin-top:4px; font-family:monospace; font-size:11px; white-space:pre-wrap; border:1px solid #e2e8f0;">
<?php if ($before): ?><strong>Before:</strong>
<?= e(json_encode($before, JSON_PRETTY_PRINT)) ?>
<hr style="border:0; border-top:1px dashed #cbd5e1; margin:6px 0;">
<?php endif; ?><strong>After / Parameters:</strong>
<?= e(json_encode($after, JSON_PRETTY_PRINT)) ?>
                                </div>
                            </details>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (!$logs): ?>
                <tr>
                    <td colspan="6" class="muted" style="text-align:center; padding:24px;">
                        No audit events recorded yet for this criteria.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
