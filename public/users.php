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
            flash('success', 'User created.');
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
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2 class="mt-0">Add User</h2>
    <form method="post" class="row3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_user">
        <div><label>Name</label><input type="text" name="name" required></div>
        <div><label>Email</label><input type="email" name="email" required></div>
        <div><label>Password</label><input type="password" name="password" required minlength="8"></div>
        <div><label>Role</label>
            <select name="role_id">
                <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['display_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Create User</button></div>
    </form>
</div>

<div class="card">
    <table>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['role_display']) ?></td>
            <td><span class="badge badge-<?= $u['status']==='active'?'green':'red' ?>"><?= ucfirst($u['status']) ?></span></td>
            <td><?= $u['last_login_at'] ? format_date($u['last_login_at']) : '-' ?></td>
            <td>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button class="btn btn-sm btn-secondary" type="submit" <?= $u['id']==current_user()['id']?'disabled':'' ?>>Toggle Status</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
