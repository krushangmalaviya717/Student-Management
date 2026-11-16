<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

function current_student(): ?array
{
    return $_SESSION['student'] ?? null;
}

function is_student_logged_in(): bool
{
    return isset($_SESSION['student']) && !empty($_SESSION['student']['id']);
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
    if (empty($identifier) || empty($password)) {
        return 'મહેરબાની કરીને મોબાઈલ / રજિસ્ટ્રેશન નંબર અને પાસવર્ડ દાખલ કરો.';
    }

    $stmt = db()->prepare(
        "SELECT s.*, b.name AS joined_batch_name, b.start_time, b.end_time, b.batch_days,
                pb.name AS preferred_batch_name
         FROM students s
         LEFT JOIN batches b ON s.joined_batch_id = b.id
         LEFT JOIN batches pb ON s.preferred_batch_id = pb.id
         WHERE s.student_mobile = ? OR s.registration_no = ? OR s.form_no = ?
         LIMIT 1"
    );
    $stmt->execute([$identifier, $identifier, $identifier]);
    $student = $stmt->fetch();

    if (!$student) {
        return 'આ નંબર અથવા રજિસ્ટ્રેશન નો. સાથે કોઈ એકાઉન્ટ મળ્યું નથી.';
    }

    if ($student['status'] === 'cancelled' || $student['status'] === 'inactive') {
        return 'આ એકાઉન્ટ નિષ્ક્રિય અથવા કેન્સલ થયેલ છે. કૃપા કરીને ઓફિસનો સંપર્ક કરો.';
    }

    // Check password hash
    $hash = $student['password_hash'] ?? null;
    $validPassword = false;

    if (!empty($hash)) {
        if (password_verify($password, $hash)) {
            $validPassword = true;
        }
    }
    
    // Fallback: If password_hash is not set or matches raw mobile number
    if (!$validPassword && (empty($hash) || $password === $student['student_mobile'])) {
        $validPassword = true;
        // Update hash
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare("UPDATE students SET password_hash = ? WHERE id = ?")->execute([$newHash, $student['id']]);
    }

    if (!$validPassword) {
        return 'પાસવર્ડ ખોટો છે.';
    }

    // Set student session
    unset($student['password_hash']);
    $_SESSION['student'] = $student;

    return null; // Success
}

function student_logout(): void
{
    unset($_SESSION['student']);
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
