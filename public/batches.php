<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
require_login();

$user = current_user();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_batch') {
            $name = trim($_POST['name'] ?? '');
            BatchService::createBatch($_POST, (int)$user['id']);
            flash('success', "Batch '{$name}' created successfully.");
            redirect('public/batches.php');

        } elseif ($action === 'edit_batch') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            BatchService::updateBatch($id, $_POST, (int)$user['id']);
            flash('success', "Batch '{$name}' updated successfully.");
            redirect('public/batches.php');

        } elseif ($action === 'delete_batch') {
            $id = (int)($_POST['id'] ?? 0);
            $result = BatchService::deleteBatch($id, (int)$user['id']);
            flash('success', $result['message']);
            redirect('public/batches.php');

        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $batch = BatchService::getBatchById($id);
            if (!$batch) {
                throw new RuntimeException('Batch not found.');
            }
            BatchService::toggleStatus($id, (int)$user['id']);
            $statusText = $batch['is_active'] ? 'Inactive' : 'Active';
            flash('success', "Batch '{$batch['name']}' status changed to {$statusText}.");
            redirect('public/batches.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$batches = BatchService::getAllBatches();
$totalCapacity = 0;
$totalActive = 0;
$totalWaiting = 0;

foreach ($batches as &$b) {
    $b['active_count'] = BatchService::getActiveStudentCount((int)$b['id']);
    $b['available'] = max(0, (int)$b['capacity'] - $b['active_count']);
    $b['waiting_count'] = BatchService::getWaitingStudentCount((int)$b['id']);

    if ($b['is_active']) {
        $totalCapacity += (int)$b['capacity'];
        $totalActive += (int)$b['active_count'];
    }
    $totalWaiting += (int)$b['waiting_count'];
}
unset($b);

$totalAvailable = max(0, $totalCapacity - $totalActive);

if (($_GET['export'] ?? '') === 'excel') {
    $headers = ['ID', 'Batch Name', 'Days', 'Start Time', 'End Time', 'Capacity', 'Active Students', 'Available Seats', 'Waiting in Queue', 'Status'];
    $rows = [];
    foreach ($batches as $b) {
        $rows[] = [
            $b['id'],
            $b['name'],
            $b['batch_days'],
            date('h:i A', strtotime($b['start_time'])),
            date('h:i A', strtotime($b['end_time'])),
            $b['capacity'],
            $b['active_count'],
            $b['available'],
            $b['waiting_count'],
            $b['is_active'] ? 'Active' : 'Inactive'
        ];
    }
    ExportService::downloadCsv('Batches_Occupancy_Report_' . date('Y-m-d') . '.csv', $headers, $rows);
}

$pageTitle = 'Batch Management';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.occupancy-bar-bg {
    background: var(--border);
    border-radius: 999px;
    height: 6px;
    overflow: hidden;
    margin-top: 6px;
    width: 120px;
}
.occupancy-bar-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
}
.batch-time-meta {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: var(--muted);
    margin-top: 3px;
}
</style>

<?php if ($error): ?>
    <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<!-- Top Summary KPIs -->
<div class="grid grid-4" style="margin-bottom: 20px;">
    <?= render_kpi_card(
        'Total Batches',
        count($batches),
        'Configured time slots',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>'
    ) ?>
    <?= render_kpi_card(
        'Enrolled Students',
        $totalActive . ' / ' . $totalCapacity,
        'Occupied seats across active batches',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>'
    ) ?>
    <?= render_kpi_card(
        'Available Seats',
        $totalAvailable,
        'Open for immediate joining',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        $totalAvailable > 0 ? 'success' : 'default'
    ) ?>
    <?= render_kpi_card(
        'Waiting Candidates',
        $totalWaiting,
        'Total queued across batches',
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        $totalWaiting > 0 ? 'warning' : 'default'
    ) ?>
</div>

<!-- Main Card & Action Bar -->
<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Batches Schedule &amp; Capacity</h2>
            <small class="muted">Manage batch schedules, seat limits, and active availability.</small>
        </div>
        <div class="action-group">
            <?= render_export_button('?export=excel', 'Export CSV') ?>
            <?php if (is_admin_or_above()): ?>
            <button type="button" class="btn btn-sm" onclick="openAddModal()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Add New Batch</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Batch Name &amp; Timing</th>
                    <th>Days</th>
                    <th>Occupancy</th>
                    <th>Availability</th>
                    <th>Waiting Queue</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $b):
                    $pct = $b['capacity'] > 0 ? min(100, round(($b['active_count'] / $b['capacity']) * 100)) : 0;
                    $barColor = $pct >= 100 ? 'var(--danger)' : ($pct >= 75 ? 'var(--warning)' : 'var(--success)');
                    $startTimeFmt = date('h:i A', strtotime($b['start_time']));
                    $endTimeFmt = date('h:i A', strtotime($b['end_time']));
                ?>
                <tr style="<?= !$b['is_active'] ? 'opacity:0.65;' : '' ?>">
                    <td>
                        <div style="font-weight: 600; color: var(--text);"><?= e($b['name']) ?></div>
                        <div class="batch-time-meta">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span><?= $startTimeFmt ?> - <?= $endTimeFmt ?></span>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-gray"><?= e($b['batch_days']) ?></span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text); font-variant-numeric: tabular-nums;">
                            <?= $b['active_count'] ?> / <?= $b['capacity'] ?> Students
                            <span style="font-size: 11.5px; color: <?= $barColor ?>; margin-left: 4px;">(<?= $pct ?>%)</span>
                        </div>
                        <div class="occupancy-bar-bg">
                            <div class="occupancy-bar-fill" style="width: <?= $pct ?>%; background: <?= $barColor ?>;"></div>
                        </div>
                    </td>
                    <td>
                        <?php if ($b['available'] <= 0): ?>
                            <span class="badge badge-red">Full</span>
                        <?php else: ?>
                            <span class="badge badge-green"><?= $b['available'] ?> Seats Available</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($b['waiting_count'] > 0): ?>
                            <a href="<?= url('public/waiting_list.php?batch=' . $b['id']) ?>" class="badge badge-amber">
                                <?= $b['waiting_count'] ?> Candidates
                            </a>
                        <?php else: ?>
                            <span class="muted">0 Waiting</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($b['is_active']): ?>
                            <span class="badge badge-green">Active</span>
                        <?php else: ?>
                            <span class="badge badge-gray">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right" style="white-space: nowrap;">
                        <div class="action-group" style="justify-content: flex-end;">
                            <a href="<?= url('public/students_list.php?batch_id=' . $b['id']) ?>" class="btn btn-sm btn-secondary" title="View students in this batch">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <span>Students</span>
                            </a>

                            <?php if (is_admin_or_above()): ?>
                            <button type="button" class="btn btn-sm btn-secondary" onclick='openEditModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>)' title="Edit Batch">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                <span>Edit</span>
                            </button>

                            <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Toggle active status for this batch?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-icon btn-secondary" title="<?= $b['is_active'] ? 'Deactivate Batch' : 'Activate Batch' ?>">
                                    <?php if ($b['is_active']): ?>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                                    <?php else: ?>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                    <?php endif; ?>
                                </button>
                            </form>

                            <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Delete batch \'<?= addslashes($b['name']) ?>\' permanently?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_batch">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-icon btn-danger-ghost" title="Delete Batch">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (!$batches): ?>
                <tr>
                    <td colspan="7" class="muted" style="text-align: center; padding: 36px 20px;">
                        No batches configured yet. Click <strong>Add New Batch</strong> to create your first batch.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== ADD BATCH MODAL ==================== -->
<div class="custom-modal-overlay" id="addBatchModal">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <h3>Add New Batch</h3>
            <button type="button" class="custom-modal-close" onclick="closeAddModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_batch">

            <div class="custom-modal-body">
                <div class="form-group">
                    <label for="add_batch_name">Batch Name *</label>
                    <input type="text" name="name" id="add_batch_name" required placeholder="e.g. 07:00 AM - 09:00 AM">
                </div>

                <div class="row form-group">
                    <div>
                        <label for="add_start_time">Start Time *</label>
                        <input type="time" name="start_time" id="add_start_time" required value="07:00" onchange="autoGenerateAddName()">
                    </div>
                    <div>
                        <label for="add_end_time">End Time *</label>
                        <input type="time" name="end_time" id="add_end_time" required value="09:00" onchange="autoGenerateAddName()">
                    </div>
                </div>

                <div class="row form-group">
                    <div>
                        <label for="add_batch_days">Days *</label>
                        <input type="text" name="batch_days" id="add_batch_days" value="Mon-Sat" required>
                    </div>
                    <div>
                        <label for="add_capacity">Capacity *</label>
                        <input type="number" name="capacity" id="add_capacity" value="8" min="1" max="100" required>
                    </div>
                </div>

                <label class="checkbox-label" for="add_is_active">
                    <input type="checkbox" name="is_active" id="add_is_active" value="1" checked>
                    <span>Batch is Active</span>
                </label>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn">Save Batch</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT BATCH MODAL ==================== -->
<div class="custom-modal-overlay" id="editBatchModal">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <h3>Edit Batch</h3>
            <button type="button" class="custom-modal-close" onclick="closeEditModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_batch">
            <input type="hidden" name="id" id="edit_id" value="">

            <div class="custom-modal-body">
                <div class="form-group">
                    <label for="edit_name">Batch Name *</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>

                <div class="row form-group">
                    <div>
                        <label for="edit_start_time">Start Time *</label>
                        <input type="time" name="start_time" id="edit_start_time" required>
                    </div>
                    <div>
                        <label for="edit_end_time">End Time *</label>
                        <input type="time" name="end_time" id="edit_end_time" required>
                    </div>
                </div>

                <div class="row form-group" style="align-items: start;">
                    <div>
                        <label for="edit_batch_days">Days *</label>
                        <input type="text" name="batch_days" id="edit_batch_days" required>
                    </div>
                    <div>
                        <label for="edit_capacity">Capacity *</label>
                        <input type="number" name="capacity" id="edit_capacity" min="1" max="100" required>
                        <small class="muted" id="edit_active_note" style="display:block; margin-top:4px; font-size:11.5px;"></small>
                    </div>
                </div>

                <label class="checkbox-label" for="edit_is_active">
                    <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                    <span>Batch is Active</span>
                </label>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn">Update Batch</button>
            </div>
        </form>
    </div>
</div>

<script>
function format12Hr(timeStr) {
    if (!timeStr) return '';
    var parts = timeStr.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1] || '00';
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    h = h ? h : 12;
    return (h < 10 ? '0' + h : h) + ':' + m + ' ' + ampm;
}

function autoGenerateAddName() {
    var s = document.getElementById('add_start_time').value;
    var e = document.getElementById('add_end_time').value;
    var nameField = document.getElementById('add_batch_name');
    if (s && e && (!nameField.value || nameField.value.includes(' - ') || nameField.value.includes('AM') || nameField.value.includes('PM'))) {
        nameField.value = format12Hr(s) + ' - ' + format12Hr(e);
    }
}

function openAddModal() {
    document.getElementById('addBatchModal').classList.add('active');
    autoGenerateAddName();
}
function closeAddModal() {
    document.getElementById('addBatchModal').classList.remove('active');
}

function openEditModal(batch) {
    document.getElementById('edit_id').value = batch.id;
    document.getElementById('edit_name').value = batch.name;
    document.getElementById('edit_batch_days').value = batch.batch_days;
    document.getElementById('edit_start_time').value = batch.start_time;
    document.getElementById('edit_end_time').value = batch.end_time;
    document.getElementById('edit_capacity').value = batch.capacity;
    document.getElementById('edit_is_active').checked = parseInt(batch.is_active, 10) === 1;

    var note = document.getElementById('edit_active_note');
    if (batch.active_count > 0) {
        note.innerText = batch.active_count + ' active students enrolled; capacity cannot be lower.';
        note.style.color = '#DC2626';
    } else {
        note.innerText = 'No active students currently enrolled.';
        note.style.color = '#64748B';
    }

    document.getElementById('editBatchModal').classList.add('active');
}
function closeEditModal() {
    document.getElementById('editBatchModal').classList.remove('active');
}

window.onclick = function(event) {
    var addModal = document.getElementById('addBatchModal');
    var editModal = document.getElementById('editBatchModal');
    if (event.target === addModal) closeAddModal();
    if (event.target === editModal) closeEditModal();
};
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
