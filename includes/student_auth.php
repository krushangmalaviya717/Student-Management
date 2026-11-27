<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

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
 * Attempt student login via Mobile No, Registration No, or Form No
 */
function attempt_student_login(string $identifier, string $password): ?string
{
    $identifier = trim($identifier);
    $password = trim($password);
    if (empty($identifier) || empty($password)) {
        return 'મહેરબાની કરીને મોબાઈલ / રજિસ્ટ્રેશન નંબર અને પાસવર્ડ દાખલ કરો.';
    }

    // Sanitize mobile format (+91, leading 0, spaces, dashes)
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
    $student = $stmt->fetch();

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
