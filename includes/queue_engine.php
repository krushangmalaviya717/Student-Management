<?php
/**
 * WAITING LIST & "NEXT STUDENT" ENGINE
 * Implements spec sections 7, 8, 9.
 *  - Priority = confirmed form order (students.confirmed_at), never expected date.
 *  - Any-Batch students get one waiting_queue row per active batch, same priority_date.
 *  - Joining one batch removes the student from every other queue automatically
 *    (handled in course_engine::confirm_joining).
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/course_engine.php';

/**
 * Confirm a draft form after the Rs.500 payment succeeds:
 *  - assigns Form No. (already assigned at draft creation) + Registration No.
 *  - creates waiting_queue row(s): one per batch if Any-Batch, else one.
 */
function confirm_form_payment(int $studentId, int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $student = get_student($studentId);
        if (!$student) throw new RuntimeException('Student not found.');
        if ($student['form_status'] === 'confirmed') throw new RuntimeException('Form already confirmed.');

        $regNo = generate_registration_no();
        $now = date('Y-m-d H:i:s');

        $pdo->prepare(
            "UPDATE students SET
                registration_no = ?, form_status = 'confirmed', confirmed_at = ?,
                status = 'waiting_ready', availability_state = 'ready', updated_at = NOW()
             WHERE id = ?"
        )->execute([$regNo, $now, $studentId]);

        $batchIds = resolve_target_batches($student);
        foreach ($batchIds as $batchId) {
            $pdo->prepare(
                "INSERT INTO waiting_queue (student_id, batch_id, priority_date, state)
                 VALUES (?, ?, ?, 'waiting_ready')"
            )->execute([$studentId, $batchId, $now]);
        }

        log_audit('student', $studentId, 'confirm_form_payment', null, [
            'registration_no' => $regNo, 'batches' => $batchIds,
        ]);

        $pdo->commit();

        foreach ($batchIds as $batchId) {
            recalculate_expected_joining_dates($batchId);
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Any-Batch => all active batch ids. Single preference => that one batch. */
function resolve_target_batches(array $student): array
{
    if ($student['any_batch']) {
        $stmt = db()->query("SELECT id FROM batches WHERE is_active = 1");
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
    return [(int)$student['preferred_batch_id']];
}

/**
 * The next eligible candidate for a batch: earliest priority_date among
 * students still in a "waiting" state (ready, future-date, date-not-confirmed
 * are all technically waiting, but "Ready" students should be offered first;
 * future-date/unknown students are skipped for *current* seat offers per
 * spec 8.4, yet remain visible for follow-up).
 */
function get_next_eligible_student(int $batchId): ?array
{
    $stmt = db()->prepare(
        "SELECT wq.*, s.name, s.student_mobile, s.registration_no, s.form_no
         FROM waiting_queue wq
         JOIN students s ON s.id = wq.student_id
         WHERE wq.batch_id = ? AND wq.state IN ('waiting_ready','call_pending','called_call_later')
         ORDER BY wq.priority_date ASC
         LIMIT 1"
    );
    $stmt->execute([$batchId]);
    return $stmt->fetch() ?: null;
}

/** Full ordered queue for a batch (for the Waiting List page). */
function get_batch_queue(int $batchId): array
{
    $pdo = db();
    $capacity = get_batch_capacity($batchId);
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    // Fetch batch schedule
    $bStmt = $pdo->prepare("SELECT id, name, start_time, end_time FROM batches WHERE id = ?");
    $bStmt->execute([$batchId]);
    $batchInfo = $bStmt->fetch();
    $batchStartTime = $batchInfo['start_time'] ?? '00:00:00';

    // If current time is past batch start time, today's batch class has already started/completed.
    // The earliest next class a student can attend is tomorrow!
    $isBatchPassedToday = ($nowTime >= $batchStartTime);
    $tomorrow = (new DateTime($today))->modify('+1 day')->format('Y-m-d');
    $earliestAvailableDate = $isBatchPassedToday ? $tomorrow : $today;

    // 1. Fetch current active students in this batch ordered by final_expiry_date
    $stmtAct = $pdo->prepare(
        "SELECT id, name, final_expiry_date
         FROM students
         WHERE joined_batch_id = ? AND status IN ('active','extended')
         ORDER BY final_expiry_date ASC"
    );
    $stmtAct->execute([$batchId]);
    $activeStudents = $stmtAct->fetchAll();

    // 2. Count active students whose expiry date is upcoming (>= today)
    $validFutureStudents = [];
    foreach ($activeStudents as $act) {
        if (!empty($act['final_expiry_date']) && $act['final_expiry_date'] >= $today) {
            $validFutureStudents[] = $act;
        }
    }

    $availableSeatsNow = max(0, $capacity - count($validFutureStudents));

    // 3. Build ordered seat vacancies
    $seatVacancies = [];

    // Currently vacant seats:
    for ($s = 0; $s < $availableSeatsNow; $s++) {
        $seatVacancies[] = [
            'date' => $earliestAvailableDate,
            'is_currently_vacant' => true,
            'batch_passed_today' => $isBatchPassedToday,
            'batch_start_time' => $batchStartTime,
            'vacating_student_name' => null,
            'vacating_student_last_day' => null,
            'days_until_vacancy' => ($isBatchPassedToday ? 1 : 0)
        ];
    }

    // Seats opening as current active students complete their course:
    foreach ($validFutureStudents as $act) {
        $lastDay = $act['final_expiry_date'];
        $openDate = (new DateTime($lastDay))->modify('+1 day')->format('Y-m-d');
        if ($openDate === $today && $isBatchPassedToday) {
            $openDate = $tomorrow;
        }
        $todayDt = new DateTime($today);
        $openDt = new DateTime($openDate);
        $daysUntil = max(0, (int)$todayDt->diff($openDt)->format('%r%a'));

        $seatVacancies[] = [
            'date' => $openDate,
            'is_currently_vacant' => false,
            'batch_passed_today' => false,
            'batch_start_time' => $batchStartTime,
            'vacating_student_name' => $act['name'],
            'vacating_student_last_day' => $lastDay,
            'days_until_vacancy' => $daysUntil
        ];
    }

    usort($seatVacancies, function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });

    // 4. Fetch waiting queue rows
    $stmt = $pdo->prepare(
        "SELECT wq.*, s.name, s.student_mobile, s.registration_no, s.form_no,
                s.expected_joining_date, s.availability_state, s.availability_ready_date
         FROM waiting_queue wq
         JOIN students s ON s.id = wq.student_id
         WHERE wq.batch_id = ? AND wq.state NOT IN ('joined','removed')
         ORDER BY wq.priority_date ASC"
    );
    $stmt->execute([$batchId]);
    $rows = $stmt->fetchAll();

    // 5. Attach vacancy & graduating student info to each waiting candidate
    foreach ($rows as $i => &$row) {
        $studentPrefDate = (!empty($row['availability_ready_date']) && $row['availability_ready_date'] > $today)
            ? $row['availability_ready_date']
            : null;

        if (isset($seatVacancies[$i])) {
            $vac = $seatVacancies[$i];
        } else {
            // Rolling 45 days
            $prevIndex = max(0, $i - max(1, $capacity));
            $baseVac = $seatVacancies[$prevIndex] ?? end($seatVacancies) ?: [
                'date' => $earliestAvailableDate, 'is_currently_vacant' => true, 'batch_passed_today' => $isBatchPassedToday, 'batch_start_time' => $batchStartTime, 'vacating_student_name' => null, 'vacating_student_last_day' => null, 'days_until_vacancy' => ($isBatchPassedToday ? 1 : 0)
            ];
            $openDate = (new DateTime($baseVac['date']))->modify('+45 days')->format('Y-m-d');
            $todayDt = new DateTime($today);
            $openDt = new DateTime($openDate);
            $daysUntil = max(0, (int)$todayDt->diff($openDt)->format('%r%a'));

            $vac = [
                'date' => $openDate,
                'is_currently_vacant' => false,
                'batch_passed_today' => false,
                'batch_start_time' => $batchStartTime,
                'vacating_student_name' => $baseVac['vacating_student_name'] ?? null,
                'vacating_student_last_day' => (new DateTime($openDate))->modify('-1 day')->format('Y-m-d'),
                'days_until_vacancy' => $daysUntil
            ];
            $seatVacancies[$i] = $vac;
        }

        $calcDate = $studentPrefDate ? max($vac['date'], $studentPrefDate) : $vac['date'];
        if ($calcDate < $earliestAvailableDate) {
            $calcDate = $earliestAvailableDate;
        }

        $row['expected_joining_date'] = $calcDate;
        $row['is_currently_vacant'] = ($vac['is_currently_vacant'] ?? false);
        $row['batch_passed_today'] = !empty($vac['batch_passed_today']);
        $row['batch_start_time'] = $batchStartTime;
        $row['vacating_student_name'] = $vac['vacating_student_name'] ?? null;
        $row['vacating_student_last_day'] = $vac['vacating_student_last_day'] ?? null;
        $row['days_until_vacancy'] = $vac['days_until_vacancy'] ?? 0;
    }
    unset($row);

    return $rows;
}

function log_call_and_update_state(
    int $studentId, int $batchId, int $userId, string $contactMethod,
    string $outcome, ?int $offeredBatchId, ?string $proposedDate,
    ?string $followUpDate, ?string $note
): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO call_logs
                (student_id, called_by, call_datetime, contact_method, outcome,
                 offered_batch_id, proposed_joining_date, follow_up_date, conversation_note)
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)"
        )->execute([$studentId, $userId, $contactMethod, $outcome, $offeredBatchId, $proposedDate, $followUpDate, $note]);

        $newQueueState = match ($outcome) {
            'confirmed' => 'called_confirmed',
            'call_later' => 'called_call_later',
            'specific_date' => 'waiting_future_date',
            'cannot_join' => 'called_not_available',
            'no_response' => 'call_pending',
            default => 'call_pending',
        };
        $studentAvailability = match ($outcome) {
            'specific_date' => 'future_date',
            default => null,
        };

        $pdo->prepare("UPDATE waiting_queue SET state = ?, future_date = ?, updated_at = NOW() WHERE student_id = ? AND batch_id = ?")
            ->execute([$newQueueState, $proposedDate, $studentId, $batchId]);

        if ($studentAvailability) {
            $pdo->prepare("UPDATE students SET availability_state = ?, availability_ready_date = ?, status = 'waiting_future_date', updated_at = NOW() WHERE id = ?")
                ->execute([$studentAvailability, $proposedDate, $studentId]);
        }

        log_audit('student', $studentId, 'call_outcome', null, [
            'outcome' => $outcome, 'note' => $note, 'batch_id' => $batchId,
        ]);

        $pdo->commit();
        recalculate_expected_joining_dates($batchId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Mark a "date not confirmed" waiting state explicitly (student doesn't know
 * when they can come). Spec 8.2 "Waiting – Date Not Confirmed".
 */
function mark_date_not_confirmed(int $studentId, int $batchId): void
{
    $pdo = db();
    $pdo->prepare("UPDATE waiting_queue SET state = 'waiting_date_not_confirmed', future_date = NULL, updated_at = NOW() WHERE student_id = ? AND batch_id = ?")
        ->execute([$studentId, $batchId]);
    $pdo->prepare("UPDATE students SET availability_state = 'date_not_confirmed', status = 'waiting_date_not_confirmed', updated_at = NOW() WHERE id = ?")
        ->execute([$studentId]);
    log_audit('student', $studentId, 'mark_date_not_confirmed', null, ['batch_id' => $batchId]);
}

/**
 * EXPECTED JOINING DATE ENGINE (spec section 9).
 * Informational only — accurately estimates when a seat will open for each
 * candidate in a batch queue based on:
 * 1. Immediate seat vacancies in the batch (capacity - activeCount).
 * 2. Active students' projected final_expiry_dates.
 * 3. Future rolling vacancies (+45 days for subsequent seats).
 * 4. Candidate's own availability_ready_date preference.
 * Guarantee: Projected date will NEVER be in the past.
 */
function recalculate_expected_joining_dates(int $batchId): void
{
    $pdo = db();

    $capacity = get_batch_capacity($batchId);
    $activeCount = count_active_students_in_batch($batchId);
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    // Fetch batch schedule
    $bStmt = $pdo->prepare("SELECT id, name, start_time, end_time FROM batches WHERE id = ?");
    $bStmt->execute([$batchId]);
    $batchInfo = $bStmt->fetch();
    $batchStartTime = $batchInfo['start_time'] ?? '00:00:00';

    // If current time is past batch start time, today's batch class has already started/completed.
    // The earliest next class session a student can attend is tomorrow!
    $isBatchPassedToday = ($nowTime >= $batchStartTime);
    $tomorrow = (new DateTime($today))->modify('+1 day')->format('Y-m-d');
    $earliestAvailableDate = $isBatchPassedToday ? $tomorrow : $today;

    // Soonest-expiring active students give the projected vacancy dates.
    $stmt = $pdo->prepare(
        "SELECT final_expiry_date FROM students
         WHERE joined_batch_id = ? AND status IN ('active','extended')
         ORDER BY final_expiry_date ASC"
    );
    $stmt->execute([$batchId]);
    $activeExpiries = array_column($stmt->fetchAll(), 'final_expiry_date');

    // Filter valid future expiries. Any active student whose expiry date is in the past (< today)
    // effectively frees a seat starting today.
    $validFutureExpiries = [];
    foreach ($activeExpiries as $exp) {
        if ($exp && $exp >= $today) {
            $validFutureExpiries[] = $exp;
        }
    }

    $availableSeatsNow = max(0, $capacity - count($validFutureExpiries));

    // Build timeline of seat vacancy dates
    $projectedDates = [];

    // 1. All currently available open seats are ready on $earliestAvailableDate:
    for ($s = 0; $s < $availableSeatsNow; $s++) {
        $projectedDates[] = $earliestAvailableDate;
    }

    // 2. Seats opening when current active students complete their course:
    foreach ($validFutureExpiries as $exp) {
        $nextDay = (new DateTime($exp))->modify('+1 day')->format('Y-m-d');
        if ($nextDay === $today && $isBatchPassedToday) {
            $nextDay = $tomorrow;
        }
        $projectedDates[] = max($earliestAvailableDate, $nextDay);
    }
    sort($projectedDates);

    // Fetch all waiting candidates in this batch ordered by priority
    $queue = $pdo->prepare(
        "SELECT wq.id, wq.student_id, s.availability_state, s.availability_ready_date 
         FROM waiting_queue wq
         JOIN students s ON s.id = wq.student_id
         WHERE wq.batch_id = ? AND wq.state NOT IN ('joined','removed')
         ORDER BY wq.priority_date ASC"
    );
    $queue->execute([$batchId]);
    $waitingCandidates = $queue->fetchAll();

    $update = $pdo->prepare("UPDATE students SET expected_joining_date = ? WHERE id = ?");

    foreach ($waitingCandidates as $i => $row) {
        $studentPrefDate = (!empty($row['availability_ready_date']) && $row['availability_ready_date'] > $today)
            ? $row['availability_ready_date']
            : null;

        if (isset($projectedDates[$i])) {
            $seatDate = $projectedDates[$i];
        } else {
            // If queue length exceeds available projected seats, project rolling cycles of 45 days:
            $prevCycleIndex = max(0, $i - max(1, $capacity));
            $baseSeatDate = $projectedDates[$prevCycleIndex] ?? end($projectedDates) ?: $earliestAvailableDate;
            $seatDate = (new DateTime($baseSeatDate))->modify('+45 days')->format('Y-m-d');
            $projectedDates[$i] = $seatDate;
        }

        $finalExpectedDate = $studentPrefDate ? max($seatDate, $studentPrefDate) : $seatDate;

        // An expected future date must never be in the past or earlier than earliestAvailableDate:
        if ($finalExpectedDate < $earliestAvailableDate) {
            $finalExpectedDate = $earliestAvailableDate;
        }

        $update->execute([$finalExpectedDate, $row['student_id']]);
    }
}
