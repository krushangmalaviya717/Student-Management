<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/queue_engine.php';
require_once __DIR__ . '/../includes/payment_engine.php';
require_once __DIR__ . '/../includes/extension_engine.php';
require_once __DIR__ . '/../includes/services/StudentService.php';
require_admin();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$student = get_student($id);
if (!$student) { flash('error', 'Student not found.'); redirect('public/students_list.php'); }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_payment') {
            flash('error', 'Please use the Payments tab to record payments.');
            redirect('public/payments.php?student_id=' . $id);
        } elseif ($action === 'log_call') {
            log_call_and_update_state(
                $id, (int)$_POST['batch_id'], current_user()['id'], $_POST['contact_method'], $_POST['outcome'],
                $_POST['offered_batch_id'] ?: null, $_POST['proposed_joining_date'] ?: null,
                $_POST['follow_up_date'] ?: null, $_POST['conversation_note'] ?: null
            );
            flash('success', 'Call logged.');
        } elseif ($action === 'confirm_joining') {
            confirm_joining($id, (int)$_POST['batch_id'], $_POST['entry_date'], current_user()['id']);
            flash('success', 'Joining confirmed. Student is now Active.');
        } elseif ($action === 'add_extension') {
            $override = $_POST['override_amount'] !== '' ? (float)$_POST['override_amount'] : null;
            create_extension($id, (int)$_POST['extension_days'], $_POST['reason'] ?: null, current_user()['id'], $override, $_POST['override_reason'] ?: null);
            flash('success', 'Extension added and invoice generated.');
        } elseif ($action === 'delete_extension') {
            if (!is_super_admin() && current_user()['role_name'] !== 'admin') {
                throw new RuntimeException('You do not have permission to delete extensions.');
            }
            $extId = (int)$_POST['extension_id'];

            $stmt = $pdo->prepare("SELECT * FROM extensions WHERE id = ? AND student_id = ?");
            $stmt->execute([$extId, $id]);
            $ext = $stmt->fetch();
            if (!$ext) throw new RuntimeException("Extension not found.");

            $stmtInv = $pdo->prepare("SELECT id, payment_status FROM invoices WHERE extension_id = ?");
            $stmtInv->execute([$extId]);
            $invoice = $stmtInv->fetch();

            if ($invoice && $invoice['payment_status'] === 'paid') {
                throw new RuntimeException("This extension is already paid and cannot be deleted.");
            }

            $pdo->beginTransaction();
            if ($invoice) {
                $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoice['id']]);
            }
            $pdo->prepare("UPDATE students SET final_expiry_date = DATE_SUB(final_expiry_date, INTERVAL ? DAY) WHERE id = ?")
                ->execute([$ext['extension_days'], $id]);
            $pdo->prepare("DELETE FROM extensions WHERE id = ?")->execute([$extId]);
            $pdo->commit();
            flash('success', 'Extension deleted successfully.');
        } elseif ($action === 'change_photo') {
            if (!is_super_admin()) {
                throw new RuntimeException('Only Super Admin is authorized to change student photos.');
            }

            $photoPath = save_uploaded_student_photo(
                $_POST['cropped_photo_data'] ?? null,
                $_FILES['photo'] ?? null,
                UPLOAD_DIR,
                UPLOAD_URL,
                MAX_PHOTO_SIZE_MB
            );

            if (!$photoPath) {
                throw new RuntimeException('Photo upload failed. No valid image provided.');
            }

            $oldPhotoPath = $student['photo_path'];

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE student_photos SET is_current = 0 WHERE student_id = ?")->execute([$id]);
            $pdo->prepare("INSERT INTO student_photos (student_id, file_path, is_current, changed_by) VALUES (?, ?, 1, ?)")
                ->execute([$id, $photoPath, current_user()['id']]);
            $pdo->prepare("UPDATE students SET photo_path = ?, updated_at = NOW() WHERE id = ?")->execute([$photoPath, $id]);
            log_audit('photo', $id, 'update', ['old_photo' => $oldPhotoPath], ['new_photo' => $photoPath]);
            $pdo->commit();
            flash('success', 'Student photo updated successfully.');
        } elseif ($action === 'pause_course') {
            $informDate = trim($_POST['inform_date'] ?? date('Y-m-d'));
            $readyDate = trim($_POST['ready_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $res = StudentService::pauseCourse($id, $informDate, $readyDate, $reason, (int)current_user()['id']);
            flash('success', "Course paused effective " . format_date($res['effective_stop_date']) . ". {$res['banked_days']} remaining days banked.");
        } elseif ($action === 'resume_course') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $restartDate = trim($_POST['restart_date'] ?? date('Y-m-d'));
            $note = trim($_POST['note'] ?? '');
            $res = StudentService::resumeCourse($id, $batchId, $restartDate, (int)current_user()['id'], $note);
            flash('success', "Course resumed from " . format_date($res['restart_date']) . ". New expiry date: " . format_date($res['new_expiry_date']) . ".");
        } elseif ($action === 'update_ready_date') {
            $readyDate = trim($_POST['ready_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            StudentService::updateResumeReadyDate($id, $readyDate, $reason, (int)current_user()['id']);
            flash('success', 'Resume ready date updated successfully.');
        } elseif ($action === 'mark_completed') {
            StudentService::markCourseCompleted($id, (int)current_user()['id']);
            flash('success', 'Student marked as Course Completed.');
        } elseif ($action === 'reopen_course') {
            StudentService::reopenCourse($id, (int)current_user()['id']);
            flash('success', 'Student status reopened to Active.');
        } elseif ($action === 'update_student_fees') {
            if (!is_admin_or_above()) {
                throw new RuntimeException('Only administrators can update student fees.');
            }
            $newFormFee = max(0.0, (float)($_POST['form_fee_amount'] ?? 500));
            $newJoiningFee = max(0.0, (float)($_POST['joining_fee_amount'] ?? 3500));
            $newSecondPayment = max(0.0, (float)($_POST['second_payment_amount'] ?? 4000));
            $newTotalFee = $newFormFee + $newJoiningFee + $newSecondPayment;
            $feeReason = trim($_POST['fee_update_reason'] ?? '');

            $pdo->prepare("
                UPDATE students 
                SET form_fee_amount = ?,
                    joining_fee_amount = ?,
                    second_payment_amount = ?,
                    total_course_fee = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$newFormFee, $newJoiningFee, $newSecondPayment, $newTotalFee, $id]);

            log_audit('student', $id, 'update_fee_structure', [
                'old_form_fee' => $student['form_fee_amount'],
                'old_joining_fee' => $student['joining_fee_amount'],
                'old_second_payment' => $student['second_payment_amount'],
                'old_total_fee' => $student['total_course_fee'],
            ], [
                'new_form_fee' => $newFormFee,
                'new_joining_fee' => $newJoiningFee,
                'new_second_payment' => $newSecondPayment,
                'new_total_fee' => $newTotalFee,
                'reason' => $feeReason,
                'updated_by' => current_user()['id']
            ]);

            flash('success', "Student fee structure updated: 1st Joining ₹" . number_format($newJoiningFee) . ", 2nd Payment ₹" . number_format($newSecondPayment) . " (Total ₹" . number_format($newTotalFee) . ").");
        }
        redirect('public/student_profile.php?id=' . $id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$progress = get_course_progress($student);
$paymentSummary = get_student_payment_summary($id);
$payments = $pdo->prepare("SELECT * FROM payments WHERE student_id = ? ORDER BY payment_date DESC");
$payments->execute([$id]); $payments = $payments->fetchAll();

$calls = $pdo->prepare("SELECT * FROM call_logs WHERE student_id = ? ORDER BY call_datetime DESC");
$calls->execute([$id]); $calls = $calls->fetchAll();

$extensions = $pdo->prepare("SELECT * FROM extensions WHERE student_id = ? ORDER BY created_at DESC");
$extensions->execute([$id]); $extensions = $extensions->fetchAll();

$queueRows = $pdo->prepare("SELECT wq.*, b.name AS batch_name FROM waiting_queue wq JOIN batches b ON b.id = wq.batch_id WHERE wq.student_id = ? AND wq.state NOT IN ('joined','removed')");
$queueRows->execute([$id]); $queueRows = $queueRows->fetchAll();

$batches = $pdo->query("SELECT * FROM batches WHERE is_active = 1 ORDER BY start_time")->fetchAll();
foreach ($batches as &$b) {
    $b['active_count'] = count_active_students_in_batch((int)$b['id']);
}
unset($b);
$currentRate = get_current_extension_rate();

$pendingDuesList = [];
if (in_array($student['status'], ['joining_confirmed', 'active', 'extended', 'course_completed'])) {
    $joiningFeeAmt = !empty($student['joining_fee_amount']) ? (float)$student['joining_fee_amount'] : (float)get_setting('joining_fee', 3500);
    $secondPaymentAmt = !empty($student['second_payment_amount']) ? (float)$student['second_payment_amount'] : (float)get_setting('second_payment_amount', 4000);
    $dueDays = (int)get_setting('second_payment_due_days', 10);

    $paidByStage = [];
    foreach ($payments as $p) {
        if ($p['status'] === 'success') {
            $paidByStage[$p['stage']] = ($paidByStage[$p['stage']] ?? 0) + $p['amount'];
        }
    }

    $entryDate = $student['entry_date'] ?: $student['expected_joining_date'];

    if (!isset($paidByStage['joining_fee']) || $paidByStage['joining_fee'] < $joiningFeeAmt) {
        $pendingDuesList[] = [
            'amount' => $joiningFeeAmt - ($paidByStage['joining_fee'] ?? 0),
            'due_date' => $entryDate ? date('d-M-Y', strtotime($entryDate)) : 'N/A',
            'label' => '1st Payment (Joining Fee)',
            'stage' => 'joining_fee'
        ];
    }

    if ($student['entry_date']) {
        if (!isset($paidByStage['second_payment']) || $paidByStage['second_payment'] < $secondPaymentAmt) {
            $dueDate = date('d-M-Y', strtotime($student['entry_date'] . " + $dueDays days"));
            $pendingDuesList[] = [
                'amount' => $secondPaymentAmt - ($paidByStage['second_payment'] ?? 0),
                'due_date' => $dueDate,
                'label' => '2nd Payment (Installment)',
                'stage' => 'second_payment'
            ];
        }
    }

    $stmtExt = $pdo->prepare("SELECT SUM(total_amount) as tot FROM invoices WHERE student_id = ? AND payment_status != 'paid'");
    $stmtExt->execute([$id]);
    $extTotal = (float)$stmtExt->fetchColumn();
    if ($extTotal > 0) {
        $pendingDuesList[] = [
            'amount' => $extTotal,
            'due_date' => 'Immediate',
            'label' => 'Extension Fee',
            'stage' => 'extension_fee'
        ];
    }
}

$pageTitle = 'Student Profile';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.profile-sidebar-card {
    margin-bottom: 0;
}
@media (min-width: 961px) {
    .profile-sidebar-card {
        position: sticky;
        top: 80px;
    }
}
.profile-photo-img {
    width: 100%;
    max-height: 280px;
    object-fit: cover;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    margin-bottom: 12px;
    display: block;
}
.profile-meta-list {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border-light);
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 13px;
}
.profile-meta-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}
.profile-meta-row span:first-child {
    color: var(--muted);
    font-weight: 500;
    flex-shrink: 0;
}
.profile-meta-row span:last-child {
    color: var(--text);
    font-weight: 600;
    text-align: right;
    word-break: break-word;
}
.timeline-stat-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 12px;
}
.timeline-stat-lbl {
    font-size: 11.5px;
    color: var(--muted);
    font-weight: 500;
}
.timeline-stat-val {
    font-size: 14.5px;
    font-weight: 700;
    color: var(--text);
    margin-top: 2px;
    font-variant-numeric: tabular-nums;
}
.state-callout {
    margin-top: 16px;
    padding: 14px 16px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.state-callout-warning {
    background: var(--warning-bg);
    border-color: var(--warning-border);
    align-items: flex-start;
}
.state-callout-success {
    background: var(--success-bg);
    border-color: var(--success-border);
}
.state-callout-neutral {
    background: var(--bg);
    border-color: var(--border);
}
.dues-callout {
    margin-bottom: 16px;
    padding: 12px 16px;
    background: var(--warning-bg);
    border: 1px solid var(--warning-border);
    border-radius: var(--radius);
}
.info-callout {
    background: var(--primary-light);
    border-left: 3px solid var(--primary);
    padding: 10px 14px;
    border-radius: var(--radius);
    font-size: 12.5px;
    color: var(--primary-hover);
    margin-bottom: 14px;
}
.crop-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.78);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1300;
    padding: 16px;
}
.crop-modal {
    background: var(--card-bg);
    padding: 22px;
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 420px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-md);
    text-align: center;
}
.canvas-wrapper {
    position: relative;
    width: 300px;
    height: 300px;
    margin: 14px auto;
    background: #0F172A;
    overflow: hidden;
    border-radius: var(--radius-lg);
    cursor: grab;
    user-select: none;
    touch-action: none;
}
.canvas-wrapper:active { cursor: grabbing; }
.canvas-overlay-frame {
    position: absolute;
    top: 24px;
    left: 52px;
    width: 195px;
    height: 251px;
    border: 2px dashed var(--primary);
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.58);
    pointer-events: none;
    border-radius: var(--radius-sm);
}
.zoom-controls {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin: 10px 0 18px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-body);
}
.zoom-controls input[type="range"] {
    width: 170px;
    cursor: pointer;
    accent-color: var(--primary);
}
</style>

<?php if ($error): ?>
    <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<div class="profile-layout">
    <!-- LEFT COLUMN: Profile Summary -->
    <div class="card profile-sidebar-card">
        <?php if ($student['photo_path']): ?>
            <img src="<?= e(url($student['photo_path'])) ?>" alt="Student Photo" class="profile-photo-img">
        <?php endif; ?>

        <?php if (is_super_admin()): ?>
            <details style="margin-bottom: 14px; font-size: 12.5px;">
                <summary style="cursor: pointer; color: var(--primary); font-weight: 600;">Change Photo (Super Admin)</summary>
                <form method="post" enctype="multipart/form-data" style="margin-top: 8px; background: var(--bg); padding: 10px; border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_photo">
                    <input type="hidden" name="cropped_photo_data" id="croppedPhotoData">
                    <input type="file" name="photo" id="photoInput" accept="image/*" style="font-size: 12px; padding: 5px 8px; height: auto; margin-bottom: 8px;">

                    <div id="previewBox" style="display: none; width: 98px; height: 126px; border: 2px dashed var(--primary); border-radius: var(--radius); align-items: center; justify-content: center; overflow: hidden; margin: 0 auto 8px; background: #fff;">
                        <img id="previewImg" src="" alt="Cropped Preview" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>

                    <button type="submit" class="btn btn-sm btn-block">Upload New Photo</button>
                </form>
            </details>

            <!-- Photo Cropper Modal -->
            <div class="crop-modal-overlay" id="cropModal">
                <div class="crop-modal">
                    <h3 style="margin: 0 0 4px; color: var(--text); font-size: 16px;">Admission Form Photo Crop</h3>
                    <p class="muted" style="font-size: 12.5px; margin: 0 0 10px;">Adjust photo inside the 35mm x 45mm frame.</p>

                    <div class="canvas-wrapper" id="canvasWrapper">
                        <canvas id="cropCanvas" width="300" height="300"></canvas>
                        <div class="canvas-overlay-frame"></div>
                    </div>

                    <div class="zoom-controls">
                        <span>Zoom:</span>
                        <input type="range" id="zoomSlider" min="0.2" max="3" step="0.05" value="1">
                    </div>

                    <div style="display: flex; justify-content: space-between; gap: 10px;">
                        <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="closeCropModal()">Cancel</button>
                        <button type="button" class="btn" style="flex: 1;" onclick="saveCroppedImage()">Apply Crop</button>
                    </div>
                </div>
            </div>

            <script>
            const photoInput = document.getElementById('photoInput');
            const cropModal = document.getElementById('cropModal');
            const cropCanvas = document.getElementById('cropCanvas');
            const ctx = cropCanvas.getContext('2d');
            const zoomSlider = document.getElementById('zoomSlider');
            const canvasWrapper = document.getElementById('canvasWrapper');
            const croppedPhotoData = document.getElementById('croppedPhotoData');
            const previewBox = document.getElementById('previewBox');
            const previewImg = document.getElementById('previewImg');

            let img = new Image();
            let scale = 1;
            let posX = 0;
            let posY = 0;
            let isDragging = false;
            let startX = 0;
            let startY = 0;

            const FRAME_W = 195;
            const FRAME_H = 251;
            const FRAME_X = 52;
            const FRAME_Y = 24;

            photoInput.addEventListener('change', function(e) {
                const files = e.target.files;
                if (files && files.length > 0) {
                    const file = files[0];
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        img = new Image();
                        img.onload = function() {
                            const minScale = Math.max(FRAME_W / img.width, FRAME_H / img.height);
                            scale = minScale;
                            zoomSlider.min = (minScale * 0.5).toFixed(2);
                            zoomSlider.max = (minScale * 4.0).toFixed(2);
                            zoomSlider.value = minScale;

                            posX = FRAME_X + (FRAME_W - img.width * scale) / 2;
                            posY = FRAME_Y + (FRAME_H - img.height * scale) / 2;

                            cropModal.style.display = 'flex';
                            draw();
                        };
                        img.src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });

            function draw() {
                ctx.clearRect(0, 0, 300, 300);
                ctx.drawImage(img, posX, posY, img.width * scale, img.height * scale);
            }

            zoomSlider.addEventListener('input', function() {
                const newScale = parseFloat(this.value);
                const centerX = FRAME_X + FRAME_W / 2;
                const centerY = FRAME_Y + FRAME_H / 2;
                posX = centerX - (centerX - posX) * (newScale / scale);
                posY = centerY - (centerY - posY) * (newScale / scale);
                scale = newScale;
                draw();
            });

            canvasWrapper.addEventListener('mousedown', function(e) { isDragging = true; startX = e.clientX - posX; startY = e.clientY - posY; });
            window.addEventListener('mousemove', function(e) { if (isDragging) { posX = e.clientX - startX; posY = e.clientY - startY; draw(); } });
            window.addEventListener('mouseup', function() { isDragging = false; });
            canvasWrapper.addEventListener('touchstart', function(e) { if (e.touches.length === 1) { isDragging = true; startX = e.touches[0].clientX - posX; startY = e.touches[0].clientY - posY; } });
            window.addEventListener('touchmove', function(e) { if (isDragging && e.touches.length === 1) { posX = e.touches[0].clientX - startX; posY = e.touches[0].clientY - startY; draw(); } });
            window.addEventListener('touchend', function() { isDragging = false; });

            function closeCropModal() { cropModal.style.display = 'none'; }

            function saveCroppedImage() {
                const sourceX = (FRAME_X - posX) / scale;
                const sourceY = (FRAME_Y - posY) / scale;
                const sourceW = FRAME_W / scale;
                const sourceH = FRAME_H / scale;

                const outCanvas = document.createElement('canvas');
                outCanvas.width = 700;
                outCanvas.height = 900;
                const outCtx = outCanvas.getContext('2d');

                outCtx.fillStyle = '#ffffff';
                outCtx.fillRect(0, 0, 700, 900);
                outCtx.drawImage(img, sourceX, sourceY, sourceW, sourceH, 0, 0, 700, 900);

                const base64Data = outCanvas.toDataURL('image/jpeg', 0.92);
                croppedPhotoData.value = base64Data;
                previewImg.src = base64Data;
                previewBox.style.display = 'flex';
                closeCropModal();
            }
            </script>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 6px;">
            <div>
                <h2 style="font-size: 17px; margin: 0 0 2px;"><?= e($student['name']) ?></h2>
                <div class="muted" style="font-size: 12.5px;">
                    Reg: <code><?= e($student['registration_no'] ?? '-') ?></code> &middot; Form: <code><?= e($student['form_no']) ?></code>
                </div>
            </div>
            <a href="<?= url('public/edit_student.php?id=' . $id) ?>" class="btn btn-sm btn-secondary" title="Edit Student Details">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <span>Edit</span>
            </a>
        </div>

        <div style="margin: 10px 0 14px;">
            <?= render_status_badge($student['status']) ?>
        </div>

        <a href="<?= url('public/print_form.php?id=' . (int)$student['id']) ?>" target="_blank" class="btn btn-sm btn-block">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span>Print Official Admission Form</span>
        </a>

        <div class="profile-meta-list">
            <div class="profile-meta-row"><span>Mobile</span><span><?= e($student['student_mobile']) ?></span></div>
            <div class="profile-meta-row"><span>Father / Husband</span><span><?= e($student['husband_father_mobile'] ?: '-') ?></span></div>
            <div class="profile-meta-row"><span>Home Mobile</span><span><?= e($student['home_mobile'] ?: '-') ?></span></div>
            <div class="profile-meta-row"><span>Knows Sewing</span><span><?= $student['knows_sewing'] ? 'Yes' : 'No' ?></span></div>
            <?php if ($student['previous_sewing_work']): ?>
                <div class="profile-meta-row"><span>Experience</span><span><?= e($student['previous_sewing_work']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($student['hear_about_us'])): ?>
                <div class="profile-meta-row"><span>Source</span><span><?= e($student['hear_about_us']) ?></span></div>
            <?php endif; ?>
            <div style="padding-top: 6px; border-top: 1px solid var(--border-light);">
                <div class="muted" style="font-size: 12px; margin-bottom: 2px;">Address</div>
                <div style="color: var(--text-body); line-height: 1.45;"><?= e($student['address']) ?></div>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Timeline, Payments, Calls, Extensions -->
    <div style="display: flex; flex-direction: column; gap: 20px; min-width: 0;">

        <!-- Course Timeline Card -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header-bar" style="margin-bottom: 14px;">
                <h2>Course Timeline</h2>
            </div>

            <div class="grid grid-3">
                <div class="timeline-stat-box">
                    <div class="timeline-stat-lbl">Entry Date</div>
                    <div class="timeline-stat-val"><?= format_date($student['entry_date']) ?></div>
                </div>
                <div class="timeline-stat-box">
                    <div class="timeline-stat-lbl">Last Class Day</div>
                    <div class="timeline-stat-val" style="color: var(--success);"><?= format_date($student['final_expiry_date']) ?></div>
                </div>
                <div class="timeline-stat-box">
                    <div class="timeline-stat-lbl">Expected Joining</div>
                    <div class="timeline-stat-val"><?= format_date($student['expected_joining_date']) ?></div>
                </div>
            </div>

            <?php if ($student['entry_date']): ?>
            <div class="grid grid-3" style="margin-top: 12px;">
                <div class="timeline-stat-box">
                    <div class="timeline-stat-lbl">Days Completed</div>
                    <div class="timeline-stat-val"><?= (int)$progress['days_completed'] ?></div>
                </div>
                <div class="timeline-stat-box">
                    <div class="timeline-stat-lbl">Days Remaining</div>
                    <div class="timeline-stat-val"><?= (int)$progress['days_remaining'] ?></div>
                </div>
                <div class="timeline-stat-box">
                    <div class="timeline-stat-lbl">Course Progress</div>
                    <div class="timeline-stat-val"><?= ucfirst($progress['status']) ?></div>
                </div>
            </div>

            <?php if (!empty($student['is_paused']) || $student['status'] === 'paused'): ?>
                <div class="state-callout state-callout-warning">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span class="badge badge-amber">ON HOLD / PAUSED</span>
                            <strong style="font-size: 14px; color: #92400E;">Course is currently paused</strong>
                        </div>
                        <div style="margin-top: 8px; font-size: 13px; color: #78350F; line-height: 1.65;">
                            <div>&bull; <strong>Effective Stop Date:</strong> <?= format_date($student['paused_at']) ?> (day after notice)</div>
                            <div>&bull; <strong>Banked Remaining Days:</strong> <strong><?= (int)$student['paused_remaining_days'] ?> days preserved</strong></div>
                            <div>&bull; <strong>Resume Ready Date:</strong> <?= !empty($student['resume_ready_date']) ? '<strong>From ' . format_date($student['resume_ready_date']) . '</strong>' : '<span class="muted">Not confirmed yet</span>' ?></div>
                            <?php if (!empty($student['pause_reason'])): ?>
                                <div>&bull; <strong>Reason:</strong> <?= e($student['pause_reason']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="action-group" style="flex-direction: column; align-items: flex-end;">
                        <button type="button" class="btn btn-sm btn-success" onclick="openResumeModal()">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            <span>Resume Course</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="openReadyDateModal()">
                            Edit Ready Date
                        </button>
                    </div>
                </div>

            <?php elseif (in_array($student['status'], ['active', 'extended'])): ?>
                <div class="state-callout state-callout-neutral">
                    <div>
                        <strong style="color: var(--text); font-size: 13.5px;">Course Pause / Hold</strong>
                        <div class="muted" style="font-size: 12.5px; margin-top: 2px;">Temporarily pause this course and bank remaining days for future resumption.</div>
                    </div>
                    <button class="btn btn-sm btn-secondary" type="button" onclick="openPauseModal()" style="color: var(--warning-hover); border-color: var(--warning-border);">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                        <span>Pause Course</span>
                    </button>
                </div>

            <?php elseif ($student['status'] === 'course_completed'): ?>
                <div class="state-callout state-callout-success">
                    <div style="color: #166534; font-weight: 600; font-size: 13.5px;">
                        This student has completed their course training.
                    </div>
                    <form method="post" onsubmit="return confirm('Reopen this student status back to Active?');" style="margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reopen_course">
                        <button class="btn btn-sm btn-secondary" type="submit">Reopen Course</button>
                    </form>
                </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$student['entry_date'] && $queueRows): ?>
            <div class="section-title">Confirm Joining</div>
            <form method="post" class="row3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_joining">
                <div>
                    <label>Batch</label>
                    <select name="batch_id" required>
                        <?php foreach ($queueRows as $q): ?><option value="<?= $q['batch_id'] ?>"><?= e($q['batch_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Entry Date</label>
                    <input type="date" name="entry_date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-actions"><button class="btn" type="submit">Confirm Joining</button></div>
            </form>
            <?php endif; ?>

            <?php if ($student['status'] === 'draft'): ?>
            <div class="flash flash-info" style="margin: 16px 0 0;">
                This form is a draft. Record the ₹500 form fee from the Payments page to confirm it and assign a Registration No.
            </div>
            <?php endif; ?>
        </div>

        <!-- Payments Card -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header-bar">
                <div>
                    <h2>Payments</h2>
                    <small class="muted">Fee stages collected and pending dues for this student.</small>
                </div>
                <div class="action-group">
                    <?php if (is_admin_or_above()): ?>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('editFeeModal').style.display='flex';" title="Edit Student Fee Structure">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <span>Change Fees (ફી બદલો)</span>
                    </button>
                    <?php endif; ?>
                    <?php foreach (['form_fee'=>'Form','joining_fee'=>'Joining','second_payment'=>'2nd Pay','extension_fee'=>'Extensions'] as $k=>$label): ?>
                        <span class="badge badge-gray"><?= $label ?>: ₹<?= number_format($paymentSummary[$k]['total'] ?? 0, 0) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Student Applied Fee Structure Summary Box -->
            <?php
            $curStFormFee = !empty($student['form_fee_amount']) ? (float)$student['form_fee_amount'] : (float)get_setting('form_fee', 500);
            $curStJoiningFee = !empty($student['joining_fee_amount']) ? (float)$student['joining_fee_amount'] : (float)get_setting('joining_fee', 3500);
            $curStSecondPay = !empty($student['second_payment_amount']) ? (float)$student['second_payment_amount'] : (float)get_setting('second_payment_amount', 4000);
            $curStTotalCourse = !empty($student['total_course_fee']) ? (float)$student['total_course_fee'] : ($curStFormFee + $curStJoiningFee + $curStSecondPay);
            ?>
            <div style="background: #F8FAFC; border: 1px solid var(--border-light); border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap; font-size: 12.5px;">
                    <span style="font-weight: 700; color: var(--text);">Applied Fees:</span>
                    <span>Form: <strong>₹<?= number_format($curStFormFee) ?></strong></span>
                    <span>1st Joining: <strong style="color: var(--primary);">₹<?= number_format($curStJoiningFee) ?></strong></span>
                    <span>2nd Payment: <strong style="color: var(--primary);">₹<?= number_format($curStSecondPay) ?></strong></span>
                    <span>Total Course Fee: <strong style="color: var(--success); font-size: 13.5px;">₹<?= number_format($curStTotalCourse) ?></strong></span>
                </div>
                <?php if (is_admin_or_above()): ?>
                <button type="button" class="btn btn-sm btn-secondary" style="padding: 3px 8px; font-size: 11.5px;" onclick="document.getElementById('editFeeModal').style.display='flex';">Edit</button>
                <?php endif; ?>
            </div>

            <!-- Modal: Edit Student Fee Structure -->
            <div id="editFeeModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.5); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:16px;">
                <div style="background:#fff; border-radius:12px; width:100%; max-width:440px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); overflow:hidden; border:1px solid #e2e8f0;">
                    <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
                        <h3 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">Change Student Fee Structure</h3>
                        <button type="button" onclick="document.getElementById('editFeeModal').style.display='none';" style="background:none; border:none; font-size:20px; line-height:1; cursor:pointer; color:#64748b;">&times;</button>
                    </div>
                    <form method="post" action="" style="padding:18px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_student_fees">

                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:12.5px; font-weight:600; margin-bottom:4px; color:#334155;">1. Form Fee (₹)</label>
                            <input type="number" step="0.01" min="0" name="form_fee_amount" id="fee_modal_form" class="form-control" style="width:100%; font-size:13px;" required value="<?= e($curStFormFee) ?>" oninput="calcModalTotalFee()">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:12.5px; font-weight:600; margin-bottom:4px; color:#334155;">2. 1st Joining Fee (₹)</label>
                            <input type="number" step="0.01" min="0" name="joining_fee_amount" id="fee_modal_joining" class="form-control" style="width:100%; font-size:13px;" required value="<?= e($curStJoiningFee) ?>" oninput="calcModalTotalFee()">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:12.5px; font-weight:600; margin-bottom:4px; color:#334155;">3. 2nd Payment / Final Installment (₹)</label>
                            <input type="number" step="0.01" min="0" name="second_payment_amount" id="fee_modal_second" class="form-control" style="width:100%; font-size:13px;" required value="<?= e($curStSecondPay) ?>" oninput="calcModalTotalFee()">
                        </div>

                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:12.5px; font-weight:700; color:#1e3a8a;">New Total Course Fee:</span>
                            <span id="fee_modal_total_display" style="font-size:15px; font-weight:800; color:#1e3a8a;">₹0</span>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#64748b;">Reason for Fee Change (Optional)</label>
                            <input type="text" name="fee_update_reason" class="form-control" style="width:100%; font-size:12.5px;" placeholder="e.g. Special concession, revised package">
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:8px;">
                            <button type="button" onclick="document.getElementById('editFeeModal').style.display='none';" class="btn btn-sm btn-secondary">Cancel</button>
                            <button type="submit" class="btn btn-sm">Save Fee Structure</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>
            function calcModalTotalFee() {
                var f = parseFloat(document.getElementById('fee_modal_form').value) || 0;
                var j = parseFloat(document.getElementById('fee_modal_joining').value) || 0;
                var s = parseFloat(document.getElementById('fee_modal_second').value) || 0;
                document.getElementById('fee_modal_total_display').textContent = '₹' + (f + j + s).toLocaleString('en-IN');
            }
            calcModalTotalFee();
            </script>

            <?php if (!empty($pendingDuesList)): ?>
            <div class="dues-callout">
                <div style="font-weight: 700; color: #92400E; font-size: 13px; margin-bottom: 6px;">Outstanding Dues</div>
                <ul style="margin: 0; padding-left: 18px; color: #78350F; font-size: 13px; line-height: 1.6;">
                    <?php foreach ($pendingDuesList as $due):
                        $isOverdue = (strtotime($due['due_date']) < time() && $due['due_date'] !== 'N/A' && $due['due_date'] !== 'Immediate');
                    ?>
                        <li>
                            <strong><?= e($due['label']) ?></strong>: ₹<?= number_format($due['amount'], 2) ?>
                            <span style="<?= $isOverdue ? 'color: var(--danger); font-weight: 700;' : '' ?>">
                                (Due Date: <?= e($due['due_date']) ?><?= $isOverdue ? ' - Overdue' : '' ?>)
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Stage</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Receipt</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td style="white-space: nowrap; font-variant-numeric: tabular-nums;"><?= format_date($p['payment_date']) ?></td>
                            <td><?= ucwords(str_replace('_',' ',$p['stage'])) ?></td>
                            <td style="font-weight: 600; font-variant-numeric: tabular-nums;">₹<?= number_format($p['amount'],2) ?></td>
                            <td><span class="badge badge-gray"><?= strtoupper(e($p['method'])) ?></span></td>
                            <td><code><?= e($p['receipt_no']) ?></code></td>
                            <td class="text-right">
                                <a href="<?= url('public/receipt.php?id=' . (int)$p['id']) ?>" class="btn btn-sm btn-secondary" target="_blank">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    <span>Receipt</span>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$payments): ?>
                        <tr><td colspan="6" class="muted" style="text-align: center; padding: 20px;">No payments yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="state-callout state-callout-neutral">
                <div>
                    <div style="font-weight: 600; color: var(--text); font-size: 13px;">Official Payments Module</div>
                    <div class="muted" style="font-size: 12px; margin-top: 2px;">
                        All new fee payments are processed with PIN verification in the Payments module.
                    </div>
                </div>
                <a href="<?= url('public/payments.php?student_id=' . (int)$id) ?>" class="btn btn-sm">
                    <span>Collect Payment</span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>
        </div>

        <!-- Call / Follow-up Log Card -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header-bar" style="margin-bottom: 14px;">
                <h2>Call / Follow-up Log</h2>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Outcome</th>
                            <th>Proposed Date</th>
                            <th>Follow-up</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calls as $c): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?= format_date($c['call_datetime']) ?></td>
                            <td><?= ucfirst($c['contact_method']) ?></td>
                            <td><?= ucwords(str_replace('_',' ',$c['outcome'])) ?></td>
                            <td style="white-space: nowrap;"><?= format_date($c['proposed_joining_date']) ?></td>
                            <td style="white-space: nowrap;"><?= format_date($c['follow_up_date']) ?></td>
                            <td><?= e($c['conversation_note']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$calls): ?>
                        <tr><td colspan="6" class="muted" style="text-align: center; padding: 20px;">No calls logged yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($queueRows): ?>
            <div class="section-title">Log New Follow-up Call</div>
            <form method="post" class="row3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="log_call">
                <div>
                    <label>Batch</label>
                    <select name="batch_id" required>
                        <?php foreach ($queueRows as $q): ?><option value="<?= $q['batch_id'] ?>"><?= e($q['batch_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Contact Method</label>
                    <select name="contact_method"><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option><option value="other">Other</option></select>
                </div>
                <div>
                    <label>Outcome</label>
                    <select name="outcome" required>
                        <option value="confirmed">Confirmed</option>
                        <option value="call_later">Call Later</option>
                        <option value="specific_date">Specific Future Date</option>
                        <option value="cannot_join">Cannot Join</option>
                        <option value="no_response">No Response</option>
                    </select>
                </div>
                <div>
                    <label>Proposed Joining Date</label>
                    <input type="date" name="proposed_joining_date">
                </div>
                <div>
                    <label>Follow-up Date</label>
                    <input type="date" name="follow_up_date">
                </div>
                <div class="col-full">
                    <label>Conversation Note</label>
                    <textarea name="conversation_note" rows="2"></textarea>
                </div>
                <div class="form-actions col-full"><button class="btn" type="submit">Save Call</button></div>
            </form>
            <?php endif; ?>
        </div>

        <!-- Extensions Card -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header-bar" style="margin-bottom: 14px;">
                <h2>Course Extensions</h2>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Days</th>
                            <th>Old Expiry</th>
                            <th>New Expiry</th>
                            <th>Calculated</th>
                            <th>Final</th>
                            <th>Approved</th>
                            <th>Invoice</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($extensions as $ex):
                            $stmtInv = $pdo->prepare("SELECT payment_status FROM invoices WHERE extension_id = ?");
                            $stmtInv->execute([$ex['id']]);
                            $invStatus = $stmtInv->fetchColumn();
                        ?>
                        <tr>
                            <td><strong>+<?= (int)$ex['extension_days'] ?></strong></td>
                            <td style="white-space: nowrap;"><?= format_date($ex['old_expiry_date']) ?></td>
                            <td style="white-space: nowrap;"><?= format_date($ex['new_expiry_date']) ?></td>
                            <td style="font-variant-numeric: tabular-nums;">₹<?= number_format($ex['calculated_amount'],2) ?></td>
                            <td style="font-variant-numeric: tabular-nums;">
                                ₹<?= number_format($ex['final_amount'],2) ?>
                                <?= $ex['is_manual_override'] ? ' <span class="badge badge-amber">override</span>' : '' ?>
                            </td>
                            <td style="white-space: nowrap;"><?= format_date($ex['approved_at']) ?></td>
                            <td>
                                <a href="<?= url('public/invoice.php?extension_id=' . (int)$ex['id']) ?>" class="btn btn-sm btn-secondary" target="_blank">Invoice</a>
                            </td>
                            <td class="text-right">
                                <?php if ($invStatus !== 'paid'): ?>
                                    <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Delete this extension and revert the student expiry date?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_extension">
                                        <input type="hidden" name="extension_id" value="<?= $ex['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger-ghost">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-green">Paid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$extensions): ?>
                        <tr><td colspan="8" class="muted" style="text-align: center; padding: 20px;">No extensions yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($student['entry_date']): ?>
            <div class="section-title">Add Extension</div>
            <form method="post" class="row3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_extension">
                <div>
                    <label>Extension Days *</label>
                    <input type="number" name="extension_days" min="1" required>
                </div>
                <div>
                    <label>Reason</label>
                    <input type="text" name="reason">
                </div>
                <div>
                    <label>Current Rate</label>
                    <input type="text" disabled value="₹<?= $currentRate ? number_format($currentRate['per_day_charge'],2) : 'not set' ?>/day">
                </div>
                <div>
                    <label>Manual Override Amount (optional)</label>
                    <input type="number" step="0.01" name="override_amount">
                </div>
                <div class="col-full">
                    <label>Override Reason (if amount overridden)</label>
                    <input type="text" name="override_reason">
                </div>
                <div class="form-actions col-full"><button class="btn" type="submit">Add Extension &amp; Generate Invoice</button></div>
            </form>
            <?php else: ?>
            <p class="muted" style="margin: 14px 0 0;">Student must have joined (Entry Date set) before an extension can be added.</p>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ================= MODALS FOR COURSE PAUSE / RESUME ================= -->

<!-- 1. Pause Course Modal -->
<div class="custom-modal-overlay" id="pauseModal">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <h3>Pause Course (Put On Hold)</h3>
            <button type="button" class="custom-modal-close" onclick="closePauseModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pause_course">

            <div class="custom-modal-body">
                <div class="info-callout">
                    <strong>Policy:</strong> The notice date counts as attended. The pause takes effect from the <strong>following day</strong>, and remaining days are safely banked.
                </div>

                <div class="form-group">
                    <label for="pause_inform_date">Notice Date (Inform Date) *</label>
                    <input type="date" name="inform_date" id="pause_inform_date" value="<?= date('Y-m-d') ?>" required onchange="calculatePausePreview()">
                </div>

                <div style="background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 12px; margin-bottom: 14px; font-size: 13px;">
                    <div>Effective Stop Date: <strong id="preview_stop_date" style="color: var(--warning-hover);"><?= date('Y-m-d', strtotime('+1 day')) ?></strong></div>
                    <div>Days to be Banked: <strong id="preview_banked_days" style="color: var(--success);">Calculating...</strong></div>
                </div>

                <div class="form-group">
                    <label>Expected Ready Date (Optional)</label>
                    <input type="date" name="ready_date">
                    <small class="muted" style="display:block; margin-top: 3px;">Date from which the student expects to resume classes.</small>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label>Reason for Pause</label>
                    <input type="text" name="reason" placeholder="e.g. Health issue / Family event / Out of station">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePauseModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm Course Pause</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Resume Course Modal -->
<div class="custom-modal-overlay" id="resumeModal">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <h3>Resume Course</h3>
            <button type="button" class="custom-modal-close" onclick="closeResumeModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resume_course">

            <div class="custom-modal-body">
                <div style="background: var(--success-bg); border: 1px solid var(--success-border); border-radius: var(--radius); padding: 10px 14px; margin-bottom: 14px; font-size: 13px; color: #166534;">
                    Banked Days Available: <strong><?= (int)($student['paused_remaining_days'] ?? 0) ?> days</strong> will be restored.
                </div>

                <div class="form-group">
                    <label>Select Batch *</label>
                    <select name="batch_id" required>
                        <?php foreach ($batches as $b):
                            $cap = (int)$b['capacity'];
                            $occ = (int)($b['active_count'] ?? 0);
                            $avail = max(0, $cap - $occ);
                            $isCurrent = ((int)$student['joined_batch_id'] === (int)$b['id']);
                        ?>
                            <option value="<?= $b['id'] ?>" <?= $isCurrent ? 'selected' : '' ?>>
                                <?= e($b['name']) ?> (<?= $occ ?>/<?= $cap ?> occupied <?= $avail > 0 ? "- {$avail} open" : "- Full" ?>) <?= $isCurrent ? '[Previous Batch]' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="resume_restart_date">Restart Date *</label>
                    <input type="date" name="restart_date" id="resume_restart_date" value="<?= !empty($student['resume_ready_date']) && $student['resume_ready_date'] >= date('Y-m-d') ? $student['resume_ready_date'] : date('Y-m-d') ?>" required onchange="calculateResumePreview()">
                </div>

                <div style="background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 12px; margin-bottom: 14px; font-size: 13px;">
                    <div>New Expiry Date: <strong id="preview_new_expiry" style="color: var(--success);">Calculating...</strong></div>
                    <div class="muted" style="font-size: 11.5px; margin-top: 2px;">(Restart Date + <?= max(0, (int)($student['paused_remaining_days'] ?? 0) - 1) ?> days)</div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label>Note (Optional)</label>
                    <input type="text" name="note" placeholder="e.g. Batch timing changed on request">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeResumeModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Confirm Resume Course</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Edit Ready Date Modal -->
<div class="custom-modal-overlay" id="updateReadyDateModal">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <h3>Edit Resume Ready Date</h3>
            <button type="button" class="custom-modal-close" onclick="closeReadyDateModal()" aria-label="Close">&times;</button>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_ready_date">

            <div class="custom-modal-body">
                <div class="form-group">
                    <label>Ready Date *</label>
                    <input type="date" name="ready_date" value="<?= e($student['resume_ready_date'] ?? '') ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label>Reason / Note</label>
                    <input type="text" name="reason" value="<?= e($student['pause_reason'] ?? '') ?>" placeholder="e.g. Confirmed via phone call">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeReadyDateModal()">Cancel</button>
                <button type="submit" class="btn">Update Date</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPauseModal() {
    document.getElementById('pauseModal').classList.add('active');
    calculatePausePreview();
}
function closePauseModal() {
    document.getElementById('pauseModal').classList.remove('active');
}

function openResumeModal() {
    document.getElementById('resumeModal').classList.add('active');
    calculateResumePreview();
}
function closeResumeModal() {
    document.getElementById('resumeModal').classList.remove('active');
}

function openReadyDateModal() {
    document.getElementById('updateReadyDateModal').classList.add('active');
}
function closeReadyDateModal() {
    document.getElementById('updateReadyDateModal').classList.remove('active');
}

window.addEventListener('click', function(event) {
    if (event.target === document.getElementById('pauseModal')) closePauseModal();
    if (event.target === document.getElementById('resumeModal')) closeResumeModal();
    if (event.target === document.getElementById('updateReadyDateModal')) closeReadyDateModal();
});

function calculatePausePreview() {
    const informInput = document.getElementById('pause_inform_date').value;
    if (!informInput) return;
    const informDate = new Date(informInput + 'T00:00:00');
    const stopDate = new Date(informDate);
    stopDate.setDate(stopDate.getDate() + 1);

    const yyyy = stopDate.getFullYear();
    const mm = String(stopDate.getMonth() + 1).padStart(2, '0');
    const dd = String(stopDate.getDate()).padStart(2, '0');
    const stopStr = `${yyyy}-${mm}-${dd}`;
    document.getElementById('preview_stop_date').innerText = stopStr;

    const expiryStr = '<?= $student['final_expiry_date'] ?: $student['base_expiry_date'] ?>';
    if (expiryStr) {
        const expDate = new Date(expiryStr + 'T00:00:00');
        const timeDiff = expDate.getTime() - stopDate.getTime();
        const dayDiff = Math.round(timeDiff / (1000 * 3600 * 24)) + 1;
        const banked = dayDiff > 0 ? dayDiff : 0;
        document.getElementById('preview_banked_days').innerText = banked + ' days';
    }
}

function calculateResumePreview() {
    const restartInput = document.getElementById('resume_restart_date').value;
    const banked = <?= (int)($student['paused_remaining_days'] ?? 0) ?>;
    if (!restartInput || banked <= 0) return;
    const rDate = new Date(restartInput + 'T00:00:00');
    rDate.setDate(rDate.getDate() + (banked - 1));
    const yyyy = rDate.getFullYear();
    const mm = String(rDate.getMonth() + 1).padStart(2, '0');
    const dd = String(rDate.getDate()).padStart(2, '0');
    document.getElementById('preview_new_expiry').innerText = `${yyyy}-${mm}-${dd}`;
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
