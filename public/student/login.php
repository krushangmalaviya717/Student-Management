<?php
$pageTitle = 'સ્ટુડન્ટ લોગિન - Devangi Classes';
require_once __DIR__ . '/includes/header.php';

if (is_student_logged_in()) {
    redirect('public/student/dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = $_POST['identifier'] ?? '';
    $password = $_POST['password'] ?? '';

    $error = attempt_student_login($identifier, $password);
    if (!$error) {
        redirect('public/student/dashboard.php');
        exit;
    }
}
?>

<div class="login-screen">
    
    <div class="login-header">
        <div class="login-logo">
            <i class="bi bi-scissors"></i>
        </div>
        <h1 class="login-title">Devangi Classes</h1>
        <p class="login-subtitle">સ્ટુડન્ટ મેનેજમેન્ટ પોર્ટલ</p>
    </div>

    <!-- PWA Install Banner (Mandatory on mobile browser) -->
    <div id="pwaInstallCard" class="card-app mb-4" style="padding: 16px; background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 14px; box-shadow: 0 4px 12px rgba(30, 58, 138, 0.25);">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <div style="background: rgba(255,255,255,0.2); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
                    <i class="bi bi-phone"></i>
                </div>
                <div>
                    <div class="fw-bold" style="font-size: 0.95rem;">એપ ડાઉનલોડ કરો</div>
                    <div class="small opacity-75" style="font-size: 0.75rem;">હોમ સ્ક્રીન પર ઉમેરીને વાપરો</div>
                </div>
            </div>
            <button type="button" class="btn btn-sm bg-white rounded-pill px-3 py-2 fw-bold pwa-install-trigger" style="color: #1e3a8a; box-shadow: 0 2px 6px rgba(0,0,0,0.15);" onclick="triggerPwaInstall();">
                <i class="bi bi-download me-1"></i> ઇન્સ્ટોલ
            </button>
        </div>
    </div>

    <div class="login-card">
        <h2 class="fs-5 fw-bold mb-4 text-center">Student Portal Login</h2>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small rounded-3 mb-4" style="background: #FEF2F2; color: #991B1B; border: 1px solid #F87171;">
                <i class="bi bi-exclamation-circle-fill me-1"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">Mobile Number or Registration ID</label>
                <div class="position-relative">
                    <i class="bi bi-person position-absolute" style="top: 17px; left: 16px; color: #94A3B8; font-size: 1.1rem;"></i>
                    <input type="text" name="identifier" class="form-control-app" style="padding-left: 45px;" placeholder="Enter 10-digit mobile or ID" required value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group mb-4">
                <label class="form-label">Password</label>
                <div class="position-relative">
                    <i class="bi bi-lock position-absolute" style="top: 17px; left: 16px; color: #94A3B8; font-size: 1.1rem;"></i>
                    <input type="password" name="password" class="form-control-app" style="padding-left: 45px;" placeholder="••••••••" required>
                </div>
                <div class="mt-2 text-end">
                    <small class="text-muted" style="font-size: 0.75rem;">First-time login? Use your registered mobile number as password.</small>
                </div>
            </div>

            <button type="submit" class="btn-app">
                Login <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </form>
    </div>

    <div class="text-center mt-5 text-muted" style="font-size: 0.8rem;">
        &copy; <?= date('Y') ?> Devangi Sewing Classes. All rights reserved.
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
