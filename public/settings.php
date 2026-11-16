<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/extension_engine.php';
require_super_admin();

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_general') {
            foreach (['business_name','course_duration_days','form_fee','joining_fee','second_payment_amount','second_payment_due_days'] as $key) {
                if (isset($_POST[$key])) set_setting($key, $_POST[$key], current_user()['id']);
            }
            log_audit('settings', null, 'update_general', null, $_POST);
            flash('success', 'Settings updated.');
        } elseif ($action === 'update_extension_rate') {
            update_extension_charge_rate((float)$_POST['per_day_charge'], $_POST['effective_from'], current_user()['id']);
            flash('success', 'New per-day extension charge scheduled. Historical invoices are unaffected.');
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

$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2 class="mt-0">Business &amp; Fee Settings</h2>
    <form method="post" class="row3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_general">
        <div><label>Business Name</label><input type="text" name="business_name" value="<?= e($s['business_name'] ?? '') ?>"></div>
        <div><label>Course Duration (days)</label><input type="number" name="course_duration_days" value="<?= e($s['course_duration_days'] ?? '') ?>"></div>
        <div><label>Second Payment Due (days)</label><input type="number" name="second_payment_due_days" value="<?= e($s['second_payment_due_days'] ?? '') ?>"></div>
        <div><label>Form Fee (₹)</label><input type="number" step="0.01" name="form_fee" value="<?= e($s['form_fee'] ?? '') ?>"></div>
        <div><label>Joining Fee (₹)</label><input type="number" step="0.01" name="joining_fee" value="<?= e($s['joining_fee'] ?? '') ?>"></div>
        <div><label>Second Payment Amount (₹)</label><input type="number" step="0.01" name="second_payment_amount" value="<?= e($s['second_payment_amount'] ?? '') ?>"></div>
        <div class="form-actions" style="grid-column: 1 / -1; margin-top: 14px;"><button class="btn" type="submit">Save Settings</button></div>
    </form>
    <p class="muted">Changing these amounts only affects new forms/payments going forward — existing payment records are never recalculated.</p>
</div>

<div class="card">
    <h2 class="mt-0">Per-Day Extension Charge</h2>
    <p>Current rate: <strong>₹<?= $currentRate = get_current_extension_rate() ? number_format(get_current_extension_rate()['per_day_charge'],2) : 'not set' ?>/day</strong></p>
    <form method="post" class="row3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_extension_rate">
        <div><label>New Per-Day Charge (₹)</label><input type="number" step="0.01" name="per_day_charge" required></div>
        <div><label>Effective From</label><input type="date" name="effective_from" required value="<?= date('Y-m-d') ?>"></div>
        <div class="form-actions"><button class="btn" type="submit">Schedule New Rate</button></div>
    </form>
    <p class="muted" style="font-size:12.5px;">Days already charged at the old rate keep their old rate on existing invoices, permanently. Extensions spanning the change date are billed with rate-wise line items automatically.</p>

    <table style="margin-top:12px;">
        <tr><th>Effective From</th><th>Effective To</th><th>Rate/Day</th><th>Status</th></tr>
        <?php foreach ($rateHistory as $r): ?>
        <tr><td><?= format_date($r['effective_from']) ?></td><td><?= $r['effective_to'] ? format_date($r['effective_to']) : 'Current' ?></td>
            <td>₹<?= number_format($r['per_day_charge'],2) ?></td>
            <td><span class="badge badge-<?= $r['status']==='active'?'green':'gray' ?>"><?= ucfirst($r['status']) ?></span></td></tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
