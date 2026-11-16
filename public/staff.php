<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/StaffService.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';

// Only Admin or Super Admin can manage staff
require_admin();

$user = current_user();
$error = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_staff') {
            $name = trim($_POST['name'] ?? '');
            StaffService::createStaff($_POST, (int)$user['id']);
            flash('success', "સ્ટાફ સભ્ય '{$name}' સફળતાપૂર્વક ઉમેરાઈ ગયા છે.");
            redirect('public/staff.php');

        } elseif ($action === 'edit_staff') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            StaffService::updateStaff($id, $_POST, (int)$user['id']);
            flash('success', "સ્ટાફ સભ્ય '{$name}' ની માહિતી સફળતાપૂર્વક અપડેટ થઈ ગઈ છે.");
            redirect('public/staff.php');

        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            StaffService::toggleStatus($id, (int)$user['id']);
            flash('success', "સ્ટાફ સ્ટેટસ સફળતાપૂર્વક બદલવામાં આવ્યું.");
            redirect('public/staff.php');

        } elseif ($action === 'delete_staff') {
            $id = (int)($_POST['id'] ?? 0);
            StaffService::deleteStaff($id, (int)$user['id']);
            flash('success', "સ્ટાફ રેકોર્ડ સફળતાપૂર્વક ડિલીટ કરવામાં આવ્યો.");
            redirect('public/staff.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Filters
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['active', 'inactive', 'all'], true)) {
    $statusFilter = 'all';
}
$batchFilter = isset($_GET['batch_id']) && is_numeric($_GET['batch_id']) ? (int)$_GET['batch_id'] : null;
$searchTerm = trim($_GET['search'] ?? '');

$filterStatus = ($statusFilter === 'all') ? null : $statusFilter;
$staffList = StaffService::getAllStaff($filterStatus, $batchFilter, $searchTerm);
$allBatches = BatchService::getAllBatches(false);
$kpis = StaffService::getKPIs();

// Excel/CSV Export
if (($_GET['export'] ?? '') === 'excel') {
    $headers = ['ID', 'Staff Name', 'Designation', 'Specialization', 'Phone', 'Email', 'Assigned Batches', 'Joining Date', 'Salary', 'Salary Type', 'Status', 'Has Login'];
    $rows = [];
    foreach ($staffList as $st) {
        $rows[] = [
            $st['id'],
            $st['name'],
            $st['designation'],
            $st['specialization'] ?: '-',
            $st['phone'],
            $st['email'] ?: '-',
            $st['batch_names_str'],
            $st['joining_date'] ? date('d-m-Y', strtotime($st['joining_date'])) : '-',
            $st['salary'] > 0 ? 'Rs. ' . number_format($st['salary'], 2) : '0.00',
            ucfirst($st['salary_type']),
            ucfirst($st['status']),
            !empty($st['user_id']) ? 'Yes (' . $st['user_email'] . ')' : 'No'
        ];
    }
    ExportService::downloadCsv('Staff_List_' . date('Y-m-d') . '.csv', $headers, $rows);
}

$pageTitle = 'Staff Management';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
/* Custom Staff Management Styles */

/* Staff Subnav */
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

.staff-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 900px) {
    .staff-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 500px) {
    .staff-kpi-grid { grid-template-columns: 1fr; }
}

.staff-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.staff-kpi-val {
    font-size: 26px;
    font-weight: 800;
    color: #111827;
    line-height: 1.1;
    margin-bottom: 6px;
}
.staff-kpi-lbl {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
}

/* Staff Avatar Badge */
.staff-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #e0e7ff;
    color: #4338ca;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    text-transform: uppercase;
    flex-shrink: 0;
}
.staff-name-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Batch Pill */
.batch-mini-pill {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
    margin: 2px 2px 2px 0;
    white-space: nowrap;
}

/* Modal Active State */
.custom-modal-overlay.active {
    display: flex !important;
}
</style>

<?php if ($error): ?>
    <div class="flash flash-error" style="margin-bottom: 20px;">
        <?= e($error) ?>
    </div>
<?php endif; ?>


<!-- Sub Navigation Tabs for Staff Module -->
<div class="staff-subnav">
    <a href="<?= url('public/staff.php') ?>" class="staff-subnav-item active">
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
    <a href="<?= url('public/staff_payroll.php') ?>" class="staff-subnav-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        Payroll & Salary (પગાર ગણતરી & સ્લિપ)
    </a>
</div>

<!-- Top KPI Summary Cards -->
<div class="staff-kpi-grid">
    <div class="staff-kpi-card">
        <div class="staff-kpi-val"><?= $kpis['total_staff'] ?></div>
        <div class="staff-kpi-lbl">Total Staff (કુલ સ્ટાફ)</div>
    </div>
    <div class="staff-kpi-card">
        <div class="staff-kpi-val" style="color: #16a34a;"><?= $kpis['active_staff'] ?></div>
        <div class="staff-kpi-lbl">Active Teachers / Staff (સક્રિય સ્ટાફ)</div>
    </div>
    <div class="staff-kpi-card">
        <div class="staff-kpi-val" style="color: #4f46e5;"><?= $kpis['assigned_batches'] ?></div>
        <div class="staff-kpi-lbl">Batches Handled (કવર થયેલ બેચ)</div>
    </div>
    <div class="staff-kpi-card">
        <div class="staff-kpi-val" style="color: #0f172a;">₹<?= number_format($kpis['monthly_payroll']) ?></div>
        <div class="staff-kpi-lbl">Monthly Payroll (અંદાજિત માસિક પગાર)</div>
    </div>
</div>

<!-- Main Table Card with Toolbar -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px;">
        <div>
            <h2 style="margin: 0 0 4px 0; font-size: 18px; font-weight: 700;">Staff Members List (સ્ટાફ લિસ્ટ)</h2>
            <div class="muted" style="font-size: 13px;">શિક્ષિકાઓ, આસિસ્ટન્ટ્સ અને સ્ટાફની માહિતી તથા બેચ એસાઈનમેન્ટ સંચાલન.</div>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php
            $exportParams = $_GET;
            $exportParams['export'] = 'excel';
            $exportUrl = '?' . http_build_query($exportParams);
            ?>
            <a href="<?= e($exportUrl) ?>" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </a>
            <button type="button" class="btn" onclick="openAddModal()" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Add Staff (નવો સ્ટાફ ઉમેરો)
            </button>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <form method="get" action="" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; background: #f8fafc; padding: 12px 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <div style="flex: 1; min-width: 200px;">
            <input type="text" name="search" value="<?= e($searchTerm) ?>" placeholder="શોધો: નામ, મોબાઇલ, ઈમેલ અથવા હોદ્દો..." style="width: 100%; margin: 0;">
        </div>
        <div style="min-width: 150px;">
            <select name="status" style="width: 100%; margin: 0;" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>બધા સ્ટેટસ (All)</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>સક્રિય (Active)</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>નિષ્ક્રિય (Inactive)</option>
            </select>
        </div>
        <div style="min-width: 180px;">
            <select name="batch_id" style="width: 100%; margin: 0;" onchange="this.form.submit()">
                <option value="">તમામ બેચ (All Batches)</option>
                <?php foreach ($allBatches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $batchFilter === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= e($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary" style="padding: 7px 14px;">ફિલ્ટર કરો</button>
        <?php if ($searchTerm !== '' || $statusFilter !== 'all' || $batchFilter !== null): ?>
            <a href="<?= url('public/staff.php') ?>" class="btn-link" style="font-size: 13px;">રીસેટ કરો</a>
        <?php endif; ?>
    </form>

    <!-- Staff Table -->
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Staff Name & Role</th>
                    <th>Contact (સંપર્ક)</th>
                    <th>Assigned Batches (ફાળવેલ બેચ)</th>
                    <th>Joining Date</th>
                    <th>Salary</th>
                    <th>Login Access</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px 20px; color: #64748b;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 8px; opacity: 0.5;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="18" y1="8" x2="23" y2="13"/><line x1="23" y1="8" x2="18" y2="13"/></svg>
                            <div style="font-size: 15px; font-weight: 600;">કોઈ સ્ટાફ સભ્ય મળ્યા નથી</div>
                            <div style="font-size: 13px; margin-top: 4px;">ઉપર આપેલ બટન પર ક્લિક કરીને નવો સ્ટાફ ઉમેરો.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($staffList as $st): ?>
                        <?php
                        $initials = '';
                        $parts = explode(' ', trim($st['name']));
                        foreach ($parts as $p) {
                            if (!empty($p)) $initials .= mb_substr($p, 0, 1);
                            if (mb_strlen($initials) >= 2) break;
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="staff-name-wrap">
                                    <div class="staff-avatar"><?= e(strtoupper($initials ?: 'S')) ?></div>
                                    <div>
                                        <div style="font-weight: 700; color: #1e293b; font-size: 14.5px;">
                                            <?= e($st['name']) ?>
                                        </div>
                                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                            <span style="font-weight: 600; color: #4338ca;"><?= e($st['designation']) ?></span>
                                            <?php if (!empty($st['specialization'])): ?>
                                                • <span title="Specialization"><?= e($st['specialization']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 13px;">
                                    <a href="tel:<?= e($st['phone']) ?>" style="font-weight: 600; color: #0f172a; text-decoration: none;">
                                        📞 <?= e($st['phone']) ?>
                                    </a>
                                </div>
                                <?php if (!empty($st['email'])): ?>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                        <a href="mailto:<?= e($st['email']) ?>" style="color: #64748b; text-decoration: none;">
                                            ✉️ <?= e($st['email']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($st['batches'])): ?>
                                    <div style="display: flex; flex-wrap: wrap; max-width: 280px;">
                                        <?php foreach ($st['batches'] as $sb): ?>
                                            <span class="batch-mini-pill" title="<?= e($sb['batch_days']) ?>">
                                                <?= e($sb['batch_name']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 12.5px;">કોઈ બેચ ફાળવેલ નથી</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size: 13px; font-weight: 500;">
                                    <?= $st['joining_date'] ? date('d M Y', strtotime($st['joining_date'])) : '-' ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($st['salary'] > 0): ?>
                                    <div style="font-weight: 700; color: #0f172a; font-size: 13.5px;">
                                        ₹<?= number_format($st['salary']) ?>
                                    </div>
                                    <div style="font-size: 11px; color: #64748b;">
                                        / <?= e($st['salary_type']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 13px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($st['user_id'])): ?>
                                    <span class="badge badge-purple" style="font-size: 11.5px; font-weight: 600;" title="User Email: <?= e($st['user_email']) ?>">
                                        Login Active
                                    </span>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 12px;">No Login</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($st['status'] === 'active'): ?>
                                    <span class="badge badge-green">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-gray">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 5px; align-items: center;">
                                    <!-- View Profile -->
                                    <button type="button" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 8px;" onclick='openViewModal(<?= htmlspecialchars(json_encode($st), ENT_QUOTES, 'UTF-8') ?>)' title="વિગતવાર જુઓ (View Profile)">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </button>

                                    <!-- Edit Staff -->
                                    <button type="button" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 9px;" onclick='openEditModal(<?= htmlspecialchars(json_encode($st), ENT_QUOTES, 'UTF-8') ?>)' title="સુધારો કરો (Edit)">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:3px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit
                                    </button>

                                    <!-- Toggle Status Form -->
                                    <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('શું તમે આ સ્ટાફનું સ્ટેટસ બદલવા માંગો છો?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 8px;" title="<?= $st['status'] === 'active' ? 'Deactivate Staff' : 'Activate Staff' ?>">
                                            <?php if ($st['status'] === 'active'): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
                                            <?php else: ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                            <?php endif; ?>
                                        </button>
                                    </form>

                                    <!-- Delete Staff Form -->
                                    <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('શું તમે ખરેખર આ સ્ટાફ સભ્યને ડિલીટ કરવા માંગો છો? આ ક્રિયા પાછી નહીં ખેંચી શકાય.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_staff">
                                        <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 8px; color: #dc2626;" title="Delete Staff">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== ADD STAFF MODAL ==================== -->
<div class="custom-modal-overlay" id="addStaffModal">
    <div class="custom-modal" style="max-width: 650px;">
        <div class="custom-modal-header">
            <h3>Add New Staff (નવો સ્ટાફ ઉમેરો)</h3>
            <button type="button" class="custom-modal-close" onclick="closeAddModal()">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_staff">

            <div class="custom-modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Staff Name (સ્ટાફનું પૂરું નામ) *</label>
                        <input type="text" name="name" required placeholder="દા.ત. મીનાબેન પટેલ" style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Mobile Number (મોબાઇલ નંબર) *</label>
                        <input type="tel" name="phone" required placeholder="દા.ત. 9876543210" style="width: 100%;">
                    </div>
                </div>

                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Designation (હોદ્દો / રોલ) *</label>
                        <select name="designation" style="width: 100%;">
                            <option value="મુખ્ય શિક્ષિકા (Head Teacher)">મુખ્ય શિક્ષિકા (Head Teacher)</option>
                            <option value="સિલાઈ શિક્ષિકા (Sewing Teacher)" selected>સિલાઈ શિક્ષિકા (Sewing Teacher)</option>
                            <option value="આસિસ્ટન્ટ શિક્ષિકા (Assistant Teacher)">આસિસ્ટન્ટ શિક્ષિકા (Assistant Teacher)</option>
                            <option value="કટીંગ માસ્ટર (Cutting Specialist)">કટીંગ માસ્ટર (Cutting Specialist)</option>
                            <option value="ઓફિસ / વહીવટી સ્ટાફ (Admin Staff)">ઓફિસ / વહીવટી સ્ટાફ (Admin Staff)</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Specialization (વિશેષતા / કુશળતા)</label>
                        <input type="text" name="specialization" placeholder="દા.ત. ડિઝાઇનર બ્લાઉઝ, કુર્તી, મશીન વર્ક" style="width: 100%;">
                    </div>
                </div>

                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Joining Date (જોડાવાની તારીખ)</label>
                        <input type="date" name="joining_date" value="<?= date('Y-m-d') ?>" style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Status (સ્થિતિ)</label>
                        <select name="status" style="width: 100%;">
                            <option value="active">Active (સક્રિય)</option>
                            <option value="inactive">Inactive (નિષ્ક્રિય)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Salary Amount (પગાર)</label>
                        <input type="number" step="0.01" name="salary" placeholder="દા.ત. 12000" style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Salary Type (પગારનો પ્રકાર)</label>
                        <select name="salary_type" style="width: 100%;">
                            <option value="monthly">માસિક (Monthly)</option>
                            <option value="per_day">પ્રતિ દિવસ (Per Day)</option>
                            <option value="per_hour">પ્રતિ કલાક (Per Hour)</option>
                        </select>
                    </div>
                </div>

                <!-- Assigned Batches -->
                <div style="margin-bottom: 14px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <label style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 8px;">
                        Assign Batches (કઈ બેચ સાચવે છે?)
                    </label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;">
                        <?php foreach ($allBatches as $b): ?>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; margin: 0; background: #fff; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                <input type="checkbox" name="batch_ids[]" value="<?= $b['id'] ?>" style="width: auto; margin: 0;">
                                <span><?= e($b['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Address & Emergency Contact -->
                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Emergency Contact (કટોકટી સંપર્ક)</label>
                        <input type="tel" name="emergency_contact" placeholder="સગા-સંબંધીનો નંબર" style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Address (સરનામું)</label>
                        <input type="text" name="address" placeholder="રહેઠાણનું સરનામું" style="width: 100%;">
                    </div>
                </div>

                <!-- Notes -->
                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Notes (ખાસ નોંધ)</label>
                    <textarea name="notes" rows="2" placeholder="કોઈ અન્ય વિગત કે અનુભવ વિશે નોંધ..." style="width: 100%;"></textarea>
                </div>

                <!-- Optional System Login Box -->
                <div style="background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; padding: 14px; margin-top: 10px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <input type="checkbox" name="create_login" id="add_create_login" value="1" style="width: auto; margin:0;" onchange="toggleAddLoginFields(this.checked)">
                        <label for="add_create_login" style="font-weight: 700; font-size: 13.5px; color: #4338ca; cursor: pointer; margin:0;">
                            Create System Login Access (સ્ટાફને સોફ્ટવેર લોગિન આપવું છે?)
                        </label>
                    </div>
                    <div id="add_login_fields" style="display: none; margin-top: 10px;">
                        <div class="grid grid-2">
                            <div>
                                <label style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">Login Email (ઈમેલ) *</label>
                                <input type="email" name="email" id="add_email" placeholder="staff@example.com" style="width: 100%;">
                            </div>
                            <div>
                                <label style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">Password (પાસવર્ડ) *</label>
                                <input type="password" name="password" id="add_password" placeholder="ઓછામાં ઓછા 6 અક્ષર" minlength="6" style="width: 100%;">
                            </div>
                        </div>
                        <small style="color: #64748b; font-size: 11.5px; display: block; margin-top: 4px;">
                            આ સ્ટાફ સભ્ય Staff / Teacher રોલ સાથે લોગિન કરીને પોતાની બેચ અને વિદ્યાર્થીઓ જોઈ શકશે.
                        </small>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel (રદ કરો)</button>
                <button type="submit" class="btn">Save Staff (સ્ટાફ સાચવો)</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT STAFF MODAL ==================== -->
<div class="custom-modal-overlay" id="editStaffModal">
    <div class="custom-modal" style="max-width: 650px;">
        <div class="custom-modal-header">
            <h3>Edit Staff Details (સ્ટાફની માહિતીમાં સુધારો)</h3>
            <button type="button" class="custom-modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_staff">
            <input type="hidden" name="id" id="edit_id" value="">

            <div class="custom-modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Staff Name (સ્ટાફનું પૂરું નામ) *</label>
                        <input type="text" name="name" id="edit_name" required style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Mobile Number (મોબાઇલ નંબર) *</label>
                        <input type="tel" name="phone" id="edit_phone" required style="width: 100%;">
                    </div>
                </div>

                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Designation (હોદ્દો / રોલ) *</label>
                        <select name="designation" id="edit_designation" style="width: 100%;">
                            <option value="મુખ્ય શિક્ષિકા (Head Teacher)">મુખ્ય શિક્ષિકા (Head Teacher)</option>
                            <option value="સિલાઈ શિક્ષિકા (Sewing Teacher)">સિલાઈ શિક્ષિકા (Sewing Teacher)</option>
                            <option value="આસિસ્ટન્ટ શિક્ષિકા (Assistant Teacher)">આસિસ્ટન્ટ શિક્ષિકા (Assistant Teacher)</option>
                            <option value="કટીંગ માસ્ટર (Cutting Specialist)">કટીંગ માસ્ટર (Cutting Specialist)</option>
                            <option value="ઓફિસ / વહીવટી સ્ટાફ (Admin Staff)">ઓફિસ / વહીવટી સ્ટાફ (Admin Staff)</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Specialization (વિશેષતા / કુશળતા)</label>
                        <input type="text" name="specialization" id="edit_specialization" style="width: 100%;">
                    </div>
                </div>

                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Joining Date (જોડાવાની તારીખ)</label>
                        <input type="date" name="joining_date" id="edit_joining_date" style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Status (સ્થિતિ)</label>
                        <select name="status" id="edit_status" style="width: 100%;">
                            <option value="active">Active (સક્રિય)</option>
                            <option value="inactive">Inactive (નિષ્ક્રિય)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Salary Amount (પગાર)</label>
                        <input type="number" step="0.01" name="salary" id="edit_salary" style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Salary Type (પગારનો પ્રકાર)</label>
                        <select name="salary_type" id="edit_salary_type" style="width: 100%;">
                            <option value="monthly">માસિક (Monthly)</option>
                            <option value="per_day">પ્રતિ દિવસ (Per Day)</option>
                            <option value="per_hour">પ્રતિ કલાક (Per Hour)</option>
                        </select>
                    </div>
                </div>

                <!-- Assigned Batches in Edit -->
                <div style="margin-bottom: 14px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <label style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 8px;">
                        Assign Batches (કઈ બેચ સાચવે છે?)
                    </label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;" id="edit_batches_container">
                        <?php foreach ($allBatches as $b): ?>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; margin: 0; background: #fff; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                <input type="checkbox" name="batch_ids[]" value="<?= $b['id'] ?>" class="edit-batch-check" id="edit_batch_<?= $b['id'] ?>" style="width: auto; margin: 0;">
                                <span><?= e($b['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Address & Emergency Contact in Edit -->
                <div class="grid grid-2" style="margin-bottom: 12px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Emergency Contact (કટોકટી સંપર્ક)</label>
                        <input type="tel" name="emergency_contact" id="edit_emergency_contact" style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Address (સરનામું)</label>
                        <input type="text" name="address" id="edit_address" style="width: 100%;">
                    </div>
                </div>

                <!-- Notes in Edit -->
                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px;">Notes (ખાસ નોંધ)</label>
                    <textarea name="notes" id="edit_notes" rows="2" style="width: 100%;"></textarea>
                </div>

                <!-- System Login Box in Edit -->
                <div style="background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; padding: 14px; margin-top: 10px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <input type="checkbox" name="create_login" id="edit_create_login" value="1" style="width: auto; margin:0;" onchange="toggleEditLoginFields(this.checked)">
                        <label for="edit_create_login" style="font-weight: 700; font-size: 13.5px; color: #4338ca; cursor: pointer; margin:0;">
                            System Login Access (સિસ્ટમ લોગિન એકાઉન્ટ)
                        </label>
                    </div>
                    <div id="edit_login_fields" style="margin-top: 10px;">
                        <div class="grid grid-2">
                            <div>
                                <label style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">Login Email (ઈમેલ)</label>
                                <input type="email" name="email" id="edit_email" style="width: 100%;">
                            </div>
                            <div>
                                <label style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">New Password (પાસવર્ડ બદલવો હોય તો જ લખો)</label>
                                <input type="password" name="password" id="edit_password" placeholder="ખાલી રાખો જો બદલવો ન હોય" minlength="6" style="width: 100%;">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel (રદ કરો)</button>
                <button type="submit" class="btn">Update Staff (સુધારો સાચવો)</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== VIEW STAFF PROFILE MODAL ==================== -->
<div class="custom-modal-overlay" id="viewStaffModal">
    <div class="custom-modal" style="max-width: 580px;">
        <div class="custom-modal-header">
            <h3>Staff Profile (સ્ટાફ પ્રોફાઇલ)</h3>
            <button type="button" class="custom-modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="custom-modal-body" id="viewModalContent" style="max-height: 75vh; overflow-y: auto;">
            <!-- Rendered by JS -->
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close (બંધ કરો)</button>
        </div>
    </div>
</div>

<script>
// Modal controls
function openAddModal() {
    document.getElementById('addStaffModal').classList.add('active');
}
function closeAddModal() {
    document.getElementById('addStaffModal').classList.remove('active');
}

function toggleAddLoginFields(isChecked) {
    var f = document.getElementById('add_login_fields');
    f.style.display = isChecked ? 'block' : 'none';
    var emailInput = document.getElementById('add_email');
    var passInput = document.getElementById('add_password');
    if (isChecked) {
        emailInput.setAttribute('required', 'required');
        passInput.setAttribute('required', 'required');
    } else {
        emailInput.removeAttribute('required');
        passInput.removeAttribute('required');
    }
}

function openEditModal(st) {
    document.getElementById('edit_id').value = st.id;
    document.getElementById('edit_name').value = st.name || '';
    document.getElementById('edit_phone').value = st.phone || '';
    document.getElementById('edit_designation').value = st.designation || '';
    document.getElementById('edit_specialization').value = st.specialization || '';
    document.getElementById('edit_joining_date').value = st.joining_date || '';
    document.getElementById('edit_status').value = st.status || 'active';
    document.getElementById('edit_salary').value = st.salary ? parseFloat(st.salary) : '';
    document.getElementById('edit_salary_type').value = st.salary_type || 'monthly';
    document.getElementById('edit_emergency_contact').value = st.emergency_contact || '';
    document.getElementById('edit_address').value = st.address || '';
    document.getElementById('edit_notes').value = st.notes || '';
    document.getElementById('edit_email').value = st.email || st.user_email || '';
    document.getElementById('edit_password').value = '';

    // Checkboxes for assigned batches
    var assignedIds = [];
    if (st.batches && Array.isArray(st.batches)) {
        assignedIds = st.batches.map(function(b) { return parseInt(b.batch_id, 10); });
    }
    var checkBoxes = document.querySelectorAll('.edit-batch-check');
    checkBoxes.forEach(function(cb) {
        cb.checked = assignedIds.includes(parseInt(cb.value, 10));
    });

    // Login account status
    var hasLogin = !!st.user_id;
    var loginCb = document.getElementById('edit_create_login');
    loginCb.checked = hasLogin;
    toggleEditLoginFields(hasLogin);

    document.getElementById('editStaffModal').classList.add('active');
}
function closeEditModal() {
    document.getElementById('editStaffModal').classList.remove('active');
}

function toggleEditLoginFields(isChecked) {
    var f = document.getElementById('edit_login_fields');
    f.style.display = isChecked ? 'block' : 'none';
}

function openViewModal(st) {
    var batchesHtml = '<span class="muted">કોઈ બેચ ફાળવેલ નથી</span>';
    if (st.batches && st.batches.length > 0) {
        batchesHtml = st.batches.map(function(b) {
            return '<span class="batch-mini-pill" style="font-size:12px; padding: 4px 10px;">' +
                   escapeHtml(b.batch_name) + ' (' + escapeHtml(b.batch_days || '') + ')' +
                   '</span>';
        }).join(' ');
    }

    var loginStatusHtml = st.user_id 
        ? '<span class="badge badge-purple">Active Login (' + escapeHtml(st.user_email || '') + ')</span>' 
        : '<span class="muted">No login account</span>';

    var statusHtml = st.status === 'active'
        ? '<span class="badge badge-green">Active (સક્રિય)</span>'
        : '<span class="badge badge-gray">Inactive (નિષ્ક્રિય)</span>';

    var salaryHtml = st.salary > 0 
        ? '₹' + parseFloat(st.salary).toLocaleString('en-IN') + ' / ' + escapeHtml(st.salary_type)
        : '-';

    var content = 
        '<div style="text-align: center; margin-bottom: 20px;">' +
            '<div class="staff-avatar" style="width: 60px; height: 60px; font-size: 22px; margin: 0 auto 10px;">' +
                escapeHtml(st.name ? st.name.substring(0, 2).toUpperCase() : 'S') +
            '</div>' +
            '<h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #0f172a;">' + escapeHtml(st.name) + '</h2>' +
            '<div style="font-size: 14px; color: #4338ca; font-weight: 600; margin-top: 3px;">' + escapeHtml(st.designation) + '</div>' +
            '<div style="margin-top: 8px;">' + statusHtml + '</div>' +
        '</div>' +
        '<div style="border-top: 1px solid #e2e8f0; padding-top: 16px;">' +
            '<div style="display: grid; grid-template-columns: 140px 1fr; gap: 10px 12px; font-size: 13.5px;">' +
                '<strong style="color: #64748b;">મોબાઇલ નંબર:</strong>' +
                '<div><a href="tel:' + escapeHtml(st.phone) + '" style="font-weight:600; color:#0f172a;">' + escapeHtml(st.phone) + '</a></div>' +

                '<strong style="color: #64748b;">ઈમેલ:</strong>' +
                '<div>' + (st.email ? '<a href="mailto:' + escapeHtml(st.email) + '">' + escapeHtml(st.email) + '</a>' : '-') + '</div>' +

                '<strong style="color: #64748b;">વિશેષતા:</strong>' +
                '<div>' + escapeHtml(st.specialization || '-') + '</div>' +

                '<strong style="color: #64748b;">જોડાવાની તારીખ:</strong>' +
                '<div>' + (st.joining_date ? escapeHtml(st.joining_date) : '-') + '</div>' +

                '<strong style="color: #64748b;">પગાર:</strong>' +
                '<div><strong>' + salaryHtml + '</strong></div>' +

                '<strong style="color: #64748b;">સિસ્ટમ લોગિન:</strong>' +
                '<div>' + loginStatusHtml + '</div>' +

                '<strong style="color: #64748b;">કટોકટી સંપર્ક:</strong>' +
                '<div>' + escapeHtml(st.emergency_contact || '-') + '</div>' +

                '<strong style="color: #64748b;">સરનામું:</strong>' +
                '<div>' + escapeHtml(st.address || '-') + '</div>' +

                '<strong style="color: #64748b;">નોંધ:</strong>' +
                '<div>' + escapeHtml(st.notes || '-') + '</div>' +

                '<strong style="color: #64748b; margin-top: 4px;">ફાળવેલ બેચ:</strong>' +
                '<div style="margin-top: 4px;">' + batchesHtml + '</div>' +
            '</div>' +
        '</div>';

    document.getElementById('viewModalContent').innerHTML = content;
    document.getElementById('viewStaffModal').classList.add('active');
}
function closeViewModal() {
    document.getElementById('viewStaffModal').classList.remove('active');
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Close modals when clicking backdrop
window.onclick = function(event) {
    var addModal = document.getElementById('addStaffModal');
    var editModal = document.getElementById('editStaffModal');
    var viewModal = document.getElementById('viewStaffModal');
    if (event.target === addModal) closeAddModal();
    if (event.target === editModal) closeEditModal();
    if (event.target === viewModal) closeViewModal();
};
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
