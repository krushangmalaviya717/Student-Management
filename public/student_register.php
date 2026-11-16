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

        flash('success', "વિદ્યાર્થી ફોર્મ #$formNo સફળતાપૂર્વક રજીસ્ટર થઈ ગયું છે. હવે Payments ટેબમાંથી ફોર્મ ફી જમા કરી શકો છો.");
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
/* Exact 35mm x 45mm Passport Ratio Frame (Width 195px, Height 251px) */
.canvas-overlay-frame {
    position: absolute; top: 24px; left: 52px; width: 195px; height: 251px; border: 2px dashed #6D5CE7; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.55); pointer-events: none; border-radius: 4px;
}
.zoom-controls {
    display: flex; align-items: center; justify-content: center; gap: 12px; margin: 10px 0 18px; font-size: 14px; color: #444;
}
.zoom-controls input[type="range"] { width: 160px; cursor: pointer; }
.crop-preview-box {
    width: 105px; height: 135px; border: 2px dashed #6D5CE7; border-radius: 6px; display: none; align-items: center; justify-content: center; overflow: hidden; margin-top: 10px; background: #f9f9f9;
}
.crop-preview-box img { width: 100%; height: 100%; object-fit: cover; }
</style>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card" id="studentForm">
    <?= csrf_field() ?>
    <input type="hidden" name="cropped_photo_data" id="croppedPhotoData">

    <div class="section-title">Personal Details</div>
    <div class="row">
        <div><label>Student Name *</label><input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>
        <div><label>Age</label><input type="number" name="age" min="1" value="<?= e($_POST['age'] ?? '') ?>"></div>
    </div>
    <div class="row3">
        <div><label>Student Mobile *</label><input type="tel" name="student_mobile" required value="<?= e($_POST['student_mobile'] ?? '') ?>"></div>
        <div><label>Husband/Father Mobile</label><input type="tel" name="husband_father_mobile"></div>
        <div><label>Home Mobile</label><input type="tel" name="home_mobile"></div>
    </div>
    <div class="row">
        <div><label>Education</label><input type="text" name="education"></div>
        <div>
            <label>Photo (35mm x 45mm Passport Size Crop) *</label>
            <input type="file" id="photoInput" accept="image/*">
            <div id="previewBox" class="crop-preview-box">
                <img id="previewImg" src="" alt="Cropped Preview">
            </div>
        </div>
    </div>
    <label>Full Address *</label>
    <textarea name="address" rows="2" required></textarea>

    <div class="section-title">Sewing Experience</div>
    <label><input type="checkbox" name="knows_sewing" value="1" style="width:auto;"> Knows Sewing?</label>
    <label>Previous Sewing Work (if any)</label>
    <textarea name="previous_sewing_work" rows="2"></textarea>

    <div class="section-title">Batch Preference</div>
    <label><input type="checkbox" name="any_batch" value="1" id="any_batch" style="width:auto;" onclick="document.getElementById('batchSelect').disabled=this.checked"> Any Batch is acceptable</label>
    <label>Preferred Batch</label>
    <select name="preferred_batch_id" id="batchSelect">
        <option value="">-- Select Batch --</option>
        <?php foreach ($batches as $b): ?>
            <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <div class="section-title">તમને ક્લાસીસ વિશે માહિતી ક્યાંથી મળી? (How Did You Hear About Us?)</div>
    <div style="display:flex; gap:24px; flex-wrap:wrap; margin:10px 0 16px;">
        <label style="margin:0; font-weight:600; cursor:pointer;"><input type="radio" name="hear_about_us" value="Instagram" style="width:auto;"> ઇન્સ્ટાગ્રામ (Instagram)</label>
        <label style="margin:0; font-weight:600; cursor:pointer;"><input type="radio" name="hear_about_us" value="Facebook" style="width:auto;"> ફેસબુક (Facebook)</label>
        <label style="margin:0; font-weight:600; cursor:pointer;"><input type="radio" name="hear_about_us" value="Board/Banner" style="width:auto;"> બોર્ડ / બેનર (Board / Banner)</label>
        <label style="margin:0; font-weight:600; cursor:pointer;"><input type="radio" name="hear_about_us" value="Relative/Friend" style="width:auto;"> રિલેટિવ / ફ્રેન્ડ્સ દ્વારા (Relative / Friend)</label>
    </div>

    <div class="section-title">રિલેટિવ / ફ્રેન્ડ્સ દ્વારા આવ્યા હોય તો વિગત (Referral Details)</div>
    <div class="row">
        <div><label>રેફરન્સ આપનારનું નામ (Reference Person Name)</label><input type="text" name="reference_person_name" placeholder="રેફરન્સ આપનારનું નામ"></div>
        <div><label>મો.નં. (Mobile No.)</label><input type="tel" name="reference_mobile" placeholder="રેફરન્સ મોબાઈલ નંબર"></div>
    </div>

    <div class="section-title">ઘરના વડીલ / વાલીની વિગત (Guardian Details for Signature)</div>
    <div class="row">
        <div><label>ઘરના વડીલનું નામ (Guardian Name)</label><input type="text" name="guardian_signature_name" placeholder="વડીલ / વાલીનું નામ"></div>
        <div><label>સંબંધ (Relation)</label><input type="text" name="guardian_relation" placeholder="દા.ત. પિતા / પતિ / માતા"></div>
    </div>

    <div style="margin-top:24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:16px 20px; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:10px;">
        <div>
            <div style="font-weight:700; color:#0f172a; font-size:14px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2.5" style="vertical-align:-2px; margin-right:4px;"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                ફોર્મ ફી કલેક્શન (Form Fee: ₹<?= e($formFee) ?>)
            </div>
            <div class="text-muted" style="font-size:12.5px; margin-top:3px;">
                વિદ્યાર્થીની નોંધણી કર્યા પછી ફોર્મ ફી સત્તાવાર <strong>Payments</strong> ટેબમાંથી જ સિક્રેટ પિન સાથે જમા થશે.
            </div>
        </div>
        <button type="submit" name="save_student" value="1" class="btn" style="background:#4338ca; color:#fff; font-weight:700; padding:10px 24px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Save Registration (વિદ્યાર્થી નોંધણી સાચવો)
        </button>
    </div>
</form>

<!-- Modal Popup for Canvas Image Cropping (Fixed 35mm x 45mm Form Ratio) -->
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

// Frame dimensions on 300x300 canvas: 195px x 251px (35mm x 45mm ratio), Top: 24px, Left: 52px
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
                
                // Center image in the 35mm x 45mm crop frame
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
