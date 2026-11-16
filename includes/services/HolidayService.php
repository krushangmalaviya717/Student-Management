<?php
/**
 * HolidayService
 * Business logic for institute holidays, batch schedule adjustments, and course expiry rollbacks.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../course_engine.php';
require_once __DIR__ . '/ExportService.php';

class HolidayService
{
    /**
     * Get filtered holiday records
     */
    public static function getHolidays(array $filters = []): array
    {
        $pdo = db();
        $batchId = $filters['batch_id'] ?? '';
        $sql = "SELECT h.*, b.name AS batch_name, u.name AS created_by_name
                FROM holidays h
                JOIN batches b ON b.id = h.batch_id
                LEFT JOIN users u ON u.id = h.created_by
                WHERE 1=1";
        $params = [];

        if ($batchId !== '' && $batchId !== null) {
            $sql .= " AND h.batch_id = ?";
            $params[] = (int)$batchId;
        }

        $sql .= " ORDER BY h.holiday_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Apply holiday to single date for one batch or all active batches
     */
    public static function declareSingleHoliday(int|string $batchTarget, string $date, ?string $reason, int $userId): array
    {
        $targets = self::resolveBatchTargets($batchTarget);
        $added = 0;
        $skipped = 0;

        foreach ($targets as $bId) {
            try {
                apply_institute_holiday($bId, $date, $reason, $userId);
                $added++;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $skipped++; // Duplicate already declared
                } else {
                    throw $e;
                }
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Apply holiday date range (e.g. Diwali Vacation) with option to skip Sundays
     */
    public static function declareHolidayRange(
        int|string $batchTarget, 
        string $startDate, 
        string $endDate, 
        ?string $reason, 
        bool $skipSundays, 
        int $userId
    ): array {
        $targets = self::resolveBatchTargets($batchTarget);
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        if ($start > $end) {
            throw new InvalidArgumentException('Start date cannot be after end date.');
        }

        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, (clone $end)->modify('+1 day'));

        $dates = [];
        foreach ($period as $dt) {
            if ($skipSundays && $dt->format('N') == 7) {
                continue; // Skip Sunday
            }
            $dates[] = $dt->format('Y-m-d');
        }

        $added = 0;
        $skipped = 0;

        foreach ($dates as $d) {
            foreach ($targets as $bId) {
                try {
                    apply_institute_holiday($bId, $d, $reason, $userId);
                    $added++;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $skipped++;
                    } else {
                        throw $e;
                    }
                }
            }
        }

        return ['total_days' => count($dates), 'added' => $added, 'skipped' => $skipped];
    }

    /**
     * Apply multiple custom comma/line-separated dates
     */
    public static function declareCustomDates(int|string $batchTarget, array $dates, ?string $reason, int $userId): array
    {
        $targets = self::resolveBatchTargets($batchTarget);
        $added = 0;
        $skipped = 0;

        foreach ($dates as $d) {
            $d = trim($d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                continue;
            }
            foreach ($targets as $bId) {
                try {
                    apply_institute_holiday($bId, $d, $reason, $userId);
                    $added++;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $skipped++;
                    } else {
                        throw $e;
                    }
                }
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Delete a holiday and rollback expiry dates of all affected students
     */
    public static function deleteHoliday(int $holidayId, int $userId): array
    {
        $stmt = db()->prepare("SELECT * FROM holidays WHERE id = ?");
        $stmt->execute([$holidayId]);
        $holiday = $stmt->fetch();

        if (!$holiday) {
            throw new RuntimeException('Holiday record not found.');
        }

        delete_institute_holiday($holidayId, $userId);
        return $holiday;
    }

    /**
     * Helper to resolve batch ID or 'all' to array of batch IDs
     */
    private static function resolveBatchTargets(int|string $batchTarget): array
    {
        if ($batchTarget === 'all' || $batchTarget === 0 || $batchTarget === '0') {
            $batches = db()->query("SELECT id FROM batches WHERE is_active = 1")->fetchAll();
            return array_column($batches, 'id');
        }
        return [(int)$batchTarget];
    }

    /**
     * Export holidays list to CSV
     */
    public static function exportToCsv(array $holidays): void
    {
        $filename = 'Holidays_' . date('Y-m-d') . '.csv';
        $headers = [
            'ID',
            'Date',
            'Day',
            'Batch',
            'Reason / Note',
            'Declared By',
            'Created Date'
        ];

        $rows = [];
        foreach ($holidays as $h) {
            $rows[] = [
                $h['id'],
                date('d-M-Y', strtotime($h['holiday_date'])),
                date('l', strtotime($h['holiday_date'])),
                $h['batch_name'],
                $h['reason'] ?: 'Institute Holiday',
                $h['created_by_name'] ?: 'Admin',
                date('d-M-Y H:i', strtotime($h['created_at']))
            ];
        }

        ExportService::downloadCsv($filename, $headers, $rows);
    }
}
