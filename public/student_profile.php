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
            flash('error', 'પેમેન્ટ ઉમેરવા માટે ફક્ત Payments ટેબનો જ ઉપયોગ કરો.');
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
                throw new RuntimeException("આ એક્સટેન્શનનું પેમેન્ટ થઇ ગયું છે, તેથી તેને ડીલીટ કરી શકાશે નહિ.");
            }
            
            $pdo->beginTransaction();
            if ($invoice) {
                $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoice['id']]);
            }
            $pdo->prepare("UPDATE students SET final_expiry_date = DATE_SUB(final_expiry_date, INTERVAL ? DAY) WHERE id = ?")
                ->execute([$ext['extension_days'], $id]);
            $pdo->prepare("DELETE FROM extensions WHERE id = ?")->execute([$extId]);
            $pdo->commit();
            flash('success', 'વધારાના દિવસો (Extension) સફળતાપૂર્વક ડીલીટ કરી દેવામાં આવ્યા છે.');
        } elseif ($action === 'change_photo') {
            if (!is_super_admin()) {
                throw new RuntimeException('Only Super Admin is authorized to change student photos.');
            }
            
            $photoPath = null;
            if (!empty($_POST['cropped_photo_data'])) {
                $data = $_POST['cropped_photo_data'];
                if (preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/', $data, $m)) {
                    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                    $imageData = base64_decode(substr($data, strpos($data, ',') + 1));
                    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                    $filename = uniqid('photo_', true) . '.' . $ext;
                    file_put_contents(UPLOAD_DIR . $filename, $imageData);
                    $photoPath = UPLOAD_URL . $filename;
                }
            }
            
            if (!$photoPath && !empty($_FILES['photo']['name'])) {
                if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Please choose a valid photo to upload.');
                }
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
                    throw new RuntimeException('Photo must be JPG, PNG, or WEBP.');
                }
                if ($_FILES['photo']['size'] > MAX_PHOTO_SIZE_MB * 1024 * 1024) {
                    throw new RuntimeException('Photo must be under ' . MAX_PHOTO_SIZE_MB . 'MB.');
                }
                if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                $filename = uniqid('photo_', true) . '.' . $ext;
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $filename)) {
                    throw new RuntimeException('Could not save uploaded photo.');
                }
                $photoPath = UPLOAD_URL . $filename;
            }
            
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
            flash('success', "વિદ્યાર્થીનો કોર્સ તારીખ " . format_date($res['effective_stop_date']) . " થી મોકૂફ (Pause) કરવામાં આવ્યો છે અને {$res['banked_days']} દિવસો જમા રાખવામાં આવ્યા છે.");
        } elseif ($action === 'resume_course') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $restartDate = trim($_POST['restart_date'] ?? date('Y-m-d'));
            $note = trim($_POST['note'] ?? '');
            $res = StudentService::resumeCourse($id, $batchId, $restartDate, (int)current_user()['id'], $note);
            flash('success', "વિદ્યાર્થીનો કોર્સ તારીખ " . format_date($res['restart_date']) . " થી ફરી શરૂ કરવામાં આવ્યો છે. નવી એક્સપાયરી તારીખ: " . format_date($res['new_expiry_date']) . ".");
        } elseif ($action === 'update_ready_date') {
            $readyDate = trim($_POST['ready_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            StudentService::updateResumeReadyDate($id, $readyDate, $reason, (int)current_user()['id']);
            flash('success', 'વિદ્યાર્થીની ફરી ક્લાસ આવવા માટેની ઉપલબ્ધ તારીખ અપડેટ થઈ ગઈ છે.');
        } elseif ($action === 'mark_completed') {
            StudentService::markCourseCompleted($id, (int)current_user()['id']);
            flash('success', 'વિદ્યાર્થીનો કોર્સ સફળતાપૂર્વક પૂર્ણ થયેલ (Course Completed / છૂટા થયેલ) તરીકે નોંધાઈ ગયો છે.');
        } elseif ($action === 'reopen_course') {
            StudentService::reopenCourse($id, (int)current_user()['id']);
            flash('success', 'વિદ્યાર્થીનું સ્ટેટસ ફરીથી Active (ચાલુ) કરવામાં આવ્યું છે.');
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

// --- Calculate Pending Dues specifically for this student ---
$pendingDuesList = [];
if (in_array($student['status'], ['joining_confirmed', 'active', 'extended', 'course_completed'])) {
    $joiningFeeAmt = (float)get_setting('joining_fee', 3500);
    $secondPaymentAmt = (float)get_setting('second_payment_amount', 4000);
    $dueDays = (int)get_setting('second_payment_due_days', 10);
    
    // Group payments by stage
    $paidByStage = [];
    foreach ($payments as $p) {
        if ($p['status'] === 'success') {
            $paidByStage[$p['stage']] = ($paidByStage[$p['stage']] ?? 0) + $p['amount'];
        }
    }
    
    $entryDate = $student['entry_date'] ?: $student['expected_joining_date'];
    
    // 1. Joining Fee
    if (!isset($paidByStage['joining_fee']) || $paidByStage['joining_fee'] < $joiningFeeAmt) {
        $pendingDuesList[] = [
            'amount' => $joiningFeeAmt - ($paidByStage['joining_fee'] ?? 0),
            'due_date' => $entryDate ? date('d-M-Y', strtotime($entryDate)) : 'N/A',
            'label' => '1st Payment (જોઈનિંગ ફી)',
            'stage' => 'joining_fee'
        ];
    }
    
    // 2. Second Payment
    if ($student['entry_date']) {
        if (!isset($paidByStage['second_payment']) || $paidByStage['second_payment'] < $secondPaymentAmt) {
            $dueDate = date('d-M-Y', strtotime($student['entry_date'] . " + $dueDays days"));
            $pendingDuesList[] = [
                'amount' => $secondPaymentAmt - ($paidByStage['second_payment'] ?? 0),
                'due_date' => $dueDate,
                'label' => '2nd Payment (બીજો હપ્તો)',
                'stage' => 'second_payment'
            ];
        }
    }
    
    // 3. Extension Fee
    $stmtExt = $pdo->prepare("SELECT SUM(total_amount) as tot FROM invoices WHERE student_id = ? AND payment_status != 'paid'");
    $stmtExt->execute([$id]);
    $extTotal = (float)$stmtExt->fetchColumn();
    if ($extTotal > 0) {
        $pendingDuesList[] = [
            'amount' => $extTotal,
            'due_date' => 'Immediate',
            'label' => 'Extension Fee (વધારાના દિવસો)',
            'stage' => 'extension_fee'
        ];
    }
}

$pageTitle = 'Student Profile';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<div class="grid grid-3" style="align-items: start; gap: 20px;">
    <!-- LEFT COLUMN: Profile Info (Sticky) -->
    <div class="card" style="grid-column:span 1; position: sticky; top: 20px; margin-bottom: 0;">
        <?php if ($student['photo_path']): ?>
            <img src="<?= e(url($student['photo_path'])) ?>" style="width:100%;border-radius:8px;margin-bottom:12px;">
        <?php endif; ?>
        <?php if (is_super_admin()): ?>
            <details style="margin-bottom:12px; font-size:12px;">
                <summary style="cursor:pointer; color:var(--primary); font-weight:600;">Change Photo (Super Admin)</summary>
                <form method="post" enctype="multipart/form-data" style="margin-top:8px; background:#f8fafc; padding:8px; border-radius:6px; border:1px solid #e2e8f0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_photo">
                    <input type="hidden" name="cropped_photo_data" id="croppedPhotoData">
                    <input type="file" name="photo" id="photoInput" accept="image/*" style="font-size:11px; margin-bottom:6px; width:100%;">
                    
                    <div id="previewBox" style="display:none; width: 105px; height: 135px; border: 2px dashed #7b2d8e; border-radius: 6px; align-items: center; justify-content: center; overflow: hidden; margin: 0 auto 10px; background: #f9f9f9;">
                        <img id="previewImg" src="" alt="Cropped Preview" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>

                    <button type="submit" class="btn" style="font-size:11px; padding:4px 8px; width:100%; margin-top:8px;">Upload New Photo</button>
                </form>
            </details>
            
            <!-- Modal Popup for Canvas Image Cropping -->
            <style>
            .crop-modal-overlay {
                position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
                background: rgba(0, 0, 0, 0.82); display: none; align-items: center; justify-content: center; z-index: 99999;
            }
            .crop-modal {
                background: #fff; padding: 24px; border-radius: 12px; width: 90%; max-width: 440px; box-shadow: 0 10px 30px rgba(0,0,0,0.4); text-align: center;
            }
            .canvas-wrapper {
                position: relative; width: 300px; height: 300px; margin: 15px auto; background: #1e1e1e; overflow: hidden; border-radius: 8px; cursor: grab; user-select: none; touch-action: none;
            }
            .canvas-wrapper:active { cursor: grabbing; }
            .canvas-overlay-frame {
                position: absolute; top: 24px; left: 52px; width: 195px; height: 251px; border: 2px dashed #7b2d8e; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.55); pointer-events: none; border-radius: 4px;
            }
            .zoom-controls {
                display: flex; align-items: center; justify-content: center; gap: 12px; margin: 10px 0 18px; font-size: 14px; color: #444;
            }
            .zoom-controls input[type="range"] { width: 160px; cursor: pointer; }
            </style>
            
            <div class="crop-modal-overlay" id="cropModal">
                <div class="crop-modal">
                    <h3 style="margin:0 0 6px; color:#6D5CE7;">Admission Form Photo Crop</h3>
                    <p style="font-size:13px; color:#666; margin:0 0 10px;">Adjust photo inside the 35mm x 45mm form frame.</p>
                    
                    <div class="canvas-wrapper" id="canvasWrapper">
                        <canvas id="cropCanvas" width="300" height="300"></canvas>
                        <div class="canvas-overlay-frame"></div>
                    </div>
            
                    <div class="zoom-controls">
                        <span>Zoom:</span>
                        <input type="range" id="zoomSlider" min="0.2" max="3" step="0.05" value="1">
                    </div>
            
                    <div style="display:flex; justify-content:space-between; gap:10px;">
                        <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeCropModal()">Cancel</button>
                        <button type="button" class="btn" style="flex:1;" onclick="saveCroppedImage()">Apply Crop / ક્રોપ કરો</button>
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
        <div style="display:flex; justify-content:space-between; align-items:start;">
            <h2 class="mt-0"><?= e($student['name']) ?></h2>
            <a href="<?= url('public/edit_student.php?id=' . $id) ?>" class="btn btn-sm btn-secondary" title="Edit Student Details">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:3px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit
            </a>
        </div>
        <p class="muted">Reg: <?= e($student['registration_no'] ?? '-') ?> &middot; Form: <?= e($student['form_no']) ?></p>
        <p><span class="badge badge-purple"><?= ucwords(str_replace('_',' ',$student['status'])) ?></span></p>
        <a href="<?= url('public/print_form.php?id=' . (int)$student['id']) ?>" target="_blank" class="btn btn-sm" style="width:100%; margin-bottom:12px; justify-content:center; display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>Print Official Admission Form (PDF)
        </a>
        <p><strong>Mobile:</strong> <?= e($student['student_mobile']) ?><br>
           <strong>Father/Husband:</strong> <?= e($student['husband_father_mobile'] ?: '-') ?><br>
           <strong>Home:</strong> <?= e($student['home_mobile'] ?: '-') ?><br>
           <strong>Address:</strong> <?= e($student['address']) ?></p>
        <p><strong>Knows Sewing:</strong> <?= $student['knows_sewing'] ? 'Yes' : 'No' ?><br>
           <?php if ($student['previous_sewing_work']): ?><strong>Experience:</strong> <?= e($student['previous_sewing_work']) ?><br><?php endif; ?>
           <?php if (!empty($student['hear_about_us'])): ?><strong>Source:</strong> <?= e($student['hear_about_us']) ?><?php endif; ?></p>
    </div>

    <!-- RIGHT COLUMN: Timeline, Payments, Calls, Extensions -->
    <div style="grid-column:span 2; display: flex; flex-direction: column; gap: 20px;">
        
        <div class="card" style="margin-bottom: 0;">
            <h2 class="mt-0">Course Timeline</h2>
            <div class="grid grid-3">
                <div><div class="muted">Entry Date</div><div><?= format_date($student['entry_date']) ?></div></div>
                <div><div class="muted">Last Class Day (છેલ્લો દિવસ)</div><div style="font-weight:700; color:#047857;"><?= format_date($student['final_expiry_date']) ?></div></div>
                <div><div class="muted">Expected Joining</div><div><?= format_date($student['expected_joining_date']) ?></div></div>
            </div>
            <?php if ($student['entry_date']): ?>
            <div class="grid grid-3" style="margin-top:14px;">
                <div><div class="muted">Days Completed</div><div><?= $progress['days_completed'] ?></div></div>
                <div><div class="muted">Days Remaining</div><div><?= $progress['days_remaining'] ?></div></div>
                <div><div class="muted">Course Status</div><div><?= ucfirst($progress['status']) ?></div></div>
            </div>

            <?php if (!empty($student['is_paused']) || $student['status'] === 'paused'): ?>
            <!-- Paused / On Hold Banner -->
            <div style="margin-top:16px; padding:16px 20px; background:#fffbeb; border:2px solid #fde68a; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px;">
                    <div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="background:#f59e0b; color:#fff; font-size:11px; font-weight:700; padding:3px 9px; border-radius:12px; letter-spacing:0.5px;">ON HOLD / PAUSED</span>
                            <strong style="font-size:16px; color:#92400e;">અભ્યાસ હાલ મોકૂફ (સ્ટોપ) રાખેલ છે</strong>
                        </div>
                        <div style="margin-top:10px; font-size:13.5px; color:#78350f; line-height:1.7;">
                            <div>&bull; <strong>મોકૂફ અમલ તારીખ:</strong> <?= format_date($student['paused_at']) ?> (જાણ કરેલ તારીખના બીજા દિવસથી)</div>
                            <div>&bull; <strong>જમા (બાકી રહેલા) દિવસો:</strong> <span style="font-size:16px; font-weight:800; color:#b45309; background:#fef3c7; padding:1px 8px; border-radius:6px;"><?= (int)$student['paused_remaining_days'] ?> દિવસો સુરક્ષિત</span></div>
                            <div>&bull; <strong>પાછા આવવા માટે ઉપલબ્ધ તારીખ:</strong> <?= !empty($student['resume_ready_date']) ? '<strong style="color:#065f46;">' . format_date($student['resume_ready_date']) . ' પછી</strong>' : '<span class="muted">હજુ તારીખ નક્કી નથી</span>' ?></div>
                            <?php if (!empty($student['pause_reason'])): ?>
                            <div>&bull; <strong>કારણ:</strong> <?= e($student['pause_reason']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
                        <button type="button" class="btn btn-sm btn-success" onclick="openResumeModal()" style="background:#16a34a; border-color:#16a34a; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                            Resume Course (કોર્સ ફરી શરૂ કરો)
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="openReadyDateModal()" style="font-size:12px;">
                            Edit Ready Date (તારીખ બદલો)
                        </button>
                    </div>
                </div>
            </div>

            <?php elseif (in_array($student['status'], ['active', 'extended'])): ?>
            <!-- Active Controls: Course Pause / Hold -->
            <div style="margin-top:16px; padding:14px 18px; background:#fff; border:1px solid #fed7aa; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div>
                    <strong style="color:#9a3412; font-size:14px;">Course Pause / Hold (કોર્સ સ્ટોપ કરો):</strong>
                    <div class="muted" style="font-size:12.5px; margin-top:2px;">વિદ્યાર્થી લાંબા સમય માટે ન આવી શકે તેમ હોય તો કોર્સ સ્ટોપ કરી બાકી દિવસો જમા કરી આપો.</div>
                </div>
                <div>
                    <button class="btn btn-sm" type="button" onclick="openPauseModal()" style="background:#ea580c; border-color:#ea580c; color:#fff; font-weight:600; padding:6px 14px; display:inline-flex; align-items:center; gap:5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
                        Pause Course (કોર્સ સ્ટોપ કરો)
                    </button>
                </div>
            </div>

            <?php elseif ($student['status'] === 'course_completed'): ?>
            <div style="margin-top:16px; padding:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="color:#16a34a; font-weight:600;">
                    આ વિદ્યાર્થીનો કોર્સ પૂર્ણ થયેલ છે (Course Completed / છૂટા થયેલ).
                </div>
                <form method="post" onsubmit="return confirm('શું તમે ખરેખર આ વિદ્યાર્થીનું સ્ટેટસ ફરીથી Active (ચાલુ) કરવા માંગો છો?');" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reopen_course">
                    <button class="btn btn-sm btn-secondary" style="font-size:12px;" type="submit">
                        Reopen / ફરી ચાલુ કરો
                    </button>
                </form>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$student['entry_date'] && $queueRows): ?>
            <div class="section-title">Confirm Joining</div>
            <form method="post" class="row3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_joining">
                <div><label>Batch</label>
                    <select name="batch_id" required>
                        <?php foreach ($queueRows as $q): ?><option value="<?= $q['batch_id'] ?>"><?= e($q['batch_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Entry Date</label><input type="date" name="entry_date" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-actions"><button class="btn" type="submit">Confirm Joining</button></div>
            </form>
            <?php endif; ?>

            <?php if ($student['status'] === 'draft'): ?>
            <p class="muted">This form is a draft. Record the ₹500 form fee below to confirm it and assign a Registration No.</p>
            <?php endif; ?>
        </div>

<div class="card" style="margin-bottom: 0;">
    <h2 class="mt-0">Payments</h2>
    
    <?php if (!empty($pendingDuesList)): ?>
    <div style="margin-bottom: 20px; padding: 12px; background: #fffbe8; border: 1px solid #fed7aa; border-radius: 6px;">
        <h4 style="margin:0 0 8px 0; color: #b45309; font-size:13.5px;">Outstanding Dues (આ સ્ટુડન્ટની બાકી ફી ની વિગત):</h4>
        <ul style="margin:0; padding-left:20px; color: #92400e; font-weight: 500; font-size:13px;">
            <?php foreach ($pendingDuesList as $due): 
                $isOverdue = (strtotime($due['due_date']) < time() && $due['due_date'] !== 'N/A' && $due['due_date'] !== 'Immediate');
                $color = $isOverdue ? 'color: #dc2626; font-weight: 700;' : '';
            ?>
                <li>
                    <strong><?= e($due['label']) ?></strong>: ₹<?= number_format($due['amount'], 2) ?> 
                    <span style="<?= $color ?>">(Due Date: <?= e($due['due_date']) ?> <?= $isOverdue ? '- Overdue!' : '' ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <p>
        <?php foreach (['form_fee'=>'Form Fee','joining_fee'=>'Joining Fee','second_payment'=>'Second Payment','extension_fee'=>'Extensions'] as $k=>$label): ?>
            <span class="badge badge-gray" style="margin-right:6px;"><?= $label ?>: ₹<?= number_format($paymentSummary[$k]['total'] ?? 0, 2) ?></span>
        <?php endforeach; ?>
    </p>
    <table>
        <tr><th>Date</th><th>Stage</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Action</th></tr>
        <?php foreach ($payments as $p): ?>
        <tr><td><?= format_date($p['payment_date']) ?></td><td><?= ucwords(str_replace('_',' ',$p['stage'])) ?></td>
            <td>₹<?= number_format($p['amount'],2) ?></td><td><?= ucfirst($p['method']) ?></td>
            <td><?= e($p['receipt_no']) ?></td>
            <td><a href="<?= url('public/receipt.php?id=' . (int)$p['id']) ?>" class="btn-link" target="_blank" style="display:inline-flex; align-items:center; gap:4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>Receipt</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="6" class="muted">No payments yet.</td></tr><?php endif; ?>
    </table>

    <div style="margin-top: 18px; padding: 14px 18px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <div style="width:36px; height:36px; border-radius:8px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:18px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
            </div>
            <div>
                <div style="font-weight: 700; color: #0f172a; font-size: 13.5px;">સત્તાવાર પેમેન્ટ મોડ્યુલ (Official Payments Module)</div>
                <div class="text-muted" style="font-size: 12px; margin-top: 2px;">
                    પેમેન્ટની સુરક્ષા અને ઓડિટ માટે નવું પેમેન્ટ ફક્ત <strong>Payments</strong> ટેબમાંથી જ સ્વીકારવામાં આવે છે.
                </div>
            </div>
        </div>
        <a href="<?= url('public/payments.php?student_id=' . (int)$id) ?>" class="btn btn-sm" style="background: #4338ca; color: #fff; font-weight: 700; text-decoration: none; padding: 8px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
            Payments પેજ પર પેમેન્ટ જમા કરો
        </a>
    </div>
</div>

<div class="card" style="margin-bottom: 0;">
    <h2 class="mt-0">Call / Follow-up Log</h2>
    <table>
        <tr><th>Date</th><th>Method</th><th>Outcome</th><th>Proposed Date</th><th>Follow-up</th><th>Note</th></tr>
        <?php foreach ($calls as $c): ?>
        <tr><td><?= format_date($c['call_datetime']) ?></td><td><?= ucfirst($c['contact_method']) ?></td>
            <td><?= ucwords(str_replace('_',' ',$c['outcome'])) ?></td><td><?= format_date($c['proposed_joining_date']) ?></td>
            <td><?= format_date($c['follow_up_date']) ?></td><td><?= e($c['conversation_note']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$calls): ?><tr><td colspan="6" class="muted">No calls logged yet.</td></tr><?php endif; ?>
    </table>

    <?php if ($queueRows): ?>
    <form method="post" class="row3" style="margin-top:14px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="log_call">
        <div><label>Batch</label>
            <select name="batch_id" required>
                <?php foreach ($queueRows as $q): ?><option value="<?= $q['batch_id'] ?>"><?= e($q['batch_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label>Contact Method</label>
            <select name="contact_method"><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option><option value="other">Other</option></select>
        </div>
        <div><label>Outcome</label>
            <select name="outcome" required>
                <option value="confirmed">Confirmed</option>
                <option value="call_later">Call Later</option>
                <option value="specific_date">Specific Future Date</option>
                <option value="cannot_join">Cannot Join</option>
                <option value="no_response">No Response</option>
            </select>
        </div>
        <div><label>Proposed Joining Date</label><input type="date" name="proposed_joining_date"></div>
        <div><label>Follow-up Date</label><input type="date" name="follow_up_date"></div>
        <div style="grid-column:span 3;"><label>Conversation Note</label><textarea name="conversation_note" rows="2"></textarea></div>
        <div class="form-actions" style="grid-column: 1 / -1; margin-top: 10px;"><button class="btn" type="submit">Save Call</button></div>
    </form>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: 0;">
    <h2 class="mt-0">Extensions</h2>
    <table>
        <tr><th>Days</th><th>Old Expiry</th><th>New Expiry</th><th>Calculated</th><th>Final</th><th>Approved</th><th>Invoice</th><th>Action</th></tr>
        <?php foreach ($extensions as $ex): 
            // Check if invoice is paid to determine if we can show delete button
            $stmtInv = $pdo->prepare("SELECT payment_status FROM invoices WHERE extension_id = ?");
            $stmtInv->execute([$ex['id']]);
            $invStatus = $stmtInv->fetchColumn();
        ?>
        <tr><td><?= $ex['extension_days'] ?></td><td><?= format_date($ex['old_expiry_date']) ?></td><td><?= format_date($ex['new_expiry_date']) ?></td>
            <td>₹<?= number_format($ex['calculated_amount'],2) ?></td><td>₹<?= number_format($ex['final_amount'],2) ?><?= $ex['is_manual_override'] ? ' <span class="badge badge-amber">override</span>' : '' ?></td>
            <td><?= format_date($ex['approved_at']) ?></td>
            <td><a href="<?= url('public/invoice.php?extension_id=' . (int)$ex['id']) ?>" class="btn-link" target="_blank" style="display:inline-flex; align-items:center; gap:4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>Invoice</a></td>
            <td>
                <?php if ($invStatus !== 'paid'): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('શું તમે ખરેખર આ એક્સટેન્શન ડીલીટ કરવા માંગો છો? આનાથી Expiry Date પાછી ખેંચાઈ જશે.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_extension">
                        <input type="hidden" name="extension_id" value="<?= $ex['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" style="padding: 3px 8px; font-size: 11.5px; display:inline-flex; align-items:center; gap:3px;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>Delete
                        </button>
                    </form>
                <?php else: ?>
                    <span class="muted" style="font-size:11px;">Paid</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$extensions): ?><tr><td colspan="8" class="muted">No extensions yet.</td></tr><?php endif; ?>
    </table>

    <?php if ($student['entry_date']): ?>
    <form method="post" class="row3" style="margin-top:14px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_extension">
        <div><label>Extension Days</label><input type="number" name="extension_days" min="1" required></div>
        <div><label>Reason</label><input type="text" name="reason"></div>
        <div><label>Current Rate</label><input type="text" disabled value="₹<?= $currentRate ? number_format($currentRate['per_day_charge'],2) : 'not set' ?>/day"></div>
        <div><label>Manual Override Amount (optional)</label><input type="number" step="0.01" name="override_amount"></div>
        <div style="grid-column:span 2;"><label>Override Reason (if amount overridden)</label><input type="text" name="override_reason"></div>
        <div class="form-actions" style="grid-column: 1 / -1; margin-top: 10px;"><button class="btn" type="submit">Add Extension &amp; Generate Invoice</button></div>
    </form>
    <?php else: ?>
    <p class="muted">Student must have joined (Entry Date set) before an extension can be added.</p>
    <?php endif; ?>
</div>

    </div> <!-- End Right Column -->
</div> <!-- End Grid-3 -->


<!-- ================= MODALS FOR COURSE PAUSE / RESUME ================= -->
<style>
.custom-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 15px;
}
.custom-modal {
    background: #fff;
    border-radius: 12px;
    max-width: 520px;
    width: 100%;
    padding: 24px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    position: relative;
}
.custom-modal h3 {
    margin-top: 0;
    margin-bottom: 12px;
    color: var(--text);
}
.custom-modal .form-group {
    margin-bottom: 14px;
}
.custom-modal label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    font-size: 13.5px;
    color: #334155;
}
.custom-modal input, .custom-modal select, .custom-modal textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}
.custom-modal .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}
.info-callout {
    background: #eff6ff;
    border-left: 3px solid #3b82f6;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: 13px;
    color: #1e40af;
    margin-bottom: 14px;
}
</style>

<!-- 1. Pause Course Modal -->
<div class="custom-modal-overlay" id="pauseModal">
    <div class="custom-modal">
        <h3><span style="color:#ea580c;">&#10074;&#10074;</span> Course Stop / Hold (કોર્સ સ્ટોપ કરો)</h3>
        <p class="muted" style="font-size:13px; margin-top:0;">વિદ્યાર્થી લાંબા સમય સુધી આવી શકે તેમ ન હોય ત્યારે કોર્સ હોલ્ડ પર રાખો.</p>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pause_course">

            <div class="info-callout">
                <strong>નિયમ:</strong> જે દિવસે વિદ્યાર્થી જાણ કરે તે દિવસ ભણવામાં ગણાશે. સ્ટોપ તેના <strong>પછીના દિવસથી</strong> અમલમાં આવશે અને બાકીના દિવસો જમા રહેશે.
            </div>

            <div class="form-group">
                <label>વિદ્યાર્થીએ જાણ કરેલ તારીખ (Inform Date):</label>
                <input type="date" name="inform_date" id="pause_inform_date" value="<?= date('Y-m-d') ?>" required onchange="calculatePausePreview()">
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:14px; font-size:13px;">
                <div>સ્ટોપ અમલ તારીખ: <strong id="preview_stop_date" style="color:#ea580c;"><?= date('Y-m-d', strtotime('+1 day')) ?></strong></div>
                <div>જમા થનારા બાકી દિવસો: <strong id="preview_banked_days" style="color:#16a34a; font-size:15px;">ગણતરી ચાલુ છે...</strong></div>
            </div>

            <div class="form-group">
                <label>પાછા આવવા માટે અનુકૂળ તારીખ (Ready Date - વૈકલ્પિક):</label>
                <input type="date" name="ready_date" placeholder="વિદ્યાર્થી આ તારીખ પછી આવી શકે તેમ છે">
                <small class="muted">જો વિદ્યાર્થીએ જણાવ્યું હોય કે "આ તારીખ પછી હું આવી શકીશ" તો તે તારીખ અહીં નોંધો.</small>
            </div>

            <div class="form-group">
                <label>સ્ટોપ કરવાનું કારણ (Reason):</label>
                <input type="text" name="reason" placeholder="દા.ત. તબિયત ખરાબ / સામાજિક પ્રસંગ / બહારગામ">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closePauseModal()">Cancel</button>
                <button type="submit" class="btn" style="background:#ea580c; border-color:#ea580c; color:#fff;">Confirm Course Stop</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Resume Course Modal -->
<div class="custom-modal-overlay" id="resumeModal">
    <div class="custom-modal">
        <h3><span style="color:#16a34a;">&#9658;</span> Resume Course (કોર્સ ફરી શરૂ કરો)</h3>
        <p class="muted" style="font-size:13px; margin-top:0;">વિદ્યાર્થીને બેચ ફાળવીને જમા રહેલા દિવસો સાથે ક્લાસ ફરી ચાલુ કરો.</p>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resume_course">

            <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:13px; color:#065f46;">
                જમા (Banked) દિવસો: <strong style="font-size:15px; color:#047857;"><?= (int)($student['paused_remaining_days'] ?? 0) ?> દિવસો</strong> ફરી ફાળવવામાં આવશે.
            </div>

            <div class="form-group">
                <label>બેચ પસંદ કરો (Select Batch):</label>
                <select name="batch_id" required>
                    <?php foreach ($batches as $b): 
                        $cap = (int)$b['capacity'];
                        $occ = (int)($b['active_count'] ?? 0);
                        $avail = max(0, $cap - $occ);
                        $isCurrent = ((int)$student['joined_batch_id'] === (int)$b['id']);
                    ?>
                        <option value="<?= $b['id'] ?>" <?= $isCurrent ? 'selected' : '' ?>>
                            <?= e($b['name']) ?> (<?= $occ ?>/<?= $cap ?> ભરેલ <?= $avail > 0 ? "- {$avail} જગ્યા ખાલી" : "- Full (ભરેલ)" ?>) <?= $isCurrent ? '[અગાઉની બેચ]' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>ફરી શરૂ કરવાની તારીખ (Restart Date):</label>
                <input type="date" name="restart_date" id="resume_restart_date" value="<?= !empty($student['resume_ready_date']) && $student['resume_ready_date'] >= date('Y-m-d') ? $student['resume_ready_date'] : date('Y-m-d') ?>" required onchange="calculateResumePreview()">
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:14px; font-size:13px;">
                <div>નવી એક્સપાયરી તારીખ: <strong id="preview_new_expiry" style="color:#16a34a; font-size:14px;">ગણતરી ચાલુ છે...</strong></div>
                <div class="muted" style="font-size:11.5px; margin-top:2px;">(Restart Date + <?= max(0, (int)($student['paused_remaining_days'] ?? 0) - 1) ?> દિવસો)</div>
            </div>

            <div class="form-group">
                <label>ખાસ નોંધ (Note - વૈકલ્પિક):</label>
                <input type="text" name="note" placeholder="દા.ત. સમય બદલી આપ્યો / રિકવેસ્ટ સ્વીકારી">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeResumeModal()">Cancel</button>
                <button type="submit" class="btn btn-success" style="background:#16a34a; border-color:#16a34a;">Confirm Resume Course</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Edit Ready Date Modal -->
<div class="custom-modal-overlay" id="updateReadyDateModal">
    <div class="custom-modal">
        <h3>Edit Ready Date (પાછા આવવાની તારીખ)</h3>
        <p class="muted" style="font-size:13px; margin-top:0;">વિદ્યાર્થી જે તારીખ પછી ક્લાસ આવવા તૈયાર હોય તે તારીખ અપડેટ કરો.</p>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_ready_date">

            <div class="form-group">
                <label>પાછા આવવા માટે ઉપલબ્ધ તારીખ (Ready Date):</label>
                <input type="date" name="ready_date" value="<?= e($student['resume_ready_date'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>કારણ / નોંધ:</label>
                <input type="text" name="reason" value="<?= e($student['pause_reason'] ?? '') ?>" placeholder="દા.ત. ફોન પર વાત થઈ તે મુજબ">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeReadyDateModal()">Cancel</button>
                <button type="submit" class="btn">Update Date</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPauseModal() {
    document.getElementById('pauseModal').style.display = 'flex';
    calculatePausePreview();
}
function closePauseModal() {
    document.getElementById('pauseModal').style.display = 'none';
}

function openResumeModal() {
    document.getElementById('resumeModal').style.display = 'flex';
    calculateResumePreview();
}
function closeResumeModal() {
    document.getElementById('resumeModal').style.display = 'none';
}

function openReadyDateModal() {
    document.getElementById('updateReadyDateModal').style.display = 'flex';
}
function closeReadyDateModal() {
    document.getElementById('updateReadyDateModal').style.display = 'none';
}

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
        document.getElementById('preview_banked_days').innerText = banked + ' દિવસો';
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
