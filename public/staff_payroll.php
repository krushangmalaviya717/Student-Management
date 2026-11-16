<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/StaffService.php';
require_once __DIR__ . '/../includes/services/AttendanceService.php';
require_once __DIR__ . '/../includes/services/PayrollService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';

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
            flash('success', "મહિના " . date('F Y', strtotime("{$selectedMonth}-01")) . " માટે કુલ {$count} સ્ટાફનો પગાર આપોઆપ ગણતરી કરીને તૈયાર થઈ ગયો છે.");
            redirect("public/staff_payroll.php?month={$selectedMonth}");

        } elseif ($action === 'record_payment') {
            $payrollId = (int)($_POST['payroll_id'] ?? 0);
            PayrollService::markAsPaid($payrollId, $_POST, (int)$user['id']);
            flash('success', "પગાર ચૂકવણી સફળતાપૂર્વક રેકોર્ડ કરવામાં આવી.");
            redirect("public/staff_payroll.php?month={$selectedMonth}");

        } elseif ($action === 'adjust_payroll') {
            $staffId = (int)($_POST['staff_id'] ?? 0);
            $payrollId = PayrollService::savePayroll($staffId, $selectedMonth, $_POST, (int)$user['id']);
            flash('success', "પગાર અને બોનસ/કપાત સફળતાપૂર્વક અપડેટ થયા.");
            redirect("public/staff_payroll.php?month={$selectedMonth}");
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Fetch Payroll List & Statistics
$payrollList = PayrollService::getPayrollList($selectedMonth);
$stats = PayrollService::getMonthlyStats($selectedMonth);

// If no payroll records exist yet for this month, calculate on the fly for all active staff
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

$pageTitle = 'Staff Payroll & Salary Calculation';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
/* Subnav */
.staff-subnav {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 12px;
    flex-wrap: wrap;
}
.staff-subnav-item {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.15s ease;
}
.staff-subnav-item:hover {
    color: #4338ca;
    background: #eef2ff;
    border-color: #c7d2fe;
}
.staff-subnav-item.active {
    color: #ffffff;
    background: #4338ca;
    border-color: #4338ca;
}

/* Modals */
.custom-modal-overlay.active {
    display: flex !important;
}

/* Payslip Printable Styling */
.payslip-box {
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 24px;
    font-family: inherit;
}
.payslip-header {
    text-align: center;
    border-bottom: 2px dashed #cbd5e1;
    padding-bottom: 16px;
    margin-bottom: 18px;
}
.payslip-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 13.5px;
    border-bottom: 1px solid #f1f5f9;
}
.payslip-total-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    font-size: 16px;
    font-weight: 800;
    border-top: 2px solid #0f172a;
    border-bottom: 2px solid #0f172a;
    margin-top: 10px;
}
</style>

<!-- Sub Navigation Tabs for Staff Module -->
<div class="staff-subnav">
    <a href="<?= url('public/staff.php') ?>" class="staff-subnav-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Staff Directory (સ્ટાફ લિસ્ટ)
    </a>
    <a href="<?= url('public/staff_attendance.php?view=daily') ?>" class="staff-subnav-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Daily Attendance (દૈનિક હાજરી)
    </a>
    <a href="<?= url('public/staff_attendance.php?view=monthly') ?>" class="staff-subnav-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Monthly Register (માસિક રજીસ્ટર)
    </a>
    <a href="<?= url('public/staff_payroll.php?month=' . $selectedMonth) ?>" class="staff-subnav-item active">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        Payroll & Salary (પગાર ગણતરી & સ્લિપ)
    </a>
</div>

<?php if ($error): ?>
    <div class="flash flash-error" style="margin-bottom: 20px;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Month Selection & Action Toolbar -->
<div class="card" style="margin-bottom: 20px; padding: 14px 18px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <form method="get" action="" style="display: flex; gap: 10px; align-items: center; margin: 0;">
            <label style="font-weight: 700; font-size: 13.5px; color: #1e293b; margin: 0;">પગાર મહિનો પસંદ કરો:</label>
            <input type="month" name="month" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()" style="margin: 0; font-weight: 700; padding: 6px 12px; font-size: 13.5px;">
        </form>

        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="?month=<?= e($selectedMonth) ?>&export=csv" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </a>

            <form method="post" action="" style="margin: 0;" onsubmit="return confirm('શું તમે આ મહિનાના તમામ સ્ટાફ માટે હાજરી મુજબ પગાર આપોઆપ ગણતરી કરીને સેવ કરવા માંગો છો?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_all">
                <button type="submit" class="btn btn-sm" style="background: #4338ca; color:#fff; font-weight: 700;">
                    ⚡ Auto-Generate Payroll (બધા સ્ટાફનો પગાર ગણો)
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Payroll KPI Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 22px;">
    <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #4338ca;">
        <div style="font-size: 24px; font-weight: 800; color: #1e293b;">
            ₹<?= number_format($stats['total_net']) ?>
        </div>
        <div class="muted" style="font-size: 12px; font-weight: 600;">કુલ ચૂકવવાપાત્ર પગાર (Total Net Payable)</div>
    </div>
    <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #16a34a;">
        <div style="font-size: 24px; font-weight: 800; color: #16a34a;">
            ₹<?= number_format($stats['total_paid']) ?>
        </div>
        <div class="muted" style="font-size: 12px; font-weight: 600;">ચૂકવાઈ ગયેલ (Total Paid - <?= $stats['paid_count'] ?> સ્ટાફ)</div>
    </div>
    <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #d97706;">
        <div style="font-size: 24px; font-weight: 800; color: #d97706;">
            ₹<?= number_format($stats['total_pending']) ?>
        </div>
        <div class="muted" style="font-size: 12px; font-weight: 600;">બાકી ચૂકવણી (Pending - <?= $stats['pending_count'] ?> સ્ટાફ)</div>
    </div>
    <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #dc2626;">
        <div style="font-size: 24px; font-weight: 800; color: #dc2626;">
            ₹<?= number_format($stats['total_deductions']) ?>
        </div>
        <div class="muted" style="font-size: 12px; font-weight: 600;">કુલ ગેરહાજરી કપાત (Total LOP Deductions)</div>
    </div>
</div>

<!-- Payroll Table -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <h3 style="margin: 0; font-size: 16px;">
            Salary Register: <?= date('F Y', strtotime("{$selectedMonth}-01")) ?>
        </h3>
        <div class="muted" style="font-size: 12.5px;">
            સંસ્થાની રજાઓ / રવિવાર પગારમાં ગણતરીપાત્ર છે (No deduction for holidays).
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Staff Name & Role</th>
                    <th>Attendance Days</th>
                    <th>Payable Days</th>
                    <th>Base Salary</th>
                    <th>Daily Rate</th>
                    <th>Gross Earned</th>
                    <th>Deduction / Bonus</th>
                    <th>Net Salary</th>
                    <th>Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payrollList)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 30px; color: #64748b;">
                            કોઈ સક્રિય સ્ટાફ રેકોર્ડ મળ્યો નથી.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payrollList as $p): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: #0f172a; font-size: 14px;"><?= e($p['staff_name']) ?></div>
                                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                    <?= e($p['staff_designation']) ?> • <?= e($p['staff_phone']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 12px; line-height: 1.4;">
                                    <span style="color:#166534; font-weight:700;">P: <?= $p['present_days'] ?></span> | 
                                    <span style="color:#d97706; font-weight:700;">HD: <?= $p['half_days'] ?></span> | 
                                    <span style="color:#7c3aed; font-weight:700;" title="Sundays & Declared Holidays">H: <?= $p['holidays_count'] ?></span> | 
                                    <span style="color:#dc2626; font-weight:700;">A: <?= $p['absent_days'] ?></span>
                                </div>
                                <div style="font-size: 10.5px; color: #94a3b8; margin-top: 2px;">
                                    Total <?= $p['total_days_in_month'] ?> Days
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 800; font-size: 14px; color: #4338ca;">
                                    <?= $p['payable_days'] ?> <small style="font-size:11px; font-weight:normal; color:#64748b;">/ <?= $p['total_days_in_month'] ?></small>
                                </div>
                                <?php if ($p['absent_days'] > 0 || $p['half_days'] > 0): ?>
                                    <div style="font-size: 11px; color: #dc2626; font-weight: 600;">
                                        -<?= $p['absent_days'] + ($p['half_days'] * 0.5) ?> LOP
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 11px; color: #16a34a; font-weight: 600;">
                                        Full Attended
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #1e293b;">
                                    ₹<?= number_format($p['base_salary']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 12px; color: #64748b;">
                                    ₹<?= number_format($p['daily_rate'], 2) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #0f172a;">
                                    ₹<?= number_format($p['gross_salary']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 12px;">
                                    <?php if ($p['deductions'] > 0): ?>
                                        <div style="color: #dc2626;">-₹<?= number_format($p['deductions']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($p['bonus'] > 0): ?>
                                        <div style="color: #16a34a;">+₹<?= number_format($p['bonus']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($p['deductions'] == 0 && $p['bonus'] == 0): ?>
                                        <span class="muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 15px; font-weight: 800; color: #0f172a;">
                                    ₹<?= number_format($p['net_salary']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($p['payment_status'] === 'paid'): ?>
                                    <span class="badge badge-green" style="font-weight:700;">Paid (ચૂકવાઈ ગયેલ)</span>
                                    <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;">
                                        <?= $p['payment_date'] ? date('d-m-Y', strtotime($p['payment_date'])) : '' ?>
                                    </div>
                                <?php elseif ($p['payment_status'] === 'partial'): ?>
                                    <span class="badge badge-amber" style="font-weight:700;">Partial (અંશત:)</span>
                                    <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;">
                                        Paid: ₹<?= number_format($p['paid_amount']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-red" style="font-weight:700;">Pending (બાકી)</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 5px; align-items: center;">
                                    <!-- View/Print Salary Slip Button -->
                                    <button type="button" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 8px;" onclick='openPayslipModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)' title="Salary Slip (પે-સ્લિપ)">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Slip
                                    </button>

                                    <!-- Adjust Bonus/Deduction Button -->
                                    <button type="button" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 8px;" onclick='openAdjustModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)' title="બોનસ કે કપાત સેટ કરો">
                                        ± Adjust
                                    </button>

                                    <!-- Pay Salary Button -->
                                    <?php if ($p['payment_status'] !== 'paid'): ?>
                                        <button type="button" class="btn btn-sm" style="background:#16a34a; color:#fff; font-size: 12px; padding: 5px 10px;" onclick='openPayModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)'>
                                            💳 Pay
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
            <h3>Record Salary Payment (પગાર ચૂકવણી)</h3>
            <button type="button" class="custom-modal-close" onclick="closePayModal()">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="payroll_id" id="pay_payroll_id">

            <div class="custom-modal-body">
                <div style="background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 14px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 14.5px; font-weight: 700; color: #0f172a;" id="pay_staff_name"></div>
                    <div style="font-size: 12.5px; color: #64748b; margin-top: 2px;">
                        મહિનો: <strong><?= date('F Y', strtotime("{$selectedMonth}-01")) ?></strong> | ચૂકવવાપાત્ર: <strong style="color: #16a34a;" id="pay_net_display"></strong>
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Paid Amount (ચૂકવેલ રકમ ₹) *</label>
                    <input type="number" step="0.01" name="paid_amount" id="pay_amount" required style="width: 100%; font-size: 16px; font-weight: 700;">
                </div>

                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Payment Method *</label>
                        <select name="payment_method" style="width: 100%;">
                            <option value="cash">રોકડ (Cash)</option>
                            <option value="upi">UPI / GPay / PhonePe</option>
                            <option value="bank_transfer">બેંક ટ્રાન્સફર (NEFT/IMPS)</option>
                            <option value="cheque">ચેક (Cheque)</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Payment Date *</label>
                        <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required style="width: 100%;">
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Reference / Transaction ID</label>
                    <input type="text" name="payment_reference" placeholder="ટ્રાન્ઝેક્શન નંબર કે ચેક નંબર..." style="width: 100%;">
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Notes (નોંધ)</label>
                    <input type="text" name="notes" placeholder="કોઈ વિગતવાર નોંધ..." style="width: 100%;">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="btn" style="background:#16a34a; color:#fff;">Confirm Payment (ચૂકવણી સાચવો)</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== ADJUST PAYROLL MODAL ==================== -->
<div class="custom-modal-overlay" id="adjustModal">
    <div class="custom-modal" style="max-width: 480px;">
        <div class="custom-modal-header">
            <h3>Adjust Bonus & Deductions (બોનસ / કપાત)</h3>
            <button type="button" class="custom-modal-close" onclick="closeAdjustModal()">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust_payroll">
            <input type="hidden" name="staff_id" id="adj_staff_id">

            <div class="custom-modal-body">
                <div style="font-size: 14.5px; font-weight: 700; margin-bottom: 12px;" id="adj_staff_name"></div>

                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Bonus / Allowance (બોનસ ₹)</label>
                        <input type="number" step="0.01" name="bonus" id="adj_bonus" value="0.00" style="width: 100%;">
                        <small class="muted">તહેવાર બોનસ, એક્સ્ટ્રા કામ વગેરે</small>
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Extra Deductions (કપાત ₹)</label>
                        <input type="number" step="0.01" name="deductions" id="adj_deductions" value="0.00" style="width: 100%;">
                        <small class="muted">ઉપાડ પરત કે દંડ વગેરે</small>
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Notes (નોંધ)</label>
                    <input type="text" name="notes" id="adj_notes" placeholder="બોનસ/કપાતનું કારણ..." style="width: 100%;">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAdjustModal()">Cancel</button>
                <button type="submit" class="btn">Update Salary (પગાર અપડેટ કરો)</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== PAYSLIP PRINT MODAL ==================== -->
<div class="custom-modal-overlay" id="payslipModal">
    <div class="custom-modal" style="max-width: 580px;">
        <div class="custom-modal-header">
            <h3>Salary Slip (પગાર સ્લિપ)</h3>
            <button type="button" class="custom-modal-close" onclick="closePayslipModal()">&times;</button>
        </div>
        <div class="custom-modal-body" id="payslipContent">
            <!-- Rendered by JS -->
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closePayslipModal()">Close</button>
            <button type="button" class="btn" onclick="printPayslip()">🖨 Print Slip (પ્રિન્ટ)</button>
        </div>
    </div>
</div>

<script>
function openPayModal(p) {
    if (!p.id) {
        alert('મહેરબાની કરીને પહેલા "Auto-Generate Payroll" બટન દબાવો જેથી રેકોર્ડ સેવ થઈ જાય.');
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
    document.getElementById('adj_staff_name').innerText = p.staff_name + ' - ' + p.staff_designation;
    document.getElementById('adj_bonus').value = p.bonus || 0;
    document.getElementById('adj_deductions').value = p.deductions || 0;
    document.getElementById('adj_notes').value = p.notes || '';
    document.getElementById('adjustModal').classList.add('active');
}
function closeAdjustModal() {
    document.getElementById('adjustModal').classList.remove('active');
}

function openPayslipModal(p) {
    var monthName = "<?= date('F Y', strtotime("{$selectedMonth}-01")) ?>";
    var content = 
        '<div class="payslip-box" id="printablePayslip">' +
            '<div class="payslip-header">' +
                '<h2 style="margin: 0; font-size: 19px; color: #0f172a;">Devangi Sewing Classes</h2>' +
                '<div style="font-size: 13px; color: #64748b; margin-top: 2px;">Staff Salary Slip (પગાર પત્રક) - ' + escapeHtml(monthName) + '</div>' +
            '</div>' +
            '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; font-size: 13px;">' +
                '<div><strong>સ્ટાફ નામ:</strong> ' + escapeHtml(p.staff_name) + '</div>' +
                '<div><strong>હોદ્દો:</strong> ' + escapeHtml(p.staff_designation) + '</div>' +
                '<div><strong>સંપર્ક:</strong> ' + escapeHtml(p.staff_phone) + '</div>' +
                '<div><strong>સ્ટેટસ:</strong> <span style="font-weight:700; color:' + (p.payment_status === 'paid' ? '#16a34a' : '#d97706') + ';">' + escapeHtml(p.payment_status.toUpperCase()) + '</span></div>' +
            '</div>' +
            '<div style="border-top: 1px solid #cbd5e1; padding-top: 10px; margin-bottom: 12px;">' +
                '<div style="font-weight: 700; font-size: 12.5px; color: #4338ca; margin-bottom: 6px;">હાજરી વિગત (Attendance Summary):</div>' +
                '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; text-align: center; font-size: 12px; background: #f8fafc; padding: 8px; border-radius: 6px;">' +
                    '<div><span style="display:block; color:#64748b;">કુલ દિવસો</span><strong>' + p.total_days_in_month + '</strong></div>' +
                    '<div><span style="display:block; color:#166534;">હાજર (P)</span><strong>' + p.present_days + '</strong></div>' +
                    '<div><span style="display:block; color:#92400e;">અડધો (HD)</span><strong>' + p.half_days + '</strong></div>' +
                    '<div><span style="display:block; color:#5b21b6;">રજાઓ (H)</span><strong>' + p.holidays_count + '</strong></div>' +
                '</div>' +
                '<div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">* સંસ્થાની રજાઓ / રવિવાર પગાર માટે માન્ય છે (Payable Days = ' + p.payable_days + ').</div>' +
            '</div>' +
            '<div style="margin-top: 14px;">' +
                '<div class="payslip-row"><span>મૂળ માસિક પગાર (Base Salary):</span><strong>₹' + parseFloat(p.base_salary).toLocaleString('en-IN') + '</strong></div>' +
                '<div class="payslip-row"><span>દૈનિક દર (Daily Rate):</span><span>₹' + parseFloat(p.daily_rate).toFixed(2) + '</span></div>' +
                '<div class="payslip-row"><span>ગણતરી મુજબ મળવાપાત્ર (Gross Earned):</span><strong>₹' + parseFloat(p.gross_salary).toLocaleString('en-IN') + '</strong></div>' +
                (p.bonus > 0 ? '<div class="payslip-row" style="color:#16a34a;"><span>બોનસ / ઇન્સેન્ટીવ (Bonus):</span><span>+₹' + parseFloat(p.bonus).toLocaleString('en-IN') + '</span></div>' : '') +
                (p.deductions > 0 ? '<div class="payslip-row" style="color:#dc2626;"><span>અન્ય કપાત (Deductions):</span><span>-₹' + parseFloat(p.deductions).toLocaleString('en-IN') + '</span></div>' : '') +
                '<div class="payslip-total-row"><span>ચૂકવવાપાત્ર ચોખ્ખો પગાર (Net Payable):</span><span>₹' + parseFloat(p.net_salary).toLocaleString('en-IN') + '</span></div>' +
            '</div>' +
            (p.payment_status === 'paid' ? 
                '<div style="margin-top: 14px; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 10px; border-radius: 6px; font-size: 12.5px; color: #065f46;">' +
                    '✔ આ પગાર તારીખ ' + (p.payment_date || '') + ' ના રોજ ચૂકવાઈ ગયેલ છે.' +
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

window.onclick = function(event) {
    var pModal = document.getElementById('payModal');
    var aModal = document.getElementById('adjustModal');
    var sModal = document.getElementById('payslipModal');
    if (event.target === pModal) closePayModal();
    if (event.target === aModal) closeAdjustModal();
    if (event.target === sModal) closePayslipModal();
};
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
