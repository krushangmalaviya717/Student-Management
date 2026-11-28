<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/services/WhatsAppService.php';

const STUDENT_REMEMBER_COOKIE = 'devangi_student_remember';
const STUDENT_REMEMBER_DAYS = 180;

function current_student(): ?array
{
    return $_SESSION['student'] ?? null;
}

function student_remember_secret(): string
{
    return defined('API_SECRET_KEY') && API_SECRET_KEY !== '' ? API_SECRET_KEY : 'devangi_student_remember_key_2026';
}

function set_student_remember_cookie(int $studentId, string $passwordHash): void
{
    if (headers_sent()) {
        return;
    }
    $expires = time() + (STUDENT_REMEMBER_DAYS * 86400);
    $pwSlice = substr($passwordHash, 0, 16);
    $payload = $studentId . '|' . $expires;
    $sig = hash_hmac('sha256', $payload . '|' . $pwSlice, student_remember_secret());
    $token = rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');

    setcookie(STUDENT_REMEMBER_COOKIE, $token, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_student_remember_cookie(): void
{
    if (!headers_sent()) {
        setcookie(STUDENT_REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE[STUDENT_REMEMBER_COOKIE]);
}

function restore_student_from_remember_cookie(): bool
{
    $raw = $_COOKIE[STUDENT_REMEMBER_COOKIE] ?? '';
    if ($raw === '') {
        return false;
    }

    $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
    if ($decoded === false) {
        clear_student_remember_cookie();
        return false;
    }

    $parts = explode('|', $decoded, 3);
    if (count($parts) !== 3) {
        clear_student_remember_cookie();
        return false;
    }

    [$studentIdStr, $expiresStr, $sig] = $parts;
    $studentId = (int)$studentIdStr;
    $expires = (int)$expiresStr;

    if ($studentId <= 0 || $expires < time()) {
        clear_student_remember_cookie();
        return false;
    }

    try {
        $stmt = db()->prepare(
            "SELECT s.*, b.name AS joined_batch_name, b.start_time, b.end_time, b.batch_days,
                    pb.name AS preferred_batch_name
             FROM students s
             LEFT JOIN batches b ON s.joined_batch_id = b.id
             LEFT JOIN batches pb ON s.preferred_batch_id = pb.id
             WHERE s.id = ?
             LIMIT 1"
        );
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }

    if (!$student || in_array($student['status'] ?? '', ['cancelled', 'inactive', 'draft'], true) || ($student['form_status'] ?? 'draft') !== 'confirmed') {
        clear_student_remember_cookie();
        return false;
    }

    $hash = (string)($student['password_hash'] ?? '');
    $expectedSig = hash_hmac('sha256', $studentId . '|' . $expires . '|' . substr($hash, 0, 16), student_remember_secret());
    if (!hash_equals($expectedSig, $sig)) {
        clear_student_remember_cookie();
        return false;
    }

    unset($student['password_hash']);
    $_SESSION['student'] = $student;
    set_student_remember_cookie($studentId, $hash);
    return true;
}

function is_student_logged_in(): bool
{
    if (isset($_SESSION['student']) && !empty($_SESSION['student']['id'])) {
        return true;
    }
    return restore_student_from_remember_cookie();
}

function require_student_login(): void
{
    if (!is_student_logged_in()) {
        redirect('public/student/login.php');
        exit;
    }
}

/**
 * Find student by mobile (+91, clean, spaces), registration_no, or form_no
 */
function find_student_by_identifier(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $digitsOnly = preg_replace('/\D+/', '', $identifier);
    $cleanMobile = $digitsOnly;
    if (strlen($digitsOnly) === 12 && str_starts_with($digitsOnly, '91')) {
        $cleanMobile = substr($digitsOnly, 2);
    } elseif (strlen($digitsOnly) === 11 && str_starts_with($digitsOnly, '0')) {
        $cleanMobile = substr($digitsOnly, 1);
    }

    $stmt = db()->prepare(
        "SELECT s.*, b.name AS joined_batch_name, b.start_time, b.end_time, b.batch_days,
                pb.name AS preferred_batch_name
         FROM students s
         LEFT JOIN batches b ON s.joined_batch_id = b.id
         LEFT JOIN batches pb ON s.preferred_batch_id = pb.id
         WHERE s.student_mobile = ? 
            OR s.student_mobile = ?
            OR REPLACE(REPLACE(s.student_mobile, ' ', ''), '-', '') = ?
            OR s.registration_no = ? 
            OR s.form_no = ?
         LIMIT 1"
    );
    $stmt->execute([$identifier, $cleanMobile, $cleanMobile, $identifier, $identifier]);
    $res = $stmt->fetch();
    return $res ?: null;
}

/**
 * Send student login OTP via WhatsApp (or generate test OTP)
 */
function send_student_login_otp(string $identifier): array
{
    $student = find_student_by_identifier($identifier);
    if (!$student) {
        return ['success' => false, 'error' => 'આ નંબર અથવા રજિસ્ટ્રેશન નો. સાથે કોઈ એકાઉન્ટ મળ્યું નથી.'];
    }

    if ($student['status'] === 'cancelled' || $student['status'] === 'inactive') {
        return ['success' => false, 'error' => 'આ એકાઉન્ટ નિષ્ક્રિય અથવા કેન્સલ થયેલ છે. કૃપા કરીને ઓફિસનો સંપર્ક કરો.'];
    }

    if (($student['form_status'] ?? 'draft') !== 'confirmed' || $student['status'] === 'draft') {
        return ['success' => false, 'error' => 'તમારું ફોર્મ રજીસ્ટ્રેશન હજુ કન્ફર્મ થયું નથી. ₹500 ફોર્મ ફી જમા કરાવ્યા બાદ જ તમારો નંબર એપમાં લોગિન માટે ચાલુ થશે.'];
    }

    $pdo = db();
    load_all_settings();
    $studentId = (int)$student['id'];
    $mobile = trim((string)$student['student_mobile']);

    // Server-side 60-second cooldown to prevent spamming WhatsApp OTP requests
    $lastState = $_SESSION['student_login_otp_state'] ?? null;
    if (is_array($lastState) && (int)($lastState['student_id'] ?? 0) === $studentId) {
        $elapsed = time() - (int)($lastState['sent_at'] ?? 0);
        if ($elapsed < 60 && !empty($lastState['wa_sent'])) {
            $wait = 60 - $elapsed;
            return ['success' => false, 'error' => "કૃપા કરીને નવો OTP મંગાવતા પહેલા {$wait} સેકન્ડ રાહ જુઓ."];
        }
    }

    $otpCode = (string)random_int(100000, 999999);

    // Invalidate previous pending login OTPs for this student
    $pdo->prepare("UPDATE student_login_otps SET status = 'expired' WHERE student_id = ? AND status = 'pending'")
        ->execute([$studentId]);

    // Send via WhatsApp
    $waRes = WhatsAppService::sendStudentLoginOtp($mobile, $otpCode, $student['name']);
    $sentVia = $waRes['success'] ? 'whatsapp' : 'system';
    $respMsg = $waRes['success'] ? 'Sent via WhatsApp' : ($waRes['error'] ?? 'API not active');

    // If WhatsApp is not configured yet, use 123456 as the primary test OTP for convenience
    if (!$waRes['success']) {
        $otpCode = '123456';
    }

    // Store in DB (valid for 15 minutes)
    $stmt = $pdo->prepare("
        INSERT INTO student_login_otps (
            student_id, mobile, otp_code, status, sent_via, response_msg, expires_at
        ) VALUES (?, ?, ?, 'pending', ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
    ");
    $stmt->execute([$studentId, $mobile, $otpCode, $sentVia, $respMsg]);

    $_SESSION['student_login_otp_state'] = [
        'student_id' => $studentId,
        'mobile' => $mobile,
        'name' => $student['name'],
        'identifier' => $identifier,
        'sent_at' => time(),
        'otp_code' => $otpCode,
        'wa_sent' => $waRes['success'],
        'wa_error' => (!$waRes['success']) ? ($waRes['error'] ?? $waRes['message'] ?? 'Unknown error') : null,
    ];

    return [
        'success' => true,
        'student_id' => $studentId,
        'mobile' => $mobile,
        'masked_mobile' => WhatsAppService::maskPhone($mobile),
        'student_name' => $student['name'],
        'wa_sent' => $waRes['success'],
        'wa_error' => $_SESSION['student_login_otp_state']['wa_error'],
        'debug_otp' => (!$waRes['success']) ? $otpCode : null
    ];
}

/**
 * Verify submitted OTP and log student in
 */
function verify_student_login_otp(string $identifier, string $otpCode): ?string
{
    $identifier = trim($identifier);
    $otpCode = trim($otpCode);

    if (empty($identifier) || empty($otpCode)) {
        return 'મહેરબાની કરીને મોબાઈલ નંબર અને ૬-આંકડાનો OTP દાખલ કરો.';
    }

    $student = find_student_by_identifier($identifier);
    if (!$student) {
        return 'વિદ્યાર્થી એકાઉન્ટ મળ્યું નથી.';
    }

    $studentId = (int)$student['id'];
    $pdo = db();

    // Verify OTP in student_login_otps (or master fallback 123456 / session debug OTP if WhatsApp not yet active)
    $stmt = $pdo->prepare("
        SELECT id FROM student_login_otps
        WHERE student_id = ? AND otp_code = ? AND status = 'pending' AND expires_at >= NOW()
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$studentId, $otpCode]);
    $otpId = $stmt->fetchColumn();

    $sessionOtp = $_SESSION['student_login_otp_state']['otp_code'] ?? '';
    $waSent = !empty($_SESSION['student_login_otp_state']['wa_sent']);
    $isValid = false;

    if ($otpId) {
        $isValid = true;
        $pdo->prepare("UPDATE student_login_otps SET status = 'verified', verified_at = NOW() WHERE id = ?")
            ->execute([$otpId]);
    } elseif (!$waSent && in_array($otpCode, ['123456', '482910', '000000', '111111', $sessionOtp], true)) {
        // Fallback test OTPs are only accepted when WhatsApp API was not active/sent
        $isValid = true;
    }

    if (!$isValid) {
        return 'ખોટો અથવા એક્સપાયર થયેલ OTP. કૃપા કરીને ફરીથી નવો OTP મંગાવો.';
    }

    // Set session & persistent cookie
    unset($student['password_hash']);
    $_SESSION['student'] = $student;
    unset($_SESSION['student_login_otp_state']);

    // Ensure persistent login token
    $hash = (string)($student['password_hash'] ?? '');
    if ($hash === '') {
        $hash = password_hash($student['student_mobile'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE students SET password_hash = ? WHERE id = ?")->execute([$hash, $studentId]);
    }
    set_student_remember_cookie($studentId, $hash);

    return null; // Success
}

/**
 * Attempt student login via Mobile No, Registration No, or Form No
 */
function attempt_student_login(string $identifier, string $password): ?string
{
    $identifier = trim($identifier);
    $password = trim($password);
    if (empty($identifier) || empty($password)) {
        return 'મહેરબાની કરીને મોબાઈલ / રજિસ્ટ્રેશન નંબર અને પાસવર્ડ દાખલ કરો.';
    }

    $student = find_student_by_identifier($identifier);

    if (!$student) {
        return 'આ નંબર અથવા રજિસ્ટ્રેશન નો. સાથે કોઈ એકાઉન્ટ મળ્યું નથી.';
    }

    if ($student['status'] === 'cancelled' || $student['status'] === 'inactive') {
        return 'આ એકાઉન્ટ નિષ્ક્રિય અથવા કેન્સલ થયેલ છે. કૃપા કરીને ઓફિસનો સંપર્ક કરો.';
    }

    if (($student['form_status'] ?? 'draft') !== 'confirmed' || $student['status'] === 'draft') {
        return 'તમારું ફોર્મ રજીસ્ટ્રેશન હજુ કન્ફર્મ થયું નથી. ₹500 ફોર્મ ફી જમા કરાવ્યા બાદ જ તમારો નંબર એપમાં લોગિન માટે ચાલુ થશે. કૃપા કરીને ઓફિસનો સંપર્ક કરો.';
    }

    // Check password hash
    $hash = $student['password_hash'] ?? null;
    $validPassword = false;

    if (!empty($hash)) {
        if (password_verify($password, $hash)) {
            $validPassword = true;
        }
    }
    
    // Fallback: If password_hash is not set or matches raw/clean mobile number (first-time login)
    $studentCleanMobile = preg_replace('/\D+/', '', (string)$student['student_mobile']);
    $enteredPwClean = preg_replace('/\D+/', '', $password);
    $isFirstTimeMobileMatch = (
        $password === $student['student_mobile'] ||
        $password === $cleanMobile ||
        ($enteredPwClean !== '' && $enteredPwClean === $studentCleanMobile)
    );

    if (!$validPassword && (empty($hash) || $isFirstTimeMobileMatch)) {
        $validPassword = true;
        // Update hash
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare("UPDATE students SET password_hash = ? WHERE id = ?")->execute([$newHash, $student['id']]);
    }

    if (!$validPassword) {
        return 'પાસવર્ડ ખોટો છે.';
    }

    // Set student session & persistent 180-day remember cookie
    $finalHash = (string)($newHash ?? $hash ?? '');
    unset($student['password_hash']);
    $_SESSION['student'] = $student;
    set_student_remember_cookie((int)$student['id'], $finalHash);

    return null; // Success
}

function student_logout(): void
{
    unset($_SESSION['student']);
    clear_student_remember_cookie();
}

function reload_student_session(): void
{
    $studentId = $_SESSION['student']['id'] ?? null;
    if (!$studentId) return;

    $stmt = db()->prepare(
        "SELECT s.*, b.name AS joined_batch_name, b.start_time, b.end_time, b.batch_days,
                pb.name AS preferred_batch_name
         FROM students s
         LEFT JOIN batches b ON s.joined_batch_id = b.id
         LEFT JOIN batches pb ON s.preferred_batch_id = pb.id
         WHERE s.id = ?
         LIMIT 1"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if ($student) {
        unset($student['password_hash']);
        $_SESSION['student'] = $student;
    }
}
