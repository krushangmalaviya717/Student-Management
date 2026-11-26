<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/extension_engine.php';
require_once __DIR__ . '/../includes/services/WhatsAppService.php';
require_super_admin();

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_general') {
            foreach (['business_name', 'course_duration_days', 'form_fee', 'joining_fee', 'second_payment_amount', 'second_payment_due_days'] as $key) {
                if (isset($_POST[$key])) set_setting($key, $_POST[$key], current_user()['id']);
            }
            log_audit('settings', null, 'update_general', null, $_POST);
            flash('success', 'Business and fee settings updated.');
        } elseif ($action === 'update_whatsapp') {
            $waKeys = ['wa_otp_enabled', 'wa_phone_number_id', 'wa_access_token', 'wa_api_version', 'wa_template_name', 'wa_template_lang', 'wa_otp_mobile_1', 'wa_otp_mobile_2'];
            foreach ($waKeys as $key) {
                $val = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
                if ($key === 'wa_otp_enabled') {
                    $val = isset($_POST['wa_otp_enabled']) ? '1' : '0';
                }
                set_setting($key, $val, current_user()['id']);
            }
            log_audit('settings', null, 'update_whatsapp', null, ['updated_by' => current_user()['id']]);
            flash('success', 'WhatsApp Meta API & OTP settings saved successfully.');
        } elseif ($action === 'test_whatsapp_otp') {
            $res = WhatsAppService::sendOtpToAdmins([
                'context' => 'payment',
                'student_name' => 'Test Verification Student',
                'amount' => 500,
                'stage_label' => 'Test Connection OTP',
                'method' => 'UPI',
                'collector_name' => current_user()['name'] ?? 'Super Admin',
            ], (int)current_user()['id']);
            $nums = implode(' & ', $res['masked_numbers']);
            flash('success', "Test WhatsApp OTP sent successfully to {$nums}.");
        } elseif ($action === 'update_extension_rate') {
            update_extension_charge_rate((float)$_POST['per_day_charge'], $_POST['effective_from'], current_user()['id']);
            flash('success', 'New per-day extension charge scheduled. Historical invoices remain unaffected.');
        }
        redirect('public/settings.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$settings = $pdo->query("SELECT * FROM settings ORDER BY setting_group, setting_key")->fetchAll();
$s = [];
foreach ($settings as $row) $s[$row['setting_key']] = $row['setting_value'];

$rateHistory = $pdo->query("SELECT * FROM extension_charge_rates ORDER BY effective_from DESC")->fetchAll();
$currentRateRow = get_current_extension_rate();

$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.wa-span-2 {
    grid-column: span 2;
}
.wa-test-bar {
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px dashed var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
@media (max-width: 900px) {
    .wa-span-2 {
        grid-column: 1 / -1;
    }
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-header-subtitle">Configure business profile, fee structure, WhatsApp Cloud API verification, and extension billing rates.</p>
    </div>
</div>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<!-- Business & Fee Settings -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Business &amp; Fee Settings</h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Changes apply only to new forms and future payments; existing records are never recalculated.</p>
        </div>
    </div>

    <form method="post" class="row3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_general">
        <div class="form-group">
            <label for="set_business_name">Business Name</label>
            <input type="text" id="set_business_name" name="business_name" value="<?= e($s['business_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="set_course_days">Course Duration (Days)</label>
            <input type="number" id="set_course_days" name="course_duration_days" value="<?= e($s['course_duration_days'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="set_second_due">Second Payment Due (Days)</label>
            <input type="number" id="set_second_due" name="second_payment_due_days" value="<?= e($s['second_payment_due_days'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="set_form_fee">Form Fee (₹)</label>
            <input type="number" step="0.01" id="set_form_fee" name="form_fee" value="<?= e($s['form_fee'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="set_joining_fee">Joining Fee (₹)</label>
            <input type="number" step="0.01" id="set_joining_fee" name="joining_fee" value="<?= e($s['joining_fee'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="set_second_amt">Second Payment Amount (₹)</label>
            <input type="number" step="0.01" id="set_second_amt" name="second_payment_amount" value="<?= e($s['second_payment_amount'] ?? '') ?>">
        </div>
        <div class="form-actions col-full" style="margin-top: 4px;">
            <button class="btn" type="submit">Save General Settings</button>
        </div>
    </form>
</div>

<!-- WhatsApp Meta Cloud API & 2-Number OTP Verification -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>WhatsApp Meta Cloud API &amp; OTP Verification</h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Automatically sends OTP and fee payment details to the configured admin WhatsApp numbers during fee collection or manual receipt creation.</p>
        </div>
        <span class="badge badge-green">Meta Official API</span>
    </div>

    <form method="post" class="row3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_whatsapp">

        <div class="form-group">
            <label for="wa_mobile_1">Admin WhatsApp Number 1 (Primary) *</label>
            <input type="text" id="wa_mobile_1" name="wa_otp_mobile_1" value="<?= e($s['wa_otp_mobile_1'] ?? '') ?>" placeholder="e.g. 9879200000 or 919879200000">
        </div>
        <div class="form-group">
            <label for="wa_mobile_2">Admin WhatsApp Number 2 (Secondary)</label>
            <input type="text" id="wa_mobile_2" name="wa_otp_mobile_2" value="<?= e($s['wa_otp_mobile_2'] ?? '') ?>" placeholder="e.g. 9879211111 or 919879211111">
        </div>
        <div class="form-group" style="align-self: center;">
            <label class="checkbox-label" for="wa_otp_enabled">
                <input type="checkbox" id="wa_otp_enabled" name="wa_otp_enabled" value="1" <?= ($s['wa_otp_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                <span>Enable WhatsApp OTP Verification</span>
            </label>
        </div>

        <div class="form-group">
            <label for="wa_phone_id">Meta Phone Number ID *</label>
            <input type="text" id="wa_phone_id" name="wa_phone_number_id" value="<?= e($s['wa_phone_number_id'] ?? '') ?>" placeholder="e.g. 109876543210123" style="font-family: monospace;">
        </div>
        <div class="form-group wa-span-2">
            <label for="wa_access_token">Meta Permanent Access Token *</label>
            <input type="password" id="wa_access_token" name="wa_access_token" value="<?= e($s['wa_access_token'] ?? '') ?>" placeholder="EAAG..." style="font-family: monospace;">
        </div>

        <div class="form-group">
            <label for="wa_template_name">Approved Template Name (Optional)</label>
            <input type="text" id="wa_template_name" name="wa_template_name" value="<?= e($s['wa_template_name'] ?? '') ?>" placeholder="e.g. payment_otp (blank = direct text)" style="font-family: monospace;">
        </div>
        <div class="form-group">
            <label for="wa_template_lang">Template Language Code</label>
            <input type="text" id="wa_template_lang" name="wa_template_lang" value="<?= e($s['wa_template_lang'] ?? 'en') ?>" placeholder="en or en_US or gu">
        </div>
        <div class="form-group">
            <label for="wa_api_version">Meta Graph API Version</label>
            <input type="text" id="wa_api_version" name="wa_api_version" value="<?= e($s['wa_api_version'] ?? 'v21.0') ?>" placeholder="v21.0">
        </div>

        <div class="form-actions col-full" style="margin-top: 4px;">
            <button class="btn" type="submit">Save WhatsApp Settings</button>
        </div>
    </form>

    <form method="post" class="wa-test-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_whatsapp_otp">
        <span class="muted" style="font-size: 12.5px;">
            <strong>Template Variables:</strong> If using an approved template, configure 4 body parameters: <code>{{1}}</code> = OTP, <code>{{2}}</code> = Student Name, <code>{{3}}</code> = Amount, <code>{{4}}</code> = Fee Details.
        </span>
        <button type="submit" class="btn btn-secondary btn-sm">
            Send Test OTP to Both Numbers
        </button>
    </form>
</div>

<!-- Per-Day Extension Charge -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Per-Day Extension Charge</h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Days billed under prior rates retain their historical rate on existing invoices. Multi-rate spans calculate automatically.</p>
        </div>
        <span class="badge badge-primary">
            Current Rate: <?= $currentRateRow ? '₹' . number_format($currentRateRow['per_day_charge'], 2) . ' / day' : 'Not set' ?>
        </span>
    </div>

    <form method="post" class="row3" style="margin-bottom: 18px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_extension_rate">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="ext_per_day">New Per-Day Charge (₹) *</label>
            <input type="number" step="0.01" id="ext_per_day" name="per_day_charge" required placeholder="e.g. 150.00">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label for="ext_effective">Effective From *</label>
            <input type="date" id="ext_effective" name="effective_from" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Schedule New Rate</button>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Effective From</th>
                    <th>Effective To</th>
                    <th>Rate / Day</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rateHistory): ?>
                    <tr>
                        <td colspan="4" class="muted" style="text-align: center; padding: 24px;">No extension rate history found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rateHistory as $r): ?>
                        <tr>
                            <td style="font-variant-numeric: tabular-nums;"><?= format_date($r['effective_from']) ?></td>
                            <td style="font-variant-numeric: tabular-nums;"><?= $r['effective_to'] ? format_date($r['effective_to']) : 'Current' ?></td>
                            <td style="font-weight: 600; font-variant-numeric: tabular-nums;">₹<?= number_format($r['per_day_charge'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= $r['status'] === 'active' ? 'green' : 'gray' ?>"><?= ucfirst($r['status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
