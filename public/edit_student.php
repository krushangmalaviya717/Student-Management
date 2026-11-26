<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_admin();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$student = get_student($id);

if (!$student) {
    flash('error', 'Student not found.');
    redirect('public/students_list.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $name = trim($_POST['name'] ?? '');
        $student_mobile = trim($_POST['student_mobile'] ?? '');
        $husband_father_mobile = trim($_POST['husband_father_mobile'] ?? '');
        $home_mobile = trim($_POST['home_mobile'] ?? '');
        $address = trim($_POST['address'] ?? '');

        $form_no = trim($_POST['form_no'] ?? '');
        $registration_no = trim($_POST['registration_no'] ?? '');

        $entry_date = $_POST['entry_date'] ?: null;
        $final_expiry_date = $_POST['final_expiry_date'] ?: null;
        $status = $_POST['status'] ?? $student['status'];

        $joined_batch_id = !empty($_POST['joined_batch_id']) ? (int)$_POST['joined_batch_id'] : null;

        if (!$name || !$student_mobile) {
            throw new RuntimeException('Name and Student Mobile are required.');
        }

        $sql = "UPDATE students SET 
                name = ?, student_mobile = ?, husband_father_mobile = ?, home_mobile = ?, 
                address = ?, form_no = ?, registration_no = ?, 
                entry_date = ?, final_expiry_date = ?, status = ?, joined_batch_id = ?,
                updated_at = NOW()
                WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $name, $student_mobile, $husband_father_mobile, $home_mobile,
            $address, $form_no, $registration_no,
            $entry_date, $final_expiry_date, $status, $joined_batch_id,
            $id
        ]);

        log_audit('student', $id, 'edit', ['old_status' => $student['status']], ['new_status' => $status, 'edited_by' => current_user()['id']]);

        flash('success', 'Student details updated successfully.');
        redirect('public/student_profile.php?id=' . $id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$batches = $pdo->query("SELECT id, name FROM batches ORDER BY start_time")->fetchAll();

$pageTitle = 'Edit Student: ' . e($student['name']);
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="card" style="max-width: 820px; margin: 0 auto;">
    <div class="card-header-bar">
        <div>
            <h2>Edit Student Details</h2>
            <small class="muted">Update personal contact information or course/batch assignment details.</small>
        </div>
        <a href="<?= url('public/student_profile.php?id=' . $id) ?>" class="btn btn-sm btn-secondary">Back to Profile</a>
    </div>

    <?php if ($error): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <div class="section-title">Personal Information</div>
        <div class="row form-group">
            <div>
                <label for="edit_name">Full Name *</label>
                <input type="text" id="edit_name" name="name" value="<?= e($student['name']) ?>" required>
            </div>
            <div>
                <label for="edit_mobile">Student Mobile *</label>
                <input type="text" id="edit_mobile" name="student_mobile" value="<?= e($student['student_mobile']) ?>" required>
            </div>
            <div>
                <label for="edit_guardian_mobile">Father / Husband Mobile</label>
                <input type="text" id="edit_guardian_mobile" name="husband_father_mobile" value="<?= e($student['husband_father_mobile']) ?>">
            </div>
            <div>
                <label for="edit_home_mobile">Home Mobile</label>
                <input type="text" id="edit_home_mobile" name="home_mobile" value="<?= e($student['home_mobile']) ?>">
            </div>
            <div class="col-full">
                <label for="edit_address">Address</label>
                <textarea id="edit_address" name="address" rows="2"><?= e($student['address']) ?></textarea>
            </div>
        </div>

        <div class="section-title">Admission &amp; Course Data</div>
        <div class="row form-group" style="align-items: start;">
            <div>
                <label for="edit_form_no">Form No.</label>
                <input type="text" id="edit_form_no" name="form_no" value="<?= e($student['form_no']) ?>">
            </div>
            <div>
                <label for="edit_reg_no">Registration No.</label>
                <input type="text" id="edit_reg_no" name="registration_no" value="<?= e($student['registration_no']) ?>">
            </div>
            <div>
                <label for="edit_entry_date">Entry Date (First Day)</label>
                <input type="date" id="edit_entry_date" name="entry_date" value="<?= e($student['entry_date']) ?>">
                <small class="muted" style="display: block; margin-top: 4px; font-size: 11.5px;">Warning: Changing this affects payment due dates.</small>
            </div>
            <div>
                <label for="edit_expiry_date">Final Expiry Date (Last Class Day)</label>
                <input type="date" id="edit_expiry_date" name="final_expiry_date" value="<?= e($student['final_expiry_date']) ?>">
                <small class="muted" style="display: block; margin-top: 4px; font-size: 11.5px;">The seat becomes vacant the day after this date.</small>
            </div>
            <div>
                <label for="edit_status">Course Status</label>
                <select id="edit_status" name="status">
                    <?php
                    $statuses = ['draft', 'confirmed', 'waiting_ready', 'waiting_future_date', 'waiting_date_not_confirmed', 'joining_confirmed', 'active', 'extended', 'course_completed', 'cancelled'];
                    foreach ($statuses as $s) {
                        $sel = ($student['status'] === $s) ? 'selected' : '';
                        echo "<option value=\"$s\" $sel>" . ucwords(str_replace('_', ' ', $s)) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="edit_batch">Joined Batch</label>
                <select id="edit_batch" name="joined_batch_id">
                    <option value="">-- No Batch --</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $student['joined_batch_id'] == $b['id'] ? 'selected' : '' ?>>
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
