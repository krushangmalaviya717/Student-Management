<?php
/**
 * StudentService
 * Core business logic for student records, status workflows, search, and metrics.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/ExportService.php';

class StudentService
{
    private static function buildStudentFilterWhere(array $filters, array &$params): string
    {
        $q = trim($filters['q'] ?? '');
        $status = $filters['status'] ?? 'active_students';
        $batchId = $filters['batch_id'] ?? '';
        $where = ' WHERE 1=1';

        if ($q !== '') {
            $where .= " AND (s.name LIKE ? OR s.student_mobile LIKE ? OR s.registration_no LIKE ? OR s.form_no LIKE ?)";
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like);
        }

        if ($status === 'active_students' || $status === '') {
            $where .= " AND (s.status IN ('active', 'extended') OR s.course_status IN ('active', 'extended')) AND s.is_paused = 0 AND s.status != 'paused' AND s.is_temporary = 0";
        } elseif ($status === 'temporary') {
            $where .= " AND s.is_temporary = 1";
        } elseif ($status === 'paused') {
            $where .= " AND (s.is_paused = 1 OR s.status = 'paused') AND s.is_temporary = 0";
        } elseif ($status === 'active') {
            $where .= " AND s.status = 'active' AND s.is_paused = 0 AND s.is_temporary = 0";
        } elseif ($status === 'extended') {
            $where .= " AND s.status = 'extended' AND s.is_paused = 0 AND s.is_temporary = 0";
        } elseif ($status === 'course_completed') {
            $where .= " AND s.status = 'course_completed' AND s.is_temporary = 0";
        } elseif ($status === 'waiting') {
            $where .= " AND s.status LIKE 'waiting%' AND s.is_temporary = 0";
        } elseif ($status === 'all') {
            // No status filter
        } else {
            $where .= " AND s.status = ?";
            $params[] = $status;
        }

        if ($batchId !== '' && $batchId !== null) {
            if ($status === 'all') {
                $where .= " AND COALESCE(s.joined_batch_id, s.preferred_batch_id) = ?";
            } else {
                $where .= " AND s.joined_batch_id = ?";
            }
            $params[] = (int)$batchId;
        }

        return $where;
    }

    /**
     * Count total matching students for pagination
     */
    public static function countStudents(array $filters = []): int
    {
        $pdo = db();
        $params = [];
        $where = self::buildStudentFilterWhere($filters, $params);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students s" . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Fetch filtered student list
     */
    public static function getStudents(array $filters = []): array
    {
        $pdo = db();
        $limit = max(1, (int)($filters['limit'] ?? 200));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $params = [];
        $where = self::buildStudentFilterWhere($filters, $params);

        $sql = "SELECT s.*, 
                       jb.name AS joined_batch_name, 
                       pb.name AS preferred_batch_name,
                       COALESCE(jb.name, pb.name) AS batch_name 
                FROM students s
                LEFT JOIN batches jb ON jb.id = s.joined_batch_id
                LEFT JOIN batches pb ON pb.id = s.preferred_batch_id"
                . $where
                . " ORDER BY s.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get single student by ID
     */
    public static function getStudentById(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT s.*, 
                    jb.name AS joined_batch_name, 
                    pb.name AS preferred_batch_name,
                    COALESCE(jb.name, pb.name) AS batch_name 
             FROM students s
             LEFT JOIN batches jb ON jb.id = s.joined_batch_id
             LEFT JOIN batches pb ON pb.id = s.preferred_batch_id
             WHERE s.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Mark a student's course as completed (છૂટા કરો)
     */
    public static function markCourseCompleted(int $id, int $userId): void
    {
        $student = self::getStudentById($id);
        if (!$student) {
            throw new RuntimeException('Student not found.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE students SET 
                    status = 'course_completed', 
                    course_status = 'completed',
                    updated_at = NOW() 
                 WHERE id = ?"
            );
            $stmt->execute([$id]);

            log_audit('student', $id, 'mark_completed', [
                'status' => $student['status'],
                'course_status' => $student['course_status']
            ], [
                'status' => 'course_completed',
                'course_status' => 'completed',
                'completed_by' => $userId
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reopen a completed student back to active
     */
    public static function reopenCourse(int $id, int $userId): void
    {
        $student = self::getStudentById($id);
        if (!$student) {
            throw new RuntimeException('Student not found.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE students SET 
                    status = 'active', 
                    course_status = 'active',
                    updated_at = NOW() 
                 WHERE id = ?"
            );
            $stmt->execute([$id]);

            log_audit('student', $id, 'reopen_course', [
                'status' => $student['status'],
                'course_status' => $student['course_status']
            ], [
                'status' => 'active',
                'course_status' => 'active',
                'reopened_by' => $userId
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Pause / Hold course for a student
     * Rule: The day the student informs/calls is counted as used.
     * The pause takes effect from NEXT day (inform_date + 1 day).
     * Days remaining from effective_stop_date to final_expiry_date are banked.
     */
    public static function pauseCourse(int $id, string $informDate, ?string $readyAfterDate, ?string $reason, int $userId): array
    {
        $student = self::getStudentById($id);
        if (!$student) {
            throw new RuntimeException('Student not found.');
        }

        if (!empty($student['is_paused']) || $student['status'] === 'paused') {
            throw new RuntimeException('Student course is already paused.');
        }

        if (empty($student['entry_date'])) {
            throw new RuntimeException('Student has not joined any course yet.');
        }

        $expiryDate = $student['final_expiry_date'] ?: $student['base_expiry_date'];
        if (!$expiryDate) {
            throw new RuntimeException('Student does not have a valid expiry date.');
        }

        // Effective stop date is next day of inform date
        $stopDate = date('Y-m-d', strtotime($informDate . ' + 1 day'));

        $stopDt = new DateTime($stopDate);
        $expDt = new DateTime($expiryDate);

        if ($stopDt > $expDt) {
            $remainingDays = 0;
        } else {
            $remainingDays = $stopDt->diff($expDt)->days + 1;
        }

        $readyDate = !empty($readyAfterDate) ? $readyAfterDate : null;
        $reasonText = trim($reason ?? '');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE students SET
                    is_paused = 1,
                    paused_at = ?,
                    paused_remaining_days = ?,
                    resume_ready_date = ?,
                    pause_reason = ?,
                    status = 'paused',
                    course_status = 'paused',
                    updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([
                $stopDate,
                $remainingDays,
                $readyDate,
                $reasonText ?: 'Student requested course pause',
                $id
            ]);

            // Record adjustment entry
            $pdo->prepare(
                "INSERT INTO course_adjustments (student_id, adjustment_type, days_added, old_expiry_date, new_expiry_date, note, created_by)
                 VALUES (?, 'manual_override', 0, ?, NULL, ?, ?)"
            )->execute([
                $id,
                $expiryDate,
                "Course paused on $informDate. Effective Stop: $stopDate. Banked Days: $remainingDays." . ($readyDate ? " Ready After: $readyDate." : "") . ($reasonText ? " Reason: $reasonText" : ""),
                $userId
            ]);

            log_audit('student', $id, 'pause_course', [
                'status' => $student['status'],
                'course_status' => $student['course_status'],
                'expiry_date' => $expiryDate
            ], [
                'status' => 'paused',
                'course_status' => 'paused',
                'is_paused' => 1,
                'inform_date' => $informDate,
                'paused_at' => $stopDate,
                'paused_remaining_days' => $remainingDays,
                'resume_ready_date' => $readyDate,
                'pause_reason' => $reasonText,
                'paused_by' => $userId
            ]);

            $pdo->commit();

            return [
                'inform_date' => $informDate,
                'effective_stop_date' => $stopDate,
                'banked_days' => $remainingDays,
                'ready_after_date' => $readyDate
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Resume a paused course into an active batch
     * Formula: new_expiry_date = restart_date + (banked_days - 1) days
     */
    public static function resumeCourse(int $id, int $batchId, string $restartDate, int $userId, ?string $note = null): array
    {
        $student = self::getStudentById($id);
        if (!$student) {
            throw new RuntimeException('Student not found.');
        }

        if (empty($student['is_paused']) && $student['status'] !== 'paused') {
            throw new RuntimeException('Student is not currently paused.');
        }

        $bankedDays = (int)($student['paused_remaining_days'] ?? 0);
        if ($bankedDays < 1) {
            throw new RuntimeException('Student has 0 banked remaining days.');
        }

        // Check batch exists and capacity
        $batchCapacity = get_batch_capacity($batchId);
        $activeInBatch = count_active_students_in_batch($batchId);
        if ($activeInBatch >= $batchCapacity) {
            throw new RuntimeException("Selected batch is already at full capacity ($activeInBatch/$batchCapacity). Cannot resume into this batch.");
        }

        // New expiry date calculation:
        $newExpiryDate = date('Y-m-d', strtotime($restartDate . ' + ' . ($bankedDays - 1) . ' days'));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE students SET
                    is_paused = 0,
                    paused_at = NULL,
                    paused_remaining_days = 0,
                    resume_ready_date = NULL,
                    pause_reason = NULL,
                    joined_batch_id = ?,
                    final_expiry_date = ?,
                    status = 'active',
                    course_status = 'active',
                    updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$batchId, $newExpiryDate, $id]);

            if ($student['joined_batch_id'] != $batchId) {
                $pdo->prepare(
                    "INSERT INTO student_batch_history (student_id, old_batch_id, new_batch_id, reason, changed_by)
                     VALUES (?, ?, ?, 'Resumed from pause into batch', ?)"
                )->execute([$id, $student['joined_batch_id'], $batchId, $userId]);
            }

            $pdo->prepare(
                "INSERT INTO course_adjustments (student_id, adjustment_type, days_added, old_expiry_date, new_expiry_date, note, created_by)
                 VALUES (?, 'manual_override', ?, ?, ?, ?, ?)"
            )->execute([
                $id,
                $bankedDays,
                $student['final_expiry_date'],
                $newExpiryDate,
                "Resumed course on $restartDate in batch #$batchId. Restored $bankedDays banked days. New expiry: $newExpiryDate" . ($note ? " Note: $note" : ""),
                $userId
            ]);

            log_audit('student', $id, 'resume_course', [
                'is_paused' => 1,
                'status' => 'paused',
                'banked_days' => $bankedDays
            ], [
                'is_paused' => 0,
                'status' => 'active',
                'restart_date' => $restartDate,
                'new_expiry_date' => $newExpiryDate,
                'batch_id' => $batchId,
                'resumed_by' => $userId
            ]);

            $pdo->commit();

            return [
                'restart_date' => $restartDate,
                'new_expiry_date' => $newExpiryDate,
                'batch_id' => $batchId,
                'restored_days' => $bankedDays
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update student resume ready date
     */
    public static function updateResumeReadyDate(int $id, string $readyDate, ?string $reason = null, ?int $userId = null): void
    {
        $student = self::getStudentById($id);
        if (!$student) {
            throw new RuntimeException('Student not found.');
        }

        $pdo = db();
        $pdo->prepare(
            "UPDATE students SET resume_ready_date = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$readyDate, $id]);

        log_audit('student', $id, 'update_ready_date', [
            'old_ready_date' => $student['resume_ready_date']
        ], [
            'new_ready_date' => $readyDate,
            'reason' => $reason,
            'updated_by' => $userId
        ]);
    }

    /**
     * Get aggregate student metrics
     */
    public static function getStudentMetrics(): array
    {
        $pdo = db();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $active = (int)$pdo->query(
            "SELECT COUNT(*) FROM students WHERE (status IN ('active', 'extended') OR course_status IN ('active', 'extended')) AND is_paused = 0 AND status != 'paused'"
        )->fetchColumn();
        $paused = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_paused = 1 OR status = 'paused'")->fetchColumn();
        $completed = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status = 'course_completed'")->fetchColumn();
        $waiting = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status LIKE 'waiting%'")->fetchColumn();

        return [
            'total' => $total,
            'active' => $active,
            'paused' => $paused,
            'completed' => $completed,
            'waiting' => $waiting
        ];
    }

    /**
     * Export student records to CSV
     */
    public static function exportToCsv(array $students, string $viewMode = 'active'): void
    {
        $prefix = 'Active_Students_';
        if ($viewMode === 'course_completed') {
            $prefix = 'Completed_Students_';
        } elseif ($viewMode === 'paused') {
            $prefix = 'Paused_Students_';
        } elseif ($viewMode === 'all') {
            $prefix = 'All_Students_';
        }

        $filename = $prefix . date('Y-m-d') . '.csv';
        $headers = [
            'Reg No',
            'Form No',
            'Student Name',
            'Mobile No',
            'Alternative Mobile',
            'Batch',
            'Status',
            'Entry Date',
            'Completion / Expiry Date',
            'Registration Date'
        ];

        $rows = [];
        foreach ($students as $s) {
            $rows[] = [
                $s['registration_no'] ?: '-',
                $s['form_no'] ?: '-',
                $s['name'],
                $s['student_mobile'],
                $s['parent_mobile'] ?: '-',
                $s['batch_name'] ?: '-',
                $s['status'],
                $s['entry_date'] ? date('d-m-Y', strtotime($s['entry_date'])) : '-',
                $s['final_expiry_date'] ? date('d-m-Y', strtotime($s['final_expiry_date'])) : '-',
                date('d-m-Y', strtotime($s['created_at']))
            ];
        }

        ExportService::downloadCsv($filename, $headers, $rows);
    }

    /**
     * Authoritative metadata & current configuration for Admission/Edit form fields.
     */
    public static function getFormFieldConfig(): array
    {
        $defs = [
            'name' => [
                'label' => 'Student Full Name',
                'gujarati_label' => 'પૂરું નામ',
                'group' => 'Personal & Contact',
                'can_hide' => false,
                'can_optional' => false,
                'default_req' => '1',
                'default_vis' => '1',
            ],
            'student_mobile' => [
                'label' => 'Student Mobile No.',
                'gujarati_label' => 'સ્ટુડન્ટ નો મો.નં.',
                'group' => 'Personal & Contact',
                'can_hide' => false,
                'can_optional' => true,
                'default_req' => '1',
                'default_vis' => '1',
                'note' => 'Used for Student Portal login when provided.',
            ],
            'age' => [
                'label' => 'Age',
                'gujarati_label' => 'ઉંમર',
                'group' => 'Personal & Contact',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '0',
                'default_vis' => '1',
            ],
            'father_mobile' => [
                'label' => 'Husband / Father Mobile',
                'gujarati_label' => 'પતિ / પિતા નો મો.નં.',
                'group' => 'Personal & Contact',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '0',
                'default_vis' => '1',
            ],
            'home_mobile' => [
                'label' => 'Home Mobile',
                'gujarati_label' => 'ઘર નો મો.નં.',
                'group' => 'Personal & Contact',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '0',
                'default_vis' => '1',
            ],
            'education' => [
                'label' => 'Education',
                'gujarati_label' => 'અભ્યાસ',
                'group' => 'Personal & Contact',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '0',
                'default_vis' => '1',
            ],
            'photo' => [
                'label' => 'Passport Photo (35mm x 45mm)',
                'gujarati_label' => 'ફોટો',
                'group' => 'Personal & Contact',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '1',
                'default_vis' => '1',
            ],
            'address' => [
                'label' => 'Full Address',
                'gujarati_label' => 'પૂરું સરનામું',
                'group' => 'Personal & Contact',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '1',
                'default_vis' => '1',
            ],
            'knows_sewing' => [
                'label' => 'Sewing Experience',
                'gujarati_label' => 'સિલાઈ કામ ની માહિતી',
                'group' => 'Questionnaire & Source',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '0',
                'default_vis' => '1',
            ],
            'hear_about_us' => [
                'label' => 'How Did You Hear About Us?',
                'gujarati_label' => 'ક્લાસીસ ની માહિતી ક્યાંથી મળી?',
                'group' => 'Questionnaire & Source',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '0',
                'default_vis' => '1',
            ],
            'referral' => [
                'label' => 'Referral Details (Name & Mobile)',
                'gujarati_label' => 'રેફરન્સ ની વિગત',
                'group' => 'Questionnaire & Source',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '0',
                'default_vis' => '1',
            ],
            'guardian' => [
                'label' => 'Guardian Name & Relation',
                'gujarati_label' => 'ઘરના વડીલ નું નામ અને સંબંધ',
                'group' => 'Guardian / Undertaking',
                'can_hide' => true,
                'can_optional' => true,
                'default_req' => '0',
                'default_vis' => '1',
            ],
        ];

        $config = [];
        foreach ($defs as $key => $meta) {
            $visible = $meta['can_hide']
                ? (get_setting('form_vis_' . $key, $meta['default_vis']) === '1')
                : true;
            $required = $meta['can_optional']
                ? ($visible && get_setting('form_req_' . $key, $meta['default_req']) === '1')
                : true;

            $config[$key] = array_merge($meta, [
                'key' => $key,
                'visible' => $visible,
                'required' => $required,
            ]);
        }
        return $config;
    }

    /**
     * Validate student form payload dynamically against settings-configured rules.
     */
    public static function validateStudentData(array $post, array $options = []): array
    {
        $cfg = self::getFormFieldConfig();
        $isEdit = !empty($options['is_edit']);
        $hasPhoto = !empty($options['has_photo']);

        $surname = trim((string)($post['surname'] ?? ''));
        $firstName = trim((string)($post['first_name'] ?? ''));
        $fatherHusbandName = trim((string)($post['father_husband_name'] ?? ''));
        $directName = trim((string)($post['name'] ?? ''));

        // Sequence requested by user: Surname + Name + Father's/Husband's Name
        if ($surname !== '' || $firstName !== '' || $fatherHusbandName !== '') {
            $nameParts = array_filter([$surname, $firstName, $fatherHusbandName], fn($p) => $p !== '');
            $name = trim(implode(' ', $nameParts));
        } else {
            $name = $directName;
            if ($name !== '') {
                $parts = preg_split('/\s+/', $name, 3);
                $surname = $parts[0] ?? '';
                $firstName = $parts[1] ?? '';
                $fatherHusbandName = $parts[2] ?? '';
            }
        }

        $studentMobile = trim((string)($post['student_mobile'] ?? ''));
        $fatherMobile = trim((string)($post['husband_father_mobile'] ?? ''));
        $homeMobile = trim((string)($post['home_mobile'] ?? ''));
        $ageRaw = trim((string)($post['age'] ?? ''));
        $education = trim((string)($post['education'] ?? ''));
        $address = trim((string)($post['address'] ?? ''));
        $hearAboutUs = trim((string)($post['hear_about_us'] ?? ''));
        $refName = trim((string)($post['reference_person_name'] ?? $post['relative_name'] ?? ''));
        $refMobile = trim((string)($post['reference_mobile'] ?? $post['relative_mobile'] ?? ''));
        $guardianName = trim((string)($post['guardian_signature_name'] ?? ''));
        $guardianRelation = trim((string)($post['guardian_relation'] ?? ''));

        $missing = [];
        if ($name === '' || ($surname === '' && $firstName === '')) {
            $missing[] = 'Student Name (અટક & નામ)';
        }
        if ($cfg['student_mobile']['required'] && $studentMobile === '') {
            $missing[] = 'Student Mobile';
        }
        if ($cfg['age']['required'] && ($ageRaw === '' || (int)$ageRaw <= 0)) {
            $missing[] = 'Age';
        }
        if ($cfg['father_mobile']['required'] && $fatherMobile === '') {
            $missing[] = 'Husband / Father Mobile';
        }
        if ($cfg['home_mobile']['required'] && $homeMobile === '') {
            $missing[] = 'Home Mobile';
        }
        if ($cfg['education']['required'] && $education === '') {
            $missing[] = 'Education';
        }
        if ($cfg['address']['required'] && $address === '') {
            $missing[] = 'Full Address';
        }
        if ($cfg['photo']['required'] && !$hasPhoto) {
            $missing[] = 'Student Photo';
        }
        if ($cfg['knows_sewing']['required'] && !isset($post['knows_sewing'])) {
            $missing[] = 'Sewing Experience (Yes/No)';
        }
        if ($cfg['hear_about_us']['required'] && $hearAboutUs === '') {
            $missing[] = 'How Did You Hear About Us';
        }
        if ($cfg['referral']['required'] && ($refName === '' || $refMobile === '')) {
            $missing[] = 'Referral Details (Name & Mobile)';
        }
        if ($cfg['guardian']['required'] && ($guardianName === '' || $guardianRelation === '')) {
            $missing[] = 'Guardian Name & Relation';
        }

        if ($missing) {
            throw new RuntimeException('Please fill required field(s): ' . implode(', ', $missing) . '.');
        }

        if (!$isEdit) {
            $anyBatch = !empty($post['any_batch']) ? 1 : 0;
            $preferredBatch = $anyBatch ? null : (!empty($post['preferred_batch_id']) ? (int)$post['preferred_batch_id'] : null);
            if (!$anyBatch && !$preferredBatch) {
                throw new RuntimeException('Choose a preferred batch, or tick "Any Batch".');
            }
        }

        return [
            'name' => $name,
            'surname' => $surname !== '' ? $surname : null,
            'first_name' => $firstName !== '' ? $firstName : null,
            'father_husband_name' => $fatherHusbandName !== '' ? $fatherHusbandName : null,
            'student_mobile' => $studentMobile,
            'husband_father_mobile' => $fatherMobile !== '' ? $fatherMobile : null,
            'home_mobile' => $homeMobile !== '' ? $homeMobile : null,
            'age' => $ageRaw !== '' ? (int)$ageRaw : null,
            'education' => $education !== '' ? $education : null,
            'address' => $address,
            'knows_sewing' => !empty($post['knows_sewing']) ? 1 : 0,
            'previous_sewing_work' => trim((string)($post['previous_sewing_work'] ?? '')) ?: null,
            'hear_about_us' => $hearAboutUs !== '' ? $hearAboutUs : null,
            'reference_person_name' => $refName !== '' ? $refName : null,
            'reference_mobile' => $refMobile !== '' ? $refMobile : null,
            'guardian_signature_name' => $guardianName !== '' ? $guardianName : null,
            'guardian_relation' => $guardianRelation !== '' ? $guardianRelation : null,
        ];
    }

    /**
     * Permanently delete a student and all dependent records inside a transaction
     */
    public static function deleteStudent(int $studentId, int $actorId): array
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Invalid student ID.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? FOR UPDATE");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new RuntimeException('Student record not found.');
            }

            // Delete child records in foreign-key-safe order
            $pdo->prepare("
                DELETE eli FROM extension_line_items eli
                INNER JOIN extensions e ON eli.extension_id = e.id
                WHERE e.student_id = ?
            ")->execute([$studentId]);

            $pdo->prepare("DELETE FROM invoices WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM payments WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM extensions WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM course_adjustments WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM call_logs WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM waiting_queue WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM student_batch_history WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM student_photos WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("UPDATE referrals SET relative_student_id = NULL WHERE relative_student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM referrals WHERE student_id = ?")->execute([$studentId]);
            $pdo->prepare("DELETE FROM student_leave_requests WHERE student_id = ?")->execute([$studentId]);

            $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$studentId]);

            log_audit('student', $studentId, 'delete_student', $student, [
                'deleted_by' => $actorId,
                'name' => $student['name'],
                'form_no' => $student['form_no'],
                'registration_no' => $student['registration_no']
            ]);

            $pdo->commit();
            return $student;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
