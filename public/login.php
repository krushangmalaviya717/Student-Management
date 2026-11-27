<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('public/dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $error = attempt_login($email, $password);
    if (!$error) {
        redirect('public/dashboard.php');
    }
}
$businessName = get_setting('business_name', 'Devangi Sewing Classes');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Class Admin Login - <?= e($businessName) ?></title>
<link rel="stylesheet" href="<?= url('public/assets/css/app.css') ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1><?= e($businessName) ?></h1>
    <p>Class Administration Portal — Authorized staff &amp; admin only.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="login_email">Email</label>
            <input type="email" id="login_email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>" placeholder="admin@example.com">
        </div>
        <div class="form-group">
            <label for="login_password">Password</label>
            <input type="password" id="login_password" name="password" required placeholder="Enter your password">
        </div>
        <button type="submit" class="btn btn-block" style="margin-top: 6px;">Log In</button>
    </form>
  </div>
</div>
</body>
</html>
