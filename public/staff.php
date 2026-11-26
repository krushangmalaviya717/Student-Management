<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/StaffService.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
require_once __DIR__ . '/../includes/components/kpi_card.php';

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
            flash('success', "Staff member '{$name}' added successfully.");
            redirect('public/staff.php');

        } elseif ($action === 'edit_staff') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            StaffService::updateStaff($id, $_POST, (int)$user['id']);
            flash('success', "Staff member '{$name}' updated successfully.");
            redirect('public/staff.php');

        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            StaffService::toggleStatus($id, (int)$user['id']);
            flash('success', 'Staff status updated successfully.');
            redirect('public/staff.php');

        } elseif ($action === 'delete_staff') {
            $id = (int)($_POST['id'] ?? 0);
            StaffService::deleteStaff($id, (int)$user['id']);
            flash('success', 'Staff record deleted successfully.');
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

$exportParams = $_GET;
$exportParams['export'] = 'excel';
$exportUrl = '?' . http_build_query($exportParams);
$hasActiveFilters = ($searchTerm !== '' || $statusFilter !== 'all' || $batchFilter !== null);

$pageTitle = 'Staff Management';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.staff-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary-light);
    color: var(--primary);
    border: 1px solid var(--primary-border);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 13px;
    text-transform: uppercase;
    flex-shrink: 0;
}
.staff-name-wrap {
    display: flex;
    align-items: center;
    gap: 11px;
}
.batch-mini-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: 11.5px;
    font-weight: 600;
    background: var(--surface-subtle);
    color: var(--text-body);
    border: 1px solid var(--border);
    margin: 2px 4px 2px 0;
    white-space: nowrap;
}
.staff-check-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
.staff-check-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    margin: 0;
    background: #FFFFFF;
    padding: 7px 10px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
}
.staff-check-item:hover {
    border-color: var(--primary-border);
    background: var(--hover-bg);
}
.staff-box-panel {
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px 14px;
    margin-bottom: 14px;
}
.staff-login-panel {
    background: var(--primary-light);
    border: 1px solid var(--primary-border);
    border-radius: var(--radius);
    padding: 14px;
    margin-top: 8px;
}
.profile-kv-grid {
    display: grid;
    grid-template-columns: 145px 1fr;
    gap: 10px 14px;
    font-size: 13.5px;
    align-items: baseline;
}
@media (max-width: 640px) {
    .staff-check-grid,
    .profile-kv-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-header-subtitle">Manage instructors, assistants, batch assignments, payroll setup, and login access.</p>
    </div>
    <div class="action-group">
        <a href="<?= e($exportUrl) ?>" class="btn btn-secondary btn-export btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
        <button type="button" class="btn" onclick="openAddModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add Staff
        </button>
    </div>
</div>

<!-- Staff Module Sub-Navigation -->
<div class="tabs-underline">
    <a href="<?= url('public/staff.php') ?>" class="tab-underline-btn active" style="text-decoration: none;">
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

<!-- Top KPI Summary Cards -->
<div class="grid grid-4" style="margin-bottom: 20px;">
    <?= render_kpi_card('Total Staff', (string)$kpis['total_staff'], 'Registered staff members', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>', 'default') ?>
    <?= render_kpi_card('Active Staff', (string)$kpis['active_staff'], 'Currently teaching or active', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>', 'success') ?>
    <?= render_kpi_card('Batches Covered', (string)$kpis['assigned_batches'], 'Assigned teaching batches', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>', 'primary') ?>
    <?= render_kpi_card('Est. Monthly Payroll', '₹' . number_format($kpis['monthly_payroll']), 'Total active base salary', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', 'default') ?>
</div>

<!-- Filters Card -->
<div class="card" style="padding: 16px 20px;">
    <form method="get" action="" class="filter-form">
        <div style="flex: 2 1 240px;">
            <label for="staff_search">Search</label>
            <input type="text" id="staff_search" name="search" value="<?= e($searchTerm) ?>" placeholder="Search by name, mobile, email, or designation...">
        </div>
        <div>
            <label for="staff_status">Status</label>
            <select id="staff_status" name="status" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div>
            <label for="staff_batch">Assigned Batch</label>
            <select id="staff_batch" name="batch_id" onchange="this.form.submit()">
                <option value="">All Batches</option>
                <?php foreach ($allBatches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $batchFilter === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= e($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($hasActiveFilters): ?>
                <a href="<?= url('public/staff.php') ?>" class="btn btn-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Staff Directory Table Card -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Staff Directory</h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Active and inactive instructors, assistants, and administrative staff.</p>
        </div>
        <span class="badge badge-secondary"><?= count($staffList) ?> record<?= count($staffList) === 1 ? '' : 's' ?></span>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Contact</th>
                    <th>Assigned Batches</th>
                    <th>Joining Date</th>
                    <th>Salary</th>
                    <th>Login Access</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--muted);">
                            <div style="font-size: 14.5px; font-weight: 600; color: var(--text-body);">No staff members found</div>
                            <div style="font-size: 13px; margin-top: 4px;">
                                <?= $hasActiveFilters ? 'Try clearing or adjusting your active filters.' : 'Click "Add Staff" above to create your first staff record.' ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($staffList as $st): ?>
                        <?php
                        $initials = '';
                        $parts = explode(' ', trim($st['name']));
                        foreach ($parts as $p) {
                            if ($p !== '') {
                                $initials .= mb_substr($p, 0, 1);
                            }
                            if (mb_strlen($initials) >= 2) {
                                break;
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="staff-name-wrap">
                                    <div class="staff-avatar"><?= e(strtoupper($initials ?: 'S')) ?></div>
                                    <div>
                                        <div style="font-weight: 600; color: var(--text); font-size: 13.5px;">
                                            <?= e($st['name']) ?>
                                        </div>
                                        <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
                                            <span style="font-weight: 600; color: var(--primary);"><?= e($st['designation']) ?></span>
                                            <?php if (!empty($st['specialization'])): ?>
                                                <span>&middot; <?= e($st['specialization']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 13px;">
                                    <a href="tel:<?= e($st['phone']) ?>" style="font-weight: 600; color: var(--text);">
                                        <?= e($st['phone']) ?>
                                    </a>
                                </div>
                                <?php if (!empty($st['email'])): ?>
                                    <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
                                        <a href="mailto:<?= e($st['email']) ?>" style="color: var(--muted);">
                                            <?= e($st['email']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($st['batches'])): ?>
                                    <div style="display: flex; flex-wrap: wrap; max-width: 260px;">
                                        <?php foreach ($st['batches'] as $sb): ?>
                                            <span class="batch-mini-pill" title="<?= e($sb['batch_days']) ?>">
                                                <?= e($sb['batch_name']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 12.5px;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap; font-variant-numeric: tabular-nums;">
                                <?= $st['joining_date'] ? date('d M Y', strtotime($st['joining_date'])) : '—' ?>
                            </td>
                            <td style="white-space: nowrap; font-variant-numeric: tabular-nums;">
                                <?php if ($st['salary'] > 0): ?>
                                    <div style="font-weight: 700; color: var(--text);">
                                        ₹<?= number_format($st['salary']) ?>
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--muted);">
                                        / <?= e(str_replace('_', ' ', $st['salary_type'])) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($st['user_id'])): ?>
                                    <span class="badge badge-purple" title="User Email: <?= e($st['user_email']) ?>">
                                        Enabled
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
                            <td class="text-right" style="white-space: nowrap;">
                                <div class="action-group" style="gap: 6px;">
                                    <button type="button" class="btn btn-sm btn-secondary btn-icon" onclick='openViewModal(<?= htmlspecialchars(json_encode($st), ENT_QUOTES, 'UTF-8') ?>)' title="View Profile" aria-label="View Profile">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </button>

                                    <button type="button" class="btn btn-sm btn-secondary" onclick='openEditModal(<?= htmlspecialchars(json_encode($st), ENT_QUOTES, 'UTF-8') ?>)' title="Edit Staff">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        Edit
                                    </button>

                                    <form method="post" style="display: inline; margin: 0;" onsubmit="return confirm('Change active status for this staff member?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary btn-icon" title="<?= $st['status'] === 'active' ? 'Deactivate Staff' : 'Activate Staff' ?>" aria-label="<?= $st['status'] === 'active' ? 'Deactivate Staff' : 'Activate Staff' ?>">
                                            <?php if ($st['status'] === 'active'): ?>
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
                                            <?php else: ?>
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                            <?php endif; ?>
                                        </button>
                                    </form>

                                    <form method="post" style="display: inline; margin: 0;" onsubmit="return confirm('Delete this staff record permanently? This action cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_staff">
                                        <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger-ghost btn-icon" title="Delete Staff" aria-label="Delete Staff">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
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
    <div class="custom-modal" style="max-width: 660px;">
        <div class="custom-modal-header">
            <h3>Add New Staff Member</h3>
            <button type="button" class="custom-modal-close" onclick="closeAddModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_staff">

            <div class="custom-modal-body">
                <div class="section-title">Basic Information</div>
                <div class="row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" required placeholder="e.g. Meenaben Patel">
                    </div>
                    <div class="form-group">
                        <label>Mobile Number *</label>
                        <input type="tel" name="phone" required placeholder="e.g. 9876543210">
                    </div>
                    <div class="form-group">
                        <label>Designation / Role *</label>
                        <select name="designation">
                            <option value="Head Teacher">Head Teacher</option>
                            <option value="Sewing Teacher" selected>Sewing Teacher</option>
                            <option value="Assistant Teacher">Assistant Teacher</option>
                            <option value="Cutting Specialist">Cutting Specialist</option>
                            <option value="Admin Staff">Admin Staff</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Specialization / Skills</label>
                        <input type="text" name="specialization" placeholder="e.g. Designer Blouse, Kurti, Machine Work">
                    </div>
                </div>

                <div class="section-title">Employment &amp; Compensation</div>
                <div class="row">
                    <div class="form-group">
                        <label>Joining Date</label>
                        <input type="date" name="joining_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Salary Amount (₹)</label>
                        <input type="number" step="0.01" name="salary" placeholder="e.g. 12000">
                    </div>
                    <div class="form-group">
                        <label>Salary Type</label>
                        <select name="salary_type">
                            <option value="monthly">Monthly</option>
                            <option value="per_day">Per Day</option>
                            <option value="per_hour">Per Hour</option>
                        </select>
                    </div>
                </div>

                <div class="staff-box-panel">
                    <label style="margin-bottom: 8px;">Assign Batches</label>
                    <div class="staff-check-grid">
                        <?php foreach ($allBatches as $b): ?>
                            <label class="staff-check-item">
                                <input type="checkbox" name="batch_ids[]" value="<?= $b['id'] ?>">
                                <span><?= e($b['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="section-title">Contact &amp; Notes</div>
                <div class="row">
                    <div class="form-group">
                        <label>Emergency Contact</label>
                        <input type="tel" name="emergency_contact" placeholder="Relative or alternate phone">
                    </div>
                    <div class="form-group">
                        <label>Residential Address</label>
                        <input type="text" name="address" placeholder="Street, area, city">
                    </div>
                    <div class="form-group col-full">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" placeholder="Additional details or experience notes..."></textarea>
                    </div>
                </div>

                <div class="staff-login-panel">
                    <label class="checkbox-label" for="add_create_login" style="color: var(--primary-hover);">
                        <input type="checkbox" name="create_login" id="add_create_login" value="1" onchange="toggleAddLoginFields(this.checked)">
                        <span>Create System Login Access</span>
                    </label>
                    <div id="add_login_fields" style="display: none; margin-top: 12px;">
                        <div class="row">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Login Email *</label>
                                <input type="email" name="email" id="add_email" placeholder="staff@example.com">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Password *</label>
                                <input type="password" name="password" id="add_password" placeholder="Minimum 6 characters" minlength="6">
                            </div>
                        </div>
                        <small class="muted" style="display: block; margin-top: 6px;">
                            Grants staff portal access to view assigned batches and student records.
                        </small>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn">Save Staff</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT STAFF MODAL ==================== -->
<div class="custom-modal-overlay" id="editStaffModal">
    <div class="custom-modal" style="max-width: 660px;">
        <div class="custom-modal-header">
            <h3>Edit Staff Details</h3>
            <button type="button" class="custom-modal-close" onclick="closeEditModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_staff">
            <input type="hidden" name="id" id="edit_id" value="">

            <div class="custom-modal-body">
                <div class="section-title">Basic Information</div>
                <div class="row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" id="edit_name" required>
                    </div>
                    <div class="form-group">
                        <label>Mobile Number *</label>
                        <input type="tel" name="phone" id="edit_phone" required>
                    </div>
                    <div class="form-group">
                        <label>Designation / Role *</label>
                        <select name="designation" id="edit_designation">
                            <option value="Head Teacher">Head Teacher</option>
                            <option value="Sewing Teacher">Sewing Teacher</option>
                            <option value="Assistant Teacher">Assistant Teacher</option>
                            <option value="Cutting Specialist">Cutting Specialist</option>
                            <option value="Admin Staff">Admin Staff</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Specialization / Skills</label>
                        <input type="text" name="specialization" id="edit_specialization">
                    </div>
                </div>

                <div class="section-title">Employment &amp; Compensation</div>
                <div class="row">
                    <div class="form-group">
                        <label>Joining Date</label>
                        <input type="date" name="joining_date" id="edit_joining_date">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Salary Amount (₹)</label>
                        <input type="number" step="0.01" name="salary" id="edit_salary">
                    </div>
                    <div class="form-group">
                        <label>Salary Type</label>
                        <select name="salary_type" id="edit_salary_type">
                            <option value="monthly">Monthly</option>
                            <option value="per_day">Per Day</option>
                            <option value="per_hour">Per Hour</option>
                        </select>
                    </div>
                </div>

                <div class="staff-box-panel">
                    <label style="margin-bottom: 8px;">Assign Batches</label>
                    <div class="staff-check-grid" id="edit_batches_container">
                        <?php foreach ($allBatches as $b): ?>
                            <label class="staff-check-item">
                                <input type="checkbox" name="batch_ids[]" value="<?= $b['id'] ?>" class="edit-batch-check" id="edit_batch_<?= $b['id'] ?>">
                                <span><?= e($b['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="section-title">Contact &amp; Notes</div>
                <div class="row">
                    <div class="form-group">
                        <label>Emergency Contact</label>
                        <input type="tel" name="emergency_contact" id="edit_emergency_contact">
                    </div>
                    <div class="form-group">
                        <label>Residential Address</label>
                        <input type="text" name="address" id="edit_address">
                    </div>
                    <div class="form-group col-full">
                        <label>Notes</label>
                        <textarea name="notes" id="edit_notes" rows="2"></textarea>
                    </div>
                </div>

                <div class="staff-login-panel">
                    <label class="checkbox-label" for="edit_create_login" style="color: var(--primary-hover);">
                        <input type="checkbox" name="create_login" id="edit_create_login" value="1" onchange="toggleEditLoginFields(this.checked)">
                        <span>System Login Access</span>
                    </label>
                    <div id="edit_login_fields" style="margin-top: 12px;">
                        <div class="row">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Login Email</label>
                                <input type="email" name="email" id="edit_email">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>New Password (optional)</label>
                                <input type="password" name="password" id="edit_password" placeholder="Leave blank to keep existing" minlength="6">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn">Update Staff</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== VIEW STAFF PROFILE MODAL ==================== -->
<div class="custom-modal-overlay" id="viewStaffModal">
    <div class="custom-modal" style="max-width: 580px;">
        <div class="custom-modal-header">
            <h3>Staff Profile</h3>
            <button type="button" class="custom-modal-close" onclick="closeViewModal()" aria-label="Close">&times;</button>
        </div>
        <div class="custom-modal-body" id="viewModalContent">
            <!-- Rendered by JS -->
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<script>
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

function matchDesignationValue(selectEl, rawVal) {
    if (!rawVal) return;
    for (var i = 0; i < selectEl.options.length; i++) {
        if (selectEl.options[i].value === rawVal) {
            selectEl.value = rawVal;
            return;
        }
    }
    var lower = String(rawVal).toLowerCase();
    for (var j = 0; j < selectEl.options.length; j++) {
        if (lower.indexOf(selectEl.options[j].value.toLowerCase()) !== -1) {
            selectEl.value = selectEl.options[j].value;
            return;
        }
    }
    var customOpt = document.createElement('option');
    customOpt.value = rawVal;
    customOpt.text = rawVal;
    selectEl.appendChild(customOpt);
    selectEl.value = rawVal;
}

function openEditModal(st) {
    document.getElementById('edit_id').value = st.id;
    document.getElementById('edit_name').value = st.name || '';
    document.getElementById('edit_phone').value = st.phone || '';
    matchDesignationValue(document.getElementById('edit_designation'), st.designation || '');
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

    var assignedIds = [];
    if (st.batches && Array.isArray(st.batches)) {
        assignedIds = st.batches.map(function(b) { return parseInt(b.batch_id, 10); });
    }
    var checkBoxes = document.querySelectorAll('.edit-batch-check');
    checkBoxes.forEach(function(cb) {
        cb.checked = assignedIds.includes(parseInt(cb.value, 10));
    });

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
    var batchesHtml = '<span class="muted">No batches assigned</span>';
    if (st.batches && st.batches.length > 0) {
        batchesHtml = st.batches.map(function(b) {
            return '<span class="batch-mini-pill">' +
                   escapeHtml(b.batch_name) + (b.batch_days ? ' (' + escapeHtml(b.batch_days) + ')' : '') +
                   '</span>';
        }).join(' ');
    }

    var loginStatusHtml = st.user_id
        ? '<span class="badge badge-purple">Enabled (' + escapeHtml(st.user_email || '') + ')</span>'
        : '<span class="muted">No login account</span>';

    var statusHtml = st.status === 'active'
        ? '<span class="badge badge-green">Active</span>'
        : '<span class="badge badge-gray">Inactive</span>';

    var salaryHtml = st.salary > 0
        ? '₹' + parseFloat(st.salary).toLocaleString('en-IN') + ' / ' + escapeHtml((st.salary_type || '').replace(/_/g, ' '))
        : '—';

    var content =
        '<div style="text-align: center; margin-bottom: 18px;">' +
            '<div class="staff-avatar" style="width: 56px; height: 56px; font-size: 20px; margin: 0 auto 10px;">' +
                escapeHtml(st.name ? st.name.substring(0, 2).toUpperCase() : 'S') +
            '</div>' +
            '<h2 style="margin: 0; font-size: 18px; font-weight: 700; color: var(--text);">' + escapeHtml(st.name) + '</h2>' +
            '<div style="font-size: 13.5px; color: var(--primary); font-weight: 600; margin-top: 3px;">' + escapeHtml(st.designation) + '</div>' +
            '<div style="margin-top: 8px;">' + statusHtml + '</div>' +
        '</div>' +
        '<div style="border-top: 1px solid var(--border); padding-top: 16px;">' +
            '<div class="profile-kv-grid">' +
                '<strong style="color: var(--muted);">Mobile Number</strong>' +
                '<div><a href="tel:' + escapeHtml(st.phone) + '" style="font-weight: 600; color: var(--text);">' + escapeHtml(st.phone) + '</a></div>' +

                '<strong style="color: var(--muted);">Email</strong>' +
                '<div>' + (st.email ? '<a href="mailto:' + escapeHtml(st.email) + '">' + escapeHtml(st.email) + '</a>' : '—') + '</div>' +

                '<strong style="color: var(--muted);">Specialization</strong>' +
                '<div>' + escapeHtml(st.specialization || '—') + '</div>' +

                '<strong style="color: var(--muted);">Joining Date</strong>' +
                '<div>' + (st.joining_date ? escapeHtml(st.joining_date) : '—') + '</div>' +

                '<strong style="color: var(--muted);">Salary</strong>' +
                '<div><strong>' + salaryHtml + '</strong></div>' +

                '<strong style="color: var(--muted);">System Login</strong>' +
                '<div>' + loginStatusHtml + '</div>' +

                '<strong style="color: var(--muted);">Emergency Contact</strong>' +
                '<div>' + escapeHtml(st.emergency_contact || '—') + '</div>' +

                '<strong style="color: var(--muted);">Address</strong>' +
                '<div>' + escapeHtml(st.address || '—') + '</div>' +

                '<strong style="color: var(--muted);">Notes</strong>' +
                '<div>' + escapeHtml(st.notes || '—') + '</div>' +

                '<strong style="color: var(--muted);">Assigned Batches</strong>' +
                '<div>' + batchesHtml + '</div>' +
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

window.addEventListener('click', function(event) {
    if (event.target === document.getElementById('addStaffModal')) closeAddModal();
    if (event.target === document.getElementById('editStaffModal')) closeEditModal();
    if (event.target === document.getElementById('viewStaffModal')) closeViewModal();
});

window.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeAddModal();
        closeEditModal();
        closeViewModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
