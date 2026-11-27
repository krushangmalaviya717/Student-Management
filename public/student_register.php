<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue_engine.php';
require_once __DIR__ . '/../includes/payment_engine.php';
require_once __DIR__ . '/../includes/services/StudentService.php';
require_admin();

$pdo = db();
$batches = $pdo->query("SELECT * FROM batches WHERE is_active = 1 ORDER BY start_time")->fetchAll();
$fields = StudentService::getFormFieldConfig();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        // Photo upload & Cropped Image processing (only when photo field is visible)
        $photoPath = null;
        if ($fields['photo']['visible']) {
            $photoPath = save_uploaded_student_photo(
                $_POST['cropped_photo_data'] ?? null,
                $_FILES['photo'] ?? null,
                UPLOAD_DIR,
                UPLOAD_URL,
                MAX_PHOTO_SIZE_MB
            );
        }

        // Dynamic server-side validation driven by Settings
        $data = StudentService::validateStudentData($_POST, [
            'is_edit' => false,
            'has_photo' => !empty($photoPath),
        ]);

        $anyBatch = isset($_POST['any_batch']) ? 1 : 0;
        $preferredBatch = $anyBatch ? null : ($_POST['preferred_batch_id'] ?: null);

        // Snapshot currently active course fee rates into student profile permanently
        $snapFormFee = (float)get_setting('form_fee', 500);
        $snapJoiningFee = (float)get_setting('joining_fee', 3500);
        $snapSecondPayment = (float)get_setting('second_payment_amount', 4000);
        $snapTotalCourseFee = $snapFormFee + $snapJoiningFee + $snapSecondPayment;

        $pdo->beginTransaction();
        $formNo = generate_form_no();
        $stmt = $pdo->prepare(
            "INSERT INTO students
                (form_no, form_submit_date, form_status, surname, first_name, father_husband_name, name, age, student_mobile,
                 husband_father_mobile, home_mobile, education, address,
                 knows_sewing, previous_sewing_work, hear_about_us, guardian_signature_name, guardian_relation,
                 preferred_batch_id, any_batch, photo_path, status, created_by,
                 form_fee_amount, joining_fee_amount, second_payment_amount, total_course_fee)
             VALUES (?, NOW(), 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?,
                 ?, ?, ?, ?)"
        );
        $stmt->execute([
            $formNo,
            $data['surname'],
            $data['first_name'],
            $data['father_husband_name'],
            $data['name'],
            $data['age'],
            $data['student_mobile'],
            $data['husband_father_mobile'],
            $data['home_mobile'],
            $data['education'],
            $data['address'],
            $data['knows_sewing'],
            $data['previous_sewing_work'],
            $data['hear_about_us'],
            $data['guardian_signature_name'],
            $data['guardian_relation'],
            $preferredBatch,
            $anyBatch,
            $photoPath,
            current_user()['id'],
            $snapFormFee,
            $snapJoiningFee,
            $snapSecondPayment,
            $snapTotalCourseFee
        ]);
        $studentId = (int)$pdo->lastInsertId();

        // Referral (optional or required based on settings)
        if ($fields['referral']['visible'] && (!empty($data['reference_person_name']) || !empty($data['reference_mobile']))) {
            $pdo->prepare(
                "INSERT INTO referrals (student_id, relative_student_id, relative_name, relative_mobile, relative_address, relation)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $studentId,
                $_POST['relative_student_id'] ?: null,
                $data['reference_person_name'],
                $data['reference_mobile'],
                $_POST['relative_address'] ?: null,
                $_POST['relation'] ?: 'Referral',
            ]);
        }

        log_audit('student', $studentId, 'create_draft', null, ['form_no' => $formNo, 'name' => $data['name']]);
        $pdo->commit();

        flash('success', "Student form #$formNo registered successfully. Please collect the form fee in the Payments tab.");
        redirect('public/payments.php?student_id=' . $studentId . '&stage=form_fee');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$formFee = get_setting('form_fee', 500);
$pageTitle = 'New Student Registration';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.reg-container {
    max-width: 960px;
    margin: 0 auto;
}
.radio-pill-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 16px;
}
@media (max-width: 768px) {
    .radio-pill-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .radio-pill-grid { grid-template-columns: 1fr; }
}
.radio-pill-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 12px;
    border: 1px solid var(--border-input);
    border-radius: var(--radius);
    background: var(--card-bg);
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-body);
    margin: 0;
    transition: all 0.15s ease;
}
.radio-pill-option:hover {
    border-color: var(--primary);
    background: var(--bg);
}
.radio-pill-option:has(input:checked) {
    border-color: var(--primary);
    background: var(--primary-light);
    color: var(--primary-hover);
}
.req-star {
    color: var(--danger);
    font-weight: 700;
}
.reg-submit-footer {
    margin-top: 24px;
    padding: 16px 20px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
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
.crop-preview-box {
    width: 98px;
    height: 126px;
    border: 2px dashed var(--primary);
    border-radius: var(--radius);
    display: none;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    margin-top: 10px;
    background: var(--bg);
}
.crop-preview-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>

<div class="reg-container">
    <?php if ($error): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card" id="studentForm">
        <?= csrf_field() ?>
        <input type="hidden" name="cropped_photo_data" id="croppedPhotoData">

        <div class="card-header-bar" style="margin-bottom: 18px;">
            <div>
                <h2>Student Admission Form</h2>
                <small class="muted">
                    Fields marked with <span class="req-star">*</span> are required (configurable in
                    <?php if (is_super_admin()): ?>
                        <a href="<?= url('public/settings.php?tab=form_fields') ?>">Settings</a>).
                    <?php else: ?>
                        Settings).
                    <?php endif; ?>
                </small>
            </div>
            <a href="<?= url('public/students_list.php') ?>" class="btn btn-sm btn-secondary">Back to Students</a>
        </div>

        <div class="section-title">Personal &amp; Contact Details</div>
        
        <!-- Separate Surname, Student Name, and Father/Husband Name -->
        <div class="row3 form-group" style="margin-bottom: 8px;">
            <div>
                <label for="reg_surname">અટક (Surname) <span class="req-star">*</span></label>
                <input type="text" id="reg_surname" name="surname" required value="<?= e($_POST['surname'] ?? '') ?>" placeholder="દા.ત. ધામેલીયા / પટેલ" oninput="updateFullNamePreview()">
            </div>
            <div>
                <label for="reg_first_name">વિદ્યાર્થીનું નામ (Student Name) <span class="req-star">*</span></label>
                <input type="text" id="reg_first_name" name="first_name" required value="<?= e($_POST['first_name'] ?? '') ?>" placeholder="દા.ત. મોનિકા / રીનાબેન" oninput="updateFullNamePreview()">
            </div>
            <div>
                <label for="reg_father_husband">પિતા / પતિનું નામ (Father / Husband Name)</label>
                <input type="text" id="reg_father_husband" name="father_husband_name" value="<?= e($_POST['father_husband_name'] ?? '') ?>" placeholder="દા.ત. હાર્દિકભાઈ / રમેશભાઈ" oninput="updateFullNamePreview()">
            </div>
        </div>

        <div class="form-group p-2 rounded-2 mb-3" style="background: #F8FAFC; border: 1px dashed #CBD5E1; font-size: 13px;">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <span class="text-muted fw-semibold">ફોર્મમાં લખાતું પૂરું નામ (Full Name):</span>
                    <strong id="fullNamePreview" class="text-primary font-monospace ms-2" style="font-size: 14px;">-</strong>
                </div>
                <small class="text-muted">(ક્રમ: <b>અટક + નામ + પિતા/પતિનું નામ</b>)</small>
            </div>
            <input type="hidden" id="reg_name" name="name" value="<?= e($_POST['name'] ?? '') ?>">
        </div>

        <div class="row form-group">
            <?php if ($fields['age']['visible']): ?>
            <div class="col-full">
                <label for="reg_age">Age <?= $fields['age']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="number" id="reg_age" name="age" min="1" <?= $fields['age']['required'] ? 'required' : '' ?> value="<?= e($_POST['age'] ?? '') ?>">
            </div>
            <?php endif; ?>
        </div>

        <div class="row3 form-group">
            <div>
                <label for="reg_mobile">Student Mobile <?= $fields['student_mobile']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="tel" id="reg_mobile" name="student_mobile" <?= $fields['student_mobile']['required'] ? 'required' : '' ?> value="<?= e($_POST['student_mobile'] ?? '') ?>">
            </div>
            <?php if ($fields['father_mobile']['visible']): ?>
            <div>
                <label for="reg_guardian_mobile">Husband / Father Mobile <?= $fields['father_mobile']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="tel" id="reg_guardian_mobile" name="husband_father_mobile" <?= $fields['father_mobile']['required'] ? 'required' : '' ?> value="<?= e($_POST['husband_father_mobile'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($fields['home_mobile']['visible']): ?>
            <div>
                <label for="reg_home_mobile">Home Mobile <?= $fields['home_mobile']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="tel" id="reg_home_mobile" name="home_mobile" <?= $fields['home_mobile']['required'] ? 'required' : '' ?> value="<?= e($_POST['home_mobile'] ?? '') ?>">
            </div>
            <?php endif; ?>
        </div>

        <?php if ($fields['education']['visible'] || $fields['photo']['visible']): ?>
        <div class="row form-group" style="align-items: start;">
            <?php if ($fields['education']['visible']): ?>
            <div class="<?= !$fields['photo']['visible'] ? 'col-full' : '' ?>">
                <label for="reg_education">Education <?= $fields['education']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="reg_education" name="education" <?= $fields['education']['required'] ? 'required' : '' ?> value="<?= e($_POST['education'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($fields['photo']['visible']): ?>
            <div class="<?= !$fields['education']['visible'] ? 'col-full' : '' ?>">
                <label for="photoInput">Photo (35mm x 45mm Passport Crop) <?= $fields['photo']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="file" id="photoInput" name="photo" accept="image/*" <?= $fields['photo']['required'] ? 'required' : '' ?> style="padding: 5px 10px; height: auto;">
                <div id="previewBox" class="crop-preview-box">
                    <img id="previewImg" src="" alt="Cropped Preview">
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($fields['address']['visible']): ?>
        <div class="form-group">
            <label for="reg_address">Full Address <?= $fields['address']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
            <textarea id="reg_address" name="address" rows="2" <?= $fields['address']['required'] ? 'required' : '' ?>><?= e($_POST['address'] ?? '') ?></textarea>
        </div>
        <?php endif; ?>

        <?php if ($fields['knows_sewing']['visible']): ?>
        <div class="section-title">Sewing Experience <?= $fields['knows_sewing']['required'] ? '<span class="req-star">*</span>' : '' ?></div>
        <div class="row form-group" style="align-items: center;">
            <div>
                <label style="margin-bottom: 8px;">Do you know sewing? <?= $fields['knows_sewing']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <div class="radio-pill-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: 0;">
                    <label class="radio-pill-option">
                        <input type="radio" name="knows_sewing" value="1" <?= $fields['knows_sewing']['required'] ? 'required' : '' ?> <?= (($_POST['knows_sewing'] ?? '') === '1') ? 'checked' : '' ?>>
                        <span>Yes (હા)</span>
                    </label>
                    <label class="radio-pill-option">
                        <input type="radio" name="knows_sewing" value="0" <?= (($_POST['knows_sewing'] ?? '') === '0') ? 'checked' : '' ?>>
                        <span>No (ના)</span>
                    </label>
                </div>
            </div>
            <div>
                <label for="reg_prev_sewing">Previous Sewing Work (if any)</label>
                <textarea id="reg_prev_sewing" name="previous_sewing_work" rows="2"><?= e($_POST['previous_sewing_work'] ?? '') ?></textarea>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-title">Batch Preference <span class="req-star">*</span></div>
        <div class="row form-group" style="align-items: center;">
            <div>
                <label for="batchSelect">Preferred Batch</label>
                <select name="preferred_batch_id" id="batchSelect">
                    <option value="">-- Select Batch --</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= (($_POST['preferred_batch_id'] ?? '') == $b['id']) ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="padding-top: 20px;">
                <label class="checkbox-label" for="any_batch">
                    <input type="checkbox" name="any_batch" value="1" id="any_batch" <?= !empty($_POST['any_batch']) ? 'checked' : '' ?> onclick="document.getElementById('batchSelect').disabled=this.checked">
                    <span>Any Batch is acceptable</span>
                </label>
            </div>
        </div>

        <?php if ($fields['hear_about_us']['visible']): ?>
        <div class="section-title">How Did You Hear About Us? <?= $fields['hear_about_us']['required'] ? '<span class="req-star">*</span>' : '' ?></div>
        <div class="radio-pill-grid">
            <?php $postedSource = $_POST['hear_about_us'] ?? ''; ?>
            <label class="radio-pill-option">
                <input type="radio" name="hear_about_us" value="Instagram" <?= $fields['hear_about_us']['required'] ? 'required' : '' ?> <?= $postedSource === 'Instagram' ? 'checked' : '' ?>>
                <span>Instagram</span>
            </label>
            <label class="radio-pill-option">
                <input type="radio" name="hear_about_us" value="Facebook" <?= $postedSource === 'Facebook' ? 'checked' : '' ?>>
                <span>Facebook</span>
            </label>
            <label class="radio-pill-option">
                <input type="radio" name="hear_about_us" value="Board/Banner" <?= $postedSource === 'Board/Banner' ? 'checked' : '' ?>>
                <span>Board / Banner</span>
            </label>
            <label class="radio-pill-option">
                <input type="radio" name="hear_about_us" value="Relative/Friend" <?= $postedSource === 'Relative/Friend' ? 'checked' : '' ?>>
                <span>Relative / Friend</span>
            </label>
        </div>
        <?php endif; ?>

        <?php if ($fields['referral']['visible']): ?>
        <div class="section-title">Referral Details <?= $fields['referral']['required'] ? '<span class="req-star">*</span>' : '(Optional)' ?></div>
        <div class="row form-group">
            <div>
                <label for="reg_ref_name">Reference Person Name <?= $fields['referral']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="reg_ref_name" name="reference_person_name" <?= $fields['referral']['required'] ? 'required' : '' ?> value="<?= e($_POST['reference_person_name'] ?? '') ?>" placeholder="Name of referring person">
            </div>
            <div>
                <label for="reg_ref_mobile">Reference Mobile No. <?= $fields['referral']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="tel" id="reg_ref_mobile" name="reference_mobile" <?= $fields['referral']['required'] ? 'required' : '' ?> value="<?= e($_POST['reference_mobile'] ?? '') ?>" placeholder="Reference mobile number">
            </div>
        </div>
        <?php endif; ?>

        <?php if ($fields['guardian']['visible']): ?>
        <div class="section-title">Guardian Details (For Admission Form) <?= $fields['guardian']['required'] ? '<span class="req-star">*</span>' : '' ?></div>
        <div class="row form-group">
            <div>
                <label for="reg_guard_name">Guardian Name <?= $fields['guardian']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="reg_guard_name" name="guardian_signature_name" <?= $fields['guardian']['required'] ? 'required' : '' ?> value="<?= e($_POST['guardian_signature_name'] ?? '') ?>" placeholder="Parent / Spouse / Guardian name">
            </div>
            <div>
                <label for="reg_guard_rel">Relation <?= $fields['guardian']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="reg_guard_rel" name="guardian_relation" <?= $fields['guardian']['required'] ? 'required' : '' ?> value="<?= e($_POST['guardian_relation'] ?? '') ?>" placeholder="e.g. Father / Husband / Mother">
            </div>
        </div>
        <?php endif; ?>

        <div class="reg-submit-footer">
            <div>
                <div style="font-weight: 700; color: var(--text); font-size: 13.5px; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    <span>Form Fee Collection: ₹<?= e($formFee) ?></span>
                </div>
                <div class="muted" style="font-size: 12.5px; margin-top: 3px;">
                    After saving, you will be redirected to the Payments page to record the form fee with Security PIN.
                </div>
            </div>
            <button type="submit" name="save_student" value="1" class="btn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Save Registration</span>
            </button>
        </div>
    </form>
</div>

<!-- Modal Popup for Canvas Image Cropping -->
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
const anyBatchEl = document.getElementById('any_batch');
const batchSelectEl = document.getElementById('batchSelect');
if (anyBatchEl && batchSelectEl) {
    batchSelectEl.disabled = anyBatchEl.checked;
}

const photoInput = document.getElementById('photoInput');
const cropModal = document.getElementById('cropModal');
const cropCanvas = document.getElementById('cropCanvas');
const ctx = cropCanvas ? cropCanvas.getContext('2d') : null;
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

if (photoInput) {
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
}

function draw() {
    if (!ctx) return;
    ctx.clearRect(0, 0, 300, 300);
    ctx.drawImage(img, posX, posY, img.width * scale, img.height * scale);
}

if (zoomSlider) {
    zoomSlider.addEventListener('input', function() {
        const newScale = parseFloat(this.value);
        const centerX = FRAME_X + FRAME_W / 2;
        const centerY = FRAME_Y + FRAME_H / 2;
        posX = centerX - (centerX - posX) * (newScale / scale);
        posY = centerY - (centerY - posY) * (newScale / scale);
        scale = newScale;
        draw();
    });
}

if (canvasWrapper) {
    canvasWrapper.addEventListener('mousedown', function(e) {
        isDragging = true;
        startX = e.clientX - posX;
        startY = e.clientY - posY;
    });

    window.addEventListener('mousemove', function(e) {
        if (isDragging) {
            posX = e.clientX - startX;
            posY = e.clientY - startY;
            draw();
        }
    });

    window.addEventListener('mouseup', function() {
        isDragging = false;
    });

    canvasWrapper.addEventListener('touchstart', function(e) {
        if (e.touches.length === 1) {
            isDragging = true;
            startX = e.touches[0].clientX - posX;
            startY = e.touches[0].clientY - posY;
        }
    });

    window.addEventListener('touchmove', function(e) {
        if (isDragging && e.touches.length === 1) {
            posX = e.touches[0].clientX - startX;
            posY = e.touches[0].clientY - startY;
            draw();
        }
    });

    window.addEventListener('touchend', function() {
        isDragging = false;
    });
}

function updateFullNamePreview() {
    var surname = (document.getElementById('reg_surname')?.value || '').trim();
    var first = (document.getElementById('reg_first_name')?.value || '').trim();
    var father = (document.getElementById('reg_father_husband')?.value || '').trim();
    var full = [surname, first, father].filter(Boolean).join(' ');
    var preview = document.getElementById('fullNamePreview');
    var hidden = document.getElementById('reg_name');
    if (preview) preview.textContent = full || '-';
    if (hidden) hidden.value = full;
}
document.addEventListener('DOMContentLoaded', updateFullNamePreview);

function closeCropModal() {
    cropModal.style.display = 'none';
}

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

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
