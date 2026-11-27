<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/components/status_badge.php';
require_once __DIR__ . '/components/kpi_card.php';
require_once __DIR__ . '/components/export_button.php';

/** ---------------- Settings ---------------- */

/**
 * Authoritative catalog of default settings introduced for dynamic customization.
 * Ensures live installations automatically receive missing settings without manual SQL.
 */
function get_default_settings_catalog(): array
{
    $defaultRules = implode("\n", [
        'ફોર્મ ભરતી વખતે ભરેલી રકમ કોઈપણ સંજોગો નામ કેન્સલ થાય તો પરત મળશે નહીં.',
        'જે વ્યક્તિના નામ નું ફોર્મ ભરેલું હશે તે જ વ્યક્તિએ આવવાનું રહેશે.',
        'જો કોઈપણ સંજોગોમાં નામ ચેન્જ કરવાનું થાય તો પણ ફોર્મ અને ફોર્મની રકમ ફરીથી ભરવાની રહેશે પણ જે વ્યક્તિની જગ્યાએ તેના સ્થાન મળી શકશે તેને વેઈટીંગ માં રહેવું પડશે નહીં. પણ કેન્સલ કરાવ્યા પછી તે સ્થાન કોઈપણ વ્યક્તિને મળશે નહીં.',
        'એન્ટ્રી કર્યા ના પહેલા જ દિવસે એન્ટ્રી ફી ફરજીયાત આપવાની રહેશે.',
        'એન્ટ્રી કર્યા પછી જો આવવાનું બંધ કરવામાં આવે તો એન્ટ્રીમાં ભરેલી ફી પરત આપવામાં આવશે નહીં.',
        'ફીની બાકીની રકમ દસ દિવસમાં આપવાની રહેશે દસ દિવસ પછી જો આવવાનું બંધ કરવામાં આવે તો ભરેલી કોઈપણ રકમ પરત આપવામાં આવશે નહીં.',
        'કોઈપણ વહીવટ કરવા જે તે વ્યક્તિ નું ફોર્મ હોય તેને રૂબરૂ આવવાનું રહેશે બીજી કોઈ પણ વ્યક્તિનું સાંભળવામાં આવશે નહીં.',
        'વારો આવ્યા નો ફોન આવે જે તે વ્યક્તિએ આવવાની હા પાડ્યા પછી તે દિવસે ન આવે તો તે દિવસ તેનામાં ગણવામાં આવશે તેમ જો બે દિવસ ન આવે તો તેનું ફોર્મ રદ કરવામાં આવશે પછી તેણે ફરીથી ફોર્મ અને ફોર્મની રકમ ભરવાની રહેશે તેમજ વેઈટીંગમાં રહેવાનું રહેશે.',
        'વારો આવ્યા પછી એકવાર લેટ કરાવી શકાશે પરંતુ બીજીવાર વારો આવે એટલે ફરજીયાત આવવાનું રહેશે. અને જો ત્યારે પણ ન આવે તો ફોર્મ કેન્સલ કરવામાં આવશે તથા ભરેલી ફોર્મની રકમ પરત કરવામાં આવશે નહીં.',
        '16 વર્ષ થી નીચેની તેમજ 45 વર્ષથી ઉપરની બહેનોને શીખવવામાં આવશે નહીં.',
        'જે-તે વ્યક્તિનો ફોન નંબર આપ્યો હોય અને તે વ્યક્તિ ફોન પર જ નામ કેન્સલ કરાવી નાખશે તો પણ નામ કેન્સલ થઈ જશે પછી કોઈ તકરાર કરવી નહીં.',
        'જો કોઈ અગમ્ય કારણસર વચ્ચે અટકા વવાની ફરજ પડે તો તેની જાણ કર્યા ના ત્રીજા દિવસથી એના બાકી બચતા દિવસ ને 1.5 મહિના પછી આપવામાં આવશે પરંતુ ભરેલી રકમ પરત આપવામાં આવશે નહીં.',
        'શીખવા માટે ફૂલ સાઇઝ નું કાપડ લાવવાનું રહેશે. ટુકડામાં કાપડ ચાલશે નહીં.',
        'પહેલા બે જોડી ડ્રેસમાં મોટી સાઈઝ નું માપ જ ચાલશે નાની સાઈઝ નું માપ લાવવું નહીં.',
        'બેન્ચ શરૂ થાય ત્યારે સમયસર આવવું અને બેન્ચ પૂરી થાય ત્યારે તરત જ મશીન ખાલી કરવાનું રહેશે.',
        'દર રવિવારે રજા રહેશે રવિવાર સાથે જ 45 દિવસ ગણવામાં આવશે.',
        'તમારી કોઈ પણ કારણે પાડેલી રજા પરત આપવામાં આવશે નહીં.',
        'અમારા પર્સનલ કારણસર કે તહેવાર નિમિત્તે પાડેલી રજા અમારે આપવાની રહેશે.',
        '45 દિવસ ઉપર એક પણ દિવસ આપવામાં આવશે નહીં વધારે દિવસ ભરવાની ફી અલગથી આપવાની રહેશે.',
        'લેડીસ ટેલર્સ ના કામ સિવાય નું બીજું કાંઈ પણ કામ શીખવવામાં આવશે નહીં.',
        'દરેક કપડા બોડી મેજરમેન્ટ થી શીખવવામાં આવશે.',
        'ક્લાસ ની કોઈ સ્પેશિયલ વસ્તુ એકસ્ટ્રા બેન્ચ તેમજ એકસ્ટ્રા ફી ચૂકવીને શીખવવાની રહેશે તે રેગ્યુલર બેન્ચમાં શીખવવામાં આવશે નહીં તેમજ તે જૂના સ્ટુડન્ટ માટે જ રહેશે.',
        'બીજાને સિલાઈકામ શીખવાડતા હોય તેવા વ્યક્તિએ ક્લાસમાં શીખવા આવવું નહીં તો પણ ગેરકાયદેસર રીતે એન્ટ્રી કરશે અને પકડાઈ જશે તો તેની ભરેલી ફી પરત મળશે નહીં અને તેને જાણ થાય ત્યારથી જ ક્લાસ માંથી કાઢી મુકવામાં આવશે.',
        'સીવણ ક્લાસ માં ઑડિયો તેમજ વિડીયો રેકોર્ડીંગ કરવું નહીં.',
        'ક્લાસમાં શીખ્યા પછી કોઈ કલાસીસ ચાલુ કરે તો તેને સીવણ ક્લાસમાં કંઈ પણ પૂછવા આવવાનો હક રહેશે નહીં.',
    ]);

    return [
        // Form Field Requirement toggles (1 = required, 0 = optional)
        'form_req_student_mobile' => ['1', 'form_fields', 'Require Student Mobile in forms'],
        'form_req_father_mobile'  => ['0', 'form_fields', 'Require Husband/Father Mobile'],
        'form_req_home_mobile'    => ['0', 'form_fields', 'Require Home Mobile'],
        'form_req_age'            => ['0', 'form_fields', 'Require Student Age'],
        'form_req_education'      => ['0', 'form_fields', 'Require Education'],
        'form_req_address'        => ['1', 'form_fields', 'Require Full Address'],
        'form_req_photo'          => ['1', 'form_fields', 'Require Student Passport Photo'],
        'form_req_knows_sewing'   => ['0', 'form_fields', 'Require Sewing Experience Answer'],
        'form_req_hear_about_us'  => ['0', 'form_fields', 'Require Hear About Us Source'],
        'form_req_referral'       => ['0', 'form_fields', 'Require Referral Details'],
        'form_req_guardian'       => ['0', 'form_fields', 'Require Guardian Name & Relation'],

        // Form Field Visibility toggles (1 = visible, 0 = hidden)
        'form_vis_father_mobile'  => ['1', 'form_fields', 'Show Husband/Father Mobile field'],
        'form_vis_home_mobile'    => ['1', 'form_fields', 'Show Home Mobile field'],
        'form_vis_age'            => ['1', 'form_fields', 'Show Age field'],
        'form_vis_education'      => ['1', 'form_fields', 'Show Education field'],
        'form_vis_address'        => ['1', 'form_fields', 'Show Full Address field'],
        'form_vis_photo'          => ['1', 'form_fields', 'Show Passport Photo upload'],
        'form_vis_knows_sewing'   => ['1', 'form_fields', 'Show Sewing Experience section'],
        'form_vis_hear_about_us'  => ['1', 'form_fields', 'Show Hear About Us section'],
        'form_vis_referral'       => ['1', 'form_fields', 'Show Referral Details section'],
        'form_vis_guardian'       => ['1', 'form_fields', 'Show Guardian Details section'],

        // Branding & Print Templates
        'institute_tagline'       => ['Stitching & Fashion Designing Training Institute', 'branding', 'Displayed below institute name on printouts'],
        'institute_phone'         => ['', 'branding', 'Contact phone number displayed on receipts/forms'],
        'institute_address'       => ['', 'branding', 'Institute address displayed on receipts/forms'],
        'receipt_terms_text'      => ["• Form confirmation fee is non-refundable upon cancellation.\n• Official computer-generated receipt for institutional records.", 'branding', 'Terms printed at bottom of fee receipts'],
        'admission_rules_text'    => [$defaultRules, 'branding', 'Rules printed on Page 2 of Admission Form (1 rule per line)'],
        'admission_caution_text'  => ['ફોર્મ વ્યવસ્થિત વાંચીને સમજી વિચારીને ભરવું પછી તેના નિયમ વિરુદ્ધ કંઈપણ સાંભળવામાં આવશે નહીં.', 'branding', 'Caution banner text at bottom of Admission Form rules'],
    ];
}

function load_all_settings(bool $forceReload = false): array
{
    if ($forceReload || !isset($GLOBALS['_APP_SETTINGS_CACHE'])) {
        $pdo = db();
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        $GLOBALS['_APP_SETTINGS_CACHE'] = $map;

        if (($map['_settings_v2_ready'] ?? '') !== '1') {
            ensure_settings_initialized($pdo, $map);
        }
        if (($map['_schema_v4_ready'] ?? '') !== '1') {
            ensure_database_schema_ready($pdo);
            $map['_schema_v4_ready'] = '1';
            $GLOBALS['_APP_SETTINGS_CACHE'] = $map;
        }
    }
    return $GLOBALS['_APP_SETTINGS_CACHE'];
}

function ensure_database_schema_ready(PDO $pdo): void
{
    static $executed = false;
    if ($executed) return;
    $executed = true;

    try {
        // 1. Ensure students table has password_hash and lifecycle columns
        $studentCols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('password_hash', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER student_mobile");
        }
        if (!in_array('is_paused', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN is_paused TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('paused_at', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN paused_at DATE DEFAULT NULL");
        }
        if (!in_array('paused_remaining_days', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN paused_remaining_days INT DEFAULT 0");
        }
        if (!in_array('resume_ready_date', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN resume_ready_date DATE DEFAULT NULL");
        }
        if (!in_array('pause_reason', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN pause_reason TEXT DEFAULT NULL");
        }
        if (!in_array('is_temporary', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN is_temporary TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('hear_about_us', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN hear_about_us VARCHAR(100) DEFAULT NULL");
        }
        if (!in_array('guardian_signature_name', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN guardian_signature_name VARCHAR(150) DEFAULT NULL");
        }
        if (!in_array('guardian_relation', $studentCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN guardian_relation VARCHAR(50) DEFAULT NULL");
        }

        // 2. Ensure student_notices table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_notices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            batch_id INT UNSIGNED DEFAULT NULL,
            priority ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
            created_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student_notices_batch (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 3. Ensure student_leave_requests table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_leave_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            leave_type ENUM('leave','extension','re_course') NOT NULL DEFAULT 'leave',
            start_date DATE NOT NULL,
            end_date DATE DEFAULT NULL,
            total_days INT UNSIGNED DEFAULT 1,
            reason TEXT NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            admin_note TEXT DEFAULT NULL,
            reviewed_by INT UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_leave_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 4. Ensure payments table has notes and is_manual
        $paymentCols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_manual', $paymentCols, true)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('notes', $paymentCols, true)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN notes VARCHAR(255) DEFAULT NULL");
        }

        // 5. Ensure payment_otps table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_otps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            otp_code VARCHAR(10) NOT NULL,
            context ENUM('payment','manual_receipt','delete_payment') NOT NULL DEFAULT 'payment',
            student_name VARCHAR(150) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            stage_label VARCHAR(150) NOT NULL,
            method VARCHAR(50) NOT NULL,
            details_json TEXT DEFAULT NULL,
            sent_to_1 VARCHAR(20) DEFAULT NULL,
            sent_to_2 VARCHAR(20) DEFAULT NULL,
            status ENUM('pending','verified','expired') NOT NULL DEFAULT 'pending',
            requested_by INT UNSIGNED DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_otp_code_status (otp_code, status),
            INDEX idx_otp_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 6. Record migration done in settings
        $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_group, description)
            VALUES ('_schema_v4_ready', '1', 'system', 'Auto-migration complete for all tables and columns')
            ON DUPLICATE KEY UPDATE setting_value = '1'
        ")->execute();

    } catch (Throwable $e) {
        // Non-fatal fallback
    }
}

function ensure_settings_initialized(PDO $pdo, array &$cache): void
{
    static $running = false;
    if ($running) {
        return;
    }
    $running = true;

    try {
        // Ensure setting_value can hold long print rules and terms text safely
        $colStmt = $pdo->query("SHOW COLUMNS FROM settings LIKE 'setting_value'");
        $colInfo = $colStmt ? $colStmt->fetch() : null;
        if ($colInfo && stripos($colInfo['Type'] ?? '', 'text') === false) {
            $pdo->exec("ALTER TABLE settings MODIFY COLUMN setting_value TEXT NOT NULL");
        }

        $catalog = get_default_settings_catalog();
        $insertStmt = $pdo->prepare(
            "INSERT IGNORE INTO settings (setting_key, setting_value, setting_group, description)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($catalog as $key => [$val, $group, $desc]) {
            if (!array_key_exists($key, $cache)) {
                $insertStmt->execute([$key, $val, $group, $desc]);
                $cache[$key] = $val;
            }
        }

        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, setting_group, description)
             VALUES ('_settings_v2_ready', '1', 'system', 'Internal migration marker for dynamic settings')
             ON DUPLICATE KEY UPDATE setting_value = '1'"
        )->execute();
        $cache['_settings_v2_ready'] = '1';
        $GLOBALS['_APP_SETTINGS_CACHE'] = $cache;
    } catch (Throwable $e) {
        // Non-fatal fallback; defaults remain accessible via get_setting()
    } finally {
        $running = false;
    }
}

function get_setting(string $key, $default = null)
{
    $cache = load_all_settings();
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $catalog = get_default_settings_catalog();
    if (isset($catalog[$key]) && $default === null) {
        return $catalog[$key][0];
    }
    return $default;
}

function set_setting(string $key, string $value, ?int $userId = null, ?string $group = null, ?string $description = null): void
{
    $pdo = db();
    if ($group !== null) {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, setting_group, description, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group), updated_by = VALUES(updated_by), updated_at = NOW()"
        );
        $stmt->execute([$key, $value, $group, $description, $userId]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()"
        );
        $stmt->execute([$key, $value, $userId]);
    }
    if (isset($GLOBALS['_APP_SETTINGS_CACHE'])) {
        $GLOBALS['_APP_SETTINGS_CACHE'][$key] = $value;
    }
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
