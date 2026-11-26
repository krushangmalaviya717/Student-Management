<?php
/**
 * BatchService
 * Business logic for batch management, capacity validation, and seat assignments.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';

class BatchService
{
    /**
     * Get all batches ordered by start time
     */
    public static function getAllBatches(bool $activeOnly = false): array
    {
        $pdo = db();
        $sql = "SELECT b.* FROM batches b";
        if ($activeOnly) {
            $sql .= " WHERE b.is_active = 1";
        }
        $sql .= " ORDER BY b.start_time ASC";
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Get single batch by ID
     */
    public static function getBatchById(int $id): ?array
    {
        $stmt = db()->prepare("SELECT * FROM batches WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Count active students currently studying in a batch
     */
    public static function getActiveStudentCount(int $batchId): int
    {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM students 
             WHERE joined_batch_id = ? AND (status IN ('active', 'extended') OR course_status IN ('active', 'extended'))"
        );
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Count waiting students who chose this batch as preferred
     */
    public static function getWaitingStudentCount(int $batchId): int
    {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM students 
             WHERE preferred_batch_id = ? AND status LIKE 'waiting%'"
        );
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get aggregate statistics across all batches
     */
    public static function getBatchStats(): array
    {
        $pdo = db();
        $totalBatches = (int)$pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn();
        $activeBatches = (int)$pdo->query("SELECT COUNT(*) FROM batches WHERE is_active = 1")->fetchColumn();
        $totalCapacity = (int)$pdo->query("SELECT COALESCE(SUM(capacity), 0) FROM batches WHERE is_active = 1")->fetchColumn();
        $occupiedSeats = (int)$pdo->query(
            "SELECT COUNT(*) FROM students 
             WHERE joined_batch_id IS NOT NULL 
               AND (status IN ('active', 'extended') OR course_status IN ('active', 'extended'))"
        )->fetchColumn();

        return [
            'total_batches' => $totalBatches,
            'active_batches' => $activeBatches,
            'total_capacity' => $totalCapacity,
            'occupied_seats' => $occupiedSeats,
            'available_seats' => max(0, $totalCapacity - $occupiedSeats),
        ];
    }

    /**
     * Create a new batch
     */
    public static function createBatch(array $data, int $userId): int
    {
        $name = trim($data['name'] ?? '');
        $days = trim($data['batch_days'] ?? 'Mon-Sat');
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        $capacity = (int)($data['capacity'] ?? get_setting('default_batch_capacity', 8));
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if (empty($name)) {
            throw new InvalidArgumentException('Batch name is required.');
        }
        if (empty($startTime) || empty($endTime)) {
            throw new InvalidArgumentException('Start time and end time are required.');
        }
        if ($capacity < 1) {
            throw new InvalidArgumentException('Capacity must be at least 1.');
        }

        $pdo = db();
        $stmt = $pdo->prepare(
            "INSERT INTO batches (name, batch_days, start_time, end_time, capacity, is_active)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$name, $days, $startTime, $endTime, $capacity, $isActive]);
        $newId = (int)$pdo->lastInsertId();

        log_audit('batch', $newId, 'create', null, $data);
        return $newId;
    }

    /**
     * Update an existing batch
     */
    public static function updateBatch(int $id, array $data, int $userId): bool
    {
        $batch = self::getBatchById($id);
        if (!$batch) {
            throw new RuntimeException('Batch not found.');
        }

        $name = trim($data['name'] ?? '');
        $days = trim($data['batch_days'] ?? 'Mon-Sat');
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        $capacity = (int)($data['capacity'] ?? get_setting('default_batch_capacity', 8));
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if (empty($name)) {
            throw new InvalidArgumentException('Batch name cannot be empty.');
        }
        if (empty($startTime) || empty($endTime)) {
            throw new InvalidArgumentException('Start and end times are required.');
        }

        $activeCount = self::getActiveStudentCount($id);
        if ($capacity < $activeCount) {
            throw new InvalidArgumentException("Capacity cannot be less than current active students ({$activeCount}).");
        }

        $pdo = db();
        $stmt = $pdo->prepare(
            "UPDATE batches 
             SET name = ?, batch_days = ?, start_time = ?, end_time = ?, capacity = ?, is_active = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $result = $stmt->execute([$name, $days, $startTime, $endTime, $capacity, $isActive, $id]);

        log_audit('batch', $id, 'update', $batch, $data);
        return $result;
    }

    /**
     * Toggle batch active/inactive status
     */
    public static function toggleStatus(int $id, int $userId): bool
    {
        $batch = self::getBatchById($id);
        if (!$batch) {
            throw new RuntimeException('Batch not found.');
        }

        $newStatus = $batch['is_active'] ? 0 : 1;
        $pdo = db();
        $stmt = $pdo->prepare("UPDATE batches SET is_active = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$newStatus, $id]);

        log_audit('batch', $id, 'toggle_status', ['is_active' => $batch['is_active']], ['is_active' => $newStatus]);
        return $result;
    }

    /**
     * Safe deletion of a batch with integrity checks
     */
    public static function deleteBatch(int $id, int $userId): array
    {
        $batch = self::getBatchById($id);
        if (!$batch) {
            throw new RuntimeException('Batch not found.');
        }

        $activeCount = self::getActiveStudentCount($id);
        if ($activeCount > 0) {
            throw new RuntimeException("આ બેચમાં હાલમાં {$activeCount} એક્ટિવ વિદ્યાર્થીઓ અભ્યાસ કરી રહ્યા છે, તેથી આ બેચ ડિલીટ કરી શકાશે નહીં. (Active students present)");
        }

        $pdo = db();
        $historyCount = (int)$pdo->prepare(
            "SELECT COUNT(*) FROM student_batch_history WHERE old_batch_id = ? OR new_batch_id = ?"
        );
        $historyCount = (int)$pdo->query(
            "SELECT (SELECT COUNT(*) FROM student_batch_history WHERE old_batch_id = {$id} OR new_batch_id = {$id}) +
                    (SELECT COUNT(*) FROM students WHERE joined_batch_id = {$id} OR preferred_batch_id = {$id}) AS total"
        )->fetchColumn();

        if ($historyCount > 0) {
            // Soft deactivation to preserve historical records
            $pdo->prepare("UPDATE batches SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
            log_audit('batch', $id, 'deactivate', $batch, ['reason' => 'historical_records_exist']);
            return [
                'action' => 'deactivated',
                'message' => "બેચ સાથે જૂનો રેકોર્ડ જોડાયેલો હોવાથી તેને કાયમી કાઢી નાખવાને બદલે નિષ્ક્રિય (Inactive) કરી દેવામાં આવી છે."
            ];
        }

        // Hard delete if clean
        $pdo->prepare("DELETE FROM holidays WHERE batch_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM batches WHERE id = ?")->execute([$id]);
        log_audit('batch', $id, 'delete', $batch, null);

        return [
            'action' => 'deleted',
            'message' => "બેચ '{$batch['name']}' સફળતાપૂર્વક ડિલીટ કરી દેવામાં આવી છે."
        ];
    }
}
