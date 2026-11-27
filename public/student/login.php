<?php
$pageTitle = 'સ્ટુડન્ટ લોગિન - Devangi Classes';
$activePage = 'login';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/student_auth.php';

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

require_once __DIR__ . '/includes/header.php';
?>

<div class="login-screen">
    
    <div class="login-header">
        <div class="login-logo">
            <i class="bi bi-scissors"></i>
        </div>
        <h1 class="login-title">Devangi Classes</h1>
        <p class="login-subtitle">સ્ટુડન્ટ મેનેજમેન્ટ પોર્ટલ</p>
    </div>

    <!-- PWA Install Banner (Prominent on mobile browser) -->
    <div id="pwaInstallCard" class="pwa-install-card">
        <div class="d-flex align-items-center gap-3">
            <div style="background: rgba(255,255,255,0.18); width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
                <i class="bi bi-phone-fill"></i>
            </div>
            <div>
                <div class="fw-bold" style="font-size: 1.05rem;">Devangi Classes Mobile App</div>
                <div class="opacity-75" style="font-size: 0.82rem;">ફોનમાં એપની જેમ વાપરવા માટે હોમ સ્ક્રીન પર ઇન્સ્ટોલ કરો</div>
            </div>
        </div>
        <button type="button" class="pwa-install-big-btn pwa-install-trigger" onclick="triggerPwaInstall();">
            <i class="bi bi-download fs-5"></i>
            <span>મોબાઈલ એપ ઇન્સ્ટોલ કરો (Install App)</span>
        </button>
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

    <!-- New Student / Unregistered Visitor Section -->
    <div class="visitor-card">
        <div class="text-center mb-3">
            <div class="fw-bold text-dark" style="font-size: 1rem;">નવા વિદ્યાર્થી છો? (Not Registered Yet?)</div>
            <div class="text-muted" style="font-size: 0.8rem;">રજીસ્ટ્રેશન વગર પણ તમે એડમિશન ફોર્મ ભરી શકો છો અને ક્લાસની બધી માહિતી જોઈ શકો છો</div>
        </div>
        <div class="d-grid gap-2">
            <a href="/public/student/apply.php" class="visitor-btn-primary">
                <i class="bi bi-pencil-square fs-5"></i>
                <span>નવું એડમિશન ફોર્મ ભરો (New Form)</span>
            </a>
            <a href="/public/student/info.php" class="visitor-btn-outline">
                <i class="bi bi-info-circle fs-5"></i>
                <span>ક્લાસની ફી, બેચ સમય અને નિયમો જુઓ</span>
            </a>
        </div>
    </div>

    <div class="text-center mt-4 text-muted" style="font-size: 0.8rem;">
        &copy; <?= date('Y') ?> Devangi Sewing Classes. All rights reserved.
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
