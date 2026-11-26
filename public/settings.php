<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/extension_engine.php';
require_once __DIR__ . '/../includes/services/WhatsAppService.php';
require_once __DIR__ . '/../includes/services/StudentService.php';
require_super_admin();

$pdo = db();
$error = null;
$allowedTabs = ['form_fields', 'branding', 'fees', 'course', 'numbering', 'whatsapp'];
$activeTab = $_GET['tab'] ?? 'form_fields';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'form_fields';
}

// Ensure settings catalog is initialized
load_all_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postTab = $_POST['active_tab'] ?? $activeTab;
    if (!in_array($postTab, $allowedTabs, true)) {
        $postTab = 'form_fields';
    }
    $activeTab = $postTab;

    try {
        $action = $_POST['action'] ?? '';
        $uid = (int)current_user()['id'];

        if ($action === 'update_form_fields') {
            $fieldConfig = StudentService::getFormFieldConfig();
            $auditChanges = [];
            foreach ($fieldConfig as $key => $meta) {
                $vis = true;
                if (!empty($meta['can_hide'])) {
                    $vis = isset($_POST['vis'][$key]);
                    set_setting('form_vis_' . $key, $vis ? '1' : '0', $uid, 'form_fields', $meta['label'] . ' visibility');
                }
                if (!empty($meta['can_optional'])) {
                    $req = $vis && isset($_POST['req'][$key]);
                    set_setting('form_req_' . $key, $req ? '1' : '0', $uid, 'form_fields', $meta['label'] . ' required');
                    $auditChanges[$key] = ['visible' => $vis ? 1 : 0, 'required' => $req ? 1 : 0];
                }
            }
            log_audit('settings', null, 'update_form_fields', null, $auditChanges);
            flash('success', 'Admission & Edit form field rules updated successfully.');

        } elseif ($action === 'update_branding') {
            $brandingKeys = [
                'business_name',
                'institute_tagline',
                'institute_phone',
                'institute_address',
                'receipt_terms_text',
                'admission_rules_text',
                'admission_caution_text',
            ];
            foreach ($brandingKeys as $key) {
                if (isset($_POST[$key])) {
                    set_setting($key, trim((string)$_POST[$key]), $uid, 'branding');
                }
            }
            log_audit('settings', null, 'update_branding', null, ['updated_by' => $uid]);
            flash('success', 'Institute branding, contact info, and print rules saved.');

        } elseif ($action === 'update_fees') {
            foreach (['form_fee', 'joining_fee', 'second_payment_amount', 'second_payment_due_days'] as $key) {
                if (isset($_POST[$key])) {
                    $val = trim((string)$_POST[$key]);
                    if ((float)$val < 0) {
                        throw new InvalidArgumentException('Fee amounts and due days cannot be negative.');
                    }
                    set_setting($key, $val, $uid, 'fees');
                }
            }
            log_audit('settings', null, 'update_fees', null, $_POST);
            flash('success', 'Fee structure and due rules updated.');

        } elseif ($action === 'update_extension_rate') {
            update_extension_charge_rate((float)$_POST['per_day_charge'], $_POST['effective_from'], $uid);
            flash('success', 'New per-day extension charge scheduled. Historical invoices remain unaffected.');

        } elseif ($action === 'update_course') {
            $courseDays = max(1, (int)($_POST['course_duration_days'] ?? 45));
            $batchCap = max(1, (int)($_POST['default_batch_capacity'] ?? 8));
            set_setting('course_duration_days', (string)$courseDays, $uid, 'course');
            set_setting('default_batch_capacity', (string)$batchCap, $uid, 'batch');
            log_audit('settings', null, 'update_course', null, [
                'course_duration_days' => $courseDays,
                'default_batch_capacity' => $batchCap,
            ]);
            flash('success', 'Course duration and default batch capacity updated.');

        } elseif ($action === 'update_numbering') {
            $prefixKeys = ['form_no_prefix', 'registration_no_prefix', 'receipt_no_prefix', 'invoice_no_prefix'];
            foreach ($prefixKeys as $key) {
                if (isset($_POST[$key])) {
                    $val = trim((string)$_POST[$key]);
                    if ($val === '') {
                        throw new InvalidArgumentException('Prefixes cannot be empty.');
                    }
                    set_setting($key, $val, $uid, 'numbering');
                }
            }
            log_audit('settings', null, 'update_numbering', null, $_POST);
            flash('success', 'Document numbering prefixes updated.');

        } elseif ($action === 'update_whatsapp') {
            $waKeys = ['wa_otp_enabled', 'wa_phone_number_id', 'wa_access_token', 'wa_api_version', 'wa_template_name', 'wa_template_lang', 'wa_otp_mobile_1', 'wa_otp_mobile_2'];
            foreach ($waKeys as $key) {
                $val = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
                if ($key === 'wa_otp_enabled') {
                    $val = isset($_POST['wa_otp_enabled']) ? '1' : '0';
                }
                set_setting($key, $val, $uid, 'whatsapp');
            }
            log_audit('settings', null, 'update_whatsapp', null, ['updated_by' => $uid]);
            flash('success', 'WhatsApp Meta API & OTP settings saved successfully.');

        } elseif ($action === 'test_whatsapp_otp') {
            $res = WhatsAppService::sendOtpToAdmins([
                'context' => 'payment',
                'student_name' => 'Test Verification Student',
                'amount' => (float)get_setting('form_fee', 500),
                'stage_label' => 'Test Connection OTP',
                'method' => 'UPI',
                'collector_name' => current_user()['name'] ?? 'Super Admin',
            ], $uid);
            $nums = implode(' & ', $res['masked_numbers']);
            flash('success', "Test WhatsApp OTP sent successfully to {$nums}.");
        }

        redirect('public/settings.php?tab=' . $postTab);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$s = load_all_settings(true);
$fieldConfig = StudentService::getFormFieldConfig();
$rateHistory = $pdo->query("SELECT * FROM extension_charge_rates ORDER BY effective_from DESC")->fetchAll();
$currentRateRow = get_current_extension_rate();

$pageTitle = 'System Settings & Customizer';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.settings-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
    padding: 6px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xs);
}
.settings-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 15px;
    border-radius: var(--radius);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-body);
    background: transparent;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}
.settings-tab-btn:hover {
    background: var(--surface-subtle);
    color: var(--text);
}
.settings-tab-btn.active {
    background: var(--primary);
    color: #FFFFFF;
    box-shadow: var(--shadow-xs);
}
.settings-tab-pane {
    display: none;
}
.settings-tab-pane.active {
    display: block;
}
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
.switch-toggle {
    position: relative;
    display: inline-block;
    width: 42px;
    height: 24px;
}
.switch-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.switch-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: #CBD5E1;
    transition: 0.2s;
    border-radius: 24px;
}
.switch-slider::before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: #FFFFFF;
    transition: 0.2s;
    border-radius: 50%;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}
.switch-toggle input:checked + .switch-slider {
    background-color: var(--primary);
}
.switch-toggle input:checked + .switch-slider::before {
    transform: translateX(18px);
}
.switch-toggle input:disabled + .switch-slider {
    opacity: 0.55;
    cursor: not-allowed;
}
.prefix-preview {
    display: inline-block;
    margin-top: 5px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px;
    padding: 2px 8px;
    background: var(--primary-light);
    color: var(--primary-hover);
    border-radius: 4px;
    font-weight: 600;
}
@media (max-width: 900px) {
    .wa-span-2 {
        grid-column: 1 / -1;
    }
}
</style>

<div class="page-header">
    <div>
        <p class="page-header-subtitle">Customize admission form fields, institute branding, print rules, fees, course engine, document prefixes, and WhatsApp OTP verification.</p>
    </div>
</div>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<!-- Tab Navigation -->
<div class="settings-tabs" role="tablist">
    <button type="button" class="settings-tab-btn <?= $activeTab === 'form_fields' ? 'active' : '' ?>" data-tab="form_fields" onclick="switchSettingsTab('form_fields')">
        📋 Form Fields Customizer
    </button>
    <button type="button" class="settings-tab-btn <?= $activeTab === 'branding' ? 'active' : '' ?>" data-tab="branding" onclick="switchSettingsTab('branding')">
        🏫 Institute &amp; Print Rules
    </button>
    <button type="button" class="settings-tab-btn <?= $activeTab === 'fees' ? 'active' : '' ?>" data-tab="fees" onclick="switchSettingsTab('fees')">
        💰 Fees &amp; Extension Rate
    </button>
    <button type="button" class="settings-tab-btn <?= $activeTab === 'course' ? 'active' : '' ?>" data-tab="course" onclick="switchSettingsTab('course')">
        📅 Course &amp; Batches
    </button>
    <button type="button" class="settings-tab-btn <?= $activeTab === 'numbering' ? 'active' : '' ?>" data-tab="numbering" onclick="switchSettingsTab('numbering')">
        🔢 Document Prefixes
    </button>
    <button type="button" class="settings-tab-btn <?= $activeTab === 'whatsapp' ? 'active' : '' ?>" data-tab="whatsapp" onclick="switchSettingsTab('whatsapp')">
        💬 WhatsApp &amp; OTP
    </button>
</div>

<!-- ==================== TAB 1: FORM FIELDS CUSTOMIZER ==================== -->
<div class="settings-tab-pane <?= $activeTab === 'form_fields' ? 'active' : '' ?>" id="tab-form_fields">
    <div class="card">
        <div class="card-header-bar">
            <div>
                <h2>Admission &amp; Edit Form Field Controls</h2>
                <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Choose which fields are visible and which fields are mandatory (*) in Student Registration and Edit Student forms.</p>
            </div>
            <span class="badge badge-primary">Live Form Sync</span>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_form_fields">
            <input type="hidden" name="active_tab" value="form_fields">

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Field Name (English / Gujarati)</th>
                            <th>Form Section</th>
                            <th style="text-align: center; width: 130px;">Show in Form</th>
                            <th style="text-align: center; width: 140px;">Mandatory (*)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fieldConfig as $key => $field): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--text);">
                                        <?= e($field['label']) ?>
                                        <span class="muted" style="font-weight: 400; font-size: 12px;">(<?= e($field['gujarati_label']) ?>)</span>
                                    </div>
                                    <?php if (!empty($field['note'])): ?>
                                        <div class="muted" style="font-size: 11.5px; margin-top: 2px;"><?= e($field['note']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-gray"><?= e($field['group']) ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (!$field['can_hide']): ?>
                                        <label class="switch-toggle" title="Core field — always visible">
                                            <input type="checkbox" checked disabled>
                                            <span class="switch-slider"></span>
                                        </label>
                                        <div class="muted" style="font-size: 10.5px; margin-top: 2px;">Always Shown</div>
                                    <?php else: ?>
                                        <label class="switch-toggle">
                                            <input type="checkbox"
                                                   name="vis[<?= e($key) ?>]"
                                                   value="1"
                                                   id="vis_<?= e($key) ?>"
                                                   <?= $field['visible'] ? 'checked' : '' ?>
                                                   onchange="syncFieldRequirement('<?= e($key) ?>')">
                                            <span class="switch-slider"></span>
                                        </label>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (!$field['can_optional']): ?>
                                        <label class="switch-toggle" title="Core identifier — always required">
                                            <input type="checkbox" checked disabled>
                                            <span class="switch-slider"></span>
                                        </label>
                                        <div class="muted" style="font-size: 10.5px; margin-top: 2px;">Always Required</div>
                                    <?php else: ?>
                                        <label class="switch-toggle">
                                            <input type="checkbox"
                                                   name="req[<?= e($key) ?>]"
                                                   value="1"
                                                   id="req_<?= e($key) ?>"
                                                   <?= $field['required'] ? 'checked' : '' ?>
                                                   <?= !$field['visible'] ? 'disabled' : '' ?>>
                                            <span class="switch-slider"></span>
                                        </label>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions" style="margin-top: 18px; justify-content: flex-end;">
                <button class="btn" type="submit">Save Form Field Settings</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== TAB 2: INSTITUTE BRANDING & PRINT RULES ==================== -->
<div class="settings-tab-pane <?= $activeTab === 'branding' ? 'active' : '' ?>" id="tab-branding">
    <div class="card">
        <div class="card-header-bar">
            <div>
                <h2>Institute Identity &amp; Printout Templates</h2>
                <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Displayed across fee receipts, extension invoices, admission printouts, and headers.</p>
            </div>
        </div>

        <form method="post" class="row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_branding">
            <input type="hidden" name="active_tab" value="branding">

            <div class="form-group">
                <label for="set_business_name">Institute / Business Name *</label>
                <input type="text" id="set_business_name" name="business_name" required value="<?= e($s['business_name'] ?? 'Devangi Sewing Classes') ?>">
            </div>
            <div class="form-group">
                <label for="set_tagline">Institute Tagline / Subtitle</label>
                <input type="text" id="set_tagline" name="institute_tagline" value="<?= e($s['institute_tagline'] ?? '') ?>" placeholder="e.g. Stitching & Fashion Designing Training Institute">
            </div>
            <div class="form-group">
                <label for="set_inst_phone">Institute Contact Phone</label>
                <input type="text" id="set_inst_phone" name="institute_phone" value="<?= e($s['institute_phone'] ?? '') ?>" placeholder="e.g. +91 98792 XXXXX">
            </div>
            <div class="form-group">
                <label for="set_inst_address">Institute Address (Displayed on Printouts)</label>
                <input type="text" id="set_inst_address" name="institute_address" value="<?= e($s['institute_address'] ?? '') ?>" placeholder="e.g. Surat, Gujarat">
            </div>

            <div class="form-group col-full">
                <label for="set_receipt_terms">Fee Receipt Terms &amp; Notes (1 point per line)</label>
                <textarea id="set_receipt_terms" name="receipt_terms_text" rows="3" style="font-size: 13px;"><?= e($s['receipt_terms_text'] ?? '') ?></textarea>
                <small class="muted">Printed in the bottom-left footer of every fee receipt.</small>
            </div>

            <div class="form-group col-full">
                <label for="set_admission_rules">Admission Form Rules — Page 2 (1 rule per line; automatically numbered 1, 2, 3...)</label>
                <textarea id="set_admission_rules" name="admission_rules_text" rows="12" style="font-size: 13px; line-height: 1.6;"><?= e($s['admission_rules_text'] ?? '') ?></textarea>
                <small class="muted">Each line becomes one numbered rule card on the printable A4 Admission Form.</small>
            </div>

            <div class="form-group col-full">
                <label for="set_caution_text">Admission Form Caution Banner Text</label>
                <input type="text" id="set_caution_text" name="admission_caution_text" value="<?= e($s['admission_caution_text'] ?? '') ?>">
            </div>

            <div class="form-actions col-full" style="justify-content: flex-end;">
                <button class="btn" type="submit">Save Branding &amp; Print Rules</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== TAB 3: FEES & EXTENSION RATE ==================== -->
<div class="settings-tab-pane <?= $activeTab === 'fees' ? 'active' : '' ?>" id="tab-fees">
    <div class="card">
        <div class="card-header-bar">
            <div>
                <h2>Course Fee Structure</h2>
                <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Changes apply only to new forms and future payments; existing records are never recalculated.</p>
            </div>
        </div>

        <form method="post" class="row4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_fees">
            <input type="hidden" name="active_tab" value="fees">

            <div class="form-group">
                <label for="set_form_fee">Form Fee (₹)</label>
                <input type="number" step="0.01" min="0" id="set_form_fee" name="form_fee" value="<?= e($s['form_fee'] ?? '500') ?>">
            </div>
            <div class="form-group">
                <label for="set_joining_fee">Joining Fee (₹)</label>
                <input type="number" step="0.01" min="0" id="set_joining_fee" name="joining_fee" value="<?= e($s['joining_fee'] ?? '3500') ?>">
            </div>
            <div class="form-group">
                <label for="set_second_amt">Second Payment Amount (₹)</label>
                <input type="number" step="0.01" min="0" id="set_second_amt" name="second_payment_amount" value="<?= e($s['second_payment_amount'] ?? '4000') ?>">
            </div>
            <div class="form-group">
                <label for="set_second_due">Second Payment Due (Days)</label>
                <input type="number" min="0" id="set_second_due" name="second_payment_due_days" value="<?= e($s['second_payment_due_days'] ?? '10') ?>">
            </div>

            <div class="form-actions col-full" style="justify-content: flex-end;">
                <button class="btn" type="submit">Save Fee Settings</button>
            </div>
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
            <input type="hidden" name="active_tab" value="fees">

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
</div>

<!-- ==================== TAB 4: COURSE & BATCHES ==================== -->
<div class="settings-tab-pane <?= $activeTab === 'course' ? 'active' : '' ?>" id="tab-course">
    <div class="card">
        <div class="card-header-bar">
            <div>
                <h2>Course Duration &amp; Batch Engine</h2>
                <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Controls standard course length and default seat limits when creating new batches.</p>
            </div>
        </div>

        <form method="post" class="row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_course">
            <input type="hidden" name="active_tab" value="course">

            <div class="form-group">
                <label for="set_course_days">Standard Course Duration (Calendar Days) *</label>
                <input type="number" min="1" id="set_course_days" name="course_duration_days" required value="<?= e($s['course_duration_days'] ?? '45') ?>">
                <small class="muted">Used to auto-calculate expiry dates for new student joinings.</small>
            </div>
            <div class="form-group">
                <label for="set_batch_cap">Default Batch Seat Capacity *</label>
                <input type="number" min="1" max="100" id="set_batch_cap" name="default_batch_capacity" required value="<?= e($s['default_batch_capacity'] ?? '8') ?>">
                <small class="muted">Pre-filled when creating a new batch in Batch Management.</small>
            </div>

            <div class="form-actions col-full" style="justify-content: flex-end;">
                <button class="btn" type="submit">Save Course &amp; Batch Settings</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== TAB 5: DOCUMENT NUMBERING & PREFIXES ==================== -->
<div class="settings-tab-pane <?= $activeTab === 'numbering' ? 'active' : '' ?>" id="tab-numbering">
    <div class="card">
        <div class="card-header-bar">
            <div>
                <h2>Document Numbering &amp; Prefixes</h2>
                <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Customize auto-generated prefixes for new forms, registrations, fee receipts, and invoices.</p>
            </div>
        </div>

        <form method="post" class="row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_numbering">
            <input type="hidden" name="active_tab" value="numbering">

            <div class="form-group">
                <label for="pref_form">Form Number Prefix *</label>
                <input type="text" id="pref_form" name="form_no_prefix" required value="<?= e($s['form_no_prefix'] ?? 'DSC-F-') ?>" oninput="updatePrefixPreview('pref_form', 'prev_form')">
                <span class="prefix-preview" id="prev_form">Preview: <?= e($s['form_no_prefix'] ?? 'DSC-F-') ?>00001</span>
            </div>
            <div class="form-group">
                <label for="pref_reg">Registration Number Prefix *</label>
                <input type="text" id="pref_reg" name="registration_no_prefix" required value="<?= e($s['registration_no_prefix'] ?? 'DSC-R-') ?>" oninput="updatePrefixPreview('pref_reg', 'prev_reg')">
                <span class="prefix-preview" id="prev_reg">Preview: <?= e($s['registration_no_prefix'] ?? 'DSC-R-') ?>00001</span>
            </div>
            <div class="form-group">
                <label for="pref_rcpt">Receipt Number Prefix *</label>
                <input type="text" id="pref_rcpt" name="receipt_no_prefix" required value="<?= e($s['receipt_no_prefix'] ?? 'DSC-RCPT-') ?>" oninput="updatePrefixPreview('pref_rcpt', 'prev_rcpt')">
                <span class="prefix-preview" id="prev_rcpt">Preview: <?= e($s['receipt_no_prefix'] ?? 'DSC-RCPT-') ?>00001</span>
            </div>
            <div class="form-group">
                <label for="pref_inv">Invoice Number Prefix *</label>
                <input type="text" id="pref_inv" name="invoice_no_prefix" required value="<?= e($s['invoice_no_prefix'] ?? 'DSC-INV-') ?>" oninput="updatePrefixPreview('pref_inv', 'prev_inv')">
                <span class="prefix-preview" id="prev_inv">Preview: <?= e($s['invoice_no_prefix'] ?? 'DSC-INV-') ?>00001</span>
            </div>

            <div class="form-actions col-full" style="justify-content: flex-end;">
                <button class="btn" type="submit">Save Numbering Prefixes</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== TAB 6: WHATSAPP META CLOUD API & OTP ==================== -->
<div class="settings-tab-pane <?= $activeTab === 'whatsapp' ? 'active' : '' ?>" id="tab-whatsapp">
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
            <input type="hidden" name="active_tab" value="whatsapp">

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

            <div class="form-actions col-full" style="margin-top: 4px; justify-content: flex-end;">
                <button class="btn" type="submit">Save WhatsApp Settings</button>
            </div>
        </form>

        <form method="post" class="wa-test-bar">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_whatsapp_otp">
            <input type="hidden" name="active_tab" value="whatsapp">
            <span class="muted" style="font-size: 12.5px;">
                <strong>Template Variables:</strong> If using an approved template, configure 4 body parameters: <code>{{1}}</code> = OTP, <code>{{2}}</code> = Student Name, <code>{{3}}</code> = Amount, <code>{{4}}</code> = Fee Details.
            </span>
            <button type="submit" class="btn btn-secondary btn-sm">
                Send Test OTP to Both Numbers
            </button>
        </form>
    </div>
</div>

<script>
function switchSettingsTab(tabId) {
    document.querySelectorAll('.settings-tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
    document.querySelectorAll('.settings-tab-pane').forEach(pane => {
        pane.classList.toggle('active', pane.id === 'tab-' + tabId);
    });
    if (history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        history.replaceState(null, '', url.toString());
    }
}

function syncFieldRequirement(key) {
    const visCb = document.getElementById('vis_' + key);
    const reqCb = document.getElementById('req_' + key);
    if (!visCb || !reqCb) return;
    if (!visCb.checked) {
        reqCb.checked = false;
        reqCb.disabled = true;
    } else {
        reqCb.disabled = false;
    }
}

function updatePrefixPreview(inputId, previewId) {
    const inp = document.getElementById(inputId);
    const prev = document.getElementById(previewId);
    if (inp && prev) {
        prev.textContent = 'Preview: ' + inp.value + '00001';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
