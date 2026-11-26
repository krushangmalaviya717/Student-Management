<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue_engine.php';
require_once __DIR__ . '/../includes/payment_engine.php';
require_admin();

$pdo = db();
$batches = $pdo->query("SELECT * FROM batches WHERE is_active = 1 ORDER BY start_time")->fetchAll();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $name = trim($_POST['name'] ?? '');
        $mobile = trim($_POST['student_mobile'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $anyBatch = isset($_POST['any_batch']) ? 1 : 0;
        $preferredBatch = $anyBatch ? null : ($_POST['preferred_batch_id'] ?: null);

        if (!$name || !$mobile || !$address) {
            throw new RuntimeException('Name, mobile and address are required.');
        }
        if (!$anyBatch && !$preferredBatch) {
            throw new RuntimeException('Choose a preferred batch, or tick "Any Batch".');
        }

        // Photo upload & Cropped Image processing
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
                throw new RuntimeException('Photo upload failed. Please try again.');
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
            throw new RuntimeException('Student photo is mandatory.');
        }

        $formNo = generate_form_no();

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO students
                (form_no, form_submit_date, form_status, name, age, student_mobile,
                 husband_father_mobile, home_mobile, education, address,
                 knows_sewing, previous_sewing_work, hear_about_us, guardian_signature_name, guardian_relation,
                 preferred_batch_id, any_batch, photo_path, status, created_by)
             VALUES (?, NOW(), 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $stmt->execute([
            $formNo, $name, $_POST['age'] ?: null, $mobile,
            $_POST['husband_father_mobile'] ?: null, $_POST['home_mobile'] ?: null,
            $_POST['education'] ?: null, $address,
            isset($_POST['knows_sewing']) ? 1 : 0, $_POST['previous_sewing_work'] ?: null,
            $_POST['hear_about_us'] ?: null, $_POST['guardian_signature_name'] ?: null, $_POST['guardian_relation'] ?: null,
            $preferredBatch, $anyBatch, $photoPath, current_user()['id'],
        ]);
        $studentId = (int)$pdo->lastInsertId();

        // Referral (optional)
        $relName = $_POST['relative_name'] ?: ($_POST['reference_person_name'] ?? null);
        $relMobile = $_POST['relative_mobile'] ?: ($_POST['reference_mobile'] ?? null);
        if (!empty($relName) || !empty($relMobile)) {
            $pdo->prepare(
                "INSERT INTO referrals (student_id, relative_student_id, relative_name, relative_mobile, relative_address, relation)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $studentId, $_POST['relative_student_id'] ?: null,
                $relName, $relMobile,
                $_POST['relative_address'] ?: null, $_POST['relation'] ?: 'Referral',
            ]);
        }

        log_audit('student', $studentId, 'create_draft', null, ['form_no' => $formNo, 'name' => $name]);
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
                <small class="muted">Fields marked with * are required. Passport photo (35mm x 45mm) will be cropped automatically.</small>
            </div>
            <a href="<?= url('public/students_list.php') ?>" class="btn btn-sm btn-secondary">Back to Students</a>
        </div>

        <div class="section-title">Personal &amp; Contact Details</div>
        <div class="row form-group">
            <div>
                <label for="reg_name">Student Name *</label>
                <input type="text" id="reg_name" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
            </div>
            <div>
                <label for="reg_age">Age</label>
                <input type="number" id="reg_age" name="age" min="1" value="<?= e($_POST['age'] ?? '') ?>">
            </div>
        </div>

        <div class="row3 form-group">
            <div>
                <label for="reg_mobile">Student Mobile *</label>
                <input type="tel" id="reg_mobile" name="student_mobile" required value="<?= e($_POST['student_mobile'] ?? '') ?>">
            </div>
            <div>
                <label for="reg_guardian_mobile">Husband / Father Mobile</label>
                <input type="tel" id="reg_guardian_mobile" name="husband_father_mobile">
            </div>
            <div>
                <label for="reg_home_mobile">Home Mobile</label>
                <input type="tel" id="reg_home_mobile" name="home_mobile">
            </div>
        </div>

        <div class="row form-group" style="align-items: start;">
            <div>
                <label for="reg_education">Education</label>
                <input type="text" id="reg_education" name="education">
            </div>
            <div>
                <label for="photoInput">Photo (35mm x 45mm Passport Crop) *</label>
                <input type="file" id="photoInput" accept="image/*" style="padding: 5px 10px; height: auto;">
                <div id="previewBox" class="crop-preview-box">
                    <img id="previewImg" src="" alt="Cropped Preview">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label for="reg_address">Full Address *</label>
            <textarea id="reg_address" name="address" rows="2" required></textarea>
        </div>

        <div class="section-title">Sewing Experience</div>
        <div class="form-group">
            <label class="checkbox-label" for="reg_knows_sewing">
                <input type="checkbox" id="reg_knows_sewing" name="knows_sewing" value="1">
                <span>Knows Sewing?</span>
            </label>
        </div>
        <div class="form-group">
            <label for="reg_prev_sewing">Previous Sewing Work (if any)</label>
            <textarea id="reg_prev_sewing" name="previous_sewing_work" rows="2"></textarea>
        </div>

        <div class="section-title">Batch Preference</div>
        <div class="row form-group" style="align-items: center;">
            <div>
                <label for="batchSelect">Preferred Batch</label>
                <select name="preferred_batch_id" id="batchSelect">
                    <option value="">-- Select Batch --</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="padding-top: 20px;">
                <label class="checkbox-label" for="any_batch">
                    <input type="checkbox" name="any_batch" value="1" id="any_batch" onclick="document.getElementById('batchSelect').disabled=this.checked">
                    <span>Any Batch is acceptable</span>
                </label>
            </div>
        </div>

        <div class="section-title">How Did You Hear About Us?</div>
        <div class="radio-pill-grid">
            <label class="radio-pill-option">
                <input type="radio" name="hear_about_us" value="Instagram">
                <span>Instagram</span>
            </label>
            <label class="radio-pill-option">
                <input type="radio" name="hear_about_us" value="Facebook">
                <span>Facebook</span>
            </label>
            <label class="radio-pill-option">
                <input type="radio" name="hear_about_us" value="Board/Banner">
                <span>Board / Banner</span>
            </label>
            <label class="radio-pill-option">
                <input type="radio" name="hear_about_us" value="Relative/Friend">
                <span>Relative / Friend</span>
            </label>
        </div>

        <div class="section-title">Referral Details (Optional)</div>
        <div class="row form-group">
            <div>
                <label for="reg_ref_name">Reference Person Name</label>
                <input type="text" id="reg_ref_name" name="reference_person_name" placeholder="Name of referring person">
            </div>
            <div>
                <label for="reg_ref_mobile">Reference Mobile No.</label>
                <input type="tel" id="reg_ref_mobile" name="reference_mobile" placeholder="Reference mobile number">
            </div>
        </div>

        <div class="section-title">Guardian Details (For Admission Form)</div>
        <div class="row form-group">
            <div>
                <label for="reg_guard_name">Guardian Name</label>
                <input type="text" id="reg_guard_name" name="guardian_signature_name" placeholder="Parent / Spouse / Guardian name">
            </div>
            <div>
                <label for="reg_guard_rel">Relation</label>
                <input type="text" id="reg_guard_rel" name="guardian_relation" placeholder="e.g. Father / Husband / Mother">
            </div>
        </div>

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
