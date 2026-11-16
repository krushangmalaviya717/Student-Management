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

    <!-- PWA Install Banner (Hidden by default, shown via JS if available) -->
    <div id="pwaInstallCard" class="card-app mb-4" style="display:none; padding: 16px; background: linear-gradient(135deg, #0b132b 0%, #1e3a8a 100%); color: white; border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 12px;">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-bold fs-6"><i class="bi bi-phone"></i> એપ ડાઉનલોડ કરો</div>
                <div class="small opacity-75" style="font-size: 0.75rem;">હોમ સ્ક્રીન પર ઉમેરો</div>
            </div>
            <button class="btn btn-sm bg-white rounded-pill px-3 fw-bold" style="color: #1e3a8a;" onclick="document.getElementById('pwaInstallBtn').click();">
                ઇન્સ્ટોલ
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
