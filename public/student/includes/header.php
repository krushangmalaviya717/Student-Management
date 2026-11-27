<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/student_auth.php';

$student = current_student();
$activePage = $activePage ?? 'dashboard';
$showPublicHeader = $showPublicHeader ?? false;
$backUrl = $backUrl ?? ($student ? '/public/student/dashboard.php' : '/public/student/login.php');
$showBackBtn = $showBackBtn ?? ($activePage !== 'dashboard' && $activePage !== 'login');
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($pageTitle ?? 'Student Portal - Devangi Sewing Classes') ?></title>
    
    <!-- PWA Manifest & Meta Tags -->
    <link rel="manifest" href="/public/student/manifest.json?v=7">
    <meta name="theme-color" content="#1e3a8a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Devangi Classes">
    <link rel="apple-touch-icon" href="/public/student/assets/icon-192.png">

    <!-- Load PWA script early in head so beforeinstallprompt is never missed -->
    <script src="/public/student/install.js?v=<?= time() ?>"></script>
    <script>
    function studentGoBack(e, fallbackUrl) {
        if (window.history.length > 1 && document.referrer && document.referrer.indexOf(window.location.host) !== -1) {
            if (e) e.preventDefault();
            window.history.back();
            return false;
        }
        return true;
    }
    </script>

    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Custom Student CSS with Cache Busting v2 -->
    <link rel="stylesheet" href="/public/student/assets/css/student.css?v=<?= time() ?>">
</head>
<body>

<?php if ($student): ?>
<!-- Top Header -->
<header class="app-header">
    <div class="header-left">
        <?php if ($showBackBtn): ?>
            <a href="<?= htmlspecialchars($backUrl) ?>" onclick="return studentGoBack(event, '<?= htmlspecialchars($backUrl) ?>');" class="header-back-btn" aria-label="Back" title="પાછા જાઓ (Back)">
                <i class="bi bi-arrow-left"></i>
            </a>
        <?php endif; ?>
        <a href="/public/student/dashboard.php" class="brand">
            <?php if (!$showBackBtn): ?><i class="bi bi-scissors"></i><?php endif; ?>
            <span>Devangi Classes</span>
        </a>
    </div>
    <div class="header-actions">
        <!-- PWA Install button in header -->
        <button id="pwaInstallBtn" type="button" class="btn btn-sm btn-light text-primary rounded-pill px-3 fw-bold pwa-install-trigger" style="font-size: 0.8rem;" onclick="triggerPwaInstall();">
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
<?php elseif ($showPublicHeader): ?>
<!-- Public Visitor Top Header with Back Button -->
<header class="app-header">
    <div class="header-left">
        <a href="<?= htmlspecialchars($backUrl) ?>" onclick="return studentGoBack(event, '<?= htmlspecialchars($backUrl) ?>');" class="header-back-btn" aria-label="Back" title="પાછા જાઓ (Back)">
            <i class="bi bi-arrow-left"></i>
        </a>
        <a href="/public/student/login.php" class="brand">
            <span>Devangi Classes</span>
        </a>
    </div>
    <div class="header-actions">
        <a href="/public/student/login.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" style="font-size: 0.8rem;">
            <i class="bi bi-box-arrow-in-right me-1"></i> Login
        </a>
    </div>
</header>
<?php endif; ?>

<?php if ($student): ?>
<div class="container-app">
<?php endif; ?>
