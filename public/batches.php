<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/services/BatchService.php';
require_once __DIR__ . '/../includes/services/ExportService.php';
require_login();

$user = current_user();
$error = null;

// Handle POST actions via BatchService (Requirement 2 & 5)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_batch') {
            $name = trim($_POST['name'] ?? '');
            BatchService::createBatch($_POST, (int)$user['id']);
            flash('success', "નવી બેચ '{$name}' સફળતાપૂર્વક ઉમેરાઈ ગઈ છે.");
            redirect('public/batches.php');

        } elseif ($action === 'edit_batch') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            BatchService::updateBatch($id, $_POST, (int)$user['id']);
            flash('success', "બેચ '{$name}' ની વિગતો સફળતાપૂર્વક અપડેટ થઈ ગઈ છે.");
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
                throw new RuntimeException('બેચ મળી નથી.');
            }
            BatchService::toggleStatus($id, (int)$user['id']);
            $statusText = $batch['is_active'] ? 'બંધ (Inactive)' : 'ચાલુ (Active)';
            flash('success', "બેચ '{$batch['name']}' નું સ્ટેટસ બદલીને {$statusText} કરવામાં આવ્યું છે.");
            redirect('public/batches.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Fetch all batches
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

// Excel Export Handler (Requirement 3: Reusable ExportService)
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
/* Custom Batch Management UI Styles */
.batch-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 900px) {
    .batch-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 500px) {
    .batch-kpi-grid { grid-template-columns: 1fr; }
}

.batch-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.batch-kpi-val {
    font-size: 26px;
    font-weight: 800;
    color: #111827;
    line-height: 1.1;
    margin-bottom: 6px;
}
.batch-kpi-lbl {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
}

/* Progress bar inside table */
.occupancy-bar-bg {
    background: #e2e8f0;
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
</style>

<?php if ($error): ?>
    <div class="flash flash-error" style="margin-bottom: 20px;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Top Summary KPIs -->
<div class="batch-kpi-grid">
    <div class="batch-kpi-card">
        <div class="batch-kpi-val"><?= count($batches) ?></div>
        <div class="batch-kpi-lbl">Total Batches (કુલ બેચ)</div>
    </div>
    <div class="batch-kpi-card">
        <div class="batch-kpi-val"><?= $totalActive ?> <span style="font-size:16px; color:#64748b; font-weight:500;">/ <?= $totalCapacity ?></span></div>
        <div class="batch-kpi-lbl">Enrolled Students (ભરાયેલી સીટ)</div>
    </div>
    <div class="batch-kpi-card">
        <div class="batch-kpi-val" style="color: #0891b2;"><?= $totalAvailable ?></div>
        <div class="batch-kpi-lbl">Available Seats (ખાલી સીટો)</div>
    </div>
    <div class="batch-kpi-card">
        <div class="batch-kpi-val" style="color: #d97706;"><?= $totalWaiting ?></div>
        <div class="batch-kpi-lbl">Waiting Candidates (કુલ વેઇટિંગ)</div>
    </div>
</div>

<!-- Main Card & Action Bar -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 class="mt-0" style="margin-bottom: 3px;">Batches Schedule &amp; Capacity (બેચ યાદી)</h2>
            <small class="muted">દરેક બેચનો સમય, ક્ષમતા (સીટો) અને હાલની ઉપલબ્ધતા અહીંથી સરળતાથી મેનેજ કરો.</small>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <?= render_export_button('?export=excel', 'Export to Excel (CSV)') ?>
            <?php if (is_admin_or_above()): ?>
            <button class="btn btn-sm" onclick="openAddModal()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>Add New Batch (નવી બેચ ઉમેરો)
            </button>
            <?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Batch Name &amp; Timing (બેચ અને સમય)</th>
                <th>Days (દિવસો)</th>
                <th>Occupancy (સીટ વિગત)</th>
                <th>Available Seats (ખાલી)</th>
                <th>Waiting Queue (વેઇટિંગ)</th>
                <th>Status</th>
                <th style="text-align:right;">Actions (ઓપરેશન્સ)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($batches as $b): 
                $pct = $b['capacity'] > 0 ? min(100, round(($b['active_count'] / $b['capacity']) * 100)) : 0;
                $barColor = $pct >= 100 ? '#dc2626' : ($pct >= 75 ? '#d97706' : '#16a34a');
                $startTimeFmt = date('h:i A', strtotime($b['start_time']));
                $endTimeFmt = date('h:i A', strtotime($b['end_time']));
            ?>
            <tr style="<?= !$b['is_active'] ? 'opacity:0.65; background:#fafafa;' : '' ?>">
                <td>
                    <div style="font-weight: 700; font-size: 14.5px; color:#111827;"><?= e($b['name']) ?></div>
                    <div style="margin-top: 4px;">
                        <span class="badge badge-purple" style="font-size: 11px; padding: 2px 8px;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:3px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><?= $startTimeFmt ?> - <?= $endTimeFmt ?>
                        </span>
                    </div>
                </td>
                <td>
                    <span class="badge badge-gray" style="font-weight: 600;"><?= e($b['batch_days']) ?></span>
                </td>
                <td>
                    <div style="font-weight: 700; font-size: 13.5px;">
                        <?= $b['active_count'] ?> / <?= $b['capacity'] ?> Students
                        <span style="font-size:11px; color:<?= $barColor ?>; font-weight:700; margin-left:4px;">(<?= $pct ?>%)</span>
                    </div>
                    <div class="occupancy-bar-bg">
                        <div class="occupancy-bar-fill" style="width: <?= $pct ?>%; background: <?= $barColor ?>;"></div>
                    </div>
                </td>
                <td>
                    <?php if ($b['available'] <= 0): ?>
                        <span class="badge badge-red" style="font-weight:700;">Full (હાઉસફુલ)</span>
                    <?php else: ?>
                        <span class="badge badge-green" style="font-weight:700;"><?= $b['available'] ?> Seats Available</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($b['waiting_count'] > 0): ?>
                        <a href="<?= url('public/waiting_list.php?batch=' . $b['id']) ?>" class="badge badge-amber" style="font-weight:700; text-decoration:none;">
                            <?= $b['waiting_count'] ?> Candidates
                        </a>
                    <?php else: ?>
                        <span class="muted" style="font-size: 13px;">0 Waiting</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($b['is_active']): ?>
                        <span class="badge badge-green">Active</span>
                    <?php else: ?>
                        <span class="badge badge-gray">Inactive</span>
                    <?php endif; ?>
                </td>
                <td style="text-align: right; white-space: nowrap;">
                    <div style="display: inline-flex; gap: 6px; align-items: center;">
                        <!-- View Students link -->
                        <a href="<?= url('public/students_list.php?batch_id=' . $b['id']) ?>" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 10px;" title="આ બેચના વિદ્યાર્થીઓ જુઓ">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:3px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>Students
                        </a>

                        <?php if (is_admin_or_above()): ?>
                        <!-- Edit Button -->
                        <button type="button" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 10px;" onclick='openEditModal(<?= json_encode($b) ?>)'>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:3px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit
                        </button>

                        <!-- Quick Status Toggle -->
                        <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('શું તમે આ બેચનું સ્ટેટસ બદલવા માંગો છો?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 5px 8px;" title="<?= $b['is_active'] ? 'Deactivate Batch' : 'Activate Batch' ?>">
                                <?php if ($b['is_active']): ?>
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
                                <?php else: ?>
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                <?php endif; ?>
                            </button>
                        </form>

                        <!-- Delete Button -->
                        <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('શું તમે ખરેખર બેચ \'<?= addslashes($b['name']) ?>\' ડિલીટ કરવા માંગો છો?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_batch">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" style="font-size: 12px; padding: 5px 8px;" title="Delete Batch">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>

            <?php if (!$batches): ?>
            <tr>
                <td colspan="7" class="muted" style="text-align: center; padding: 30px;">
                    હજુ સુધી કોઈ બેચ ઉમેરવામાં આવી નથી. ઉપરના <strong>Add New Batch</strong> બટન પર ક્લિક કરીને પ્રથમ બેચ ઉમેરો.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ==================== ADD BATCH MODAL ==================== -->
<div class="custom-modal-overlay" id="addBatchModal">
    <div class="custom-modal-box">
        <div class="custom-modal-header">
            <h3>Add New Batch (નવી બેચ ઉમેરો)</h3>
            <button type="button" class="custom-modal-close" onclick="closeAddModal()">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_batch">
            
            <div class="custom-modal-body">
                <div style="margin-bottom: 14px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">Batch Name (બેચનું નામ) *</label>
                    <input type="text" name="name" id="add_batch_name" required placeholder="દા.ત. 07:00 AM - 09:00 AM અથવા Morning Batch" style="width: 100%;">
                </div>

                <div class="grid grid-2" style="margin-bottom: 14px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">Start Time (શરૂ થવાનો સમય) *</label>
                        <input type="time" name="start_time" id="add_start_time" required value="07:00" style="width: 100%;" onchange="autoGenerateAddName()">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">End Time (પૂરો થવાનો સમય) *</label>
                        <input type="time" name="end_time" id="add_end_time" required value="09:00" style="width: 100%;" onchange="autoGenerateAddName()">
                    </div>
                </div>

                <div class="grid grid-2" style="margin-bottom: 14px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">Days (દિવસો)</label>
                        <input type="text" name="batch_days" value="Mon-Sat" required style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">Capacity (સીટોની સંખ્યા) *</label>
                        <input type="number" name="capacity" value="8" min="1" max="100" required style="width: 100%;">
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px;">
                    <input type="checkbox" name="is_active" id="add_is_active" value="1" checked style="width: auto;">
                    <label for="add_is_active" style="font-weight: 600; font-size: 13.5px; cursor: pointer; margin:0;">આ બેચ હાલમાં એક્ટિવ રાખવી (Active)</label>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel (રદ કરો)</button>
                <button type="submit" class="btn">Save Batch (બેચ સાચવો)</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT BATCH MODAL ==================== -->
<div class="custom-modal-overlay" id="editBatchModal">
    <div class="custom-modal-box">
        <div class="custom-modal-header">
            <h3>Edit Batch (બેચમાં સુધારો કરો)</h3>
            <button type="button" class="custom-modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_batch">
            <input type="hidden" name="id" id="edit_id" value="">

            <div class="custom-modal-body">
                <div style="margin-bottom: 14px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">Batch Name (બેચનું નામ) *</label>
                    <input type="text" name="name" id="edit_name" required style="width: 100%;">
                </div>

                <div class="grid grid-2" style="margin-bottom: 14px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">Start Time (શરૂ થવાનો સમય) *</label>
                        <input type="time" name="start_time" id="edit_start_time" required style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">End Time (પૂરો થવાનો સમય) *</label>
                        <input type="time" name="end_time" id="edit_end_time" required style="width: 100%;">
                    </div>
                </div>

                <div class="grid grid-2" style="margin-bottom: 14px;">
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">Days (દિવસો)</label>
                        <input type="text" name="batch_days" id="edit_batch_days" required style="width: 100%;">
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 5px;">Capacity (સીટોની ક્ષમતા) *</label>
                        <input type="number" name="capacity" id="edit_capacity" min="1" max="100" required style="width: 100%;">
                        <small class="muted" id="edit_active_note" style="display:block; margin-top:2px; font-size:11px;"></small>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px;">
                    <input type="checkbox" name="is_active" id="edit_is_active" value="1" style="width: auto;">
                    <label for="edit_is_active" style="font-weight: 600; font-size: 13.5px; cursor: pointer; margin:0;">બેચ એક્ટિવ છે (Active)</label>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel (રદ કરો)</button>
                <button type="submit" class="btn">Update Batch (સુધારો સાચવો)</button>
            </div>
        </form>
    </div>
</div>

<script>
// Format 24-hr time to 12-hr string
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

// Modal controls
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
        note.innerText = 'હાલ ' + batch.active_count + ' વિદ્યાર્થીઓ ભણે છે, ક્ષમતા તેનાથી ઓછી ન રાખી શકાય.';
        note.style.color = '#ef4444';
    } else {
        note.innerText = 'આ બેચમાં હાલ કોઈ વિદ્યાર્થી જોડાયેલ નથી.';
        note.style.color = '#64748b';
    }

    document.getElementById('editBatchModal').classList.add('active');
}
function closeEditModal() {
    document.getElementById('editBatchModal').classList.remove('active');
}

// Close modals when clicking backdrop
window.onclick = function(event) {
    var addModal = document.getElementById('addBatchModal');
    var editModal = document.getElementById('editBatchModal');
    if (event.target === addModal) closeAddModal();
    if (event.target === editModal) closeEditModal();
};
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
