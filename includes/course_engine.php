<?php
/**
 * 45-DAY COURSE ENGINE
 * Implements spec section 10 & 11:
 *  - Course duration = configurable calendar days (default 45), from Entry Date.
 *  - Sunday counts normally (no special handling).
 *  - Personal leave never extends the course (we simply never call an "add day"
 *    function for leave — leave is recorded as a note only, see log_audit()).
 *  - Institute holiday for a batch adds +1 day to every active student in that batch.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

/** Base expiry = Entry Date + (course_duration_days - 1), since day 1 is the entry date itself. */
function calculate_base_expiry(string $entryDate): string
{
    $days = (int) get_setting('course_duration_days', 45);
    $dt = new DateTime($entryDate);
    $dt->modify('+' . ($days - 1) . ' days');
    return $dt->format('Y-m-d');
}

/**
 * Confirm actual joining for a waiting student: sets Entry Date, calculates
 * expiry, marks the seat occupied, and removes the student from every other
 * (Any-Batch) waiting queue. This is the "Confirm Joining" button action.
 */
function confirm_joining(int $studentId, int $batchId, string $entryDate, int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $student = get_student($studentId);
        if (!$student) throw new RuntimeException('Student not found.');

        // Capacity check
        $batchCapacity = get_batch_capacity($batchId);
        if (count_active_students_in_batch($batchId) >= $batchCapacity) {
            throw new RuntimeException("Selected batch is already at full capacity ({$batchCapacity}/{$batchCapacity}). Cannot confirm joining.");
        }

        $baseExpiry = calculate_base_expiry($entryDate);

        $stmt = $pdo->prepare(
            "UPDATE students SET
                entry_date = ?, base_expiry_date = ?, final_expiry_date = ?,
                joined_batch_id = ?, course_status = 'active', status = 'active',
                updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$entryDate, $baseExpiry, $baseExpiry, $batchId, $studentId]);

        // Batch history record
        $pdo->prepare(
            "INSERT INTO student_batch_history (student_id, old_batch_id, new_batch_id, reason, changed_by)
             VALUES (?, NULL, ?, 'Joining confirmed', ?)"
        )->execute([$studentId, $batchId, $userId]);

        // Remove from ALL waiting queue rows (covers Any-Batch multi-queue entries)
        $pdo->prepare("UPDATE waiting_queue SET state = 'joined', updated_at = NOW() WHERE student_id = ? AND batch_id = ?")
            ->execute([$studentId, $batchId]);
        $pdo->prepare("UPDATE waiting_queue SET state = 'removed', updated_at = NOW() WHERE student_id = ? AND batch_id != ?")
            ->execute([$studentId, $batchId]);

        log_audit('student', $studentId, 'confirm_joining', null, [
            'entry_date' => $entryDate, 'batch_id' => $batchId, 'expiry_date' => $baseExpiry,
        ]);

        $pdo->commit();

        // Recalculate expected joining dates for everyone still waiting in this batch
        recalculate_expected_joining_dates($batchId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Apply an institute holiday: +1 day to final_expiry_date for every currently
 * ACTIVE student in the given batch. Idempotent per (batch, date) thanks to
 * the unique key on holidays(batch_id, holiday_date).
 */
function apply_institute_holiday(int $batchId, string $holidayDate, ?string $reason, int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO holidays (batch_id, holiday_date, reason, created_by) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$batchId, $holidayDate, $reason, $userId]);
        $holidayId = (int)$pdo->lastInsertId();

        $students = $pdo->prepare(
            "SELECT id, final_expiry_date FROM students
             WHERE joined_batch_id = ? AND status IN ('active','extended')"
        );
        $students->execute([$batchId]);

        $update = $pdo->prepare(
            "UPDATE students SET final_expiry_date = ?, updated_at = NOW() WHERE id = ?"
        );
        $adjustment = $pdo->prepare(
            "INSERT INTO course_adjustments
                (student_id, adjustment_type, holiday_id, days_added, old_expiry_date, new_expiry_date, note, created_by)
             VALUES (?, 'institute_holiday', ?, 1, ?, ?, ?, ?)"
        );

        foreach ($students->fetchAll() as $s) {
            $old = $s['final_expiry_date'];
            $new = (new DateTime($old))->modify('+1 day')->format('Y-m-d');
            $update->execute([$new, $s['id']]);
            $adjustment->execute([$s['id'], $holidayId, $old, $new, $reason, $userId]);
        }

        log_audit('holiday', $holidayId, 'create', null, [
            'batch_id' => $batchId, 'date' => $holidayDate, 'reason' => $reason,
            'students_affected' => $students->rowCount(),
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') {
            throw new RuntimeException('This holiday date has already been recorded for this batch.');
        }
        throw $e;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Delete / Revert an institute holiday.
 * Safely deducts the +1 day adjustment from all affected students who received it.
 */
function delete_institute_holiday(int $holidayId, int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM holidays WHERE id = ?");
        $stmt->execute([$holidayId]);
        $holiday = $stmt->fetch();
        if (!$holiday) {
            throw new RuntimeException('Holiday not found.');
        }

        // Find all student course_adjustments created for this holiday
        $adjStmt = $pdo->prepare("SELECT student_id, days_added FROM course_adjustments WHERE holiday_id = ?");
        $adjStmt->execute([$holidayId]);
        $adjustments = $adjStmt->fetchAll();

        // Revert days from each affected student
        $revertStmt = $pdo->prepare("UPDATE students SET final_expiry_date = DATE_SUB(final_expiry_date, INTERVAL ? DAY), updated_at = NOW() WHERE id = ?");
        foreach ($adjustments as $adj) {
            $days = (int)$adj['days_added'];
            if ($days > 0) {
                $revertStmt->execute([$days, $adj['student_id']]);
            }
        }

        // Delete course adjustments
        $pdo->prepare("DELETE FROM course_adjustments WHERE holiday_id = ?")->execute([$holidayId]);

        // Delete holiday record
        $pdo->prepare("DELETE FROM holidays WHERE id = ?")->execute([$holidayId]);

        log_audit('holiday', $holidayId, 'delete', $holiday, ['reverted_students' => count($adjustments)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Record a student's personal leave/absence as a NOTE ONLY.
 * Per spec section 10/11: personal leave never changes the expiry date.
 */
function record_student_leave_note(int $studentId, string $note, int $userId): void
{
    db()->prepare(
        "INSERT INTO course_adjustments (student_id, adjustment_type, days_added, note, created_by)
         VALUES (?, 'leave_note', 0, ?, ?)"
    )->execute([$studentId, $note, $userId]);
    log_audit('student', $studentId, 'leave_note', null, ['note' => $note]);
}

/** Days completed / remaining / status, for progress bars and dashboards. */
function get_course_progress(array $student): array
{
    if (!$student['entry_date']) {
        return ['status' => 'not_started', 'days_completed' => null, 'days_remaining' => null];
    }
    $today = new DateTime('today');
    $entry = new DateTime($student['entry_date']);
    $expiry = new DateTime($student['final_expiry_date'] ?? $student['base_expiry_date']);

    $daysCompleted = max(0, $today->diff($entry)->days) + ($today >= $entry ? 1 : 0);
    $daysCompleted = min($daysCompleted, $entry->diff($expiry)->days + 1);
    $daysRemaining = max(0, (int)$today->diff($expiry)->format('%r%a'));
    if ($today > $expiry) $daysRemaining = 0;

    $status = 'active';
    if ($today > $expiry) $status = 'completed';

    return [
        'status' => $status,
        'days_completed' => $daysCompleted,
        'days_remaining' => $daysRemaining,
        'total_days' => $entry->diff($expiry)->days + 1,
    ];
}

/** ---------------- Small batch/student lookups used above ---------------- */

function get_student(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function get_batch_capacity(int $batchId): int
{
    $stmt = db()->prepare("SELECT capacity FROM batches WHERE id = ?");
    $stmt->execute([$batchId]);
    return (int) $stmt->fetchColumn();
}

function count_active_students_in_batch(int $batchId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM students WHERE joined_batch_id = ? AND status IN ('active','extended')"
    );
    $stmt->execute([$batchId]);
    return (int) $stmt->fetchColumn();
}
