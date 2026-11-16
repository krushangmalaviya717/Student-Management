<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/StaffService.php';
require_once __DIR__ . '/../includes/services/AttendanceService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';

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
            flash('success', "તારીખ " . date('d-m-Y', strtotime($date)) . " ની સ્ટાફ હાજરી સફળતાપૂર્વક સાચવી લેવામાં આવી ({$count} સ્ટાફ).");
            redirect('public/staff_attendance.php?date=' . urlencode($date));

        } elseif ($action === 'mark_all_status') {
            $date = $_POST['date'] ?? date('Y-m-d');
            $status = $_POST['status'] ?? 'present';
            $staffList = StaffService::getAllStaff('active');
            $sheet = [];
            foreach ($staffList as $st) {
                $sheet[$st['id']] = [
                    'status' => $status,
                    'notes' => ($status === 'holiday' ? 'સંસ્થાની રજા' : null)
                ];
            }
            AttendanceService::saveDailySheet($date, $sheet, (int)$user['id']);
            flash('success', "તમામ સ્ટાફ માટે '{$status}' સ્ટેટસ સફળતાપૂર્વક લાગુ કરવામાં આવ્યું.");
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
/* Attendance Navigation Tabs */
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

/* Status Radio / Segmented pill controls */
.att-radio-group {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
    gap: 2px;
    border: 1px solid #e2e8f0;
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
    padding: 5px 11px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    user-select: none;
    transition: all 0.15s ease;
}
.att-radio-btn input:checked + .att-radio-label.lbl-present {
    background: #16a34a; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.att-radio-btn input:checked + .att-radio-label.lbl-halfday {
    background: #d97706; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.att-radio-btn input:checked + .att-radio-label.lbl-absent {
    background: #dc2626; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.att-radio-btn input:checked + .att-radio-label.lbl-paidleave {
    background: #2563eb; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.att-radio-btn input:checked + .att-radio-label.lbl-holiday {
    background: #7c3aed; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

/* Matrix Table */
.matrix-container {
    overflow-x: auto;
    max-width: 100%;
    margin-top: 15px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.matrix-table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    font-size: 11.5px;
}
.matrix-table th, .matrix-table td {
    padding: 6px 5px;
    text-align: center;
    border-right: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    white-space: nowrap;
}
.matrix-table th {
    background: #f8fafc;
    font-weight: 700;
    color: #475569;
    position: sticky;
    top: 0;
}
.matrix-table th.col-sun, .matrix-table td.col-sun {
    background: #fff1f2 !important;
    color: #be123c;
}
.matrix-table th.col-hol, .matrix-table td.col-hol {
    background: #f5f3ff !important;
    color: #6d28d9;
}
.matrix-table th.sticky-name, .matrix-table td.sticky-name {
    position: sticky;
    left: 0;
    background: #ffffff;
    z-index: 2;
    text-align: left;
    min-width: 150px;
    font-weight: 600;
    border-right: 2px solid #cbd5e1;
}
.matrix-badge {
    display: inline-block;
    width: 22px;
    height: 22px;
    line-height: 22px;
    border-radius: 4px;
    font-weight: 800;
    font-size: 10.5px;
}
.mb-P { background: #dcfce7; color: #166534; }
.mb-HD { background: #fef3c7; color: #92400e; }
.mb-A { background: #fee2e2; color: #991b1b; }
.mb-PL { background: #dbeafe; color: #1e40af; }
.mb-H { background: #ede9fe; color: #5b21b6; }
.mb-unmarked { background: #f1f5f9; color: #94a3b8; }
.mb-future { background: transparent; color: #cbd5e1; }
</style>

<!-- Sub Navigation Tabs for Staff Module -->
<div class="staff-subnav">
    <a href="<?= url('public/staff.php') ?>" class="staff-subnav-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Staff Directory (સ્ટાફ લિસ્ટ)
    </a>
    <a href="<?= url('public/staff_attendance.php?view=daily') ?>" class="staff-subnav-item <?= $view === 'daily' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Daily Attendance (દૈનિક હાજરી)
    </a>
    <a href="<?= url('public/staff_attendance.php?view=monthly') ?>" class="staff-subnav-item <?= $view === 'monthly' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Monthly Register (માસિક રજીસ્ટર)
    </a>
    <a href="<?= url('public/staff_payroll.php') ?>" class="staff-subnav-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        Payroll & Salary (પગાર ગણતરી & સ્લિપ)
    </a>
</div>

<?php if ($error): ?>
    <div class="flash flash-error" style="margin-bottom: 20px;">
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

    <!-- Top KPI Cards for Selected Date -->
    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 22px;">
        <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #4338ca;">
            <div style="font-size: 24px; font-weight: 800; color: #1e293b;"><?= $dailyKPIs['total_staff'] ?></div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">કુલ સ્ટાફ (Total Staff)</div>
        </div>
        <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #16a34a;">
            <div style="font-size: 24px; font-weight: 800; color: #16a34a;"><?= $dailyKPIs['present'] ?></div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">હાજર (Full Day Present)</div>
        </div>
        <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #d97706;">
            <div style="font-size: 24px; font-weight: 800; color: #d97706;"><?= $dailyKPIs['half_day'] ?></div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">અડધો દિવસ (Half Day - 0.5)</div>
        </div>
        <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #dc2626;">
            <div style="font-size: 24px; font-weight: 800; color: #dc2626;"><?= $dailyKPIs['absent'] ?></div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">ગેરહાજર (Absent - LOP)</div>
        </div>
        <div class="card" style="margin: 0; padding: 14px 16px; border-left: 4px solid #7c3aed;">
            <div style="font-size: 24px; font-weight: 800; color: #7c3aed;"><?= $dailyKPIs['holiday'] + $dailyKPIs['paid_leave'] ?></div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">રજા / સંસ્થા રજા (Holiday)</div>
        </div>
    </div>

    <!-- Date Bar & Controls -->
    <div class="card" style="margin-bottom: 20px; padding: 14px 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <a href="?view=daily&date=<?= e($prevDate) ?>" class="btn btn-sm btn-secondary" title="અગાઉનો દિવસ">◀ ગઈકાલે</a>
                <form method="get" action="" style="display: inline-flex; align-items: center; gap: 6px; margin: 0;">
                    <input type="hidden" name="view" value="daily">
                    <input type="date" name="date" value="<?= e($selectedDate) ?>" onchange="this.form.submit()" style="margin:0; font-weight: 700; font-size: 14px; padding: 6px 12px;">
                </form>
                <a href="?view=daily&date=<?= e($nextDate) ?>" class="btn btn-sm btn-secondary" title="પછીનો દિવસ">આવતીકાલે ▶</a>
                <?php if (!$isToday): ?>
                    <a href="?view=daily&date=<?= date('Y-m-d') ?>" class="btn btn-sm btn-secondary" style="font-size: 12px;">આજે (Today)</a>
                <?php endif; ?>
            </div>

            <div style="display: flex; gap: 8px; align-items: center;">
                <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('બધા જ સ્ટાફને હાજર (Present) તરીકે સેટ કરવા છે?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_status">
                    <input type="hidden" name="date" value="<?= e($selectedDate) ?>">
                    <input type="hidden" name="status" value="present">
                    <button type="submit" class="btn btn-secondary btn-sm" style="color: #166534; font-weight: 600;">
                        ✔ Mark All Present (બધા હાજર)
                    </button>
                </form>

                <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('શું આ તારીખે સમગ્ર સ્ટાફ માટે રજા (Holiday) જાહેર કરવી છે? (પગાર કપાશે નહીં)');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_status">
                    <input type="hidden" name="date" value="<?= e($selectedDate) ?>">
                    <input type="hidden" name="status" value="holiday">
                    <button type="submit" class="btn btn-secondary btn-sm" style="color: #6d28d9; font-weight: 600;">
                        🏖 Mark All Holiday (જાહેર રજા)
                    </button>
                </form>
            </div>
        </div>

        <?php if ($dailySheet['holiday_info']): ?>
            <div style="background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; padding: 10px 14px; margin-top: 14px; display: flex; align-items: center; gap: 10px; color: #5b21b6; font-size: 13.5px;">
                <span style="font-size: 18px;">🏖</span>
                <div>
                    <strong>આ તારીખે સંસ્થાની રજા / વીકલી ઓફ છે:</strong> <?= e($dailySheet['holiday_info']['reason']) ?>
                    <div style="font-size: 12px; color: #6d28d9; margin-top: 2px;">
                        નિયમ મુજબ, સંસ્થા દ્વારા આપવામાં આવેલી રજા કે રવિવારના પગારમાં કોઈ કાપ મુકાશે નહીં (Not Countable for Deduction).
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="margin: 0; font-size: 16px;">
                    Attendance Sheet: <?= date('d M Y (l)', strtotime($selectedDate)) ?>
                </h3>
                <button type="submit" class="btn" style="padding: 8px 20px; font-weight: 700;">
                    💾 Save Attendance (હાજરી સાચવો)
                </button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 220px;">Staff Member</th>
                        <th>Assigned Batches</th>
                        <th style="min-width: 320px;">Attendance Status (હાજરી સ્થિતિ)</th>
                        <th>Time (ઇન / આઉટ)</th>
                        <th>Notes (નોંધ)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dailySheet['staff'] as $st): ?>
                        <?php 
                        $att = $st['attendance'];
                        $curStatus = $att['status'];
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: #0f172a; font-size: 14px;"><?= e($st['name']) ?></div>
                                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                    <?= e($st['designation']) ?> • <a href="tel:<?= e($st['phone']) ?>" style="color: #64748b;"><?= e($st['phone']) ?></a>
                                </div>
                            </td>
                            <td>
                                <span style="font-size: 12px; color: #475569;"><?= e($st['batches_str']) ?></span>
                            </td>
                            <td>
                                <div class="att-radio-group">
                                    <label class="att-radio-btn" title="સંપૂર્ણ દિવસ હાજર (1 Day)">
                                        <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="present" <?= $curStatus === 'present' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                        <span class="att-radio-label lbl-present">✔ Present (હાજર)</span>
                                    </label>
                                    <label class="att-radio-btn" title="અડધો દિવસ (0.5 Day)">
                                        <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="half_day" <?= $curStatus === 'half_day' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                        <span class="att-radio-label lbl-halfday">⏱ Half Day (0.5)</span>
                                    </label>
                                    <label class="att-radio-btn" title="ગેરહાજર - પગાર કપાશે (Unpaid Absent)">
                                        <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="absent" <?= $curStatus === 'absent' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                        <span class="att-radio-label lbl-absent">✖ Absent (ગેરહાજર)</span>
                                    </label>
                                    <label class="att-radio-btn" title="મંજૂર રજા (Paid Leave)">
                                        <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="paid_leave" <?= $curStatus === 'paid_leave' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                        <span class="att-radio-label lbl-paidleave">🌴 Leave (CL)</span>
                                    </label>
                                    <label class="att-radio-btn" title="સંસ્થાની રજા / વારતહેવાર (Paid Holiday)">
                                        <input type="radio" name="attendance[<?= $st['id'] ?>][status]" value="holiday" <?= $curStatus === 'holiday' ? 'checked' : '' ?> onchange="toggleHalfDaySelect(<?= $st['id'] ?>, this.value)">
                                        <span class="att-radio-label lbl-holiday">🏖 Holiday (રજા)</span>
                                    </label>
                                </div>

                                <!-- Session selector for Half Day -->
                                <div id="halfday_wrap_<?= $st['id'] ?>" style="display: <?= $curStatus === 'half_day' ? 'inline-block' : 'none' ?>; margin-top: 6px;">
                                    <select name="attendance[<?= $st['id'] ?>][half_day_session]" style="font-size: 11.5px; padding: 4px 8px; margin: 0; width: auto;">
                                        <option value="morning" <?= ($att['half_day_session'] ?? '') === 'morning' ? 'selected' : '' ?>>Morning Session (સવારનો સમય)</option>
                                        <option value="afternoon" <?= ($att['half_day_session'] ?? '') === 'afternoon' ? 'selected' : '' ?>>Afternoon Session (બપોર પછી)</option>
                                    </select>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; align-items: center;">
                                    <input type="time" name="attendance[<?= $st['id'] ?>][check_in_time]" value="<?= e($att['check_in_time'] ?? '') ?>" title="Check-In" style="width: 85px; font-size: 11.5px; padding: 4px; margin:0;">
                                    <span style="color:#94a3b8;">-</span>
                                    <input type="time" name="attendance[<?= $st['id'] ?>][check_out_time]" value="<?= e($att['check_out_time'] ?? '') ?>" title="Check-Out" style="width: 85px; font-size: 11.5px; padding: 4px; margin:0;">
                                </div>
                            </td>
                            <td>
                                <input type="text" name="attendance[<?= $st['id'] ?>][notes]" value="<?= e($att['notes'] ?? '') ?>" placeholder="નોંધ..." style="font-size: 12px; padding: 4px 8px; margin: 0; min-width: 140px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 18px; text-align: right;">
                <button type="submit" class="btn" style="padding: 10px 24px; font-weight: 700; font-size: 14px;">
                    💾 Save Attendance (હાજરી સાચવો)
                </button>
            </div>
        </div>
    </form>

    <script>
    function toggleHalfDaySelect(staffId, status) {
        var wrap = document.getElementById('halfday_wrap_' + staffId);
        if (wrap) {
            wrap.style.display = (status === 'half_day') ? 'inline-block' : 'none';
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

    <!-- Monthly Filter Toolbar -->
    <div class="card" style="margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <form method="get" action="" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 0;">
                <input type="hidden" name="view" value="monthly">
                
                <div>
                    <label style="font-size: 12px; font-weight: 600; color: #64748b; display: block; margin-bottom: 2px;">Month (મહિનો)</label>
                    <select name="month" style="margin: 0; font-weight: 600; padding: 6px 12px;" onchange="this.form.submit()">
                        <?php foreach ($monthsList as $num => $mName): ?>
                            <option value="<?= $num ?>" <?= $selectedMonth === $num ? 'selected' : '' ?>><?= $mName ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="font-size: 12px; font-weight: 600; color: #64748b; display: block; margin-bottom: 2px;">Year (વર્ષ)</label>
                    <select name="year" style="margin: 0; font-weight: 600; padding: 6px 12px;" onchange="this.form.submit()">
                        <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div style="margin-top: 18px;">
                    <button type="submit" class="btn btn-secondary btn-sm">શોધો (Filter)</button>
                </div>
            </form>

            <div style="display: flex; gap: 8px;">
                <a href="?view=monthly&year=<?= $selectedYear ?>&month=<?= $selectedMonth ?>&export=monthly_csv" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export Register (CSV)
                </a>
                <a href="<?= url('public/staff_payroll.php?month=' . sprintf('%04d-%02d', $selectedYear, $selectedMonth)) ?>" class="btn btn-sm" style="background: #4338ca; color:#fff;">
                    💰 Calculate Salary for <?= date('M Y', strtotime(sprintf('%04d-%02d-01', $selectedYear, $selectedMonth))) ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Monthly Summary Bar -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 14px 16px;">
            <div style="font-size: 22px; font-weight: 800; color: #1e293b;"><?= $monthlyRegister['total_days'] ?> Days</div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">કુલ મહિનાના દિવસો (Total Calendar Days)</div>
        </div>
        <div class="card" style="margin:0; padding: 14px 16px;">
            <div style="font-size: 22px; font-weight: 800; color: #16a34a;"><?= $monthlyRegister['working_days'] ?> Days</div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">કામના દિવસો (Working Days)</div>
        </div>
        <div class="card" style="margin:0; padding: 14px 16px;">
            <div style="font-size: 22px; font-weight: 800; color: #6d28d9;"><?= $monthlyRegister['total_holidays'] ?> Days</div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">રજાઓ + રવિવાર (Holidays & Sundays - Paid)</div>
        </div>
        <div class="card" style="margin:0; padding: 14px 16px;">
            <div style="font-size: 22px; font-weight: 800; color: #4338ca;"><?= count($monthlyRegister['matrix']) ?> Staff</div>
            <div class="muted" style="font-size: 12px; font-weight: 600;">સક્રિય સ્ટાફ સભ્યો (Active Staff)</div>
        </div>
    </div>

    <!-- Attendance Matrix Table -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3 style="margin: 0; font-size: 16px;">
                Monthly Attendance Register (<?= $monthlyRegister['month_name'] ?>)
            </h3>
            <div style="display: flex; gap: 10px; font-size: 12px;">
                <span><span class="matrix-badge mb-P">P</span> Present</span>
                <span><span class="matrix-badge mb-HD">HD</span> Half Day (0.5)</span>
                <span><span class="matrix-badge mb-A">A</span> Absent</span>
                <span><span class="matrix-badge mb-PL">PL</span> Paid Leave</span>
                <span><span class="matrix-badge mb-H">H</span> Holiday/Sun</span>
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
                        <th style="background:#dcfce7; color:#166534; font-weight:700;">P</th>
                        <th style="background:#fef3c7; color:#92400e; font-weight:700;">HD</th>
                        <th style="background:#fee2e2; color:#991b1b; font-weight:700;">A</th>
                        <th style="background:#ede9fe; color:#5b21b6; font-weight:700;">H</th>
                        <th style="background:#e0e7ff; color:#3730a3; font-weight:800; min-width:70px;">Payable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthlyRegister['matrix'] as $row): ?>
                        <?php $s = $row['summary']; ?>
                        <tr>
                            <td class="sticky-name">
                                <div style="font-weight: 700; color: #0f172a;"><?= e($row['staff']['name']) ?></div>
                                <div style="font-size: 10.5px; color: #64748b;"><?= e($row['staff']['designation']) ?></div>
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
                            <td style="font-weight: 700; color:#166534;"><?= $s['present'] ?></td>
                            <td style="font-weight: 700; color:#92400e;"><?= $s['half_day'] ?></td>
                            <td style="font-weight: 700; color:#991b1b;"><?= $s['absent'] ?></td>
                            <td style="font-weight: 700; color:#5b21b6;"><?= $s['holiday'] ?></td>
                            <td style="font-weight: 800; color:#3730a3; background: #e0e7ff; font-size: 12.5px;">
                                <?= $s['payable_days'] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; margin-top: 14px; font-size: 12.5px; color: #475569; border: 1px solid #e2e8f0;">
            <strong>💡 રીયલ સિસ્ટમ પગાર ગણતરી નિયમ (Payroll Rules):</strong>
            <ul style="margin: 6px 0 0 18px; padding: 0;">
                <li><strong>સંસ્થાની રજાઓ / રવિવાર (H):</strong> સંસ્થા દ્વારા આપવામાં આવેલ રજાઓ અને રવિવાર <strong>Payable Days</strong> માં ગણાય છે (પગાર કપાતો નથી).</li>
                <li><strong>અડધો દિવસ (HD):</strong> અડધા દિવસની હાજરીમાં માત્ર અડધા દિવસ (0.5 Day) નો જ પગાર કપાશે.</li>
                <li><strong>ગેરહાજર (A):</strong> મંજૂરી વગરની રજા માટે પૂરા 1 દિવસનો પગાર કપાશે.</li>
                <li><strong>Payable Days =</strong> હાજર દિવસો (P) + (અડધા દિવસો × 0.5) + રજાઓ/રવિવાર (H) + મંજૂર પેઇડ રજાઓ (PL).</li>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
