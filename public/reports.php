<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/queue_engine.php';
require_login();

$pdo = db();
$report = $_GET['report'] ?? 'active_by_batch';
$batchId = (int)($_GET['batch_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$expiryFilter = $_GET['expiry_filter'] ?? '7_days';
$export = ($_GET['export'] ?? '') === 'csv';

// Fetch all batches for filtering
$batches = $pdo->query("SELECT * FROM batches ORDER BY start_time ASC")->fetchAll();
$batchMap = [];
foreach ($batches as $b) {
    $batchMap[$b['id']] = $b['name'];
}

$reportTitles = [
    'active_by_batch' => 'Active Students by Batch',
    'waiting_by_batch' => 'Waiting List by Batch',
    'next_student_by_batch' => 'Next Eligible Student per Batch',
    'expiring_students' => 'Course Expiry Alerts (Today / 7 / 30 Days)',
    'course_completion' => 'Course Completion Report',
    'extensions' => 'Course Extension & Billing Report',
    'holiday_adjustments' => 'Institute Holiday Adjustment Report',
    'payment_collections' => 'Payment Collections Ledger',
    'pending_payments' => 'Pending Second Payments (₹4,000 / 10-Day Rule)',
    'form_cancellations' => 'Form Cancellations & Non-Refundable Audit',
    'number_sequences' => 'Form No & Registration No Sequence Audit',
    'referrals_relatives' => 'Referral & Relative Network Report',
    'student_history' => 'Master Student History & Archive',
];

$title = $reportTitles[$report] ?? 'Reports';
$rows = [];
$columns = [];

// Handle CSV Export
function output_csv(string $filename, array $headers, array $dataRows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($dataRows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

// -----------------------------------------------------------------------------
// Report Data Fetching Logic
// -----------------------------------------------------------------------------

if ($report === 'active_by_batch') {
    $sql = "SELECT s.*, b.name AS batch_name
            FROM students s
            LEFT JOIN batches b ON b.id = s.joined_batch_id
            WHERE s.status = 'active' OR s.course_status IN ('active', 'extended')";
    $params = [];
    if ($batchId > 0) {
        $sql .= " AND s.joined_batch_id = ?";
        $params[] = $batchId;
    }
    $sql .= " ORDER BY b.start_time, s.entry_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $columns = ['Registration No', 'Student Name', 'Mobile', 'Batch', 'Entry Date', 'Original Expiry', 'Final Expiry', 'Days Done', 'Days Left', 'Status'];
    foreach ($students as $s) {
        $prog = get_course_progress($s);
        $rows[] = [
            $s['registration_no'] ?: '-',
            $s['name'],
            $s['student_mobile'],
            $s['batch_name'] ?: 'Not Assigned',
            format_date($s['entry_date']),
            format_date($s['base_expiry_date']),
            format_date($s['final_expiry_date']),
            $prog['days_completed'],
            $prog['days_remaining'],
            ucwords(str_replace('_', ' ', $s['status'])),
            'student_id' => $s['id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('active_students_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'waiting_by_batch') {
    $sql = "SELECT wq.*, s.name AS student_name, s.student_mobile, s.form_no, s.registration_no,
                   s.form_submit_date, s.expected_joining_date, b.name AS batch_name
            FROM waiting_queue wq
            JOIN students s ON s.id = wq.student_id
            JOIN batches b ON b.id = wq.batch_id
            WHERE wq.state NOT IN ('joined', 'removed')";
    $params = [];
    if ($batchId > 0) {
        $sql .= " AND wq.batch_id = ?";
        $params[] = $batchId;
    }
    $sql .= " ORDER BY b.start_time, wq.priority_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $queue = $stmt->fetchAll();

    $columns = ['Batch', 'Priority Date', 'Registration No', 'Form No', 'Student Name', 'Mobile', 'State', 'Expected Joining', 'Future Date'];
    foreach ($queue as $q) {
        $rows[] = [
            $q['batch_name'],
            date('d-M-Y H:i', strtotime($q['priority_date'])),
            $q['registration_no'] ?: '-',
            $q['form_no'],
            $q['student_name'],
            $q['student_mobile'],
            ucwords(str_replace('_', ' ', $q['state'])),
            format_date($q['expected_joining_date']),
            format_date($q['future_date']),
            'student_id' => $q['student_id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('waiting_list_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'next_student_by_batch') {
    $columns = ['Batch', 'Capacity', 'Active Occupancy', 'Available Seats', 'Waiting Count', 'Next Eligible Student', 'Contact', 'Priority Date'];
    foreach ($batches as $b) {
        $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE joined_batch_id = ? AND status IN ('active','extended')");
        $activeStmt->execute([$b['id']]);
        $activeCount = (int)$activeStmt->fetchColumn();
        $available = max(0, (int)$b['capacity'] - $activeCount);

        $waitStmt = $pdo->prepare("SELECT COUNT(*) FROM waiting_queue WHERE batch_id = ? AND state NOT IN ('joined','removed')");
        $waitStmt->execute([$b['id']]);
        $waitCount = (int)$waitStmt->fetchColumn();

        $next = get_next_student_for_batch((int)$b['id']);

        $rows[] = [
            $b['name'],
            $b['capacity'],
            $activeCount,
            $available,
            $waitCount,
            $next ? $next['name'] . ' (' . ($next['registration_no'] ?: $next['form_no']) . ')' : 'No eligible candidate',
            $next ? $next['student_mobile'] : '-',
            $next ? date('d-M-Y H:i', strtotime($next['priority_date'])) : '-',
            'student_id' => $next ? $next['id'] : null
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('next_students_by_batch_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'expiring_students') {
    $today = date('Y-m-d');
    $sql = "SELECT s.*, b.name AS batch_name
            FROM students s
            LEFT JOIN batches b ON b.id = s.joined_batch_id
            WHERE s.status IN ('active','extended') AND s.final_expiry_date IS NOT NULL";
    
    if ($expiryFilter === 'today') {
        $sql .= " AND s.final_expiry_date = '$today'";
    } elseif ($expiryFilter === '7_days') {
        $limit7 = date('Y-m-d', strtotime('+7 days'));
        $sql .= " AND s.final_expiry_date BETWEEN '$today' AND '$limit7'";
    } elseif ($expiryFilter === '30_days') {
        $limit30 = date('Y-m-d', strtotime('+30 days'));
        $sql .= " AND s.final_expiry_date BETWEEN '$today' AND '$limit30'";
    } elseif ($from !== '' && $to !== '') {
        $sql .= " AND s.final_expiry_date BETWEEN '$from' AND '$to'";
    }
    $sql .= " ORDER BY s.final_expiry_date ASC";
    $expiring = $pdo->query($sql)->fetchAll();

    $columns = ['Registration No', 'Student Name', 'Mobile', 'Batch', 'Entry Date', 'Expiry Date', 'Days Left', 'Course Status'];
    foreach ($expiring as $s) {
        $daysLeft = (int)(new DateTime($today))->diff(new DateTime($s['final_expiry_date']))->format('%r%a');
        $rows[] = [
            $s['registration_no'] ?: '-',
            $s['name'],
            $s['student_mobile'],
            $s['batch_name'] ?: 'General',
            format_date($s['entry_date']),
            format_date($s['final_expiry_date']),
            $daysLeft >= 0 ? "$daysLeft days left" : abs($daysLeft) . " days overdue",
            ucwords(str_replace('_', ' ', $s['status'])),
            'student_id' => $s['id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('expiring_students_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'course_completion') {
    $sql = "SELECT s.*, b.name AS batch_name
            FROM students s
            LEFT JOIN batches b ON b.id = s.joined_batch_id
            WHERE s.status = 'course_completed' OR s.course_status = 'completed'";
    if ($from !== '') { $sql .= " AND s.final_expiry_date >= '$from'"; }
    if ($to !== '') { $sql .= " AND s.final_expiry_date <= '$to'"; }
    $sql .= " ORDER BY s.final_expiry_date DESC";
    $completed = $pdo->query($sql)->fetchAll();

    $columns = ['Registration No', 'Student Name', 'Mobile', 'Batch', 'Entry Date', 'Completion Date', 'Status'];
    foreach ($completed as $s) {
        $rows[] = [
            $s['registration_no'] ?: '-',
            $s['name'],
            $s['student_mobile'],
            $s['batch_name'] ?: 'General',
            format_date($s['entry_date']),
            format_date($s['final_expiry_date']),
            'Completed',
            'student_id' => $s['id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('completed_students_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'extensions') {
    $sql = "SELECT ext.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile,
                   u.name AS approver_name, inv.invoice_no, inv.payment_status, inv.id AS invoice_id
            FROM extensions ext
            JOIN students s ON s.id = ext.student_id
            LEFT JOIN users u ON u.id = ext.approved_by
            LEFT JOIN invoices inv ON inv.extension_id = ext.id
            WHERE 1=1";
    if ($from !== '') { $sql .= " AND ext.created_at >= '$from 00:00:00'"; }
    if ($to !== '') { $sql .= " AND ext.created_at <= '$to 23:59:59'"; }
    $sql .= " ORDER BY ext.created_at DESC";
    $exts = $pdo->query($sql)->fetchAll();

    $columns = ['Date', 'Invoice No', 'Student Name', 'Reg No', 'Added Days', 'Old Expiry', 'New Expiry', 'Calculated (₹)', 'Final Amount (₹)', 'Override?', 'Status', 'Approved By'];
    foreach ($exts as $e) {
        $rows[] = [
            date('d-M-Y', strtotime($e['created_at'])),
            $e['invoice_no'] ?: '-',
            $e['student_name'],
            $e['registration_no'] ?: '-',
            '+' . $e['extension_days'] . ' days',
            format_date($e['old_expiry_date']),
            format_date($e['new_expiry_date']),
            '₹' . number_format($e['calculated_amount'], 2),
            '₹' . number_format($e['final_amount'], 2),
            $e['is_manual_override'] ? 'Yes (' . $e['override_reason'] . ')' : 'No',
            ucwords($e['payment_status'] ?? 'pending'),
            $e['approver_name'] ?? 'Admin',
            'student_id' => $e['student_id'],
            'invoice_id' => $e['invoice_id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); array_pop($r); return $r; }, $rows);
        output_csv('extensions_report_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'holiday_adjustments') {
    $sql = "SELECT h.*, b.name AS batch_name, u.name AS creator_name,
                   (SELECT COUNT(*) FROM course_adjustments ca WHERE ca.holiday_id = h.id) AS affected_count
            FROM holidays h
            JOIN batches b ON b.id = h.batch_id
            LEFT JOIN users u ON u.id = h.created_by
            WHERE 1=1";
    if ($from !== '') { $sql .= " AND h.holiday_date >= '$from'"; }
    if ($to !== '') { $sql .= " AND h.holiday_date <= '$to'"; }
    if ($batchId > 0) { $sql .= " AND h.batch_id = $batchId"; }
    $sql .= " ORDER BY h.holiday_date DESC";
    $holidays = $pdo->query($sql)->fetchAll();

    $columns = ['Holiday Date', 'Batch', 'Reason', 'Affected Students (+1 Day)', 'Recorded By', 'Recorded Date'];
    foreach ($holidays as $h) {
        $rows[] = [
            format_date($h['holiday_date']),
            $h['batch_name'],
            $h['reason'] ?: 'Institute Closed',
            $h['affected_count'] . ' active students',
            $h['creator_name'] ?? 'Admin',
            date('d-M-Y', strtotime($h['created_at']))
        ];
    }
    if ($export) {
        output_csv('holiday_adjustments_' . date('Ymd'), $columns, $rows);
    }

} elseif ($report === 'payment_collections') {
    $stage = $_GET['stage'] ?? '';
    $method = $_GET['method'] ?? '';
    $sql = "SELECT p.*, s.name AS student_name, s.registration_no, s.form_no, u.name AS collector_name
            FROM payments p
            JOIN students s ON s.id = p.student_id
            LEFT JOIN users u ON u.id = p.collected_by
            WHERE p.status = 'success'";
    $params = [];
    if ($stage !== '') { $sql .= " AND p.stage = ?"; $params[] = $stage; }
    if ($method !== '') { $sql .= " AND p.method = ?"; $params[] = $method; }
    if ($from !== '') { $sql .= " AND p.payment_date >= ?"; $params[] = $from . ' 00:00:00'; }
    if ($to !== '') { $sql .= " AND p.payment_date <= ?"; $params[] = $to . ' 23:59:59'; }
    $sql .= " ORDER BY p.payment_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();

    $columns = ['Date', 'Receipt No', 'Student Name', 'Reg No', 'Stage', 'Amount (₹)', 'Method', 'Reference No', 'Collector'];
    $totalAmount = 0.0;
    foreach ($payments as $p) {
        $totalAmount += (float)$p['amount'];
        $rows[] = [
            date('d-M-Y H:i', strtotime($p['payment_date'])),
            $p['receipt_no'],
            $p['student_name'],
            $p['registration_no'] ?: '-',
            ucwords(str_replace('_', ' ', $p['stage'])),
            '₹' . number_format($p['amount'], 2),
            strtoupper($p['method']),
            $p['reference_no'] ?: '-',
            $p['collector_name'] ?? 'Admin',
            'payment_id' => $p['id'],
            'student_id' => $p['student_id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); array_pop($r); return $r; }, $rows);
        output_csv('payment_collections_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'pending_payments') {
    // Active students whose second payment (₹4,000) has not been recorded
    $secondFee = (float)get_setting('fee_second_payment', '4000');
    $dueDays = (int)get_setting('due_days_second_payment', '10');

    $sql = "SELECT s.*, b.name AS batch_name,
                   (SELECT SUM(amount) FROM payments WHERE student_id = s.id AND stage = 'second_payment' AND status = 'success') AS second_paid,
                   (SELECT SUM(amount) FROM payments WHERE student_id = s.id AND status = 'success') AS total_paid
            FROM students s
            LEFT JOIN batches b ON b.id = s.joined_batch_id
            WHERE s.status IN ('active','extended') AND s.entry_date IS NOT NULL
            HAVING second_paid IS NULL OR second_paid < $secondFee
            ORDER BY s.entry_date ASC";
    $pendingList = $pdo->query($sql)->fetchAll();

    $columns = ['Registration No', 'Student Name', 'Mobile', 'Batch', 'Entry Date', '10-Day Due Date', 'Total Paid', 'Pending Second Fee', 'Overdue Status'];
    $today = new DateTime();
    foreach ($pendingList as $s) {
        $entryDate = new DateTime($s['entry_date']);
        $dueDate = (clone $entryDate)->modify("+{$dueDays} days");
        $isOverdue = $today > $dueDate;
        $diff = $today->diff($dueDate);

        $statusText = $isOverdue ? "OVERDUE by " . $diff->days . " days" : "Due in " . $diff->days . " days";
        $rows[] = [
            $s['registration_no'] ?: '-',
            $s['name'],
            $s['student_mobile'],
            $s['batch_name'] ?: 'General',
            format_date($s['entry_date']),
            $dueDate->format('d-M-Y'),
            '₹' . number_format((float)$s['total_paid'], 2),
            '₹' . number_format($secondFee - (float)$s['second_paid'], 2),
            $statusText,
            'student_id' => $s['id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('pending_payments_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'form_cancellations') {
    $sql = "SELECT s.*, b.name AS batch_name,
                   (SELECT SUM(amount) FROM payments WHERE student_id = s.id AND stage = 'form_fee' AND status = 'success') AS form_fee_paid
            FROM students s
            LEFT JOIN batches b ON b.id = s.preferred_batch_id OR b.id = s.joined_batch_id
            WHERE s.status = 'cancelled' OR s.form_status = 'cancelled'
            ORDER BY s.updated_at DESC";
    $cancelled = $pdo->query($sql)->fetchAll();

    $columns = ['Form No', 'Reg No', 'Student Name', 'Mobile', 'Form Submit Date', 'Cancellation Date', 'Non-Refundable Fee Kept'];
    foreach ($cancelled as $s) {
        $rows[] = [
            $s['form_no'],
            $s['registration_no'] ?: '-',
            $s['name'],
            $s['student_mobile'],
            date('d-M-Y', strtotime($s['form_submit_date'])),
            date('d-M-Y', strtotime($s['updated_at'])),
            '₹' . number_format((float)($s['form_fee_paid'] ?? 500), 2) . ' (Non-refundable)',
            'student_id' => $s['id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('form_cancellations_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'number_sequences') {
    $sql = "SELECT id, form_no, registration_no, name, student_mobile, form_status, status, created_at, confirmed_at
            FROM students
            ORDER BY id ASC";
    $seq = $pdo->query($sql)->fetchAll();

    $columns = ['ID', 'Form No', 'Registration No', 'Student Name', 'Mobile', 'Form Status', 'Overall Status', 'Created Date', 'Confirmed Date'];
    foreach ($seq as $s) {
        $rows[] = [
            $s['id'],
            $s['form_no'],
            $s['registration_no'] ?: '[Not Confirmed / Unpaid]',
            $s['name'],
            $s['student_mobile'],
            ucfirst($s['form_status']),
            ucwords(str_replace('_', ' ', $s['status'])),
            date('d-M-Y H:i', strtotime($s['created_at'])),
            $s['confirmed_at'] ? date('d-M-Y H:i', strtotime($s['confirmed_at'])) : '-',
            'student_id' => $s['id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('number_sequences_audit_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'referrals_relatives') {
    $sql = "SELECT r.*, s.name AS student_name, s.student_mobile, s.registration_no, s.form_no,
                   rel_s.name AS linked_student_name, rel_s.registration_no AS linked_reg_no
            FROM referrals r
            JOIN students s ON s.id = r.student_id
            LEFT JOIN students rel_s ON rel_s.id = r.relative_student_id
            ORDER BY r.created_at DESC";
    $refs = $pdo->query($sql)->fetchAll();

    $columns = ['Student Name', 'Reg No', 'Relation', 'Relative Name', 'Relative Contact', 'Historical Linked Student', 'Relative Address'];
    foreach ($refs as $r) {
        $rows[] = [
            $r['student_name'],
            $r['registration_no'] ?: $r['form_no'],
            $r['relation'] ?: 'Relative',
            $r['relative_name'] ?: ($r['linked_student_name'] ?? '-'),
            $r['relative_mobile'] ?: '-',
            $r['linked_student_name'] ? $r['linked_student_name'] . ' (' . $r['linked_reg_no'] . ')' : 'Direct Entry',
            $r['relative_address'] ?: '-',
            'student_id' => $r['student_id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('referrals_report_' . date('Ymd'), $columns, $csvData);
    }

} elseif ($report === 'student_history') {
    $search = trim($_GET['q'] ?? '');
    $sql = "SELECT s.*, b.name AS batch_name FROM students s LEFT JOIN batches b ON b.id = s.joined_batch_id OR b.id = s.preferred_batch_id WHERE 1=1";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (s.name LIKE ? OR s.student_mobile LIKE ? OR s.registration_no LIKE ? OR s.form_no LIKE ?)";
        $term = "%$search%";
        $params = [$term, $term, $term, $term];
    }
    $sql .= " ORDER BY s.id DESC LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allStudents = $stmt->fetchAll();

    $columns = ['Form No', 'Reg No', 'Student Name', 'Mobile', 'Batch', 'Entry Date', 'Expiry Date', 'Status', 'Imported?'];
    foreach ($allStudents as $s) {
        $rows[] = [
            $s['form_no'],
            $s['registration_no'] ?: '-',
            $s['name'],
            $s['student_mobile'],
            $s['batch_name'] ?: 'Not Assigned',
            format_date($s['entry_date']),
            format_date($s['final_expiry_date']),
            ucwords(str_replace('_', ' ', $s['status'])),
            $s['is_imported'] ? 'Yes (Historical)' : 'No',
            'student_id' => $s['id']
        ];
    }
    if ($export) {
        $csvData = array_map(function($r){ array_pop($r); return $r; }, $rows);
        output_csv('master_students_history_' . date('Ymd'), $columns, $csvData);
    }
}

$pageTitle = $reportTitles[$report] ?? 'Reports';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 class="mt-0" style="margin-bottom:4px;"><?= e($pageTitle) ?></h2>
            <div class="muted">Operational & Financial Intelligence for Devangi Sewing Classes</div>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Export CSV
            </a>
            <button onclick="window.print()" class="btn" style="display:inline-flex; align-items:center; gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>Print Report
            </button>
        </div>
    </div>

    <!-- Sub-report Selector Tabs -->
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; border-top:1px solid #e2e8f0; padding-top:14px;">
        <?php foreach ($reportTitles as $k => $label): ?>
            <a href="?report=<?= $k ?>" class="btn <?= $report === $k ? '' : 'btn-secondary' ?>" style="font-size:12px; padding:6px 12px;">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="margin-bottom: 20px;">
    <form method="get" class="filter-form">
        <input type="hidden" name="report" value="<?= e($report) ?>">

        <?php if (in_array($report, ['active_by_batch', 'waiting_by_batch', 'holiday_adjustments'])): ?>
        <div>
            <label style="margin-top:0;">Batch</label>
            <select name="batch_id">
                <option value="0">All Batches</option>
                <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $batchId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($report === 'expiring_students'): ?>
        <div>
            <label style="margin-top:0;">Expiry Window</label>
            <select name="expiry_filter">
                <option value="today" <?= $expiryFilter === 'today' ? 'selected' : '' ?>>Expiring Today</option>
                <option value="7_days" <?= $expiryFilter === '7_days' ? 'selected' : '' ?>>Next 7 Days</option>
                <option value="30_days" <?= $expiryFilter === '30_days' ? 'selected' : '' ?>>Next 30 Days</option>
                <option value="custom" <?= $expiryFilter === 'custom' ? 'selected' : '' ?>>Custom Date Range</option>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($report === 'payment_collections'): ?>
        <div>
            <label style="margin-top:0;">Stage</label>
            <select name="stage">
                <option value="">All Stages</option>
                <option value="form_fee" <?= ($_GET['stage'] ?? '') === 'form_fee' ? 'selected' : '' ?>>Form Fee (₹500)</option>
                <option value="joining_fee" <?= ($_GET['stage'] ?? '') === 'joining_fee' ? 'selected' : '' ?>>Joining Fee (₹3,500)</option>
                <option value="second_payment" <?= ($_GET['stage'] ?? '') === 'second_payment' ? 'selected' : '' ?>>Second Payment (₹4,000)</option>
                <option value="extension_fee" <?= ($_GET['stage'] ?? '') === 'extension_fee' ? 'selected' : '' ?>>Extension Fee</option>
            </select>
        </div>
        <div>
            <label style="margin-top:0;">Payment Method</label>
            <select name="method">
                <option value="">All Methods</option>
                <option value="cash" <?= ($_GET['method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="upi" <?= ($_GET['method'] ?? '') === 'upi' ? 'selected' : '' ?>>UPI</option>
                <option value="bank_transfer" <?= ($_GET['method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
            </select>
        </div>
        <?php endif; ?>

        <?php if (in_array($report, ['payment_collections', 'extensions', 'holiday_adjustments', 'course_completion', 'expiring_students'])): ?>
        <div>
            <label style="margin-top:0;">From Date</label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label style="margin-top:0;">To Date</label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>
        <?php endif; ?>

        <?php if ($report === 'student_history'): ?>
        <div>
            <label style="margin-top:0;">Search Keyword</label>
            <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Name, Mobile, Reg or Form No...">
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button class="btn" type="submit">Apply Filter</button>
            <a href="?report=<?= e($report) ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Report Results -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 class="mt-0" style="margin-bottom:0;">
            Report Records: <span class="badge badge-purple"><?= count($rows) ?> entries</span>
            <?php if ($report === 'payment_collections' && isset($totalAmount)): ?>
                &nbsp;|&nbsp; Total Revenue: <span class="badge badge-green" style="font-size:14px;">₹<?= number_format($totalAmount, 2) ?></span>
            <?php endif; ?>
        </h3>
    </div>

    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $c): ?>
                        <th><?= e($c) ?></th>
                    <?php endforeach; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <?php 
                    $studentId = $row['student_id'] ?? null;
                    $paymentId = $row['payment_id'] ?? null;
                    $invoiceId = $row['invoice_id'] ?? null;
                    $cellCount = count($columns);
                    for ($idx = 0; $idx < $cellCount; $idx++): 
                    ?>
                        <td><?= e($row[$idx] ?? '-') ?></td>
                    <?php endfor; ?>
                    <td>
                        <?php if ($studentId): ?>
                            <a href="/student-management/public/student_profile.php?id=<?= $studentId ?>" class="btn-link" style="font-size:12px;">Profile</a>
                        <?php endif; ?>
                        <?php if ($paymentId): ?>
                            &middot; <a href="/student-management/public/receipt.php?id=<?= $paymentId ?>" class="btn-link" style="font-size:12px;" target="_blank">Receipt</a>
                        <?php endif; ?>
                        <?php if ($invoiceId): ?>
                            &middot; <a href="/student-management/public/invoice.php?id=<?= $invoiceId ?>" class="btn-link" style="font-size:12px;" target="_blank">Invoice</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (!$rows): ?>
                <tr>
                    <td colspan="<?= count($columns) + 1 ?>" class="muted" style="text-align:center; padding:24px;">
                        No records match the selected filter criteria.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
