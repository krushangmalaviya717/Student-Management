<?php
/**
 * AttendanceService
 * Business logic for Staff daily attendance, half-day tracking, holidays and monthly register.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';

class AttendanceService
{
    /**
     * Check if a given date is an official institute holiday or Sunday
     */
    public static function getHolidayInfo(string $date): ?array
    {
        $pdo = db();
        $dayOfWeek = (int)date('N', strtotime($date)); // 1 (Mon) to 7 (Sun)
        
        // 1. Check if declared holiday in holidays table
        $stmt = $pdo->prepare("SELECT DISTINCT holiday_date, reason FROM holidays WHERE holiday_date = ? LIMIT 1");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'is_holiday' => true,
                'type' => 'declared_holiday',
                'reason' => $row['reason'] ?: 'જાહેર રજા (Institute Holiday)'
            ];
        }

        // 2. Check if Sunday
        if ($dayOfWeek === 7) {
            return [
                'is_holiday' => true,
                'type' => 'sunday',
                'reason' => 'રવિવાર (Sunday Weekly Off)'
            ];
        }

        return null;
    }

    /**
     * Get attendance sheet for a specific date
     */
    public static function getDailySheet(string $date): array
    {
        $pdo = db();
        $holidayInfo = self::getHolidayInfo($date);

        // Fetch all active staff
        $staffList = $pdo->query("
            SELECT s.id, s.name, s.phone, s.designation, s.salary, s.salary_type, s.status 
            FROM staff s 
            WHERE s.status = 'active' 
            ORDER BY s.name ASC
        ")->fetchAll();

        // Fetch attendance marked for this date
        $attStmt = $pdo->prepare("SELECT * FROM staff_attendance WHERE attendance_date = ?");
        $attStmt->execute([$date]);
        $attRecords = $attStmt->fetchAll();
        $attMap = [];
        foreach ($attRecords as $r) {
            $attMap[$r['staff_id']] = $r;
        }

        // Fetch batch names for staff
        $bStmt = $pdo->query("
            SELECT sb.staff_id, b.name AS batch_name 
            FROM staff_batches sb 
            JOIN batches b ON b.id = sb.batch_id
            ORDER BY b.start_time ASC
        ");
        $batchAssignments = $bStmt->fetchAll();
        $batchMap = [];
        foreach ($batchAssignments as $ba) {
            $batchMap[$ba['staff_id']][] = $ba['batch_name'];
        }

        foreach ($staffList as &$st) {
            $st['batches_str'] = !empty($batchMap[$st['id']]) ? implode(', ', $batchMap[$st['id']]) : 'કોઈ બેચ નથી';
            if (isset($attMap[$st['id']])) {
                $st['attendance'] = $attMap[$st['id']];
                $st['is_marked'] = true;
            } else {
                // If not marked and is holiday, default to holiday, else default to present
                $defaultStatus = $holidayInfo ? 'holiday' : 'present';
                $st['attendance'] = [
                    'id' => null,
                    'staff_id' => $st['id'],
                    'attendance_date' => $date,
                    'status' => $defaultStatus,
                    'half_day_session' => null,
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'working_hours' => null,
                    'notes' => $holidayInfo ? $holidayInfo['reason'] : null,
                ];
                $st['is_marked'] = false;
            }
        }
        unset($st);

        return [
            'date' => $date,
            'holiday_info' => $holidayInfo,
            'staff' => $staffList
        ];
    }

    /**
     * Mark or update attendance for a single staff member
     */
    public static function markSingle(
        int $staffId,
        string $date,
        string $status,
        ?string $halfDaySession = null,
        ?string $checkIn = null,
        ?string $checkOut = null,
        ?string $notes = null,
        int $actorId = 1
    ): bool {
        $pdo = db();
        $validStatuses = ['present', 'half_day', 'absent', 'paid_leave', 'holiday'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid attendance status: {$status}");
        }

        $checkIn = !empty($checkIn) ? $checkIn : null;
        $checkOut = !empty($checkOut) ? $checkOut : null;
        $halfDaySession = ($status === 'half_day' && in_array($halfDaySession, ['morning', 'afternoon'], true)) ? $halfDaySession : null;
        $notes = trim($notes ?? '') ?: null;

        // Calculate hours if both checkIn and checkOut provided
        $workingHours = null;
        if ($checkIn && $checkOut) {
            $tIn = strtotime("{$date} {$checkIn}");
            $tOut = strtotime("{$date} {$checkOut}");
            if ($tOut > $tIn) {
                $workingHours = round(($tOut - $tIn) / 3600, 2);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO staff_attendance (
                staff_id, attendance_date, status, half_day_session,
                check_in_time, check_out_time, working_hours, notes, marked_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                half_day_session = VALUES(half_day_session),
                check_in_time = VALUES(check_in_time),
                check_out_time = VALUES(check_out_time),
                working_hours = VALUES(working_hours),
                notes = VALUES(notes),
                marked_by = VALUES(marked_by),
                updated_at = NOW()
        ");

        $stmt->execute([
            $staffId, $date, $status, $halfDaySession,
            $checkIn, $checkOut, $workingHours, $notes, $actorId
        ]);

        return true;
    }

    /**
     * Save daily sheet for all staff in bulk
     */
    public static function saveDailySheet(string $date, array $sheetData, int $actorId = 1): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        $saved = 0;

        try {
            foreach ($sheetData as $staffId => $data) {
                $staffId = (int)$staffId;
                if ($staffId <= 0) continue;

                $status = $data['status'] ?? 'present';
                $halfDaySession = $data['half_day_session'] ?? null;
                $checkIn = $data['check_in_time'] ?? null;
                $checkOut = $data['check_out_time'] ?? null;
                $notes = $data['notes'] ?? null;

                self::markSingle($staffId, $date, $status, $halfDaySession, $checkIn, $checkOut, $notes, $actorId);
                $saved++;
            }

            log_audit('staff_attendance', 0, 'daily_bulk_save', null, ['date' => $date, 'count' => $saved]);
            $pdo->commit();
            return $saved;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get monthly attendance register matrix
     */
    public static function getMonthlyRegister(int $year, int $month, ?int $staffId = null): array
    {
        $pdo = db();
        $totalDays = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $totalDays);
        $today = date('Y-m-d');

        // Fetch declared institute holidays in this month
        $hStmt = $pdo->prepare("SELECT DISTINCT holiday_date, reason FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $hStmt->execute([$startDate, $endDate]);
        $declaredHolidays = [];
        foreach ($hStmt->fetchAll() as $h) {
            $declaredHolidays[$h['holiday_date']] = $h['reason'] ?: 'જાહેર રજા';
        }

        // Build calendar days metadata
        $daysMeta = [];
        $totalSundays = 0;
        $totalDeclaredHolidays = 0;

        for ($d = 1; $d <= $totalDays; $d++) {
            $curDate = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $dayOfWeek = (int)date('N', strtotime($curDate));
            $isSunday = ($dayOfWeek === 7);
            $isDeclared = isset($declaredHolidays[$curDate]);
            $isHoliday = $isSunday || $isDeclared;

            if ($isSunday) $totalSundays++;
            if ($isDeclared && !$isSunday) $totalDeclaredHolidays++;

            $daysMeta[$d] = [
                'day' => $d,
                'date' => $curDate,
                'day_name' => date('D', strtotime($curDate)),
                'is_sunday' => $isSunday,
                'is_declared_holiday' => $isDeclared,
                'holiday_reason' => $isDeclared ? $declaredHolidays[$curDate] : ($isSunday ? 'Sunday' : null),
                'is_holiday' => $isHoliday,
                'is_future' => ($curDate > $today)
            ];
        }

        $totalHolidaysInMonth = $totalSundays + $totalDeclaredHolidays;
        $totalWorkingDaysInMonth = $totalDays - $totalHolidaysInMonth;

        // Fetch staff list
        $sql = "SELECT s.id, s.name, s.phone, s.designation, s.salary, s.salary_type, s.status FROM staff s WHERE 1=1";
        $params = [];
        if ($staffId) {
            $sql .= " AND s.id = ?";
            $params[] = $staffId;
        } else {
            $sql .= " AND s.status = 'active'";
        }
        $sql .= " ORDER BY s.name ASC";

        $staffStmt = $pdo->prepare($sql);
        $staffStmt->execute($params);
        $staffList = $staffStmt->fetchAll();

        // Fetch all attendance for this month
        $attSql = "SELECT * FROM staff_attendance WHERE attendance_date BETWEEN ? AND ?";
        $attParams = [$startDate, $endDate];
        if ($staffId) {
            $attSql .= " AND staff_id = ?";
            $attParams[] = $staffId;
        }
        $attStmt = $pdo->prepare($attSql);
        $attStmt->execute($attParams);
        $allAttendance = $attStmt->fetchAll();

        // Map: [staff_id][day_number] => attendance record
        $staffAttMap = [];
        foreach ($allAttendance as $att) {
            $dayNum = (int)date('j', strtotime($att['attendance_date']));
            $staffAttMap[$att['staff_id']][$dayNum] = $att;
        }

        // Compile matrix for each staff member
        $matrix = [];
        foreach ($staffList as $st) {
            $pCount = 0;
            $hdCount = 0;
            $aCount = 0;
            $plCount = 0;
            $hCount = 0;
            $dayRecords = [];

            for ($d = 1; $d <= $totalDays; $d++) {
                $dMeta = $daysMeta[$d];
                $curDate = $dMeta['date'];
                $att = $staffAttMap[$st['id']][$d] ?? null;

                if ($att) {
                    $status = $att['status'];
                    $record = [
                        'status' => $status,
                        'half_day_session' => $att['half_day_session'],
                        'notes' => $att['notes'],
                        'is_marked' => true
                    ];

                    if ($status === 'present') $pCount += 1;
                    elseif ($status === 'half_day') $hdCount += 1;
                    elseif ($status === 'absent') $aCount += 1;
                    elseif ($status === 'paid_leave') $plCount += 1;
                    elseif ($status === 'holiday') $hCount += 1;
                } else {
                    // Not marked
                    if ($dMeta['is_holiday']) {
                        // Official Institute Holiday or Sunday is counted as Holiday
                        $record = [
                            'status' => 'holiday',
                            'half_day_session' => null,
                            'notes' => $dMeta['holiday_reason'],
                            'is_marked' => false
                        ];
                        // If not in the future, count towards holiday
                        if (!$dMeta['is_future']) {
                            $hCount += 1;
                        }
                    } elseif ($dMeta['is_future']) {
                        $record = [
                            'status' => 'future',
                            'half_day_session' => null,
                            'notes' => null,
                            'is_marked' => false
                        ];
                    } else {
                        // Past working day not marked
                        $record = [
                            'status' => 'unmarked',
                            'half_day_session' => null,
                            'notes' => 'હાજરી પુરાયેલ નથી',
                            'is_marked' => false
                        ];
                    }
                }

                $dayRecords[$d] = $record;
            }

            // Real-world Payable Days formula:
            // Payable Days = Present + (Half Days * 0.5) + Paid Leaves + Holidays (Sundays & Declared Holidays)
            // Holidays given by institute are NOT deducted!
            $payableDays = $pCount + ($hdCount * 0.5) + $plCount + $hCount;

            $matrix[] = [
                'staff' => $st,
                'days' => $dayRecords,
                'summary' => [
                    'present' => $pCount,
                    'half_day' => $hdCount,
                    'absent' => $aCount,
                    'paid_leave' => $plCount,
                    'holiday' => $hCount,
                    'total_days' => $totalDays,
                    'payable_days' => $payableDays,
                    'loss_of_pay_days' => $aCount + ($hdCount * 0.5)
                ]
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'month_name' => date('F Y', strtotime($startDate)),
            'total_days' => $totalDays,
            'total_sundays' => $totalSundays,
            'total_declared_holidays' => $totalDeclaredHolidays,
            'total_holidays' => $totalHolidaysInMonth,
            'working_days' => $totalWorkingDaysInMonth,
            'days_meta' => $daysMeta,
            'matrix' => $matrix
        ];
    }

    /**
     * Get Daily attendance KPIs
     */
    public static function getDailyKPIs(string $date): array
    {
        $sheet = self::getDailySheet($date);
        $total = count($sheet['staff']);
        $p = 0; $hd = 0; $a = 0; $pl = 0; $h = 0; $unmarked = 0;

        foreach ($sheet['staff'] as $st) {
            if (!$st['is_marked'] && !$sheet['holiday_info']) {
                $unmarked++;
            } else {
                $s = $st['attendance']['status'];
                if ($s === 'present') $p++;
                elseif ($s === 'half_day') $hd++;
                elseif ($s === 'absent') $a++;
                elseif ($s === 'paid_leave') $pl++;
                elseif ($s === 'holiday') $h++;
            }
        }

        return [
            'total_staff' => $total,
            'present' => $p,
            'half_day' => $hd,
            'absent' => $a,
            'paid_leave' => $pl,
            'holiday' => $h,
            'unmarked' => $unmarked,
            'is_holiday' => !empty($sheet['holiday_info']),
            'holiday_reason' => $sheet['holiday_info']['reason'] ?? null
        ];
    }
}
