<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_once __DIR__ . '/../includes/services/StudentService.php';
require_admin();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$student = get_student($id);

if (!$student) {
    flash('error', 'Student not found.');
    redirect('public/students_list.php');
}

$refStmt = $pdo->prepare("SELECT * FROM referrals WHERE student_id = ? LIMIT 1");
$refStmt->execute([$id]);
$referral = $refStmt->fetch() ?: null;

$fields = StudentService::getFormFieldConfig();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $newPhotoPath = null;
        if ($fields['photo']['visible'] && (!empty($_FILES['photo']['name']) || !empty($_POST['cropped_photo_data']))) {
            $newPhotoPath = save_uploaded_student_photo(
                $_POST['cropped_photo_data'] ?? null,
                $_FILES['photo'] ?? null,
                UPLOAD_DIR,
                UPLOAD_URL,
                MAX_PHOTO_SIZE_MB
            );
        }
        $finalPhotoPath = $newPhotoPath ?: $student['photo_path'];

        // Merge hidden fields from existing student record so hidden settings never wipe stored values
        $mergedInput = $_POST;
        if (!$fields['age']['visible']) $mergedInput['age'] = $student['age'];
        if (!$fields['father_mobile']['visible']) $mergedInput['husband_father_mobile'] = $student['husband_father_mobile'];
        if (!$fields['home_mobile']['visible']) $mergedInput['home_mobile'] = $student['home_mobile'];
        if (!$fields['education']['visible']) $mergedInput['education'] = $student['education'];
        if (!$fields['address']['visible']) $mergedInput['address'] = $student['address'];
        if (!$fields['knows_sewing']['visible']) {
            $mergedInput['knows_sewing'] = $student['knows_sewing'];
            $mergedInput['previous_sewing_work'] = $student['previous_sewing_work'];
        }
        if (!$fields['hear_about_us']['visible']) $mergedInput['hear_about_us'] = $student['hear_about_us'];
        if (!$fields['guardian']['visible']) {
            $mergedInput['guardian_signature_name'] = $student['guardian_signature_name'];
            $mergedInput['guardian_relation'] = $student['guardian_relation'];
        }

        $data = StudentService::validateStudentData($mergedInput, [
            'is_edit' => true,
            'has_photo' => !empty($finalPhotoPath),
        ]);

        $form_no = trim($_POST['form_no'] ?? '');
        $registration_no = trim($_POST['registration_no'] ?? '');
        $entry_date = $_POST['entry_date'] ?: null;
        $final_expiry_date = $_POST['final_expiry_date'] ?: null;
        $status = $_POST['status'] ?? $student['status'];
        $joined_batch_id = !empty($_POST['joined_batch_id']) ? (int)$_POST['joined_batch_id'] : null;

        $pdo->beginTransaction();

        $sql = "UPDATE students SET 
                name = ?, age = ?, student_mobile = ?, husband_father_mobile = ?, home_mobile = ?, 
                education = ?, address = ?, knows_sewing = ?, previous_sewing_work = ?,
                hear_about_us = ?, guardian_signature_name = ?, guardian_relation = ?, photo_path = ?,
                form_no = ?, registration_no = ?, 
                entry_date = ?, final_expiry_date = ?, status = ?, joined_batch_id = ?,
                updated_at = NOW()
                WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
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
            $finalPhotoPath,
            $form_no,
            $registration_no !== '' ? $registration_no : null,
            $entry_date,
            $final_expiry_date,
            $status,
            $joined_batch_id,
            $id
        ]);

        if ($fields['referral']['visible']) {
            if (!empty($data['reference_person_name']) || !empty($data['reference_mobile'])) {
                if ($referral) {
                    $pdo->prepare("UPDATE referrals SET relative_name = ?, relative_mobile = ? WHERE id = ?")
                        ->execute([$data['reference_person_name'], $data['reference_mobile'], $referral['id']]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO referrals (student_id, relative_name, relative_mobile, relation)
                         VALUES (?, ?, ?, 'Referral')"
                    )->execute([$id, $data['reference_person_name'], $data['reference_mobile']]);
                }
            } elseif ($referral) {
                $pdo->prepare("DELETE FROM referrals WHERE id = ?")->execute([$referral['id']]);
            }
        }

        log_audit('student', $id, 'edit', ['old_status' => $student['status']], ['new_status' => $status, 'edited_by' => current_user()['id']]);
        $pdo->commit();

        flash('success', 'Student details updated successfully.');
        redirect('public/student_profile.php?id=' . $id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$batches = $pdo->query("SELECT id, name FROM batches ORDER BY start_time")->fetchAll();

$pageTitle = 'Edit Student: ' . e($student['name']);
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.req-star {
    color: var(--danger);
    font-weight: 700;
}
.radio-pill-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
@media (max-width: 768px) {
    .radio-pill-grid { grid-template-columns: repeat(2, 1fr); }
}
.radio-pill-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border: 1px solid var(--border-input);
    border-radius: var(--radius);
    background: var(--card-bg);
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-body);
    margin: 0;
}
.radio-pill-option:has(input:checked) {
    border-color: var(--primary);
    background: var(--primary-light);
    color: var(--primary-hover);
}
</style>

<div class="card" style="max-width: 860px; margin: 0 auto;">
    <div class="card-header-bar">
        <div>
            <h2>Edit Student Details</h2>
            <small class="muted">Fields marked with <span class="req-star">*</span> are enforced dynamically from Settings.</small>
        </div>
        <a href="<?= url('public/student_profile.php?id=' . $id) ?>" class="btn btn-sm btn-secondary">Back to Profile</a>
    </div>

    <?php if ($error): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="section-title">Personal Information</div>
        <div class="row form-group">
            <div class="<?= !$fields['age']['visible'] ? 'col-full' : '' ?>">
                <label for="edit_name">Full Name <span class="req-star">*</span></label>
                <input type="text" id="edit_name" name="name" value="<?= e($_POST['name'] ?? $student['name']) ?>" required>
            </div>
            <?php if ($fields['age']['visible']): ?>
            <div>
                <label for="edit_age">Age <?= $fields['age']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="number" id="edit_age" name="age" min="1" value="<?= e($_POST['age'] ?? $student['age']) ?>" <?= $fields['age']['required'] ? 'required' : '' ?>>
            </div>
            <?php endif; ?>
            <div>
                <label for="edit_mobile">Student Mobile <?= $fields['student_mobile']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="edit_mobile" name="student_mobile" value="<?= e($_POST['student_mobile'] ?? $student['student_mobile']) ?>" <?= $fields['student_mobile']['required'] ? 'required' : '' ?>>
            </div>
            <?php if ($fields['father_mobile']['visible']): ?>
            <div>
                <label for="edit_guardian_mobile">Father / Husband Mobile <?= $fields['father_mobile']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="edit_guardian_mobile" name="husband_father_mobile" value="<?= e($_POST['husband_father_mobile'] ?? $student['husband_father_mobile']) ?>" <?= $fields['father_mobile']['required'] ? 'required' : '' ?>>
            </div>
            <?php endif; ?>
            <?php if ($fields['home_mobile']['visible']): ?>
            <div>
                <label for="edit_home_mobile">Home Mobile <?= $fields['home_mobile']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="edit_home_mobile" name="home_mobile" value="<?= e($_POST['home_mobile'] ?? $student['home_mobile']) ?>" <?= $fields['home_mobile']['required'] ? 'required' : '' ?>>
            </div>
            <?php endif; ?>
            <?php if ($fields['education']['visible']): ?>
            <div>
                <label for="edit_education">Education <?= $fields['education']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="edit_education" name="education" value="<?= e($_POST['education'] ?? $student['education']) ?>" <?= $fields['education']['required'] ? 'required' : '' ?>>
            </div>
            <?php endif; ?>
            <?php if ($fields['address']['visible']): ?>
            <div class="col-full">
                <label for="edit_address">Address <?= $fields['address']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <textarea id="edit_address" name="address" rows="2" <?= $fields['address']['required'] ? 'required' : '' ?>><?= e($_POST['address'] ?? $student['address']) ?></textarea>
            </div>
            <?php endif; ?>
            <?php if ($fields['photo']['visible']): ?>
            <div class="col-full">
                <label for="edit_photo">Passport Photo <?= ($fields['photo']['required'] && empty($student['photo_path'])) ? '<span class="req-star">*</span>' : '' ?></label>
                <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                    <?php if (!empty($student['photo_path'])): ?>
                        <img src="<?= e(url($student['photo_path'])) ?>" alt="Current Photo" style="width: 54px; height: 70px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border);">
                    <?php endif; ?>
                    <input type="file" id="edit_photo" name="photo" accept="image/*" style="flex: 1; padding: 5px 10px; height: auto;" <?= ($fields['photo']['required'] && empty($student['photo_path'])) ? 'required' : '' ?>>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($fields['knows_sewing']['visible'] || $fields['hear_about_us']['visible'] || $fields['referral']['visible'] || $fields['guardian']['visible']): ?>
        <div class="section-title">Questionnaire, Referral &amp; Guardian</div>
        <?php endif; ?>

        <?php if ($fields['knows_sewing']['visible']): ?>
        <?php $knowsVal = (string)($_POST['knows_sewing'] ?? ($student['knows_sewing'] ?? '0')); ?>
        <div class="row form-group" style="align-items: center;">
            <div>
                <label>Knows Sewing? <?= $fields['knows_sewing']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <div class="radio-pill-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <label class="radio-pill-option">
                        <input type="radio" name="knows_sewing" value="1" <?= $knowsVal === '1' ? 'checked' : '' ?> <?= $fields['knows_sewing']['required'] ? 'required' : '' ?>>
                        <span>Yes (હા)</span>
                    </label>
                    <label class="radio-pill-option">
                        <input type="radio" name="knows_sewing" value="0" <?= $knowsVal === '0' ? 'checked' : '' ?>>
                        <span>No (ના)</span>
                    </label>
                </div>
            </div>
            <div>
                <label for="edit_prev_sewing">Previous Sewing Work</label>
                <input type="text" id="edit_prev_sewing" name="previous_sewing_work" value="<?= e($_POST['previous_sewing_work'] ?? $student['previous_sewing_work']) ?>">
            </div>
        </div>
        <?php endif; ?>

        <?php if ($fields['hear_about_us']['visible']): ?>
        <?php $hearVal = $_POST['hear_about_us'] ?? ($student['hear_about_us'] ?? ''); ?>
        <div class="form-group">
            <label>How Did You Hear About Us? <?= $fields['hear_about_us']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
            <div class="radio-pill-grid">
                <?php foreach (['Instagram', 'Facebook', 'Board/Banner', 'Relative/Friend'] as $idx => $src): ?>
                <label class="radio-pill-option">
                    <input type="radio" name="hear_about_us" value="<?= e($src) ?>" <?= $hearVal === $src ? 'checked' : '' ?> <?= ($idx === 0 && $fields['hear_about_us']['required']) ? 'required' : '' ?>>
                    <span><?= e($src) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($fields['referral']['visible']): ?>
        <div class="row form-group">
            <div>
                <label for="edit_ref_name">Reference Person Name <?= $fields['referral']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="edit_ref_name" name="reference_person_name" value="<?= e($_POST['reference_person_name'] ?? ($referral['relative_name'] ?? '')) ?>" <?= $fields['referral']['required'] ? 'required' : '' ?>>
            </div>
            <div>
                <label for="edit_ref_mobile">Reference Mobile <?= $fields['referral']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="edit_ref_mobile" name="reference_mobile" value="<?= e($_POST['reference_mobile'] ?? ($referral['relative_mobile'] ?? '')) ?>" <?= $fields['referral']['required'] ? 'required' : '' ?>>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($fields['guardian']['visible']): ?>
        <div class="row form-group">
            <div>
                <label for="edit_guard_name">Guardian Name <?= $fields['guardian']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="edit_guard_name" name="guardian_signature_name" value="<?= e($_POST['guardian_signature_name'] ?? $student['guardian_signature_name']) ?>" <?= $fields['guardian']['required'] ? 'required' : '' ?>>
            </div>
            <div>
                <label for="edit_guard_rel">Guardian Relation <?= $fields['guardian']['required'] ? '<span class="req-star">*</span>' : '' ?></label>
                <input type="text" id="edit_guard_rel" name="guardian_relation" value="<?= e($_POST['guardian_relation'] ?? $student['guardian_relation']) ?>" <?= $fields['guardian']['required'] ? 'required' : '' ?>>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-title">Admission &amp; Course Data</div>
        <div class="row form-group" style="align-items: start;">
            <div>
                <label for="edit_form_no">Form No.</label>
                <input type="text" id="edit_form_no" name="form_no" value="<?= e($_POST['form_no'] ?? $student['form_no']) ?>">
            </div>
            <div>
                <label for="edit_reg_no">Registration No.</label>
                <input type="text" id="edit_reg_no" name="registration_no" value="<?= e($_POST['registration_no'] ?? $student['registration_no']) ?>">
            </div>
            <div>
                <label for="edit_entry_date">Entry Date (First Day)</label>
                <input type="date" id="edit_entry_date" name="entry_date" value="<?= e($_POST['entry_date'] ?? $student['entry_date']) ?>">
                <small class="muted" style="display: block; margin-top: 4px; font-size: 11.5px;">Warning: Changing this affects payment due dates.</small>
            </div>
            <div>
                <label for="edit_expiry_date">Final Expiry Date (Last Class Day)</label>
                <input type="date" id="edit_expiry_date" name="final_expiry_date" value="<?= e($_POST['final_expiry_date'] ?? $student['final_expiry_date']) ?>">
                <small class="muted" style="display: block; margin-top: 4px; font-size: 11.5px;">The seat becomes vacant the day after this date.</small>
            </div>
            <div>
                <label for="edit_status">Course Status</label>
                <select id="edit_status" name="status">
                    <?php
                    $statuses = ['draft', 'confirmed', 'waiting_ready', 'waiting_future_date', 'waiting_date_not_confirmed', 'joining_confirmed', 'active', 'extended', 'course_completed', 'paused', 'cancelled'];
                    $curStatus = $_POST['status'] ?? $student['status'];
                    foreach ($statuses as $st) {
                        $sel = ($curStatus === $st) ? 'selected' : '';
                        echo "<option value=\"$st\" $sel>" . ucwords(str_replace('_', ' ', $st)) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="edit_batch">Joined Batch</label>
                <select id="edit_batch" name="joined_batch_id">
                    <option value="">-- No Batch --</option>
                    <?php $curBatch = $_POST['joined_batch_id'] ?? $student['joined_batch_id']; ?>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $curBatch == $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-actions" style="justify-content: flex-end; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-light);">
            <a href="<?= url('public/student_profile.php?id=' . $id) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn">Save Changes</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
