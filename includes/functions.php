<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/components/status_badge.php';
require_once __DIR__ . '/components/kpi_card.php';
require_once __DIR__ . '/components/export_button.php';

/** ---------------- Settings ---------------- */

function get_setting(string $key, $default = null)
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $cache[$key] = $row ? $row['setting_value'] : $default;
    return $cache[$key];
}

function set_setting(string $key, string $value, ?int $userId = null): void
{
    $stmt = db()->prepare(
        "INSERT INTO settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()"
    );
    $stmt->execute([$key, $value, $userId]);
}

/** Current per-day extension charge (effective today), or null if none set. */
function get_current_extension_rate(?string $onDate = null): ?array
{
    $onDate = $onDate ?? date('Y-m-d');
    $stmt = db()->prepare(
        "SELECT * FROM extension_charge_rates
         WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
         ORDER BY effective_from DESC LIMIT 1"
    );
    $stmt->execute([$onDate, $onDate]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** ---------------- Auto-numbering ---------------- */

function generate_form_no(): string
{
    return generate_sequenced_number('form_no_prefix', 'students', 'form_no');
}

function generate_registration_no(): string
{
    return generate_sequenced_number('registration_no_prefix', 'students', 'registration_no');
}

function generate_receipt_no(): string
{
    return generate_sequenced_number('receipt_no_prefix', 'payments', 'receipt_no');
}

function generate_invoice_no(): string
{
    return generate_sequenced_number('invoice_no_prefix', 'invoices', 'invoice_no');
}

/**
 * Generic "PREFIX + zero-padded running number" generator.
 * Looks at the highest existing number with that prefix and increments it.
 */
function generate_sequenced_number(string $settingKey, string $table, string $column): string
{
    $allowed = [
        'students' => ['form_no', 'registration_no'],
        'payments' => ['receipt_no'],
        'invoices' => ['invoice_no'],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        throw new InvalidArgumentException('Invalid sequence target.');
    }

    $prefix = get_setting($settingKey, '');
    $pdo = db();
    $lockSuffix = $pdo->inTransaction() ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
        "SELECT $column FROM $table WHERE $column LIKE ? ORDER BY id DESC LIMIT 1{$lockSuffix}"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $nextNumber = 1;
    if ($last) {
        $numericPart = (int) substr($last, strlen($prefix));
        $nextNumber = $numericPart + 1;
    }

    $checkStmt = $pdo->prepare("SELECT 1 FROM $table WHERE $column = ? LIMIT 1");
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = $prefix . str_pad((string)($nextNumber + $attempt), 5, '0', STR_PAD_LEFT);
        $checkStmt->execute([$candidate]);
        if (!$checkStmt->fetchColumn()) {
            return $candidate;
        }
    }

    return $prefix . str_pad((string)($nextNumber + 20), 5, '0', STR_PAD_LEFT);
}

/** ---------------- CSRF & State (Requirement 8) ---------------- */

function csrf_token(): string
{
    return Session::csrfToken();
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!Session::verifyCsrf()) {
        http_response_code(419);
        flash('danger', 'Your session expired or the form token is invalid. Please try again.');
        if (!empty($_SERVER['HTTP_REFERER'])) {
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }
        die('Session expired or invalid form submission. Please go back and try again.');
    }
}

/** ---------------- Small utilities ---------------- */

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function format_date(?string $date): string
{
    if (!$date) return '-';
    return date('d-M-Y', strtotime($date));
}

function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function set_flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function get_flashes(): array
{
    return Session::getFlashes();
}

function url(string $path = ''): string
{
    $base = rtrim(defined('APP_URL') ? APP_URL : '/student-management', '/');
    $path = ltrim($path, '/');
    return $path ? "$base/$path" : $base;
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Render clean pagination controls preserving existing GET query parameters.
 */
function render_pagination(int $total, int $page, int $perPage, array $queryParams = [], string $anchor = ''): string
{
    $totalPages = max(1, (int)ceil($total / max(1, $perPage)));
    if ($total <= $perPage && $page <= 1) {
        return '';
    }
    $page = max(1, min($page, $totalPages));
    $startItem = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
    $endItem = min($total, $page * $perPage);

    $buildUrl = static function (int $p) use ($queryParams, $anchor): string {
        $params = $queryParams;
        $params['page'] = $p;
        return '?' . http_build_query($params) . $anchor;
    };

    $html = '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:16px; padding-top:14px; border-top:1px solid var(--border-light);">';
    $html .= '<div class="muted" style="font-size:12.5px;">Showing <strong>' . $startItem . '</strong>&ndash;<strong>' . $endItem . '</strong> of <strong>' . $total . '</strong></div>';
    $html .= '<div class="action-group" style="gap:4px;">';

    if ($page > 1) {
        $html .= '<a href="' . htmlspecialchars($buildUrl($page - 1)) . '" class="btn btn-sm btn-secondary">&larr; Prev</a>';
    }
    $fromPage = max(1, $page - 2);
    $toPage = min($totalPages, $page + 2);
    if ($fromPage > 1) {
        $html .= '<a href="' . htmlspecialchars($buildUrl(1)) . '" class="btn btn-sm btn-secondary">1</a>';
        if ($fromPage > 2) $html .= '<span class="muted" style="padding:0 4px;">...</span>';
    }
    for ($i = $fromPage; $i <= $toPage; $i++) {
        $cls = ($i === $page) ? 'btn btn-sm' : 'btn btn-sm btn-secondary';
        $html .= '<a href="' . htmlspecialchars($buildUrl($i)) . '" class="' . $cls . '">' . $i . '</a>';
    }
    if ($toPage < $totalPages) {
        if ($toPage < $totalPages - 1) $html .= '<span class="muted" style="padding:0 4px;">...</span>';
        $html .= '<a href="' . htmlspecialchars($buildUrl($totalPages)) . '" class="btn btn-sm btn-secondary">' . $totalPages . '</a>';
    }
    if ($page < $totalPages) {
        $html .= '<a href="' . htmlspecialchars($buildUrl($page + 1)) . '" class="btn btn-sm btn-secondary">Next &rarr;</a>';
    }
    $html .= '</div></div>';
    return $html;
}

/**
 * Securely validate and save an uploaded student photo (either base64 crop or multipart file).
 * Verifies real image binary contents, MIME type, dimensions, and byte size.
 */
function save_uploaded_student_photo(?string $base64Data, ?array $fileUpload, string $uploadDir, string $uploadUrl, int $maxSizeMb = 5): ?string
{
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $maxBytes = max(1, $maxSizeMb) * 1024 * 1024;
    $targetDir = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR;
    $targetUrl = '/' . trim($uploadUrl, '/') . '/';

    if (!empty($base64Data)) {
        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+\/=]+)$/', trim($base64Data), $m)) {
            throw new RuntimeException('Invalid cropped image format.');
        }
        $imageData = base64_decode($m[2], true);
        if ($imageData === false || $imageData === '') {
            throw new RuntimeException('Invalid base64 image data.');
        }
        if (strlen($imageData) > $maxBytes) {
            throw new RuntimeException('Photo must be under ' . $maxSizeMb . 'MB.');
        }

        $imgInfo = @getimagesizefromstring($imageData);
        if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1]) || $imgInfo[0] > 10000 || $imgInfo[1] > 10000) {
            throw new RuntimeException('Uploaded cropped file is not a valid image.');
        }

        $mime = $imgInfo['mime'] ?? '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected = $finfo ? finfo_buffer($finfo, $imageData) : false;
            if ($finfo) finfo_close($finfo);
            if ($detected !== false) {
                $mime = $detected;
            }
        }
        if (!isset($allowedMimes[$mime])) {
            throw new RuntimeException('Photo must be a valid JPG, PNG, or WEBP image.');
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filename = 'photo_' . bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
        if (file_put_contents($targetDir . $filename, $imageData) === false) {
            throw new RuntimeException('Could not save cropped photo.');
        }
        return $targetUrl . $filename;
    }

    if (!empty($fileUpload['name'])) {
        if (($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Photo upload failed. Please choose a valid image.');
        }
        $tmpPath = $fileUpload['tmp_name'] ?? '';
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid uploaded photo file.');
        }
        if (($fileUpload['size'] ?? 0) <= 0 || $fileUpload['size'] > $maxBytes) {
            throw new RuntimeException('Photo must be under ' . $maxSizeMb . 'MB.');
        }

        $imgInfo = @getimagesize($tmpPath);
        if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1]) || $imgInfo[0] > 10000 || $imgInfo[1] > 10000) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $mime = $imgInfo['mime'] ?? '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected = $finfo ? finfo_file($finfo, $tmpPath) : false;
            if ($finfo) finfo_close($finfo);
            if ($detected !== false) {
                $mime = $detected;
            }
        }
        if (!isset($allowedMimes[$mime])) {
            throw new RuntimeException('Photo must be a valid JPG, PNG, or WEBP image.');
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filename = 'photo_' . bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
        if (!move_uploaded_file($tmpPath, $targetDir . $filename)) {
            throw new RuntimeException('Could not save uploaded photo.');
        }
        return $targetUrl . $filename;
    }

    return null;
}
