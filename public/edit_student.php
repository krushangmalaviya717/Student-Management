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

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2 style="margin:0;">Edit Student Details</h2>
        <a href="<?= url('public/student_profile.php?id=' . $id) ?>" class="btn btn-secondary">Back to Profile</a>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        
        <div class="grid grid-2" style="gap:16px;">
            <!-- Personal Info -->
            <div style="grid-column: span 2;">
                <h3 style="border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Personal Information</h3>
            </div>
            
            <div>
                <label>Full Name *</label>
                <input type="text" name="name" value="<?= e($student['name']) ?>" required>
            </div>
            
            <div>
                <label>Student Mobile *</label>
                <input type="text" name="student_mobile" value="<?= e($student['student_mobile']) ?>" required>
            </div>
            
            <div>
                <label>Father/Husband Mobile</label>
                <input type="text" name="husband_father_mobile" value="<?= e($student['husband_father_mobile']) ?>">
            </div>
            
            <div>
                <label>Home Mobile</label>
                <input type="text" name="home_mobile" value="<?= e($student['home_mobile']) ?>">
            </div>
            
            <div style="grid-column: span 2;">
                <label>Address</label>
                <textarea name="address" rows="2"><?= e($student['address']) ?></textarea>
            </div>

            <!-- Admission & Course Info -->
            <div style="grid-column: span 2; margin-top:16px;">
                <h3 style="border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Admission & Course Data (Careful!)</h3>
            </div>

            <div>
                <label>Form No.</label>
                <input type="text" name="form_no" value="<?= e($student['form_no']) ?>">
            </div>
            
            <div>
                <label>Registration No.</label>
                <input type="text" name="registration_no" value="<?= e($student['registration_no']) ?>">
            </div>

            <div>
                <label>Entry Date (First Day)</label>
                <input type="date" name="entry_date" value="<?= e($student['entry_date']) ?>">
                <small class="muted">Warning: Changing this affects payment due dates.</small>
            </div>
            
            <div>
                <label>Final Expiry Date (તાલીમનો છેલ્લો દિવસ / Last Class Day)</label>
                <input type="date" name="final_expiry_date" value="<?= e($student['final_expiry_date']) ?>">
                <small class="muted">વિદ્યાર્થીની તાલીમનો આ છેલ્લો દિવસ ગણાય છે. તેના પછીના દિવસે સીટ ખાલી થશે.</small>
            </div>

            <div>
                <label>Course Status</label>
                <select name="status">
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
                <label>Joined Batch</label>
                <select name="joined_batch_id">
                    <option value="">-- No Batch --</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $student['joined_batch_id'] == $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <div style="margin-top: 24px;">
            <button type="submit" class="btn" style="padding: 10px 24px; font-size: 15px;">Save Changes</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
