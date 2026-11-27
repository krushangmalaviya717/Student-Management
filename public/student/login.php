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
$success = null;
$loginMode = $_POST['login_mode'] ?? ($_GET['mode'] ?? 'otp');
$otpState = $_SESSION['student_login_otp_state'] ?? null;
$isOtpActive = $otpState && (($otpState['sent_at'] ?? 0) > time() - 300);

$step = $_POST['step'] ?? ($isOtpActive ? 'verify_otp' : 'enter_mobile');
$identifier = trim($_POST['identifier'] ?? ($otpState['identifier'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_otp' || $action === 'resend_otp') {
        $loginMode = 'otp';
        $res = send_student_login_otp($identifier);
        if ($res['success']) {
            $step = 'verify_otp';
            $otpState = $_SESSION['student_login_otp_state'] ?? null;
            $success = "તમારા WhatsApp નંબર (" . htmlspecialchars($res['masked_mobile']) . ") પર ૬ આંકડાનો OTP મોકલેલ છે.";
            if (!empty($res['debug_otp'])) {
                $success .= " (ટેસ્ટિંગ OTP: " . htmlspecialchars($res['debug_otp']) . " અથવા 123456)";
            }
        } else {
            $error = $res['error'];
            $step = 'enter_mobile';
        }
    } elseif ($action === 'verify_otp') {
        $loginMode = 'otp';
        $otpCode = trim($_POST['otp_code'] ?? '');
        $error = verify_student_login_otp($identifier, $otpCode);
        if (!$error) {
            redirect('public/student/dashboard.php');
            exit;
        }
        $step = 'verify_otp';
    } elseif ($action === 'password_login') {
        $loginMode = 'password';
        $password = $_POST['password'] ?? '';
        $error = attempt_student_login($identifier, $password);
        if (!$error) {
            redirect('public/student/dashboard.php');
            exit;
        }
    } elseif ($action === 'change_mobile') {
        unset($_SESSION['student_login_otp_state']);
        $otpState = null;
        $step = 'enter_mobile';
        $loginMode = 'otp';
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
        
        <!-- Toggle Tabs: WhatsApp OTP vs Password -->
        <div class="d-flex p-1 rounded-3 mb-4" style="background: #f1f5f9;">
            <button type="button" class="btn btn-sm flex-fill fw-bold rounded-2 <?= ($loginMode === 'otp') ? 'bg-white shadow-sm text-primary' : 'text-muted' ?>" style="font-size: 0.86rem; padding: 7px 10px;" onclick="setLoginMode('otp')">
                <i class="bi bi-whatsapp text-success me-1"></i> WhatsApp OTP Login
            </button>
            <button type="button" class="btn btn-sm flex-fill fw-bold rounded-2 <?= ($loginMode === 'password') ? 'bg-white shadow-sm text-primary' : 'text-muted' ?>" style="font-size: 0.86rem; padding: 7px 10px;" onclick="setLoginMode('password')">
                <i class="bi bi-key-fill me-1"></i> Password Login
            </button>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small rounded-3 mb-3" style="background: #FEF2F2; color: #991B1B; border: 1px solid #F87171;">
                <i class="bi bi-exclamation-circle-fill me-1"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success py-2 small rounded-3 mb-3" style="background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0;">
                <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- ================= MODE 1: WHATSAPP OTP LOGIN ================= -->
        <div id="otpSection" style="<?= ($loginMode === 'otp') ? 'display:block;' : 'display:none;' ?>">
            
            <?php if ($step === 'enter_mobile'): ?>
                <!-- Step 1: Enter Mobile Number -->
                <form method="POST" action="">
                    <input type="hidden" name="action" value="send_otp">
                    <input type="hidden" name="login_mode" value="otp">

                    <div class="form-group mb-3">
                        <label class="form-label">મોબાઈલ નંબર (Mobile Number) *</label>
                        <div class="position-relative">
                            <span class="position-absolute fw-bold text-muted" style="top: 15px; left: 16px; font-size: 0.95rem;">+91</span>
                            <input type="tel" name="identifier" class="form-control-app" style="padding-left: 55px; font-size: 1.05rem; letter-spacing: 0.5px;" placeholder="10 આંકડાનો મોબાઈલ નંબર" required autofocus value="<?= htmlspecialchars($identifier) ?>" maxlength="15" inputmode="numeric">
                        </div>
                        <small class="text-muted mt-1 d-block" style="font-size: 0.76rem;">
                            <i class="bi bi-info-circle me-1"></i> તમારા રજીસ્ટર્ડ નંબરના WhatsApp પર ૬ આંકડાનો OTP મોકલવામાં આવશે.
                        </small>
                    </div>

                    <button type="submit" class="btn-app" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); box-shadow: 0 6px 16px -4px rgba(5,150,105,0.4);">
                        <i class="bi bi-whatsapp fs-5 me-1"></i> વોટ્સએપ પર OTP મેળવો (Send OTP)
                    </button>
                </form>

            <?php else: ?>
                <!-- Step 2: Verify WhatsApp OTP -->
                <form method="POST" action="">
                    <input type="hidden" name="action" value="verify_otp">
                    <input type="hidden" name="login_mode" value="otp">
                    <input type="hidden" name="identifier" value="<?= htmlspecialchars($identifier) ?>">

                    <div class="p-3 rounded-3 mb-3" style="background: #F0FDF4; border: 1.5px solid #BBF7D0;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="small text-muted" style="font-size: 0.74rem;">OTP મોકલેલ નંબર:</div>
                                <div class="fw-bold text-success font-monospace" style="font-size: 0.98rem;">
                                    +91 <?= htmlspecialchars(!empty($otpState['mobile']) ? WhatsAppService::maskPhone($otpState['mobile']) : $identifier) ?>
                                </div>
                            </div>
                            <button type="submit" name="action" value="change_mobile" class="btn btn-sm btn-link text-decoration-none text-primary fw-semibold p-0" style="font-size: 0.8rem;">
                                <i class="bi bi-pencil-square me-1"></i> નંબર બદલો
                            </button>
                        </div>
                    </div>

                    <?php if (empty($otpState['wa_sent'])): ?>
                        <div class="p-3 rounded-3 mb-3 text-center" style="background: #EFF6FF; border: 1.5px solid #60A5FA;">
                            <div class="fw-bold text-primary mb-1" style="font-size: 0.92rem;">
                                <i class="bi bi-info-circle-fill me-1"></i> WhatsApp API કનેક્ટ નથી (ટેસ્ટિંગ મોડ)
                            </div>
                            <div class="small text-muted mb-2">તમારો લોગિન OTP નીચે મુજબ છે:</div>
                            <div class="d-inline-block px-4 py-1 bg-white border border-primary rounded-3 shadow-sm font-monospace fw-bold text-primary mb-2" style="font-size: 1.5rem; letter-spacing: 5px;">
                                <?= htmlspecialchars($otpState['otp_code'] ?? '123456') ?>
                            </div>
                            <div class="small text-muted">
                                (તમે <b><?= htmlspecialchars($otpState['otp_code'] ?? '123456') ?></b> અથવા <b>123456</b> નાખીને લોગિન કરી શકો છો)
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-group mb-3">
                        <label class="form-label text-center d-block fw-bold" style="font-size: 0.92rem;">WhatsApp પર આવેલ ૬ આંકડાનો OTP દાખલ કરો</label>
                        <input type="text" name="otp_code" class="form-control-app text-center fw-bold" style="font-size: 1.4rem; letter-spacing: 6px; height: 52px;" placeholder="• • • • • •" required autofocus maxlength="6" inputmode="numeric" value="<?= empty($otpState['wa_sent']) ? htmlspecialchars($otpState['otp_code'] ?? '123456') : '' ?>">
                        <div class="d-flex justify-content-between align-items-center mt-2 px-1">
                            <span id="otpTimerText" class="text-muted small" style="font-size: 0.78rem;">
                                <i class="bi bi-clock me-1"></i> <span id="countdownVal">60</span> સેકન્ડમાં ફરી મોકલી શકાશે
                            </span>
                            <button type="submit" name="action" value="resend_otp" id="resendOtpBtn" class="btn btn-sm btn-link text-decoration-none p-0 fw-bold" style="font-size: 0.8rem; display: none;">
                                <i class="bi bi-arrow-clockwise me-1"></i> ફરી OTP મોકલો
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-app mb-2">
                        Verify &amp; Login <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </form>

                <script>
                (function() {
                    var sec = 60;
                    var timerEl = document.getElementById('countdownVal');
                    var timerText = document.getElementById('otpTimerText');
                    var resendBtn = document.getElementById('resendOtpBtn');
                    var interval = setInterval(function() {
                        sec--;
                        if (timerEl) timerEl.textContent = sec;
                        if (sec <= 0) {
                            clearInterval(interval);
                            if (timerText) timerText.style.display = 'none';
                            if (resendBtn) resendBtn.style.display = 'inline-block';
                        }
                    }, 1000);
                })();
                </script>
            <?php endif; ?>

        </div>

        <!-- ================= MODE 2: PASSWORD LOGIN ================= -->
        <div id="passwordSection" style="<?= ($loginMode === 'password') ? 'display:block;' : 'display:none;' ?>">
            <form method="POST" action="">
                <input type="hidden" name="action" value="password_login">
                <input type="hidden" name="login_mode" value="password">

                <div class="form-group mb-3">
                    <label class="form-label">Mobile Number or Registration ID</label>
                    <div class="position-relative">
                        <i class="bi bi-person position-absolute" style="top: 17px; left: 16px; color: #94A3B8; font-size: 1.1rem;"></i>
                        <input type="text" name="identifier" class="form-control-app" style="padding-left: 45px;" placeholder="Enter 10-digit mobile or ID" required value="<?= htmlspecialchars($identifier) ?>">
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
                    Login with Password <i class="bi bi-arrow-right ms-1"></i>
                </button>
            </form>
        </div>

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

<script>
function setLoginMode(mode) {
    var otpSec = document.getElementById('otpSection');
    var pwSec = document.getElementById('passwordSection');
    if (mode === 'otp') {
        otpSec.style.display = 'block';
        pwSec.style.display = 'none';
    } else {
        otpSec.style.display = 'none';
        pwSec.style.display = 'block';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
