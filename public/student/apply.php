<?php
$pageTitle = 'નવું એડમિશન ફોર્મ - Devangi Classes';
$activePage = 'apply';
$showPublicHeader = true;
$showBackBtn = true;
$backUrl = '/public/student/login.php';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/student_auth.php';
require_once __DIR__ . '/../../includes/services/StudentService.php';

if (is_student_logged_in()) {
    redirect('public/student/dashboard.php');
    exit;
}

$pdo = db();
$batches = $pdo->query("SELECT * FROM batches WHERE is_active = 1 ORDER BY start_time")->fetchAll();
$fields = StudentService::getFormFieldConfig();
$formFee = (float)get_setting('form_fee', 500);
$rulesRaw = get_setting('admission_rules_text', '');
$rulesList = array_values(array_filter(array_map('trim', explode("\n", $rulesRaw))));
$cautionText = get_setting('admission_caution_text', '');

$error = null;
$submittedFormNo = null;
$submittedName = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (empty($_POST['agree_rules'])) {
            throw new InvalidArgumentException('કૃપા કરીને ક્લાસના નિયમો વાંચીને સંમતિ (Agree) બોક્સ ટીક કરો.');
        }

        $photoPath = null;
        if ($fields['photo']['visible'] && !empty($_FILES['photo']['name'])) {
            $photoPath = save_uploaded_student_photo(
                null,
                $_FILES['photo'] ?? null,
                UPLOAD_DIR,
                UPLOAD_URL,
                MAX_PHOTO_SIZE_MB
            );
        }

        $data = StudentService::validateStudentData($_POST, [
            'is_edit' => false,
            'has_photo' => !empty($photoPath),
        ]);

        $anyBatch = isset($_POST['any_batch']) ? 1 : 0;
        $preferredBatch = $anyBatch ? null : (!empty($_POST['preferred_batch_id']) ? (int)$_POST['preferred_batch_id'] : null);

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
             VALUES (?, NOW(), 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NULL,
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
            $snapFormFee,
            $snapJoiningFee,
            $snapSecondPayment,
            $snapTotalCourseFee
        ]);
        $studentId = (int)$pdo->lastInsertId();

        if ($fields['referral']['visible'] && (!empty($data['reference_person_name']) || !empty($data['reference_mobile']))) {
            $pdo->prepare(
                "INSERT INTO referrals (student_id, relative_student_id, relative_name, relative_mobile, relative_address, relation)
                 VALUES (?, NULL, ?, ?, NULL, ?)"
            )->execute([
                $studentId,
                $data['reference_person_name'],
                $data['reference_mobile'],
                trim($_POST['relation'] ?? '') ?: 'Referral',
            ]);
        }

        log_audit('student', $studentId, 'self_register_draft', null, ['form_no' => $formNo, 'name' => $data['name']]);
        $pdo->commit();

        $submittedFormNo = $formNo;
        $submittedName = $data['name'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-app" style="padding-top: 18px; padding-bottom: 40px;">

    <?php if ($submittedFormNo): ?>
        <div class="card-app text-center py-4">
            <div class="mb-3 text-success" style="font-size: 3.4rem;"><i class="bi bi-check-circle-fill"></i></div>
            <h4 class="fw-bold text-dark mb-2">ફોર્મ સફળતાપૂર્વક ભરાઈ ગયું!</h4>
            <p class="text-muted small mb-3">
                આભાર, <strong><?= htmlspecialchars($submittedName) ?></strong>. તમારું એડમિશન ફોર્મ ઓફિસમાં નોંધાઈ ગયું છે.
            </p>

            <div class="p-3 rounded-3 mb-3" style="background: #eff6ff; border: 1.5px solid #bfdbfe;">
                <div class="small text-muted mb-1">તમારો ફોર્મ નંબર (Form No.)</div>
                <div class="fs-4 fw-bold text-primary font-monospace"><?= htmlspecialchars($submittedFormNo) ?></div>
            </div>

            <div class="p-3 rounded-3 mb-4 text-start small" style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e;">
                <div class="fw-bold mb-1"><i class="bi bi-info-circle-fill me-1"></i> આગળની પ્રક્રિયા:</div>
                ક્લાસ ઓફિસ પર જઈને <strong>₹<?= number_format($formFee, 0) ?> ફોર્મ ફી</strong> જમા કરાવ્યા બાદ તમારું રજીસ્ટ્રેશન કન્ફર્મ થશે, ત્યારબાદ જ તમે મોબાઈલ નંબરથી આ એપમાં લોગિન કરી શકશો.
            </div>

            <div class="d-grid gap-2">
                <a href="/public/student/info.php" class="visitor-btn-outline">
                    <i class="bi bi-info-circle"></i>
                    <span>ક્લાસની ફી અને બેચ સમય જુઓ</span>
                </a>
                <a href="/public/student/login.php" class="btn-app text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i> હોમ / લોગિન પેજ પર જાઓ
                </a>
            </div>
        </div>
    <?php else: ?>

        <div class="card-app mb-3">
            <div class="card-title mb-2">
                <span><i class="bi bi-pencil-square text-primary me-2"></i> નવું એડમિશન ફોર્મ</span>
            </div>
            <p class="small text-muted mb-0">
                ક્લાસમાં જોડાવા માટે નીચેની વિગતો ભરો. ફોર્મ ભર્યા પછી ઓફિસમાં <strong>₹<?= number_format($formFee, 0) ?> ફોર્મ ફી</strong> ભરવાથી એડમિશન કન્ફર્મ થશે.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small rounded-3 mb-3">
                <i class="bi bi-exclamation-circle-fill me-1"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="card-app">
            <?= csrf_field() ?>

            <!-- 3 Separate Name Boxes: Surname, Name, Father/Husband Name -->
            <div class="row g-2 mb-2">
                <div class="col-12 col-sm-4">
                    <div class="form-group mb-0">
                        <label class="form-label">અટક (Surname) *</label>
                        <input type="text" id="apply_surname" name="surname" class="form-control-app" required value="<?= htmlspecialchars($_POST['surname'] ?? '') ?>" placeholder="દા.ત. ધામેલીયા / પટેલ" oninput="updateApplyFullName()">
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="form-group mb-0">
                        <label class="form-label">વિદ્યાર્થીનું નામ (Name) *</label>
                        <input type="text" id="apply_first_name" name="first_name" class="form-control-app" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" placeholder="દા.ત. મોનિકા / રીનાબેન" oninput="updateApplyFullName()">
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="form-group mb-0">
                        <label class="form-label">પિતા / પતિનું નામ</label>
                        <input type="text" id="apply_father_husband" name="father_husband_name" class="form-control-app" value="<?= htmlspecialchars($_POST['father_husband_name'] ?? '') ?>" placeholder="દા.ત. હાર્દિકભાઈ / રમેશભાઈ" oninput="updateApplyFullName()">
                    </div>
                </div>
            </div>

            <!-- Full Name Live Badge Preview -->
            <div class="p-2 rounded-2 mb-3" style="background: #f8fafc; border: 1px dashed #cbd5e1; font-size: 0.84rem;">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-1">
                    <div>
                        <span class="text-muted fw-semibold">ફોર્મમાં લખાતું પૂરું નામ:</span>
                        <strong id="applyFullNamePreview" class="text-primary font-monospace ms-1">-</strong>
                    </div>
                    <small class="text-muted">(અટક + નામ + પિતા/પતિનું નામ)</small>
                </div>
                <input type="hidden" id="apply_name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">
                    <?= htmlspecialchars($fields['student_mobile']['gujarati_label']) ?> (Mobile No.)
                    <?= $fields['student_mobile']['required'] ? '*' : '' ?>
                </label>
                <input type="tel" name="student_mobile" class="form-control-app" inputmode="numeric" maxlength="15"
                       <?= $fields['student_mobile']['required'] ? 'required' : '' ?>
                       value="<?= htmlspecialchars($_POST['student_mobile'] ?? '') ?>" placeholder="10 આંકડાનો મોબાઈલ નંબર">
            </div>

            <?php if ($fields['father_mobile']['visible']): ?>
            <div class="form-group">
                <label class="form-label">
                    <?= htmlspecialchars($fields['father_mobile']['gujarati_label']) ?>
                    <?= $fields['father_mobile']['required'] ? '*' : '' ?>
                </label>
                <input type="tel" name="husband_father_mobile" class="form-control-app" inputmode="numeric" maxlength="15"
                       <?= $fields['father_mobile']['required'] ? 'required' : '' ?>
                       value="<?= htmlspecialchars($_POST['husband_father_mobile'] ?? '') ?>" placeholder="પતિ / પિતાનો નંબર">
            </div>
            <?php endif; ?>

            <?php if ($fields['home_mobile']['visible']): ?>
            <div class="form-group">
                <label class="form-label">
                    <?= htmlspecialchars($fields['home_mobile']['gujarati_label']) ?>
                    <?= $fields['home_mobile']['required'] ? '*' : '' ?>
                </label>
                <input type="tel" name="home_mobile" class="form-control-app" inputmode="numeric" maxlength="15"
                       <?= $fields['home_mobile']['required'] ? 'required' : '' ?>
                       value="<?= htmlspecialchars($_POST['home_mobile'] ?? '') ?>" placeholder="ઘરનો બીજો નંબર">
            </div>
            <?php endif; ?>

            <div class="row g-2">
                <?php if ($fields['age']['visible']): ?>
                <div class="col-5">
                    <div class="form-group">
                        <label class="form-label">ઉંમર <?= $fields['age']['required'] ? '*' : '' ?></label>
                        <input type="number" name="age" class="form-control-app" min="10" max="80"
                               <?= $fields['age']['required'] ? 'required' : '' ?>
                               value="<?= htmlspecialchars($_POST['age'] ?? '') ?>" placeholder="વર્ષ">
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($fields['education']['visible']): ?>
                <div class="<?= $fields['age']['visible'] ? 'col-7' : 'col-12' ?>">
                    <div class="form-group">
                        <label class="form-label">અભ્યાસ <?= $fields['education']['required'] ? '*' : '' ?></label>
                        <input type="text" name="education" class="form-control-app"
                               <?= $fields['education']['required'] ? 'required' : '' ?>
                               value="<?= htmlspecialchars($_POST['education'] ?? '') ?>" placeholder="દા.ત. 10th / 12th / Graduate">
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($fields['address']['visible']): ?>
            <div class="form-group">
                <label class="form-label">પૂરું સરનામું (Address) <?= $fields['address']['required'] ? '*' : '' ?></label>
                <textarea name="address" class="form-control-app" rows="2" <?= $fields['address']['required'] ? 'required' : '' ?> placeholder="ઘર નં., સોસાયટી, વિસ્તાર, શહેર"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">પસંદગીનો બેચ સમય (Preferred Batch)</label>
                <select name="preferred_batch_id" id="applyBatchSelect" class="form-control-app">
                    <option value="">-- બેચ પસંદ કરો --</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (($_POST['preferred_batch_id'] ?? '') == $b['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['name']) ?> (<?= date('h:i A', strtotime($b['start_time'])) ?> - <?= date('h:i A', strtotime($b['end_time'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="any_batch" value="1" id="anyBatchCheck" <?= !empty($_POST['any_batch']) ? 'checked' : '' ?> onchange="document.getElementById('applyBatchSelect').disabled = this.checked;">
                    <label class="form-check-label small fw-semibold" for="anyBatchCheck">
                        ગમે તે બેચ ચાલશે (Any Available Batch)
                    </label>
                </div>
            </div>

            <?php if ($fields['knows_sewing']['visible']): ?>
            <div class="form-group">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="knows_sewing" value="1" id="knowsSewingCheck" <?= !empty($_POST['knows_sewing']) ? 'checked' : '' ?>>
                    <label class="form-check-label small fw-semibold" for="knowsSewingCheck">
                        પહેલાથી થોડું સિલાઈકામ આવડે છે?
                    </label>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($fields['hear_about_us']['visible']): ?>
            <div class="form-group">
                <label class="form-label">ક્લાસ વિશે ક્યાંથી જાણ થઈ? <?= $fields['hear_about_us']['required'] ? '*' : '' ?></label>
                <input type="text" name="hear_about_us" class="form-control-app"
                       <?= $fields['hear_about_us']['required'] ? 'required' : '' ?>
                       value="<?= htmlspecialchars($_POST['hear_about_us'] ?? '') ?>" placeholder="દા.ત. મિત્ર / Instagram / બોર્ડ">
            </div>
            <?php endif; ?>

            <?php if ($fields['referral']['visible']): ?>
            <div class="row g-2">
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">ઓળખાણ આપનારનું નામ <?= $fields['referral']['required'] ? '*' : '' ?></label>
                        <input type="text" name="reference_person_name" class="form-control-app"
                               <?= $fields['referral']['required'] ? 'required' : '' ?>
                               value="<?= htmlspecialchars($_POST['reference_person_name'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">તેમનો મોબાઈલ <?= $fields['referral']['required'] ? '*' : '' ?></label>
                        <input type="tel" name="reference_mobile" class="form-control-app" inputmode="numeric"
                               <?= $fields['referral']['required'] ? 'required' : '' ?>
                               value="<?= htmlspecialchars($_POST['reference_mobile'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($fields['guardian']['visible']): ?>
            <div class="row g-2">
                <div class="col-7">
                    <div class="form-group">
                        <label class="form-label">વાલીનું નામ <?= $fields['guardian']['required'] ? '*' : '' ?></label>
                        <input type="text" name="guardian_signature_name" class="form-control-app"
                               <?= $fields['guardian']['required'] ? 'required' : '' ?>
                               value="<?= htmlspecialchars($_POST['guardian_signature_name'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-5">
                    <div class="form-group">
                        <label class="form-label">સંબંધ <?= $fields['guardian']['required'] ? '*' : '' ?></label>
                        <input type="text" name="guardian_relation" class="form-control-app"
                               <?= $fields['guardian']['required'] ? 'required' : '' ?>
                               value="<?= htmlspecialchars($_POST['guardian_relation'] ?? '') ?>" placeholder="દા.ત. પતિ / પિતા">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($fields['photo']['visible']): ?>
            <div class="form-group">
                <label class="form-label">પાસપોર્ટ ફોટો (Photo) <?= $fields['photo']['required'] ? '*' : '' ?></label>
                <input type="file" name="photo" class="form-control" accept="image/*" <?= $fields['photo']['required'] ? 'required' : '' ?>>
                <div class="form-text" style="font-size: 0.74rem;">ફોનમાંથી ફોટો પાડીને અથવા ગેલેરીમાંથી પસંદ કરો.</div>
            </div>
            <?php endif; ?>

            <!-- Rules & Agreement -->
            <div class="p-3 rounded-3 mb-3" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold small text-dark"><i class="bi bi-shield-check text-primary me-1"></i> ક્લાસના નિયમો</span>
                    <a href="/public/student/info.php" class="small text-primary fw-semibold text-decoration-none">બધા નિયમો વાંચો &rarr;</a>
                </div>
                <?php if ($cautionText): ?>
                    <p class="small text-muted mb-2" style="font-size: 0.78rem;"><?= htmlspecialchars($cautionText) ?></p>
                <?php endif; ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="agree_rules" value="1" id="agreeRulesCheck" required>
                    <label class="form-check-label small fw-bold text-dark" for="agreeRulesCheck">
                        મેં ક્લાસના બધા નિયમો વાંચ્યા છે અને મને મંજૂર છે. *
                    </label>
                </div>
            </div>

            <button type="submit" class="btn-app">
                <i class="bi bi-check2-circle me-1"></i> ફોર્મ સબમિટ કરો (Submit Form)
            </button>
        </form>

    <?php endif; ?>
</div>

<script>
function updateApplyFullName() {
    var surname = (document.getElementById('apply_surname')?.value || '').trim();
    var first = (document.getElementById('apply_first_name')?.value || '').trim();
    var father = (document.getElementById('apply_father_husband')?.value || '').trim();
    var full = [surname, first, father].filter(Boolean).join(' ');
    var preview = document.getElementById('applyFullNamePreview');
    var hidden = document.getElementById('apply_name');
    if (preview) preview.textContent = full || '-';
    if (hidden) hidden.value = full;
}
document.addEventListener('DOMContentLoaded', updateApplyFullName);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
