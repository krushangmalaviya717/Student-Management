<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

$pdo = db();
$results = null;
$error = null;

// Handle Sample CSV Download
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="devangi_student_import_sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'form_no',
        'registration_no',
        'name',
        'student_mobile',
        'husband_father_mobile',
        'home_mobile',
        'age',
        'address',
        'knows_sewing',
        'previous_sewing_work',
        'batch_id_or_time',
        'entry_date',
        'expiry_date',
        'status',
        'relative_name',
        'relative_mobile',
        'relation'
    ]);
    fputcsv($out, [
        'F-2025-001',
        'REG-001',
        'Pooja Patel',
        '9876543210',
        '9876543211',
        '',
        '24',
        '12, Shanti Nagar, Surat',
        '1',
        'Basic stitching experience',
        '10:00 AM – 12:00 PM',
        '2026-08-01',
        '2026-09-15',
        'active',
        'Riya Patel',
        '9876543212',
        'Sister'
    ]);
    fputcsv($out, [
        'F-2025-002',
        'REG-002',
        'Anjali Sharma',
        '9123456780',
        '',
        '',
        '28',
        '45, Navrang Society, Surat',
        '0',
        '',
        '1:00 PM – 3:00 PM',
        '2026-07-10',
        '2026-08-24',
        'course_completed',
        '',
        '',
        ''
    ]);
    fclose($out);
    exit;
}

// Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    verify_csrf();
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed. Please choose a valid CSV file.';
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        if (!$handle) {
            $error = 'Unable to read uploaded file.';
        } else {
            $header = fgetcsv($handle);
            $header = array_map(function ($h) { return strtolower(trim(str_replace([' ', '-'], '_', $h))); }, $header);

            $batches = $pdo->query("SELECT id, name FROM batches")->fetchAll();
            $batchList = [];
            foreach ($batches as $b) {
                $batchList[strtolower(trim($b['name']))] = (int)$b['id'];
                $batchList[(string)$b['id']] = (int)$b['id'];
            }

            $importedCount = 0;
            $skippedCount = 0;
            $errors = [];
            $rowNum = 1;

            $pdo->beginTransaction();
            try {
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    if (empty(array_filter($row))) continue;

                    $data = array_combine($header, $row);
                    if (!$data) {
                        $skippedCount++;
                        $errors[] = "Row #$rowNum: Column count mismatch.";
                        continue;
                    }

                    $name = trim($data['name'] ?? '');
                    $mobile = trim($data['student_mobile'] ?? '');
                    if ($name === '' || $mobile === '') {
                        $skippedCount++;
                        $errors[] = "Row #$rowNum: Name and Student Mobile are required.";
                        continue;
                    }

                    $formNo = trim($data['form_no'] ?? '');
                    if ($formNo === '') {
                        $formNo = generate_form_no();
                    }
                    $regNo = trim($data['registration_no'] ?? '') ?: null;

                    $dupStmt = $pdo->prepare("SELECT id FROM students WHERE form_no = ? OR (registration_no IS NOT NULL AND registration_no = ?)");
                    $dupStmt->execute([$formNo, $regNo]);
                    if ($dupStmt->fetch()) {
                        $skippedCount++;
                        $errors[] = "Row #$rowNum: Duplicate record for Form No '$formNo' or Reg No '$regNo'. Skipped.";
                        continue;
                    }

                    $batchStr = strtolower(trim($data['batch_id_or_time'] ?? ''));
                    $batchId = null;
                    if (isset($batchList[$batchStr])) {
                        $batchId = $batchList[$batchStr];
                    } else {
                        foreach ($batchList as $bName => $bId) {
                            if (str_contains($batchStr, '10') || str_contains($batchStr, '10:00')) { $batchId = 1; break; }
                            if (str_contains($batchStr, '1') || str_contains($batchStr, '1:00')) { $batchId = 2; break; }
                            if (str_contains($batchStr, '3') || str_contains($batchStr, '3:00')) { $batchId = 3; break; }
                            if (str_contains($batchStr, '5') || str_contains($batchStr, '5:00')) { $batchId = 4; break; }
                        }
                    }

                    $entryDate = trim($data['entry_date'] ?? '') ?: null;
                    $expiryDate = trim($data['expiry_date'] ?? '') ?: null;
                    $status = trim($data['status'] ?? 'active');
                    $validStatuses = ['draft', 'confirmed', 'waiting_ready', 'active', 'extended', 'course_completed', 'cancelled', 'inactive'];
                    if (!in_array($status, $validStatuses)) {
                        $status = 'active';
                    }

                    $stmt = $pdo->prepare(
                        "INSERT INTO students
                            (form_no, registration_no, form_submit_date, form_status, confirmed_at,
                             name, age, student_mobile, husband_father_mobile, home_mobile, address,
                             knows_sewing, previous_sewing_work, joined_batch_id, preferred_batch_id,
                             entry_date, base_expiry_date, final_expiry_date, course_status, status,
                             is_imported, created_by, created_at)
                         VALUES
                            (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())"
                    );
                    $formStatus = ($regNo || in_array($status, ['active', 'extended', 'course_completed'])) ? 'confirmed' : 'draft';
                    $confirmedAt = $formStatus === 'confirmed' ? ($entryDate ?: date('Y-m-d H:i:s')) : null;
                    $courseStatus = in_array($status, ['active', 'extended', 'course_completed']) ? ($status === 'extended' ? 'extended' : ($status === 'course_completed' ? 'completed' : 'active')) : 'not_started';

                    $stmt->execute([
                        $formNo,
                        $regNo,
                        $formStatus,
                        $confirmedAt,
                        $name,
                        (int)($data['age'] ?? 0) ?: null,
                        $mobile,
                        trim($data['husband_father_mobile'] ?? '') ?: null,
                        trim($data['home_mobile'] ?? '') ?: null,
                        trim($data['address'] ?? 'Surat'),
                        !empty($data['knows_sewing']) ? 1 : 0,
                        trim($data['previous_sewing_work'] ?? '') ?: null,
                        $batchId,
                        $batchId,
                        $entryDate,
                        $expiryDate,
                        $expiryDate,
                        $courseStatus,
                        $status,
                        current_user()['id']
                    ]);
                    $newStudentId = (int)$pdo->lastInsertId();

                    $relName = trim($data['relative_name'] ?? '');
                    if ($relName !== '') {
                        $pdo->prepare(
                            "INSERT INTO referrals (student_id, relative_name, relative_mobile, relation) VALUES (?, ?, ?, ?)"
                        )->execute([
                            $newStudentId,
                            $relName,
                            trim($data['relative_mobile'] ?? '') ?: null,
                            trim($data['relation'] ?? '') ?: 'Relative'
                        ]);
                    }

                    $importedCount++;
                }

                $pdo->commit();
                log_audit('student', null, 'import_csv', null, [
                    'imported_count' => $importedCount,
                    'skipped_count' => $skippedCount
                ]);

                $results = [
                    'imported' => $importedCount,
                    'skipped' => $skippedCount,
                    'errors' => $errors
                ];
                flash('success', "Import completed successfully! {$importedCount} records imported, {$skippedCount} skipped.");
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Import failed: ' . $e->getMessage();
            }
            fclose($handle);
        }
    }
}

$pageTitle = 'Historical Data Migration (CSV Import)';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.file-drop-input {
    width: 100%;
    padding: 12px 14px;
    border: 1px dashed var(--border-input);
    border-radius: var(--radius);
    background: var(--surface-subtle);
    font-size: 13.5px;
    cursor: pointer;
}
.import-error-box {
    background: var(--danger-bg);
    border: 1px solid var(--danger-border);
    color: #991B1B;
    padding: 12px 14px;
    border-radius: var(--radius);
    font-size: 13px;
    margin-top: 12px;
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-header-subtitle">Bulk-import historical student registers via CSV. Imported records are flagged with <code>is_imported = 1</code>.</p>
    </div>
    <div class="action-group">
        <a href="?sample=1" class="btn btn-secondary btn-export btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Download Sample CSV Template
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($results): ?>
    <div class="card">
        <div class="card-header-bar">
            <h2>Import Summary</h2>
            <span class="badge badge-green">Completed</span>
        </div>
        <div style="display: flex; gap: 24px; flex-wrap: wrap; font-size: 13.5px;">
            <div><strong>Successfully Imported:</strong> <?= (int)$results['imported'] ?> student record<?= (int)$results['imported'] === 1 ? '' : 's' ?></div>
            <div><strong>Skipped / Failed:</strong> <?= (int)$results['skipped'] ?> record<?= (int)$results['skipped'] === 1 ? '' : 's' ?></div>
        </div>
        <?php if ($results['errors']): ?>
            <div class="import-error-box">
                <strong>Error Details:</strong>
                <ul style="margin: 6px 0 0 20px; padding: 0;">
                    <?php foreach ($results['errors'] as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header-bar">
        <div>
            <h2>Upload CSV File</h2>
            <p class="muted" style="margin: 2px 0 0; font-size: 12.5px;">Ensure columns match the sample template header format. Duplicate Form No or Registration No values are automatically skipped.</p>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="import_csv_file">Select CSV File (.csv) *</label>
            <input type="file" id="import_csv_file" name="csv_file" accept=".csv" required class="file-drop-input">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                Start Migration Import
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
