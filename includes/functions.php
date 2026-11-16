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
        "UPDATE settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ?"
    );
    $stmt->execute([$value, $userId, $key]);
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
    $prefix = get_setting($settingKey, '');
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT $column FROM $table WHERE $column LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $nextNumber = 1;
    if ($last) {
        $numericPart = (int) substr($last, strlen($prefix));
        $nextNumber = $numericPart + 1;
    }
    return $prefix . str_pad((string)$nextNumber, 5, '0', STR_PAD_LEFT);
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
        flash('danger', 'સેશન સમાપ્ત થઈ ગયું છે અથવા ફોર્મ અમાન્ય છે. કૃપા કરીને ફરી પ્રયાસ કરો. (Session expired)');
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
