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
$hasFilters = ($entityType !== '' || $action !== '' || $userId > 0 || $from !== '' || $to !== '');

$pageTitle = 'Audit Trail & Activity Log';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.audit-json-summary {
    color: var(--primary);
    font-weight: 600;
    font-size: 12px;
    cursor: pointer;
}
.audit-json-box {
    background: var(--surface-subtle);
    padding: 10px;
    border-radius: var(--radius);
    margin-top: 6px;
    font-family: monospace;
    font-size: 11px;
    white-space: pre-wrap;
    border: 1px solid var(--border);
    max-height: 240px;
    overflow-y: auto;
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-header-subtitle">Permanent audit log of administrative actions, status changes, payments, holidays, and extensions.</p>
    </div>
    <div class="action-group">
        <span class="badge badge-purple"><?= count($logs) ?> log entr<?= count($logs) === 1 ? 'y' : 'ies' ?></span>
    </div>
</div>

<!-- Filters Card -->
<div class="card" style="padding: 16px 20px;">
    <form method="get" class="row3">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="audit_entity">Entity Type</label>
            <select id="audit_entity" name="entity_type">
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

        <div class="form-group" style="margin-bottom: 0;">
            <label for="audit_action">Action</label>
            <input type="text" id="audit_action" name="action_name" value="<?= e($action) ?>" placeholder="e.g. create, update, joining...">
        </div>

        <div class="form-group" style="margin-bottom: 0;">
            <label for="audit_user">Performed By</label>
            <select id="audit_user" name="user_id">
                <option value="0">All Users</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
            <label for="audit_from">From Date</label>
            <input type="date" id="audit_from" name="from" value="<?= e($from) ?>">
        </div>

        <div class="form-group" style="margin-bottom: 0;">
            <label for="audit_to">To Date</label>
            <input type="date" id="audit_to" name="to" value="<?= e($to) ?>">
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Filter Logs</button>
            <?php if ($hasFilters): ?>
                <a href="<?= url('public/audit_log.php') ?>" class="btn btn-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Log Entries Table -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>System Audit Trail</h2>
        </div>
        <span class="muted" style="font-size: 12px;">Showing latest up to 300 events</span>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="min-width: 155px;">Timestamp</th>
                    <th>User</th>
                    <th>Entity</th>
                    <th>Action</th>
                    <th>IP Address</th>
                    <th>Change Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="6" class="muted" style="text-align: center; padding: 36px 20px;">
                            No audit events match the selected filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td style="white-space: nowrap; font-variant-numeric: tabular-nums; font-size: 12.5px;">
                                <?= date('d M Y H:i:s', strtotime($l['created_at'])) ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--text);"><?= e($l['user_name'] ?? 'System / Anonymous') ?></div>
                                <?php if ($l['role_name']): ?>
                                    <div style="font-size: 11.5px; color: var(--muted);"><?= e($l['role_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap;">
                                <span class="badge badge-gray"><?= strtoupper($l['entity_type']) ?></span>
                                <?php if ($l['entity_id']): ?>
                                    <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">ID: <?= (int)$l['entity_id'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap;">
                                <span class="badge badge-purple"><?= e($l['action']) ?></span>
                            </td>
                            <td style="font-family: monospace; font-size: 12px; color: var(--muted);">
                                <?= e($l['ip_address'] ?? '—') ?>
                            </td>
                            <td style="max-width: 380px;">
                                <?php
                                $before = json_decode($l['before_values'] ?? '', true);
                                $after = json_decode($l['after_values'] ?? '', true);
                                ?>
                                <?php if ($after): ?>
                                    <details>
                                        <summary class="audit-json-summary">View JSON Details (<?= count($after) ?> fields)</summary>
                                        <div class="audit-json-box"><?php if ($before): ?><strong>Before:</strong>
<?= e(json_encode($before, JSON_PRETTY_PRINT)) ?>
<hr style="border: 0; border-top: 1px dashed var(--border); margin: 6px 0;"><?php endif; ?><strong>After / Parameters:</strong>
<?= e(json_encode($after, JSON_PRETTY_PRINT)) ?></div>
                                    </details>
                                <?php else: ?>
                                    <span class="muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
