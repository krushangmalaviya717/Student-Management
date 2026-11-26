<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/services/HolidayService.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
require_once __DIR__ . '/../includes/components/kpi_card.php';
require_admin();

$pdo = db();
$user = current_user();
$error = null;

// Handle POST actions via HolidayService
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
                    throw new RuntimeException('Please select a holiday date.');
                }
                $res = HolidayService::declareSingleHoliday($batchSelection, $singleDate, $reason, (int)$user['id']);
                flash('success', "Holiday declared successfully (applied across {$res['added']} batch(es)).");
            } elseif ($mode === 'range') {
                $startDate = trim($_POST['start_date'] ?? '');
                $endDate = trim($_POST['end_date'] ?? '');
                if (empty($startDate) || empty($endDate)) {
                    throw new RuntimeException('Please select both start and end dates for the holiday range.');
                }
                $res = HolidayService::declareHolidayRange($batchSelection, $startDate, $endDate, $reason, (bool)$skipSundays, (int)$user['id']);
                flash('success', "Vacation range declared successfully ({$res['total_days']} holiday day(s) applied).");
            } elseif ($mode === 'custom') {
                $rawDates = $_POST['custom_dates'] ?? [];
                if (!is_array($rawDates)) $rawDates = [];
                $res = HolidayService::declareCustomDates($batchSelection, $rawDates, $reason, (int)$user['id']);
                flash('success', 'Custom holiday dates declared successfully.');
            }
            redirect('public/holidays.php');

        } elseif ($action === 'delete_holiday') {
            $holidayId = (int)($_POST['holiday_id'] ?? 0);
            HolidayService::deleteHoliday($holidayId, (int)$user['id']);
            flash('success', 'Holiday deleted and +1 day course expiry adjustment reverted for affected students.');
            redirect('public/holidays.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Active batches for selection
$batches = BatchService::getAllBatches(true);

// Filter & Pagination for holidays list
$batchFilter = $_GET['batch_id'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$whereSql = " WHERE 1=1";
$params = [];

if ($batchFilter !== '') {
    $whereSql .= " AND h.batch_id = ?";
    $params[] = $batchFilter;
}

// Excel/CSV Export Handler (full filtered dataset)
if (($_GET['export'] ?? '') === 'excel') {
    $exportStmt = $pdo->prepare("
        SELECT h.*, b.name AS batch_name,
               (SELECT COUNT(*) FROM course_adjustments ca WHERE ca.holiday_id = h.id) AS affected_count,
               u.name AS creator_name
        FROM holidays h
        JOIN batches b ON b.id = h.batch_id
        LEFT JOIN users u ON u.id = h.created_by"
        . $whereSql . " ORDER BY h.holiday_date DESC
    ");
    $exportStmt->execute($params);
    $exportHolidays = $exportStmt->fetchAll();

    $headers = ['Holiday Date', 'Day', 'Batch', 'Reason', 'Affected Students (+1 Day)', 'Recorded By', 'Recorded At'];
    $rows = [];
    foreach ($exportHolidays as $h) {
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

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM holidays h" . $whereSql);
$cntStmt->execute($params);
$totalHolidayRows = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalHolidayRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = "SELECT h.*, b.name AS batch_name,
               (SELECT COUNT(*) FROM course_adjustments ca WHERE ca.holiday_id = h.id) AS affected_count,
               u.name AS creator_name
        FROM holidays h
        JOIN batches b ON b.id = h.batch_id
        LEFT JOIN users u ON u.id = h.created_by"
        . $whereSql . " ORDER BY h.holiday_date DESC LIMIT {$perPage} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$holidays = $stmt->fetchAll();

// Summary KPIs
$totalHolidaysCount = (int)$pdo->query("SELECT COUNT(*) FROM holidays")->fetchColumn();
$upcomingHolidaysCount = (int)$pdo->query("SELECT COUNT(*) FROM holidays WHERE holiday_date >= CURDATE()")->fetchColumn();
$totalAdjustments = (int)$pdo->query("SELECT COUNT(*) FROM course_adjustments WHERE adjustment_type = 'institute_holiday'")->fetchColumn();

$pageTitle = 'Holiday Management';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.mode-toggle-group {
    display: inline-flex;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 3px;
    gap: 3px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.mode-toggle-btn {
    padding: 6px 14px;
    border-radius: var(--radius-sm);
    border: 1px solid transparent;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted);
    background: transparent;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: inherit;
}
.mode-toggle-btn:hover {
    color: var(--text);
}
.mode-toggle-btn.active {
    background: #FFFFFF;
    color: var(--text);
    font-weight: 600;
    box-shadow: var(--shadow-xs);
    border-color: var(--border-light);
}
.mode-toggle-btn.active svg {
    color: var(--primary);
}
.custom-date-row {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.custom-date-row input[type=date] {
    max-width: 220px;
}
.range-days-pill {
    font-weight: 600;
    color: var(--text-body);
    font-size: 12px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    padding: 4px 10px;
    border-radius: var(--radius);
}
.reason-submit-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 14px;
    align-items: end;
}
@media (max-width: 640px) {
    .reason-submit-row {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-header-subtitle">Declare institute holidays and automatically extend course expiry dates for active students.</p>
    </div>
    <div class="action-group">
        <a href="?export=excel<?= $batchFilter !== '' ? '&batch_id=' . urlencode($batchFilter) : '' ?>" class="btn btn-secondary btn-export btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash-error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Summary KPI Cards -->
<div class="grid grid-3" style="margin-bottom: 20px;">
    <?= render_kpi_card('Total Holidays Recorded', (string)$totalHolidaysCount, 'All scheduled holiday entries', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>', 'default') ?>
    <?= render_kpi_card('Upcoming Holidays', (string)$upcomingHolidaysCount, 'Scheduled from today onward', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>', 'primary') ?>
    <?= render_kpi_card('Student Days Extended', (string)$totalAdjustments, 'Total +1 day course extensions', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>', 'success') ?>
</div>

<!-- ==================== DECLARE HOLIDAY FORM ==================== -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Declare Institute Holiday</h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Each declared holiday adds +1 day automatically to the course expiry date of active students in the selected batch(es).</p>
        </div>
    </div>

    <!-- Mode Selector -->
    <div class="mode-toggle-group">
        <button type="button" class="mode-toggle-btn active" id="btnModeSingle" onclick="switchMode('single')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Single Day</span>
        </button>
        <button type="button" class="mode-toggle-btn" id="btnModeRange" onclick="switchMode('range')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h8"/></svg>
            <span>Vacation Range</span>
        </button>
        <button type="button" class="mode-toggle-btn" id="btnModeCustom" onclick="switchMode('custom')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8.01" y2="14"/><line x1="12" y1="14" x2="12.01" y2="14"/><line x1="16" y1="14" x2="16.01" y2="14"/></svg>
            <span>Multiple Dates</span>
        </button>
    </div>

    <form method="post" id="holidayForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="declare_holiday">
        <input type="hidden" name="holiday_mode" id="holiday_mode" value="single">

        <div class="row" style="align-items: start; margin-bottom: 14px;">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="holiday_batch_select">Target Batch *</label>
                <select id="holiday_batch_select" name="batch_id" required>
                    <option value="all" selected>All Active Batches</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted" style="display: block; margin-top: 4px;">Choose "All Active Batches" for institute-wide festivals or vacations.</small>
            </div>

            <!-- Single Day Picker -->
            <div id="sectionSingleDate" class="form-group" style="margin-bottom: 0;">
                <label for="inputSingleDate">Holiday Date *</label>
                <input type="date" name="holiday_date" id="inputSingleDate" value="<?= date('Y-m-d') ?>">
            </div>

            <!-- Date Range Pickers -->
            <div id="sectionRangeDate" class="col-full" style="display: none;">
                <div class="row">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="inputStartDate">From Date *</label>
                        <input type="date" name="start_date" id="inputStartDate" value="<?= date('Y-m-d') ?>" onchange="calculateRangeDays()">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="inputEndDate">To Date *</label>
                        <input type="date" name="end_date" id="inputEndDate" value="<?= date('Y-m-d', strtotime('+4 days')) ?>" onchange="calculateRangeDays()">
                    </div>
                </div>

                <div style="margin-top: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <label class="checkbox-label" for="skip_sundays">
                        <input type="checkbox" name="skip_sundays" id="skip_sundays" value="1" checked onchange="calculateRangeDays()">
                        <span>Skip Sundays (exclude Sundays from holiday count)</span>
                    </label>
                    <div id="rangeDaysBadge" class="range-days-pill">
                        Count: 5 holiday day(s)
                    </div>
                </div>
            </div>

            <!-- Custom Multiple Dates -->
            <div id="sectionCustomDates" class="col-full" style="display: none;">
                <label>Select Multiple Dates</label>
                <div id="customDatesContainer">
                    <div class="custom-date-row">
                        <input type="date" name="custom_dates[]" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="custom-date-row">
                        <input type="date" name="custom_dates[]" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        <button type="button" class="btn btn-sm btn-danger-ghost" onclick="this.parentElement.remove()">Remove</button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addCustomDateRow()" style="margin-top: 4px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Another Date
                </button>
            </div>
        </div>

        <!-- Reason & Submit -->
        <div class="reason-submit-row">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="holiday_reason">Reason / Occasion</label>
                <input type="text" id="holiday_reason" name="reason" placeholder="e.g. Diwali Vacation, Uttarayan, Maintenance...">
            </div>
            <div>
                <button class="btn" type="submit">
                    Declare Holiday
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ==================== RECORDED HOLIDAYS HISTORY ==================== -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Recorded Holidays History</h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Deleting an entry reverts the +1 day course extension from affected students.</p>
        </div>
        <form method="get" style="display: flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <label for="filter_batch_id" style="margin: 0; white-space: nowrap;">Batch</label>
            <select id="filter_batch_id" name="batch_id" onchange="this.form.submit()" style="width: auto; min-width: 190px;">
                <option value="">All Batches</option>
                <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= (string)$batchFilter === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($batchFilter !== ''): ?>
                <a href="<?= url('public/holidays.php') ?>" class="btn btn-sm btn-secondary">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Holiday Date</th>
                    <th>Day</th>
                    <th>Batch</th>
                    <th>Reason</th>
                    <th>Affected Students</th>
                    <th>Recorded On</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$holidays): ?>
                    <tr>
                        <td colspan="7" class="muted" style="text-align: center; padding: 36px 20px;">
                            No holidays recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($holidays as $h):
                        $dateObj = new DateTime($h['holiday_date']);
                        $isFuture = $dateObj >= new DateTime('today');
                    ?>
                        <tr>
                            <td style="white-space: nowrap;">
                                <span style="font-weight: 600; color: var(--text);">
                                    <?= format_date($h['holiday_date']) ?>
                                </span>
                                <?php if ($isFuture): ?>
                                    <span class="badge badge-blue" style="margin-left: 6px;">Upcoming</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-gray"><?= $dateObj->format('l') ?></span>
                            </td>
                            <td style="font-weight: 500; color: var(--text);">
                                <?= e($h['batch_name']) ?>
                            </td>
                            <td style="color: var(--text-body);">
                                <?= e($h['reason'] ?: 'Institute Closed') ?>
                            </td>
                            <td>
                                <span class="badge badge-green">
                                    +1 Day to <?= (int)$h['affected_count'] ?> active student<?= (int)$h['affected_count'] === 1 ? '' : 's' ?>
                                </span>
                            </td>
                            <td style="color: var(--muted); font-size: 12.5px; white-space: nowrap;">
                                <?= format_date($h['created_at']) ?>
                                <?= !empty($h['creator_name']) ? 'by ' . e($h['creator_name']) : '' ?>
                            </td>
                            <td class="text-right" style="white-space: nowrap;">
                                <form method="post" style="display: inline; margin: 0;" onsubmit="return confirm('Delete this holiday? This will automatically deduct 1 day from affected students\' expiry dates.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_holiday">
                                    <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger-ghost" title="Delete Holiday">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($totalHolidayRows, $page, $perPage, $_GET) ?>
</div>

<script>
function switchMode(mode) {
    document.getElementById('holiday_mode').value = mode;

    document.getElementById('btnModeSingle').classList.toggle('active', mode === 'single');
    document.getElementById('btnModeRange').classList.toggle('active', mode === 'range');
    document.getElementById('btnModeCustom').classList.toggle('active', mode === 'custom');

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
        badge.innerText = 'Start date must be before end date';
        badge.style.color = '#991B1B';
        badge.style.background = '#FEF2F2';
        badge.style.borderColor = '#FECACA';
        return;
    }

    var count = 0;
    var cur = new Date(start);
    while (cur <= end) {
        if (!skipSundays || cur.getDay() !== 0) {
            count++;
        }
        cur.setDate(cur.getDate() + 1);
    }

    badge.innerText = 'Total: ' + count + ' holiday day' + (count === 1 ? '' : 's') + ' will be applied';
    badge.style.color = '';
    badge.style.background = '';
    badge.style.borderColor = '';
}

function addCustomDateRow() {
    var container = document.getElementById('customDatesContainer');
    var div = document.createElement('div');
    div.className = 'custom-date-row';
    div.innerHTML = '<input type="date" name="custom_dates[]" required>' +
                    '<button type="button" class="btn btn-sm btn-danger-ghost" onclick="this.parentElement.remove()">Remove</button>';
    container.appendChild(div);
}

calculateRangeDays();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
