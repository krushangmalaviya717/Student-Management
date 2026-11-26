<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/StaffService.php';
require_once __DIR__ . '/../includes/services/AttendanceService.php';
require_once __DIR__ . '/../includes/services/PayrollService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
require_once __DIR__ . '/../includes/components/kpi_card.php';

require_admin();

$user = current_user();
$error = null;

// Selected Month
$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
list($year, $monthNum) = explode('-', $selectedMonth);
$year = (int)$year;
$monthNum = (int)$monthNum;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'generate_all') {
            $count = PayrollService::generateAllForMonth($selectedMonth, (int)$user['id']);
            flash('success', 'Payroll calculated and saved for ' . date('F Y', strtotime("{$selectedMonth}-01")) . " ({$count} staff).");
            redirect("public/staff_payroll.php?month={$selectedMonth}");

        } elseif ($action === 'record_payment') {
            $payrollId = (int)($_POST['payroll_id'] ?? 0);
            PayrollService::markAsPaid($payrollId, $_POST, (int)$user['id']);
            flash('success', 'Salary payment recorded successfully.');
            redirect("public/staff_payroll.php?month={$selectedMonth}");

        } elseif ($action === 'adjust_payroll') {
            $staffId = (int)($_POST['staff_id'] ?? 0);
            $payrollId = PayrollService::savePayroll($staffId, $selectedMonth, $_POST, (int)$user['id']);
            flash('success', 'Bonus and deduction adjustments saved.');
            redirect("public/staff_payroll.php?month={$selectedMonth}");
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Fetch Payroll List & Statistics
$payrollList = PayrollService::getPayrollList($selectedMonth);
$stats = PayrollService::getMonthlyStats($selectedMonth);

// If no payroll records exist yet for this month, calculate preview on the fly for active staff
if (empty($payrollList)) {
    $activeStaff = StaffService::getAllStaff('active');
    $calculatedList = [];
    foreach ($activeStaff as $st) {
        $calc = PayrollService::calculateSalary($st['id'], $year, $monthNum);
        $calculatedList[] = [
            'id' => null,
            'staff_id' => $st['id'],
            'staff_name' => $st['name'],
            'staff_phone' => $st['phone'],
            'staff_designation' => $st['designation'],
            'registered_salary' => $st['salary'],
            'total_days_in_month' => $calc['total_days'],
            'working_days' => $calc['working_days'],
            'present_days' => $calc['present_days'],
            'half_days' => $calc['half_days'],
            'absent_days' => $calc['absent_days'],
            'paid_leaves' => $calc['paid_leaves'],
            'holidays_count' => $calc['holidays_count'],
            'payable_days' => $calc['payable_days'],
            'base_salary' => $calc['base_salary'],
            'daily_rate' => $calc['daily_rate'],
            'gross_salary' => $calc['gross_salary'],
            'deductions' => $calc['deductions'],
            'bonus' => $calc['bonus'],
            'net_salary' => $calc['net_salary'],
            'payment_status' => 'pending',
            'paid_amount' => 0.00,
            'is_draft' => true
        ];
    }
    $payrollList = $calculatedList;
}

// Export CSV
if (($_GET['export'] ?? '') === 'csv') {
    $headers = ['Staff Name', 'Designation', 'Total Days', 'Present', 'Half Days', 'Holidays/Sundays', 'Absent (LOP)', 'Payable Days', 'Base Salary', 'Gross Earned', 'Bonus', 'Deductions', 'Net Payable', 'Status'];
    $rows = [];
    foreach ($payrollList as $p) {
        $rows[] = [
            $p['staff_name'],
            $p['staff_designation'],
            $p['total_days_in_month'],
            $p['present_days'],
            $p['half_days'],
            $p['holidays_count'],
            $p['absent_days'],
            $p['payable_days'],
            $p['base_salary'],
            $p['gross_salary'],
            $p['bonus'],
            $p['deductions'],
            $p['net_salary'],
            ucfirst($p['payment_status'])
        ];
    }
    ExportService::downloadCsv("Staff_Payroll_{$selectedMonth}.csv", $headers, $rows);
}

$monthLabel = date('F Y', strtotime("{$selectedMonth}-01"));
$pageTitle = 'Staff Payroll & Salary';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.payslip-box {
    background: #FFFFFF;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px;
    font-family: inherit;
}
.payslip-header {
    text-align: center;
    border-bottom: 1px dashed var(--border-input);
    padding-bottom: 14px;
    margin-bottom: 16px;
}
.payslip-row {
    display: flex;
    justify-content: space-between;
    padding: 7px 0;
    font-size: 13.5px;
    border-bottom: 1px solid var(--border-light);
}
.payslip-total-row {
    display: flex;
    justify-content: space-between;
    padding: 11px 0;
    font-size: 15.5px;
    font-weight: 700;
    border-top: 2px solid var(--text);
    border-bottom: 2px solid var(--text);
    margin-top: 10px;
    font-variant-numeric: tabular-nums;
}
.payslip-att-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    text-align: center;
    font-size: 12px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    padding: 10px;
    border-radius: var(--radius);
}
.pay-summary-callout {
    background: var(--surface-subtle);
    padding: 12px 14px;
    border-radius: var(--radius);
    margin-bottom: 14px;
    border: 1px solid var(--border);
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-header-subtitle">Monthly attendance-based salary calculation, adjustments, payouts, and printable payslips.</p>
    </div>
    <div class="action-group">
        <a href="?month=<?= e($selectedMonth) ?>&export=csv" class="btn btn-secondary btn-export btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
        <form method="post" action="" style="display: inline; margin: 0;" onsubmit="return confirm('Auto-calculate and save payroll for all active staff in <?= e($monthLabel) ?>?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="generate_all">
            <button type="submit" class="btn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                Auto-Generate Payroll
            </button>
        </form>
    </div>
</div>

<!-- Staff Module Sub-Navigation -->
<div class="tabs-underline">
    <a href="<?= url('public/staff.php') ?>" class="tab-underline-btn" style="text-decoration: none;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Staff Directory
    </a>
    <a href="<?= url('public/staff_attendance.php?view=daily') ?>" class="tab-underline-btn" style="text-decoration: none;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Daily Attendance
    </a>
    <a href="<?= url('public/staff_attendance.php?view=monthly') ?>" class="tab-underline-btn" style="text-decoration: none;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Monthly Register
    </a>
    <a href="<?= url('public/staff_payroll.php?month=' . e($selectedMonth)) ?>" class="tab-underline-btn active" style="text-decoration: none;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        Payroll &amp; Salary
    </a>
</div>

<?php if ($error): ?>
    <div class="flash flash-error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Payroll KPI Statistics -->
<div class="grid grid-4" style="margin-bottom: 20px;">
    <?= render_kpi_card('Total Net Payable', '₹' . number_format($stats['total_net']), $monthLabel, '', 'primary') ?>
    <?= render_kpi_card('Total Paid', '₹' . number_format($stats['total_paid']), $stats['paid_count'] . ' staff settled', '', 'success') ?>
    <?= render_kpi_card('Pending Payout', '₹' . number_format($stats['total_pending']), $stats['pending_count'] . ' staff pending', '', 'warning') ?>
    <?= render_kpi_card('LOP Deductions', '₹' . number_format($stats['total_deductions']), 'Absence & half-day cuts', '', 'danger') ?>
</div>

<!-- Salary Register Card -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Salary Register &mdash; <?= e($monthLabel) ?></h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Declared institute holidays and Sundays are paid with zero deduction.</p>
        </div>
        <form method="get" action="" style="display: inline-flex; gap: 10px; align-items: center; margin: 0;">
            <label for="payroll_month" style="margin: 0; white-space: nowrap;">Payroll Month</label>
            <input type="month" id="payroll_month" name="month" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()" style="width: auto; font-weight: 600;">
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Attendance Breakdown</th>
                    <th>Payable Days</th>
                    <th>Base Salary</th>
                    <th>Daily Rate</th>
                    <th>Gross Earned</th>
                    <th>Bonus / Deduct</th>
                    <th>Net Salary</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payrollList)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 36px 20px; color: var(--muted);">
                            No active staff records found for payroll calculation.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payrollList as $p): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600; color: var(--text); font-size: 13.5px;"><?= e($p['staff_name']) ?></div>
                                <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
                                    <?= e($p['staff_designation']) ?> &middot; <?= e($p['staff_phone']) ?>
                                </div>
                            </td>
                            <td style="white-space: nowrap;">
                                <div style="font-size: 12px; line-height: 1.4; font-variant-numeric: tabular-nums;">
                                    <span style="color: #166534; font-weight: 700;">P: <?= $p['present_days'] ?></span> &middot;
                                    <span style="color: #92400E; font-weight: 700;">HD: <?= $p['half_days'] ?></span> &middot;
                                    <span style="color: var(--primary-hover); font-weight: 700;" title="Sundays &amp; Declared Holidays">H: <?= $p['holidays_count'] ?></span> &middot;
                                    <span style="color: #991B1B; font-weight: 700;">A: <?= $p['absent_days'] ?></span>
                                </div>
                                <div style="font-size: 11px; color: var(--muted); margin-top: 2px;">
                                    <?= $p['total_days_in_month'] ?> days in month
                                </div>
                            </td>
                            <td style="white-space: nowrap; font-variant-numeric: tabular-nums;">
                                <div style="font-weight: 700; font-size: 13.5px; color: var(--primary);">
                                    <?= $p['payable_days'] ?> <span style="font-size: 11.5px; font-weight: 500; color: var(--muted);">/ <?= $p['total_days_in_month'] ?></span>
                                </div>
                                <?php if ($p['absent_days'] > 0 || $p['half_days'] > 0): ?>
                                    <div style="font-size: 11px; color: var(--danger); font-weight: 600;">
                                        -<?= $p['absent_days'] + ($p['half_days'] * 0.5) ?> LOP
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 11px; color: var(--success); font-weight: 600;">
                                        Full attendance
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap; font-weight: 600; font-variant-numeric: tabular-nums;">
                                ₹<?= number_format($p['base_salary']) ?>
                            </td>
                            <td style="white-space: nowrap; color: var(--muted); font-variant-numeric: tabular-nums;">
                                ₹<?= number_format($p['daily_rate'], 2) ?>
                            </td>
                            <td style="white-space: nowrap; font-weight: 600; font-variant-numeric: tabular-nums;">
                                ₹<?= number_format($p['gross_salary']) ?>
                            </td>
                            <td style="white-space: nowrap; font-variant-numeric: tabular-nums;">
                                <div style="font-size: 12px; font-weight: 600;">
                                    <?php if ($p['deductions'] > 0): ?>
                                        <div style="color: var(--danger);">-₹<?= number_format($p['deductions']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($p['bonus'] > 0): ?>
                                        <div style="color: var(--success);">+₹<?= number_format($p['bonus']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($p['deductions'] == 0 && $p['bonus'] == 0): ?>
                                        <span class="muted">&mdash;</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="white-space: nowrap; font-size: 14px; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums;">
                                ₹<?= number_format($p['net_salary']) ?>
                            </td>
                            <td style="white-space: nowrap;">
                                <?php if ($p['payment_status'] === 'paid'): ?>
                                    <span class="badge badge-green">Paid</span>
                                    <?php if (!empty($p['payment_date'])): ?>
                                        <div style="font-size: 11px; color: var(--muted); margin-top: 2px;">
                                            <?= date('d M Y', strtotime($p['payment_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($p['payment_status'] === 'partial'): ?>
                                    <span class="badge badge-amber">Partial</span>
                                    <div style="font-size: 11px; color: var(--muted); margin-top: 2px;">
                                        Paid: ₹<?= number_format($p['paid_amount']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-red">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right" style="white-space: nowrap;">
                                <div class="action-group" style="gap: 6px;">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick='openPayslipModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)' title="View / Print Salary Slip">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                        Slip
                                    </button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick='openAdjustModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)' title="Adjust Bonus or Deductions">
                                        Adjust
                                    </button>
                                    <?php if ($p['payment_status'] !== 'paid'): ?>
                                        <button type="button" class="btn btn-sm btn-success" onclick='openPayModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)'>
                                            Pay
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== PAY SALARY MODAL ==================== -->
<div class="custom-modal-overlay" id="payModal">
    <div class="custom-modal" style="max-width: 480px;">
        <div class="custom-modal-header">
            <h3>Record Salary Payment</h3>
            <button type="button" class="custom-modal-close" onclick="closePayModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="payroll_id" id="pay_payroll_id">

            <div class="custom-modal-body">
                <div class="pay-summary-callout">
                    <div style="font-size: 14px; font-weight: 700; color: var(--text);" id="pay_staff_name"></div>
                    <div style="font-size: 12.5px; color: var(--muted); margin-top: 3px;">
                        Month: <strong style="color: var(--text);"><?= e($monthLabel) ?></strong> &middot; Net Payable: <strong style="color: var(--success);" id="pay_net_display"></strong>
                    </div>
                </div>

                <div class="form-group">
                    <label>Paid Amount (₹) *</label>
                    <input type="number" step="0.01" name="paid_amount" id="pay_amount" required style="font-size: 15px; font-weight: 700;">
                </div>

                <div class="row">
                    <div class="form-group">
                        <label>Payment Method *</label>
                        <select name="payment_method">
                            <option value="cash">Cash</option>
                            <option value="upi">UPI / GPay / PhonePe</option>
                            <option value="bank_transfer">Bank Transfer (NEFT / IMPS)</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Payment Date *</label>
                        <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Reference / Transaction ID</label>
                    <input type="text" name="payment_reference" placeholder="UPI reference, UTR, or cheque number...">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label>Notes</label>
                    <input type="text" name="notes" placeholder="Optional payment remarks...">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== ADJUST PAYROLL MODAL ==================== -->
<div class="custom-modal-overlay" id="adjustModal">
    <div class="custom-modal" style="max-width: 480px;">
        <div class="custom-modal-header">
            <h3>Adjust Bonus &amp; Deductions</h3>
            <button type="button" class="custom-modal-close" onclick="closeAdjustModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust_payroll">
            <input type="hidden" name="staff_id" id="adj_staff_id">

            <div class="custom-modal-body">
                <div style="font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 14px;" id="adj_staff_name"></div>

                <div class="row">
                    <div class="form-group">
                        <label>Bonus / Allowance (₹)</label>
                        <input type="number" step="0.01" name="bonus" id="adj_bonus" value="0.00">
                        <small class="muted">Festival bonus, extra classes, etc.</small>
                    </div>
                    <div class="form-group">
                        <label>Extra Deductions (₹)</label>
                        <input type="number" step="0.01" name="deductions" id="adj_deductions" value="0.00">
                        <small class="muted">Advance recovery or adjustments</small>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label>Notes</label>
                    <input type="text" name="notes" id="adj_notes" placeholder="Reason for bonus or deduction...">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAdjustModal()">Cancel</button>
                <button type="submit" class="btn">Update Salary</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== PAYSLIP PRINT MODAL ==================== -->
<div class="custom-modal-overlay" id="payslipModal">
    <div class="custom-modal" style="max-width: 580px;">
        <div class="custom-modal-header">
            <h3>Salary Slip</h3>
            <button type="button" class="custom-modal-close" onclick="closePayslipModal()" aria-label="Close">&times;</button>
        </div>
        <div class="custom-modal-body" id="payslipContent">
            <!-- Rendered by JS -->
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closePayslipModal()">Close</button>
            <button type="button" class="btn" onclick="printPayslip()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print Slip
            </button>
        </div>
    </div>
</div>

<script>
function openPayModal(p) {
    if (!p.id) {
        alert('Please click "Auto-Generate Payroll" first so monthly payroll records are saved before recording a payment.');
        return;
    }
    document.getElementById('pay_payroll_id').value = p.id;
    document.getElementById('pay_staff_name').innerText = p.staff_name + ' (' + p.staff_designation + ')';
    document.getElementById('pay_net_display').innerText = '₹' + parseFloat(p.net_salary).toLocaleString('en-IN');
    document.getElementById('pay_amount').value = p.net_salary;
    document.getElementById('payModal').classList.add('active');
}
function closePayModal() {
    document.getElementById('payModal').classList.remove('active');
}

function openAdjustModal(p) {
    document.getElementById('adj_staff_id').value = p.staff_id;
    document.getElementById('adj_staff_name').innerText = p.staff_name + ' — ' + p.staff_designation;
    document.getElementById('adj_bonus').value = p.bonus || 0;
    document.getElementById('adj_deductions').value = p.deductions || 0;
    document.getElementById('adj_notes').value = p.notes || '';
    document.getElementById('adjustModal').classList.add('active');
}
function closeAdjustModal() {
    document.getElementById('adjustModal').classList.remove('active');
}

function openPayslipModal(p) {
    var monthName = "<?= e($monthLabel) ?>";
    var statusColor = p.payment_status === 'paid' ? '#16A34A' : (p.payment_status === 'partial' ? '#D97706' : '#DC2626');
    var content =
        '<div class="payslip-box" id="printablePayslip">' +
            '<div class="payslip-header">' +
                '<h2 style="margin: 0; font-size: 18px; color: #0F172A;">Devangi Sewing Classes</h2>' +
                '<div style="font-size: 13px; color: #64748B; margin-top: 2px;">Staff Salary Slip &mdash; ' + escapeHtml(monthName) + '</div>' +
            '</div>' +
            '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; font-size: 13px;">' +
                '<div><strong>Staff Name:</strong> ' + escapeHtml(p.staff_name) + '</div>' +
                '<div><strong>Designation:</strong> ' + escapeHtml(p.staff_designation) + '</div>' +
                '<div><strong>Contact:</strong> ' + escapeHtml(p.staff_phone) + '</div>' +
                '<div><strong>Status:</strong> <span style="font-weight: 700; color: ' + statusColor + ';">' + escapeHtml((p.payment_status || 'pending').toUpperCase()) + '</span></div>' +
            '</div>' +
            '<div style="border-top: 1px solid #E2E8F0; padding-top: 12px; margin-bottom: 14px;">' +
                '<div style="font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #4F46E5; margin-bottom: 8px;">Attendance Summary</div>' +
                '<div class="payslip-att-grid">' +
                    '<div><span style="display:block; color:#64748B;">Total Days</span><strong>' + p.total_days_in_month + '</strong></div>' +
                    '<div><span style="display:block; color:#166534;">Present (P)</span><strong>' + p.present_days + '</strong></div>' +
                    '<div><span style="display:block; color:#92400E;">Half Day (HD)</span><strong>' + p.half_days + '</strong></div>' +
                    '<div><span style="display:block; color:#4338CA;">Holidays (H)</span><strong>' + p.holidays_count + '</strong></div>' +
                '</div>' +
                '<div style="font-size: 11.5px; color: #64748B; margin-top: 6px;">Institute holidays and Sundays are paid (Payable Days = ' + p.payable_days + ').</div>' +
            '</div>' +
            '<div style="margin-top: 12px;">' +
                '<div class="payslip-row"><span>Base Monthly Salary</span><strong>₹' + parseFloat(p.base_salary).toLocaleString('en-IN') + '</strong></div>' +
                '<div class="payslip-row"><span>Daily Rate</span><span>₹' + parseFloat(p.daily_rate).toFixed(2) + '</span></div>' +
                '<div class="payslip-row"><span>Gross Earned</span><strong>₹' + parseFloat(p.gross_salary).toLocaleString('en-IN') + '</strong></div>' +
                (p.bonus > 0 ? '<div class="payslip-row" style="color:#16A34A;"><span>Bonus / Allowance</span><span>+₹' + parseFloat(p.bonus).toLocaleString('en-IN') + '</span></div>' : '') +
                (p.deductions > 0 ? '<div class="payslip-row" style="color:#DC2626;"><span>Deductions</span><span>-₹' + parseFloat(p.deductions).toLocaleString('en-IN') + '</span></div>' : '') +
                '<div class="payslip-total-row"><span>Net Payable</span><span>₹' + parseFloat(p.net_salary).toLocaleString('en-IN') + '</span></div>' +
            '</div>' +
            (p.payment_status === 'paid' ?
                '<div style="margin-top: 14px; background: #ECFDF5; border: 1px solid #A7F3D0; padding: 10px 12px; border-radius: 6px; font-size: 12.5px; color: #065F46;">' +
                    'Salary settled on ' + escapeHtml(p.payment_date || '') + '.' +
                '</div>' : '') +
        '</div>';

    document.getElementById('payslipContent').innerHTML = content;
    document.getElementById('payslipModal').classList.add('active');
}
function closePayslipModal() {
    document.getElementById('payslipModal').classList.remove('active');
}

function printPayslip() {
    var printContents = document.getElementById('printablePayslip').innerHTML;
    var originalContents = document.body.innerHTML;
    document.body.innerHTML = '<div style="max-width: 600px; margin: 30px auto;">' + printContents + '</div>';
    window.print();
    document.body.innerHTML = originalContents;
    window.location.reload();
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

window.addEventListener('click', function(event) {
    if (event.target === document.getElementById('payModal')) closePayModal();
    if (event.target === document.getElementById('adjustModal')) closeAdjustModal();
    if (event.target === document.getElementById('payslipModal')) closePayslipModal();
});

window.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closePayModal();
        closeAdjustModal();
        closePayslipModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
