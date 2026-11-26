<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/StaffService.php';
require_once __DIR__ . '/../includes/services/AttendanceService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
require_once __DIR__ . '/../includes/components/kpi_card.php';

require_admin();

$user = current_user();
$error = null;
$view = $_GET['view'] ?? 'daily'; // 'daily' or 'monthly'

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_daily_sheet') {
            $date = $_POST['date'] ?? date('Y-m-d');
            $sheet = $_POST['attendance'] ?? [];
            $count = AttendanceService::saveDailySheet($date, $sheet, (int)$user['id']);
            flash('success', 'Attendance saved for ' . date('d M Y', strtotime($date)) . " ({$count} staff).");
            redirect('public/staff_attendance.php?date=' . urlencode($date));

        } elseif ($action === 'mark_all_status') {
            $date = $_POST['date'] ?? date('Y-m-d');
            $status = $_POST['status'] ?? 'present';
            $staffList = StaffService::getAllStaff('active');
            $sheet = [];
            foreach ($staffList as $st) {
                $sheet[$st['id']] = [
                    'status' => $status,
                    'notes' => ($status === 'holiday' ? 'Institute Holiday' : null)
                ];
            }
            AttendanceService::saveDailySheet($date, $sheet, (int)$user['id']);
            flash('success', "All active staff marked as '" . ucfirst(str_replace('_', ' ', $status)) . "' for " . date('d M Y', strtotime($date)) . '.');
            redirect('public/staff_attendance.php?date=' . urlencode($date));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Daily View Data
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$dailySheet = AttendanceService::getDailySheet($selectedDate);
$dailyKPIs = AttendanceService::getDailyKPIs($selectedDate);

// Monthly View Data
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($selectedMonth < 1) $selectedMonth = 1;
if ($selectedMonth > 12) $selectedMonth = 12;

$monthlyRegister = AttendanceService::getMonthlyRegister($selectedYear, $selectedMonth);

// Export Monthly Register to CSV
if (($_GET['export'] ?? '') === 'monthly_csv') {
    $headers = ['Staff Name', 'Designation', 'Present (P)', 'Half Day (HD)', 'Absent (A)', 'Paid Leave (PL)', 'Holidays/Sundays (H)', 'Payable Days', 'LOP Days'];
    $rows = [];
    foreach ($monthlyRegister['matrix'] as $row) {
        $s = $row['summary'];
        $rows[] = [
            $row['staff']['name'],
            $row['staff']['designation'],
            $s['present'],
            $s['half_day'],
            $s['absent'],
            $s['paid_leave'],
            $s['holiday'],
            $s['payable_days'],
            $s['loss_of_pay_days']
        ];
    }
    ExportService::downloadCsv("Staff_Attendance_{$selectedYear}_{$selectedMonth}.csv", $headers, $rows);
}

$pageTitle = 'Staff Attendance';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
/* Status Segmented Radio Controls */
.att-radio-group {
    display: inline-flex;
    background: var(--surface-subtle);
    padding: 3px;
    border-radius: var(--radius);
    gap: 2px;
    border: 1px solid var(--border);
    flex-wrap: wrap;
}
.att-radio-btn {
    position: relative;
    cursor: pointer;
    margin: 0;
}
.att-radio-btn input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
}
.att-radio-label {
    display: inline-block;
    padding: 5px 10px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    user-select: none;
    transition: all 0.15s ease;
    white-space: nowrap;
}
.att-radio-btn input:checked + .att-radio-label.lbl-present {
    background: var(--success); color: #FFFFFF; box-shadow: var(--shadow-xs);
}
.att-radio-btn input:checked + .att-radio-label.lbl-halfday {
    background: var(--warning); color: #FFFFFF; box-shadow: var(--shadow-xs);
}
.att-radio-btn input:checked + .att-radio-label.lbl-absent {
    background: var(--danger); color: #FFFFFF; box-shadow: var(--shadow-xs);
}
.att-radio-btn input:checked + .att-radio-label.lbl-paidleave {
    background: #2563EB; color: #FFFFFF; box-shadow: var(--shadow-xs);
}
.att-radio-btn input:checked + .att-radio-label.lbl-holiday {
    background: var(--primary); color: #FFFFFF; box-shadow: var(--shadow-xs);
}

/* Monthly Matrix Table */
.matrix-container {
    overflow-x: auto;
    max-width: 100%;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}
.matrix-table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    font-size: 11.5px;
}
.matrix-table th, .matrix-table td {
    padding: 7px 6px;
    text-align: center;
    border-right: 1px solid var(--border-light);
    border-bottom: 1px solid var(--border-light);
    white-space: nowrap;
}
.matrix-table th {
    background: #F8FAFC;
    font-weight: 700;
    color: var(--muted);
    position: sticky;
    top: 0;
}
.matrix-table th.col-sun, .matrix-table td.col-sun {
    background: #FFF1F2 !important;
    color: #BE123C;
}
.matrix-table th.col-hol, .matrix-table td.col-hol {
    background: var(--primary-light) !important;
    color: var(--primary-hover);
}
.matrix-table th.sticky-name, .matrix-table td.sticky-name {
    position: sticky;
    left: 0;
    background: #FFFFFF;
    z-index: 2;
    text-align: left;
    min-width: 170px;
    font-weight: 600;
    border-right: 2px solid var(--border);
}
.matrix-badge {
    display: inline-block;
    width: 22px;
    height: 22px;
    line-height: 22px;
    border-radius: var(--radius-sm);
    font-weight: 700;
    font-size: 10.5px;
    text-align: center;
}
.mb-P { background: var(--success-bg); color: #166534; }
.mb-HD { background: var(--warning-bg); color: #92400E; }
.mb-A { background: var(--danger-bg); color: #991B1B; }
.mb-PL { background: #DBEAFE; color: #1E40AF; }
.mb-H { background: var(--primary-light); color: var(--primary-hover); }
.mb-unmarked { background: var(--surface-subtle); color: #94A3B8; }
.mb-future { background: transparent; color: #CBD5E1; }

.rules-callout {
    background: var(--surface-subtle);
    padding: 14px 16px;
    border-radius: var(--radius);
    margin-top: 16px;
    font-size: 12.5px;
    color: var(--text-body);
    border: 1px solid var(--border);
}
.rules-callout ul {
    margin: 6px 0 0 18px;
    padding: 0;
    line-height: 1.6;
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-header-subtitle">
            <?php if ($view === 'daily'): ?>
                Record daily attendance, check-in/out times, and leave status for <?= date('d M Y', strtotime($selectedDate)) ?>.
            <?php else: ?>
                Review monthly attendance matrix and payable days for <?= e($monthlyRegister['month_name']) ?>.
            <?php endif; ?>
        </p>
    </div>
    <div class="action-group">
        <?php if ($view === 'daily'): ?>
            <form method="post" style="display: inline; margin: 0;" onsubmit="return confirm('Mark all active staff as Present for <?= date('d M Y', strtotime($selectedDate)) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_status">
                <input type="hidden" name="date" value="<?= e($selectedDate) ?>">
                <input type="hidden" name="status" value="present">
                <button type="submit" class="btn btn-secondary btn-sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
                    Mark All Present
                </button>
            </form>
            <form method="post" style="display: inline; margin: 0;" onsubmit="return confirm('Mark <?= date('d M Y', strtotime($selectedDate)) ?> as a Paid Institute Holiday for all active staff?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_status">
                <input type="hidden" name="date" value="<?= e($selectedDate) ?>">
                <input type="hidden" name="status" value="holiday">
                <button type="submit" class="btn btn-secondary btn-sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Mark All Holiday
                </button>
            </form>
        <?php else: ?>
            <a href="?view=monthly&year=<?= $selectedYear ?>&month=<?= $selectedMonth ?>&export=monthly_csv" class="btn btn-secondary btn-export btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </a>
            <a href="<?= url('public/staff_payroll.php?month=' . sprintf('%04d-%02d', $selectedYear, $selectedMonth)) ?>" class="btn btn-sm">
                Calculate Payroll (<?= date('M Y', strtotime(sprintf('%04d-%02d-01', $selectedYear, $selectedMonth))) ?>)
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Staff Module Sub-Navigation -->
<div class="tabs-underline">
    <a href="<?= url('public/staff.php') ?>" class="tab-underline-btn" style="text-decoration: none;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Staff Directory
    </a>
    <a href="<?= url('public/staff_attendance.php?view=daily') ?>" class="tab-underline-btn <?= $view === 'daily' ? 'active' : '' ?>" style="text-decoration: none;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Daily Attendance
    </a>
    <a href="<?= url('public/staff_attendance.php?view=monthly') ?>" class="tab-underline-btn <?= $view === 'monthly' ? 'active' : '' ?>" style="text-decoration: none;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Monthly Register
    </a>
    <a href="<?= url('public/staff_payroll.php') ?>" class="tab-underline-btn" style="text-decoration: none;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        Payroll &amp; Salary
    </a>
</div>

<?php if ($error): ?>
    <div class="flash flash-error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- =========================================================================
     VIEW 1: DAILY ATTENDANCE SHEET
     ========================================================================= -->
<?php if ($view === 'daily'): ?>
    <?php
    $prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
    $nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
    $isToday = ($selectedDate === date('Y-m-d'));
    ?>

    <!-- Daily KPI Summary -->
    <div class="grid grid-5" style="margin-bottom: 20px;">
        <?= render_kpi_card('Total Staff', (string)$dailyKPIs['total_staff'], 'Active headcount', '', 'primary') ?>
        <?= render_kpi_card('Present', (string)$dailyKPIs['present'], 'Full day credit', '', 'success') ?>
        <?= render_kpi_card('Half Day', (string)$dailyKPIs['half_day'], '0.5 day credit', '', 'warning') ?>
        <?= render_kpi_card('Absent (LOP)', (string)$dailyKPIs['absent'], 'Unpaid absence', '', 'danger') ?>
        <?= render_kpi_card('Leave / Holiday', (string)($dailyKPIs['holiday'] + $dailyKPIs['paid_leave']), 'Paid days off', '', 'default') ?>
    </div>

    <!-- Date Selector Bar -->
    <div class="card" style="padding: 14px 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div class="action-group">
                <a href="?view=daily&date=<?= e($prevDate) ?>" class="btn btn-sm btn-secondary" title="Previous Day">&larr; Prev Day</a>
                <form method="get" action="" style="display: inline-flex; align-items: center; margin: 0;">
                    <input type="hidden" name="view" value="daily">
                    <input type="date" name="date" value="<?= e($selectedDate) ?>" onchange="this.form.submit()" style="width: auto; font-weight: 600;">
                </form>
                <a href="?view=daily&date=<?= e($nextDate) ?>" class="btn btn-sm btn-secondary" title="Next Day">Next Day &rarr;</a>
                <?php if (!$isToday): ?>
                    <a href="?view=daily&date=<?= date('Y-m-d') ?>" class="btn btn-sm btn-secondary">Today</a>
                <?php endif; ?>
            </div>
            <div class="muted" style="font-size: 13px; font-weight: 500;">
                Selected: <strong style="color: var(--text);"><?= date('l, d M Y', strtotime($selectedDate)) ?></strong>
            </div>
        </div>

        <?php if ($dailySheet['holiday_info']): ?>
            <div class="flash flash-info" style="margin: 14px 0 0;">
                <div>
                    <strong>Declared Holiday / Weekly Off:</strong> <?= e($dailySheet['holiday_info']['reason']) ?>
                    <div style="font-size: 12px; opacity: 0.9; margin-top: 2px;">
                        Institute holidays and Sundays are counted as paid days with zero salary deduction.
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Daily Attendance Marking Form -->
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_daily_sheet">
        <input type="hidden" name="date" value="<?= e($selectedDate) ?>">

        <div class="card">
            <div class="card-header-bar">
                <div>
                    <h2>Daily Attendance Sheet</h2>
                    <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;"><?= date('d M Y (l)', strtotime($selectedDate)) ?></p>
                </div>
                <button type="submit" class="btn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save Attendance
                </button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width: 200px;">Staff Member</th>
                            <th>Assigned Batches</th>
                            <th style="min-width: 340px;">Attendance Status</th>
                            <th>In / Out Time</th>
                            <th style="min-width: 150px;">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dailySheet['staff'])): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 36px 20px; color: var(--muted);">
                                    No active staff members found to mark attendance.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dailySheet['staff'] as $st): ?>
                                <?php
                                $att = $st['attendance'];
                                $curStatus = $att['status'];
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: var(--text); font-size: 13.5px;"><?= e($st['name']) ?></div>
                                        <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
                                            <?= e($st['designation']) ?> &middot; <a href="tel:<?= e($st['phone']) ?>" style="color: var(--muted);"><?= e($st['phone']) ?></a>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size: 12.5px; color: var(--text-body);"><?= e($st['batches_str']) ?></span>
                                    </td>
                                    <td>
                                        <div class="att-radio-group">
                                            <label class="att-radio-btn" title="Full day present (1 Day)">
                                                <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="present" <?= $curStatus === 'present' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                                <span class="att-radio-label lbl-present">Present</span>
                                            </label>
                                            <label class="att-radio-btn" title="Half day present (0.5 Day)">
                                                <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="half_day" <?= $curStatus === 'half_day' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                                <span class="att-radio-label lbl-halfday">Half Day</span>
                                            </label>
                                            <label class="att-radio-btn" title="Unpaid absent (Loss of Pay)">
                                                <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="absent" <?= $curStatus === 'absent' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                                <span class="att-radio-label lbl-absent">Absent</span>
                                            </label>
                                            <label class="att-radio-btn" title="Approved Paid Leave">
                                                <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="paid_leave" <?= $curStatus === 'paid_leave' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                                <span class="att-radio-label lbl-paidleave">Leave (PL)</span>
                                            </label>
                                            <label class="att-radio-btn" title="Paid Institute Holiday">
                                                <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="holiday" <?= $curStatus === 'holiday' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                                <span class="att-radio-label lbl-holiday">Holiday</span>
                                            </label>
                                        </div>

                                        <div id="halfday_wrap_<?= $st['id'] ?>" style="display: <?= $curStatus === 'half_day' ? 'block' : 'none' ?>; margin-top: 6px;">
                                            <select name="attendance[<?= $st['id'] ?>][half_day_session]" style="width: auto; height: 30px; padding: 3px 10px; font-size: 12px;">
                                                <option value="morning" <?= ($att['half_day_session'] ?? '') === 'morning' ? 'selected' : '' ?>>Morning Session</option>
                                                <option value="afternoon" <?= ($att['half_day_session'] ?? '') === 'afternoon' ? 'selected' : '' ?>>Afternoon Session</option>
                                            </select>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 6px; align-items: center;">
                                            <input type="time" name="attendance[<?= $st['id'] ?>][check_in_time]" value="<?= e($att['check_in_time'] ?? '') ?>" title="Check-In Time" style="width: 110px; height: 32px; font-size: 12px; padding: 4px 8px;">
                                            <span class="muted">-</span>
                                            <input type="time" name="attendance[<?= $st['id'] ?>][check_out_time]" value="<?= e($att['check_out_time'] ?? '') ?>" title="Check-Out Time" style="width: 110px; height: 32px; font-size: 12px; padding: 4px 8px;">
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" name="attendance[<?= $st['id'] ?>][notes]" value="<?= e($att['notes'] ?? '') ?>" placeholder="Optional notes..." style="height: 32px; font-size: 12.5px; padding: 4px 10px;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <script>
    function toggleHalfDaySelect(staffId, status) {
        var wrap = document.getElementById('halfday_wrap_' + staffId);
        if (wrap) {
            wrap.style.display = (status === 'half_day') ? 'block' : 'none';
        }
    }
    </script>

<!-- =========================================================================
     VIEW 2: MONTHLY ATTENDANCE REGISTER MATRIX
     ========================================================================= -->
<?php elseif ($view === 'monthly'): ?>
    <?php
    $monthsList = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    ?>

    <!-- Monthly Filter Card -->
    <div class="card" style="padding: 16px 20px;">
        <form method="get" action="" class="filter-form">
            <input type="hidden" name="view" value="monthly">
            <div>
                <label for="att_month">Month</label>
                <select id="att_month" name="month" onchange="this.form.submit()">
                    <?php foreach ($monthsList as $num => $mName): ?>
                        <option value="<?= $num ?>" <?= $selectedMonth === $num ? 'selected' : '' ?>><?= $mName ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="att_year">Year</label>
                <select id="att_year" name="year" onchange="this.form.submit()">
                    <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>

    <!-- Monthly Summary KPIs -->
    <div class="grid grid-4" style="margin-bottom: 20px;">
        <?= render_kpi_card('Calendar Days', $monthlyRegister['total_days'] . ' Days', $monthlyRegister['month_name'], '', 'default') ?>
        <?= render_kpi_card('Working Days', $monthlyRegister['working_days'] . ' Days', 'Scheduled workdays', '', 'success') ?>
        <?= render_kpi_card('Holidays & Sundays', $monthlyRegister['total_holidays'] . ' Days', 'Paid non-working days', '', 'primary') ?>
        <?= render_kpi_card('Active Staff', count($monthlyRegister['matrix']) . ' Staff', 'Included in register', '', 'default') ?>
    </div>

    <!-- Attendance Matrix Card -->
    <div class="card">
        <div class="card-header-bar">
            <div>
                <h2>Monthly Attendance Register</h2>
                <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;"><?= e($monthlyRegister['month_name']) ?></p>
            </div>
            <div class="action-group" style="font-size: 12px; color: var(--text-body); gap: 12px;">
                <span><span class="matrix-badge mb-P">P</span> Present</span>
                <span><span class="matrix-badge mb-HD">HD</span> Half Day</span>
                <span><span class="matrix-badge mb-A">A</span> Absent</span>
                <span><span class="matrix-badge mb-PL">PL</span> Paid Leave</span>
                <span><span class="matrix-badge mb-H">H</span> Holiday / Sun</span>
            </div>
        </div>

        <div class="matrix-container">
            <table class="matrix-table">
                <thead>
                    <tr>
                        <th class="sticky-name">Staff Name</th>
                        <?php for ($d = 1; $d <= $monthlyRegister['total_days']; $d++): ?>
                            <?php
                            $meta = $monthlyRegister['days_meta'][$d];
                            $colClass = $meta['is_sunday'] ? 'col-sun' : ($meta['is_declared_holiday'] ? 'col-hol' : '');
                            ?>
                            <th class="<?= $colClass ?>" title="<?= e($meta['holiday_reason'] ?: $meta['date']) ?>">
                                <div><?= $d ?></div>
                                <div style="font-size: 9.5px; opacity: 0.8;"><?= $meta['day_name'] ?></div>
                            </th>
                        <?php endfor; ?>
                        <th style="background: var(--success-bg); color: #166534;">P</th>
                        <th style="background: var(--warning-bg); color: #92400E;">HD</th>
                        <th style="background: var(--danger-bg); color: #991B1B;">A</th>
                        <th style="background: var(--primary-light); color: var(--primary-hover);">H</th>
                        <th style="background: var(--primary-light); color: var(--primary-hover); min-width: 72px;">Payable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthlyRegister['matrix'])): ?>
                        <tr>
                            <td colspan="<?= $monthlyRegister['total_days'] + 6 ?>" style="text-align: center; padding: 36px 20px; color: var(--muted);">
                                No active staff records found for this month.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($monthlyRegister['matrix'] as $row): ?>
                            <?php $s = $row['summary']; ?>
                            <tr>
                                <td class="sticky-name">
                                    <div style="font-weight: 600; color: var(--text);"><?= e($row['staff']['name']) ?></div>
                                    <div style="font-size: 11px; color: var(--muted); font-weight: 500;"><?= e($row['staff']['designation']) ?></div>
                                </td>
                                <?php for ($d = 1; $d <= $monthlyRegister['total_days']; $d++): ?>
                                    <?php
                                    $rec = $row['days'][$d];
                                    $meta = $monthlyRegister['days_meta'][$d];
                                    $colClass = $meta['is_sunday'] ? 'col-sun' : ($meta['is_declared_holiday'] ? 'col-hol' : '');

                                    $status = $rec['status'];
                                    $badgeClass = 'mb-unmarked';
                                    $badgeText = '-';

                                    if ($status === 'present') { $badgeClass = 'mb-P'; $badgeText = 'P'; }
                                    elseif ($status === 'half_day') { $badgeClass = 'mb-HD'; $badgeText = 'HD'; }
                                    elseif ($status === 'absent') { $badgeClass = 'mb-A'; $badgeText = 'A'; }
                                    elseif ($status === 'paid_leave') { $badgeClass = 'mb-PL'; $badgeText = 'PL'; }
                                    elseif ($status === 'holiday') { $badgeClass = 'mb-H'; $badgeText = 'H'; }
                                    elseif ($status === 'future') { $badgeClass = 'mb-future'; $badgeText = '·'; }
                                    ?>
                                    <td class="<?= $colClass ?>" title="<?= e($meta['date'] . ($rec['notes'] ? ': ' . $rec['notes'] : '')) ?>">
                                        <span class="matrix-badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                                    </td>
                                <?php endfor; ?>
                                <td style="font-weight: 700; color: #166534; font-variant-numeric: tabular-nums;"><?= $s['present'] ?></td>
                                <td style="font-weight: 700; color: #92400E; font-variant-numeric: tabular-nums;"><?= $s['half_day'] ?></td>
                                <td style="font-weight: 700; color: #991B1B; font-variant-numeric: tabular-nums;"><?= $s['absent'] ?></td>
                                <td style="font-weight: 700; color: var(--primary-hover); font-variant-numeric: tabular-nums;"><?= $s['holiday'] ?></td>
                                <td style="font-weight: 800; color: var(--primary-hover); background: var(--primary-light); font-size: 12.5px; font-variant-numeric: tabular-nums;">
                                    <?= $s['payable_days'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="rules-callout">
            <strong>Payroll Calculation Rules</strong>
            <ul>
                <li><strong>Holidays &amp; Sundays (H):</strong> Declared institute holidays and Sundays are included in <strong>Payable Days</strong> with zero deduction.</li>
                <li><strong>Half Day (HD):</strong> Counts as 0.5 payable day (half-day deduction applies).</li>
                <li><strong>Absent (A):</strong> Unpaid absence deducts 1 full day from payable days.</li>
                <li><strong>Formula:</strong> <code>Payable Days = P + (HD &times; 0.5) + H + PL</code>.</li>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
