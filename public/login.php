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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Devangi Sewing Classes</title>
<link rel="stylesheet" href="<?= url('public/assets/css/app.css') ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1>Devangi Sewing Classes</h1>
    <p>Student Management System</p>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Email</label>
        <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
        <label>Password</label>
        <input type="password" name="password" required>
        <button type="submit" class="btn" style="width:100%;margin-top:20px;">Log In</button>
    </form>
  </div>
</div>
</body>
</html>
