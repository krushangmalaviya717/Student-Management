<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/student_auth.php';

$student = current_student();
$activePage = $activePage ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($pageTitle ?? 'Student Portal - Devangi Sewing Classes') ?></title>
    
    <!-- PWA Manifest & Meta Tags -->
    <link rel="manifest" href="/public/student/manifest.json">
    <meta name="theme-color" content="#1e3a8a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Devangi Classes">
    <link rel="apple-touch-icon" href="https://cdn-icons-png.flaticon.com/512/3135/3135715.png">

    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Custom Student CSS with Cache Busting -->
    <link rel="stylesheet" href="/public/student/assets/css/student.css?v=<?= filemtime(__DIR__ . '/../assets/css/student.css') ?>">
</head>
<body>

<?php if ($student): ?>
<!-- Top Header -->
<header class="app-header">
    <a href="/public/student/dashboard.php" class="brand">
        <i class="bi bi-scissors"></i>
        <span>Devangi Classes</span>
    </a>
    <div class="header-actions">
        <!-- PWA Install button in header -->
        <button id="pwaInstallBtn" class="btn btn-sm btn-light text-primary rounded-pill px-3 fw-bold" style="display:none; font-size: 0.8rem;">
            <i class="bi bi-download me-1"></i> Install App
        </button>
        <a href="/public/student/notices.php" class="text-secondary text-decoration-none position-relative me-2 fs-5" title="Notices">
            <i class="bi bi-bell"></i>
        </a>
        <a href="/public/student/logout.php" class="text-secondary text-decoration-none fs-5" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</header>
<?php endif; ?>

<?php if ($student): ?>
<div class="container-app">
<?php endif; ?>
