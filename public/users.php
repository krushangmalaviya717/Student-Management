<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_user') {
            $stmt = $pdo->prepare("INSERT INTO users (role_id, name, email, password_hash, status) VALUES (?,?,?,?,'active')");
            $stmt->execute([
                (int)$_POST['role_id'], trim($_POST['name']), trim($_POST['email']),
                password_hash($_POST['password'], PASSWORD_DEFAULT),
            ]);
            log_audit('user', (int)$pdo->lastInsertId(), 'create', null, ['email' => $_POST['email']]);
            flash('success', 'User created successfully.');
        } elseif ($action === 'toggle_status') {
            $uid = (int)$_POST['user_id'];
            $pdo->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$uid]);
            log_audit('user', $uid, 'toggle_status', null, null);
            flash('success', 'User status updated.');
        }
        redirect('public/users.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$users = $pdo->query("SELECT u.*, r.display_name AS role_display FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.created_at DESC")->fetchAll();
$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();

$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-header-subtitle">Create admin accounts, assign system roles, and manage login access.</p>
    </div>
</div>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<!-- Add User Card -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Add User</h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Create a new administrator or staff portal account.</p>
        </div>
    </div>

    <form method="post" class="row">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_user">

        <div class="form-group">
            <label for="usr_name">Full Name *</label>
            <input type="text" id="usr_name" name="name" required placeholder="e.g. Priya Shah">
        </div>
        <div class="form-group">
            <label for="usr_email">Email Address *</label>
            <input type="email" id="usr_email" name="email" required placeholder="user@example.com">
        </div>
        <div class="form-group">
            <label for="usr_password">Password *</label>
            <input type="password" id="usr_password" name="password" required minlength="8" placeholder="Minimum 8 characters">
        </div>
        <div class="form-group">
            <label for="usr_role">Role *</label>
            <select id="usr_role" name="role_id">
                <?php foreach ($roles as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= e($r['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions col-full" style="margin-top: 4px;">
            <button class="btn" type="submit">Create User</button>
        </div>
    </form>
</div>

<!-- System Users Table -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>System Users</h2>
        </div>
        <span class="badge badge-secondary"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></span>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="6" class="muted" style="text-align: center; padding: 30px;">No users found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <?php $isSelf = ((int)$u['id'] === (int)current_user()['id']); ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--text);">
                                <?= e($u['name']) ?>
                                <?php if ($isSelf): ?>
                                    <span class="badge badge-purple" style="margin-left: 6px;">You</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($u['email']) ?></td>
                            <td>
                                <span class="badge badge-gray"><?= e($u['role_display']) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $u['status'] === 'active' ? 'green' : 'red' ?>">
                                    <?= ucfirst($u['status']) ?>
                                </span>
                            </td>
                            <td style="font-variant-numeric: tabular-nums; color: var(--muted);">
                                <?= $u['last_login_at'] ? format_date($u['last_login_at']) : 'Never' ?>
                            </td>
                            <td class="text-right" style="white-space: nowrap;">
                                <form method="post" style="display: inline; margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button class="btn btn-sm btn-secondary" type="submit" <?= $isSelf ? 'disabled title="You cannot deactivate your own account"' : '' ?>>
                                        <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
