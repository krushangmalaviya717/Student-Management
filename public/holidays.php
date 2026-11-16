<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/services/HolidayService.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
require_admin();

$pdo = db();
$user = current_user();
$error = null;

// Handle POST actions via HolidayService (Requirement 2 & 5)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'declare_holiday';

    try {
        if ($action === 'declare_holiday') {
            $batchSelection = $_POST['batch_id'] ?? 'all';
            $mode = $_POST['holiday_mode'] ?? 'single';
            $reason = trim($_POST['reason'] ?? '') ?: null;
            $skipSundays = isset($_POST['skip_sundays']) ? 1 : 0;

            if ($mode === 'single') {
                $singleDate = trim($_POST['holiday_date'] ?? '');
                if (empty($singleDate)) {
                    throw new RuntimeException('મહેરબાની કરીને રજાની તારીખ પસંદ કરો.');
                }
                $res = HolidayService::declareSingleHoliday($batchSelection, $singleDate, $reason, (int)$user['id']);
                flash('success', "સફળતાપૂર્વક રજા જાહેર કરવામાં આવી ({$res['added']} બેચમાં લાગુ થયેલ).");
            } elseif ($mode === 'range') {
                $startDate = trim($_POST['start_date'] ?? '');
                $endDate = trim($_POST['end_date'] ?? '');
                if (empty($startDate) || empty($endDate)) {
                    throw new RuntimeException('વેકેશન માટે શરૂઆત અને સમાપ્તિ તારીખ પસંદ કરવી જરૂરી છે.');
                }
                $res = HolidayService::declareHolidayRange($batchSelection, $startDate, $endDate, $reason, (bool)$skipSundays, (int)$user['id']);
                flash('success', "સફળતાપૂર્વક વેકેશન લાગુ કરવામાં આવ્યું: કુલ {$res['total_days']} દિવસની રજા લાગુ થઈ.");
            } elseif ($mode === 'custom') {
                $rawDates = $_POST['custom_dates'] ?? [];
                if (!is_array($rawDates)) $rawDates = [];
                $res = HolidayService::declareCustomDates($batchSelection, $rawDates, $reason, (int)$user['id']);
                flash('success', "સફળતાપૂર્વક કસ્ટમ રજાઓ લાગુ કરવામાં આવી.");
            }
            redirect('public/holidays.php');

        } elseif ($action === 'delete_holiday') {
            $holidayId = (int)($_POST['holiday_id'] ?? 0);
            HolidayService::deleteHoliday($holidayId, (int)$user['id']);
            flash('success', 'રજા સફળતાપૂર્વક રદ (Delete) કરવામાં આવી છે અને સંલગ્ન વિદ્યાર્થીઓની એક્સપાયરી ડેટમાંથી ૧ દિવસ બાદ કરી દેવામાં આવ્યો છે.');
            redirect('public/holidays.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Active batches for selection
$batches = BatchService::getAllBatches(true);

// Filter for holidays list
$batchFilter = $_GET['batch_id'] ?? '';
$sql = "SELECT h.*, b.name AS batch_name, 
               (SELECT COUNT(*) FROM course_adjustments ca WHERE ca.holiday_id = h.id) AS affected_count,
               u.name AS creator_name
        FROM holidays h 
        JOIN batches b ON b.id = h.batch_id 
        LEFT JOIN users u ON u.id = h.created_by
        WHERE 1=1";
$params = [];

if ($batchFilter !== '') {
    $sql .= " AND h.batch_id = ?";
    $params[] = $batchFilter;
}
$sql .= " ORDER BY h.holiday_date DESC LIMIT 150";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$holidays = $stmt->fetchAll();

// Excel Export Handler (Requirement 3: Reusable ExportService)
if (($_GET['export'] ?? '') === 'excel') {
    $headers = ['Holiday Date', 'Day', 'Batch', 'Reason', 'Affected Students (+1 Day)', 'Recorded By', 'Recorded At'];
    $rows = [];
    foreach ($holidays as $h) {
        $rows[] = [
            format_date($h['holiday_date']),
            date('l', strtotime($h['holiday_date'])),
            $h['batch_name'],
            $h['reason'] ?: 'Institute Closed',
            $h['affected_count'] . ' active students',
            $h['creator_name'] ?? 'Admin',
            format_date($h['created_at'])
        ];
    }
    ExportService::downloadCsv('Holidays_Schedule_' . date('Y-m-d') . '.csv', $headers, $rows);
}

// Summary numbers
$totalHolidaysCount = (int)$pdo->query("SELECT COUNT(*) FROM holidays")->fetchColumn();
$upcomingHolidaysCount = (int)$pdo->query("SELECT COUNT(*) FROM holidays WHERE holiday_date >= CURDATE()")->fetchColumn();
$totalAdjustments = (int)$pdo->query("SELECT COUNT(*) FROM course_adjustments WHERE adjustment_type = 'institute_holiday'")->fetchColumn();

$pageTitle = 'Holiday Management';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
/* Segmented Control for Holiday Modes */
.mode-toggle-group {
    display: inline-flex;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 3px;
    gap: 2px;
    margin-bottom: 20px;
}
.mode-toggle-btn {
    padding: 7px 16px;
    border-radius: 4px;
    border: none;
    font-size: 13px;
    font-weight: 500;
    color: #64748b;
    background: transparent;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.mode-toggle-btn svg {
    color: #94a3b8;
}
.mode-toggle-btn:hover {
    color: #1f2937;
}
.mode-toggle-btn:hover svg {
    color: #475569;
}
.mode-toggle-btn.active {
    background: #ffffff;
    color: #111827;
    font-weight: 600;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(0, 0, 0, 0.04);
}
.mode-toggle-btn.active svg {
    color: var(--primary);
}

/* Statistics Grid */
.kpi-grid-holiday {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
@media (max-width: 768px) {
    .kpi-grid-holiday { grid-template-columns: 1fr; }
}

.holiday-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 18px 20px;
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.03);
}
.holiday-kpi-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.holiday-kpi-title {
    font-size: 12.5px;
    font-weight: 500;
    color: #64748b;
}
.holiday-kpi-icon {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
}
.holiday-kpi-value {
    font-size: 26px;
    font-weight: 700;
    color: #111827;
    line-height: 1.1;
    letter-spacing: -0.02em;
}

.custom-date-row {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 8px;
}
</style>

<?php if ($error): ?>
    <div class="flash flash-error" style="margin-bottom: 20px;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Summary KPI Cards -->
<div class="kpi-grid-holiday">
    <div class="holiday-kpi-card">
        <div class="holiday-kpi-header">
            <span class="holiday-kpi-title">Total Holidays Recorded (કુલ રજાઓ)</span>
            <div class="holiday-kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
        </div>
        <div class="holiday-kpi-value"><?= $totalHolidaysCount ?></div>
    </div>
    <div class="holiday-kpi-card">
        <div class="holiday-kpi-header">
            <span class="holiday-kpi-title">Upcoming Holidays (આગામી રજાઓ)</span>
            <div class="holiday-kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
        </div>
        <div class="holiday-kpi-value"><?= $upcomingHolidaysCount ?></div>
    </div>
    <div class="holiday-kpi-card">
        <div class="holiday-kpi-header">
            <span class="holiday-kpi-title">Student Days Added (વિદ્યાર્થીઓને મળેલ દિવસો)</span>
            <div class="holiday-kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
            </div>
        </div>
        <div class="holiday-kpi-value"><?= $totalAdjustments ?></div>
    </div>
</div>

<!-- ==================== DECLARE HOLIDAY FORM ==================== -->
<div class="card">
    <div style="margin-bottom: 18px;">
        <h2 style="font-size: 15px; font-weight: 700; color: #111827; margin: 0 0 4px;">Declare Institute Holiday (રજા / વેકેશન જાહેર કરો)</h2>
        <small style="font-size: 12.5px; color: #64748b;">
            અહીંથી જાહેર કરેલી રજા મુજબ તે બેચમાં ભણતા તમામ એક્ટિવ વિદ્યાર્થીઓની કોર્સ પૂર્ણ થવાની તારીખ (Expiry Date) માં તેટલા દિવસો આપોઆપ વધી જશે (+1 day per holiday).
        </small>
    </div>

    <!-- Mode Selector: Single Day / Vacation Range / Multiple Dates -->
    <div class="mode-toggle-group">
        <button type="button" class="mode-toggle-btn active" id="btnModeSingle" onclick="switchMode('single')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Single Day (એક દિવસ)</span>
        </button>
        <button type="button" class="mode-toggle-btn" id="btnModeRange" onclick="switchMode('range')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h8"/></svg>
            <span>Vacation / Range (સળંગ રજાઓ / વેકેશન)</span>
        </button>
        <button type="button" class="mode-toggle-btn" id="btnModeCustom" onclick="switchMode('custom')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8.01" y2="14"/><line x1="12" y1="14" x2="12.01" y2="14"/><line x1="16" y1="14" x2="16.01" y2="14"/></svg>
            <span>Multiple Dates (છૂટક તારીખો)</span>
        </button>
    </div>

    <form method="post" id="holidayForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="declare_holiday">
        <input type="hidden" name="holiday_mode" id="holiday_mode" value="single">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; align-items: start; margin-bottom: 16px;">
            <!-- Batch Selection: All batches or specific -->
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Batch (કઈ બેચ માટે?)</label>
                <select name="batch_id" required style="width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13.5px;">
                    <option value="all" selected>All Active Batches (બધી જ બેચ માટે)</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="display: block; margin-top: 4px; font-size: 12px; color: #64748b;">તહેવાર કે વેકેશનમાં સામાન્ય રીતે 'All Batches' પસંદ કરવું.</small>
            </div>

            <!-- Single Day Picker (Shown in 'single' mode) -->
            <div id="sectionSingleDate">
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Holiday Date (રજાની તારીખ) *</label>
                <input type="date" name="holiday_date" id="inputSingleDate" value="<?= date('Y-m-d') ?>" style="width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13.5px;">
            </div>

            <!-- Date Range Pickers (Shown in 'range' mode) -->
            <div id="sectionRangeDate" style="display: none; grid-column: span 2;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">From Date (શરૂઆતની તારીખ) *</label>
                        <input type="date" name="start_date" id="inputStartDate" value="<?= date('Y-m-d') ?>" style="width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13.5px;" onchange="calculateRangeDays()">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">To Date (સમાપ્તિ તારીખ) *</label>
                        <input type="date" name="end_date" id="inputEndDate" value="<?= date('Y-m-d', strtotime('+4 days')) ?>" style="width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13.5px;" onchange="calculateRangeDays()">
                    </div>
                </div>

                <div style="margin-top: 10px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="skip_sundays" id="skip_sundays" value="1" checked style="width: 16px; height: 16px; margin: 0; cursor: pointer;" onchange="calculateRangeDays()">
                        <label for="skip_sundays" style="font-weight: 500; font-size: 12.5px; color: #374151; cursor: pointer; margin: 0;">
                            Skip Sundays (રવિવાર બાદ કરવો - રજા તરીકે ન ગણવો)
                        </label>
                    </div>
                    <div id="rangeDaysBadge" style="font-weight: 600; color: #475569; font-size: 12px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 3px 10px; border-radius: 4px;">
                        ગણતરી: ૫ દિવસની રજા
                    </div>
                </div>
            </div>

            <!-- Custom Multiple Dates (Shown in 'custom' mode) -->
            <div id="sectionCustomDates" style="display: none; grid-column: span 2;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Select Multiple Dates (એક કરતાં વધુ તારીખો પસંદ કરો):</label>
                <div id="customDatesContainer">
                    <div class="custom-date-row">
                        <input type="date" name="custom_dates[]" value="<?= date('Y-m-d') ?>" style="max-width: 220px; height: 36px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px;">
                    </div>
                    <div class="custom-date-row">
                        <input type="date" name="custom_dates[]" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" style="max-width: 220px; height: 36px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="this.parentElement.remove()" style="color:#dc2626; border-color:#fecaca; height:36px; padding:0 10px;">Remove</button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addCustomDateRow()" style="margin-top: 6px; height: 32px; font-size: 12px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Add Another Date (બીજી તારીખ ઉમેરો)</span>
                </button>
            </div>
        </div>

        <!-- Reason & Submit Button -->
        <div style="display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: end;">
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Reason (રજાનું કારણ / તહેવારનું નામ)</label>
                <input type="text" name="reason" placeholder="દા.ત. Diwali Vacation, Navratri, Maintenance, વગેરે." style="width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13.5px;">
            </div>
            <div>
                <button class="btn" type="submit" style="height: 38px; padding: 0 20px; font-weight: 600; background: #6D5CE7; color: #ffffff; border-radius: 6px; white-space: nowrap;">
                    Declare Holiday (રજા જાહેર કરો)
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ==================== RECENT HOLIDAYS LIST ==================== -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 style="font-size: 15px; font-weight: 700; color: #111827; margin: 0 0 3px;">Recorded Holidays History (જાહેર થયેલી રજાઓનો ઇતિહાસ)</h2>
            <small style="font-size: 12.5px; color: #64748b;">જો ભૂલથી કોઈ રજા નોંધાઈ ગઈ હોય તો 'Delete / રદ કરો' બટનથી તેને કેન્સલ કરી શકાય છે.</small>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="?export=excel<?= $batchFilter ? '&batch_id=' . $batchFilter : '' ?>" 
               class="btn btn-sm" style="background:#16A34A; color:#ffffff; border:none; display:inline-flex; align-items:center; gap:6px; font-weight:600; height:34px; padding:0 14px; border-radius:6px; text-decoration:none;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span>Export to Excel (CSV)</span>
            </a>
        </div>
    </div>

    <!-- Batch Filter -->
    <div style="margin-bottom: 14px;">
        <form method="get" style="display:flex; gap:10px; align-items:center;">
            <label style="font-weight:600; margin:0; font-size:12.5px; color:#475569;">Filter by Batch:</label>
            <select name="batch_id" onchange="this.form.submit()" style="max-width:240px; height:34px; padding:4px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;">
                <option value="">All Batches (બધી જ બેચ)</option>
                <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= (string)$batchFilter === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($batchFilter !== ''): ?>
                <a href="<?= url('public/holidays.php') ?>" class="btn btn-sm btn-secondary" style="height:34px; padding:0 10px;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>Holiday Date (તારીખ)</th>
                <th>Day (વાર)</th>
                <th>Batch (બેચ)</th>
                <th>Reason (કારણ)</th>
                <th>Affected Students</th>
                <th>Recorded Date</th>
                <th style="text-align: right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($holidays as $h): 
                $dateObj = new DateTime($h['holiday_date']);
                $isFuture = $dateObj >= new DateTime('today');
            ?>
            <tr>
                <td>
                    <span style="font-weight:600; color:#1F2937;">
                        <?= format_date($h['holiday_date']) ?>
                    </span>
                    <?php if ($isFuture): ?>
                        <span class="badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:11px; padding:1px 6px; border-radius:4px; font-weight:500; margin-left:6px;">Upcoming</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-gray"><?= $dateObj->format('l') ?></span>
                </td>
                <td>
                    <span style="font-weight:500; color:#1F2937;"><?= e($h['batch_name']) ?></span>
                </td>
                <td style="color:#475569;">
                    <?= e($h['reason'] ?: 'Institute Closed') ?>
                </td>
                <td>
                    <span class="badge badge-green">
                        +1 Day to <?= $h['affected_count'] ?> Active Students
                    </span>
                </td>
                <td style="color:#64748B; font-size:12.5px;">
                    <?= format_date($h['created_at']) ?> 
                    <?= !empty($h['creator_name']) ? 'by ' . e($h['creator_name']) : '' ?>
                </td>
                <td style="text-align: right;">
                    <!-- Delete / Cancel Holiday Button -->
                    <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('શું તમે ખરેખર આ રજા રદ કરવા માંગો છો?\nઆનાથી સંલગ્ન વિદ્યાર્થીઓની એક્સપાયરી ડેટમાંથી ૧ દિવસ આપોઆપ બાદ થઈ જશે.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_holiday">
                        <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-secondary" style="color:#dc2626; border-color:#fecaca; height:28px; padding:0 10px; font-size:12px; font-weight:500;" title="રજા રદ કરો">
                            Delete / રદ કરો
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>

            <?php if (!$holidays): ?>
            <tr>
                <td colspan="7" class="muted" style="text-align: center; padding: 26px;">
                    હજુ સુધી કોઈ રજા જાહેર થયેલ નથી.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function switchMode(mode) {
    document.getElementById('holiday_mode').value = mode;

    // Button states
    document.getElementById('btnModeSingle').classList.toggle('active', mode === 'single');
    document.getElementById('btnModeRange').classList.toggle('active', mode === 'range');
    document.getElementById('btnModeCustom').classList.toggle('active', mode === 'custom');

    // Section visibility
    document.getElementById('sectionSingleDate').style.display = (mode === 'single') ? 'block' : 'none';
    document.getElementById('sectionRangeDate').style.display = (mode === 'range') ? 'block' : 'none';
    document.getElementById('sectionCustomDates').style.display = (mode === 'custom') ? 'block' : 'none';

    if (mode === 'range') {
        calculateRangeDays();
    }
}

function calculateRangeDays() {
    var startInput = document.getElementById('inputStartDate').value;
    var endInput = document.getElementById('inputEndDate').value;
    var skipSundays = document.getElementById('skip_sundays').checked;
    var badge = document.getElementById('rangeDaysBadge');

    if (!startInput || !endInput) return;

    var start = new Date(startInput);
    var end = new Date(endInput);

    if (start > end) {
        badge.innerText = 'શરૂઆતની તારીખ મોટી છે';
        badge.style.color = '#dc2626';
        badge.style.background = '#fef2f2';
        badge.style.borderColor = '#fecaca';
        return;
    }

    var count = 0;
    var cur = new Date(start);
    while (cur <= end) {
        if (!skipSundays || cur.getDay() !== 0) { // 0 is Sunday
            count++;
        }
        cur.setDate(cur.getDate() + 1);
    }

    badge.innerText = 'ગણતરી: ' + count + ' દિવસની રજા લાગુ થશે';
    badge.style.color = '#334155';
    badge.style.background = '#f8fafc';
    badge.style.borderColor = '#e2e8f0';
}

function addCustomDateRow() {
    var container = document.getElementById('customDatesContainer');
    var div = document.createElement('div');
    div.className = 'custom-date-row';
    div.innerHTML = '<input type="date" name="custom_dates[]" style="max-width: 220px; height: 36px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px;" required>' +
                    '<button type="button" class="btn btn-sm btn-secondary" onclick="this.parentElement.remove()" style="color:#dc2626; border-color:#fecaca; height:36px; padding:0 10px;">Remove</button>';
    container.appendChild(div);
}

// Initial calculation
calculateRangeDays();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
