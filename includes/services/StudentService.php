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
    /**
     * Fetch filtered student list
     */
    public static function getStudents(array $filters = []): array
    {
        $pdo = db();
        $q = trim($filters['q'] ?? '');
        $status = $filters['status'] ?? 'active_students';
        $batchId = $filters['batch_id'] ?? '';
        $limit = (int)($filters['limit'] ?? 200);

        $sql = "SELECT s.*, 
                       jb.name AS joined_batch_name, 
                       pb.name AS preferred_batch_name,
                       COALESCE(jb.name, pb.name) AS batch_name 
                FROM students s
                LEFT JOIN batches jb ON jb.id = s.joined_batch_id
                LEFT JOIN batches pb ON pb.id = s.preferred_batch_id
                WHERE 1=1";
        $params = [];

        if ($q !== '') {
            $sql .= " AND (s.name LIKE ? OR s.student_mobile LIKE ? OR s.registration_no LIKE ? OR s.form_no LIKE ?)";
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like);
        }

        if ($status === 'active_students' || $status === '') {
            $sql .= " AND (s.status IN ('active', 'extended') OR s.course_status IN ('active', 'extended')) AND s.is_paused = 0 AND s.status != 'paused'";
        } elseif ($status === 'paused') {
            $sql .= " AND (s.is_paused = 1 OR s.status = 'paused')";
        } elseif ($status === 'active') {
            $sql .= " AND s.status = 'active' AND s.is_paused = 0";
        } elseif ($status === 'extended') {
            $sql .= " AND s.status = 'extended' AND s.is_paused = 0";
        } elseif ($status === 'course_completed') {
            $sql .= " AND s.status = 'course_completed'";
        } elseif ($status === 'waiting') {
            $sql .= " AND s.status LIKE 'waiting%'";
        } elseif ($status === 'all') {
            // No status filter
        } else {
            $sql .= " AND s.status = ?";
            $params[] = $status;
        }

        if ($batchId !== '' && $batchId !== null) {
            if ($status === 'all') {
                $sql .= " AND COALESCE(s.joined_batch_id, s.preferred_batch_id) = ?";
            } else {
                $sql .= " AND s.joined_batch_id = ?";
            }
            $params[] = (int)$batchId;
        }

        $sql .= " ORDER BY s.created_at DESC LIMIT " . max(1, $limit);

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
}
