<?php
require_once __DIR__ . '/../config/config.php';

/** Roles, from least to most privileged. */
const ROLE_STAFF = 'staff';
const ROLE_ADMIN = 'admin';
const ROLE_SUPER_ADMIN = 'super_admin';

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function current_role(): ?string
{
    return $_SESSION['user']['role_name'] ?? null;
}

function is_super_admin(): bool
{
    return current_role() === ROLE_SUPER_ADMIN;
}

function is_admin_or_above(): bool
{
    return in_array(current_role(), [ROLE_ADMIN, ROLE_SUPER_ADMIN], true);
}

/** Call at the top of any page that requires login. */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('public/login.php');
        exit;
    }
}

/** Call at the top of any page restricted to Admin/Super Admin. */
function require_admin(): void
{
    require_login();
    if (!is_admin_or_above()) {
        http_response_code(403);
        die('Access denied. This page requires Admin or Super Admin privileges.');
    }
}

/** Call at the top of any page restricted to Super Admin only. */
function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        http_response_code(403);
        die('Access denied. This page requires Super Admin privileges.');
    }
}

function attempt_login(string $email, string $password): ?string
{
    $stmt = db()->prepare(
        "SELECT u.id, u.name, u.email, u.password_hash, u.status, r.name AS role_name, r.display_name AS role_display
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return 'No account found with that email.';
    }
    if ($user['status'] !== 'active') {
        return 'This account is inactive. Contact your Super Admin.';
    }
    if (!password_verify($password, $user['password_hash'])) {
        return 'Incorrect password.';
    }

    unset($user['password_hash']);
    $_SESSION['user'] = $user;

    db()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
    log_audit('user', (int)$user['id'], 'login', null, null);

    return null; // success
}

function logout(): void
{
    if (isset($_SESSION['user'])) {
        log_audit('user', (int)$_SESSION['user']['id'], 'logout', null, null);
    }
    $_SESSION = [];
    session_destroy();
}

/**
 * Write an audit log entry. old/new values should be associative arrays or null.
 */
function log_audit(string $entityType, ?int $entityId, string $action, ?array $old, ?array $new): void
{
    $userId = current_user()['id'] ?? null;
    $stmt = db()->prepare(
        "INSERT INTO audit_logs (user_id, entity_type, entity_id, action, old_values, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $userId,
        $entityType,
        $entityId,
        $action,
        $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
        $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
