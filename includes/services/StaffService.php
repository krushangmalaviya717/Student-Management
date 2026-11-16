<?php
/**
 * StaffService
 * Business logic for Staff & Teacher management, batch assignments, and user credentials.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';

class StaffService
{
    /**
     * Get list of staff with optional filtering
     */
    public static function getAllStaff(?string $status = null, ?int $batchId = null, ?string $search = null): array
    {
        $pdo = db();
        $params = [];
        $where = [];

        if ($status && in_array($status, ['active', 'inactive'], true)) {
            $where[] = "s.status = ?";
            $params[] = $status;
        }

        if ($batchId && $batchId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM staff_batches sb WHERE sb.staff_id = s.id AND sb.batch_id = ?)";
            $params[] = $batchId;
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $where[] = "(s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.designation LIKE ? OR s.specialization LIKE ?)";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT s.*, 
                       u.email AS user_email, 
                       u.status AS user_status, 
                       u.last_login_at
                FROM staff s
                LEFT JOIN users u ON u.id = s.user_id
                {$whereClause}
                ORDER BY s.status ASC, s.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $staffList = $stmt->fetchAll();

        // Fetch batch assignments for all staff
        if (!empty($staffList)) {
            $staffIds = array_column($staffList, 'id');
            $inClause = implode(',', array_map('intval', $staffIds));

            $batchStmt = $pdo->query("
                SELECT sb.staff_id, b.id AS batch_id, b.name AS batch_name, b.start_time, b.end_time, b.batch_days, b.capacity
                FROM staff_batches sb
                JOIN batches b ON b.id = sb.batch_id
                WHERE sb.staff_id IN ({$inClause})
                ORDER BY b.start_time ASC
            ");
            $assignments = $batchStmt->fetchAll();

            $batchMap = [];
            foreach ($assignments as $row) {
                $batchMap[$row['staff_id']][] = $row;
            }

            foreach ($staffList as &$st) {
                $st['batches'] = $batchMap[$st['id']] ?? [];
                $batchNames = array_map(function($b) {
                    return $b['batch_name'];
                }, $st['batches']);
                $st['batch_names_str'] = !empty($batchNames) ? implode(', ', $batchNames) : 'કોઈ બેચ ફાળવેલ નથી';
            }
            unset($st);
        }

        return $staffList;
    }

    /**
     * Get single staff member by ID with batch assignments
     */
    public static function getStaffById(int $id): ?array
    {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   u.email AS user_email, 
                   u.status AS user_status, 
                   u.last_login_at
            FROM staff s
            LEFT JOIN users u ON u.id = s.user_id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $staff = $stmt->fetch();

        if (!$staff) {
            return null;
        }

        $bStmt = $pdo->prepare("SELECT batch_id FROM staff_batches WHERE staff_id = ?");
        $bStmt->execute([$id]);
        $staff['batch_ids'] = $bStmt->fetchAll(PDO::FETCH_COLUMN);

        return $staff;
    }

    /**
     * Create a new staff member
     */
    public static function createStaff(array $data, int $actorId): int
    {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $name = trim($data['name'] ?? '');
            $phone = trim($data['phone'] ?? '');
            $email = trim($data['email'] ?? '') ?: null;
            $designation = trim($data['designation'] ?? 'Teacher');
            $specialization = trim($data['specialization'] ?? '') ?: null;
            $joiningDate = !empty($data['joining_date']) ? $data['joining_date'] : date('Y-m-d');
            $salary = isset($data['salary']) && is_numeric($data['salary']) ? (float)$data['salary'] : 0.00;
            $salaryType = in_array($data['salary_type'] ?? '', ['monthly', 'per_day', 'per_hour']) ? $data['salary_type'] : 'monthly';
            $address = trim($data['address'] ?? '') ?: null;
            $emergencyContact = trim($data['emergency_contact'] ?? '') ?: null;
            $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
            $notes = trim($data['notes'] ?? '') ?: null;

            if (empty($name)) {
                throw new InvalidArgumentException('સ્ટાફનું નામ દાખલ કરવું ફરજિયાત છે.');
            }
            if (empty($phone)) {
                throw new InvalidArgumentException('મોબાઇલ નંબર દાખલ કરવો ફરજિયાત છે.');
            }

            $userId = null;
            $createLogin = !empty($data['create_login']);
            $password = $data['password'] ?? '';

            if ($createLogin) {
                if (empty($email)) {
                    throw new InvalidArgumentException('સિસ્ટમ લોગિન માટે ઈમેલ આપવો જરૂરી છે.');
                }
                if (strlen($password) < 6) {
                    throw new InvalidArgumentException('પાસવર્ડ ઓછામાં ઓછો 6 અક્ષરનો હોવો જોઈએ.');
                }

                // Check if email already used in users table
                $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $uStmt->execute([$email]);
                if ($uStmt->fetch()) {
                    throw new InvalidArgumentException("આ ઈમેલ ({$email}) પર પહેલેથી યુઝર એકાઉન્ટ ઉપલબ્ધ છે.");
                }

                // Get role ID for staff
                $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'staff' LIMIT 1");
                $roleStmt->execute();
                $staffRoleId = (int)$roleStmt->fetchColumn();
                if (!$staffRoleId) {
                    $staffRoleId = 3;
                }

                $insUser = $pdo->prepare("
                    INSERT INTO users (role_id, name, email, phone, password_hash, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insUser->execute([
                    $staffRoleId,
                    $name,
                    $email,
                    $phone,
                    password_hash($password, PASSWORD_DEFAULT),
                    $status
                ]);
                $userId = (int)$pdo->lastInsertId();
            }

            // Insert into staff table
            $insStaff = $pdo->prepare("
                INSERT INTO staff (
                    user_id, name, phone, email, designation, specialization,
                    joining_date, salary, salary_type, address, emergency_contact,
                    status, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insStaff->execute([
                $userId, $name, $phone, $email, $designation, $specialization,
                $joiningDate, $salary, $salaryType, $address, $emergencyContact,
                $status, $notes
            ]);
            $staffId = (int)$pdo->lastInsertId();

            // Insert batch assignments
            $batchIds = $data['batch_ids'] ?? [];
            if (is_array($batchIds) && !empty($batchIds)) {
                $sbStmt = $pdo->prepare("INSERT IGNORE INTO staff_batches (staff_id, batch_id) VALUES (?, ?)");
                foreach ($batchIds as $bId) {
                    $bId = (int)$bId;
                    if ($bId > 0) {
                        $sbStmt->execute([$staffId, $bId]);
                    }
                }
            }

            log_audit('staff', $staffId, 'create', null, [
                'name' => $name,
                'phone' => $phone,
                'designation' => $designation,
                'user_id' => $userId
            ]);

            $pdo->commit();
            return $staffId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing staff member
     */
    public static function updateStaff(int $id, array $data, int $actorId): bool
    {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $existing = self::getStaffById($id);
            if (!$existing) {
                throw new RuntimeException('સ્ટાફ રેકોર્ડ મળ્યો નથી.');
            }

            $name = trim($data['name'] ?? '');
            $phone = trim($data['phone'] ?? '');
            $email = trim($data['email'] ?? '') ?: null;
            $designation = trim($data['designation'] ?? 'Teacher');
            $specialization = trim($data['specialization'] ?? '') ?: null;
            $joiningDate = !empty($data['joining_date']) ? $data['joining_date'] : $existing['joining_date'];
            $salary = isset($data['salary']) && is_numeric($data['salary']) ? (float)$data['salary'] : 0.00;
            $salaryType = in_array($data['salary_type'] ?? '', ['monthly', 'per_day', 'per_hour']) ? $data['salary_type'] : 'monthly';
            $address = trim($data['address'] ?? '') ?: null;
            $emergencyContact = trim($data['emergency_contact'] ?? '') ?: null;
            $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
            $notes = trim($data['notes'] ?? '') ?: null;

            if (empty($name)) {
                throw new InvalidArgumentException('સ્ટાફનું નામ દાખલ કરવું ફરજિયાત છે.');
            }
            if (empty($phone)) {
                throw new InvalidArgumentException('મોબાઇલ નંબર દાખલ કરવો ફરજિયાત છે.');
            }

            $userId = $existing['user_id'];
            $password = $data['password'] ?? '';
            $createLogin = !empty($data['create_login']);

            if ($userId) {
                // Update existing user credentials
                if ($email) {
                    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $chk->execute([$email, $userId]);
                    if ($chk->fetch()) {
                        throw new InvalidArgumentException("આ ઈમેલ ({$email}) અન્ય યુઝર સાથે જોડાયેલ છે.");
                    }
                }

                $userSql = "UPDATE users SET name = ?, email = ?, phone = ?, status = ?";
                $userParams = [$name, $email, $phone, $status];

                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        throw new InvalidArgumentException('પાસવર્ડ ઓછામાં ઓછો 6 અક્ષરનો હોવો જોઈએ.');
                    }
                    $userSql .= ", password_hash = ?";
                    $userParams[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $userSql .= " WHERE id = ?";
                $userParams[] = $userId;

                $pdo->prepare($userSql)->execute($userParams);
            } elseif ($createLogin) {
                // Create brand new login for staff
                if (empty($email)) {
                    throw new InvalidArgumentException('સિસ્ટમ લોગિન માટે ઈમેલ આપવો જરૂરી છે.');
                }
                if (strlen($password) < 6) {
                    throw new InvalidArgumentException('પાસવર્ડ ઓછામાં ઓછો 6 અક્ષરનો હોવો જોઈએ.');
                }

                $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $uStmt->execute([$email]);
                if ($uStmt->fetch()) {
                    throw new InvalidArgumentException("આ ઈમેલ ({$email}) પર પહેલેથી યુઝર એકાઉન્ટ ઉપલબ્ધ છે.");
                }

                $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'staff' LIMIT 1");
                $roleStmt->execute();
                $staffRoleId = (int)$roleStmt->fetchColumn() ?: 3;

                $insUser = $pdo->prepare("
                    INSERT INTO users (role_id, name, email, phone, password_hash, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insUser->execute([
                    $staffRoleId,
                    $name,
                    $email,
                    $phone,
                    password_hash($password, PASSWORD_DEFAULT),
                    $status
                ]);
                $userId = (int)$pdo->lastInsertId();
            }

            // Update staff record
            $upd = $pdo->prepare("
                UPDATE staff SET
                    user_id = ?,
                    name = ?,
                    phone = ?,
                    email = ?,
                    designation = ?,
                    specialization = ?,
                    joining_date = ?,
                    salary = ?,
                    salary_type = ?,
                    address = ?,
                    emergency_contact = ?,
                    status = ?,
                    notes = ?
                WHERE id = ?
            ");
            $upd->execute([
                $userId, $name, $phone, $email, $designation, $specialization,
                $joiningDate, $salary, $salaryType, $address, $emergencyContact,
                $status, $notes, $id
            ]);

            // Sync batch assignments
            $pdo->prepare("DELETE FROM staff_batches WHERE staff_id = ?")->execute([$id]);
            $batchIds = $data['batch_ids'] ?? [];
            if (is_array($batchIds) && !empty($batchIds)) {
                $sbStmt = $pdo->prepare("INSERT IGNORE INTO staff_batches (staff_id, batch_id) VALUES (?, ?)");
                foreach ($batchIds as $bId) {
                    $bId = (int)$bId;
                    if ($bId > 0) {
                        $sbStmt->execute([$id, $bId]);
                    }
                }
            }

            log_audit('staff', $id, 'update', $existing, [
                'name' => $name,
                'phone' => $phone,
                'designation' => $designation,
                'status' => $status
            ]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Toggle active/inactive status
     */
    public static function toggleStatus(int $id, int $actorId): bool
    {
        $pdo = db();
        $staff = self::getStaffById($id);
        if (!$staff) {
            throw new RuntimeException('સ્ટાફ રેકોર્ડ મળ્યો નથી.');
        }

        $newStatus = ($staff['status'] === 'active') ? 'inactive' : 'active';
        $pdo->prepare("UPDATE staff SET status = ? WHERE id = ?")->execute([$newStatus, $id]);

        if (!empty($staff['user_id'])) {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $staff['user_id']]);
        }

        log_audit('staff', $id, 'toggle_status', ['old_status' => $staff['status']], ['new_status' => $newStatus]);
        return true;
    }

    /**
     * Delete staff member
     */
    public static function deleteStaff(int $id, int $actorId): bool
    {
        $pdo = db();
        $staff = self::getStaffById($id);
        if (!$staff) {
            throw new RuntimeException('સ્ટાફ રેકોર્ડ મળ્યો નથી.');
        }

        $pdo->prepare("DELETE FROM staff WHERE id = ?")->execute([$id]);

        if (!empty($staff['user_id'])) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$staff['user_id']]);
        }

        log_audit('staff', $id, 'delete', $staff, null);
        return true;
    }

    /**
     * Get KPI summary metrics
     */
    public static function getKPIs(): array
    {
        $pdo = db();
        $totalStaff = (int)$pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();
        $activeStaff = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status = 'active'")->fetchColumn();
        $inactiveStaff = $totalStaff - $activeStaff;
        
        $assignedBatches = (int)$pdo->query("SELECT COUNT(DISTINCT batch_id) FROM staff_batches sb JOIN staff s ON s.id = sb.staff_id WHERE s.status = 'active'")->fetchColumn();
        
        $monthlyPayroll = (float)$pdo->query("SELECT COALESCE(SUM(salary), 0) FROM staff WHERE status = 'active' AND salary_type = 'monthly'")->fetchColumn();

        return [
            'total_staff' => $totalStaff,
            'active_staff' => $activeStaff,
            'inactive_staff' => $inactiveStaff,
            'assigned_batches' => $assignedBatches,
            'monthly_payroll' => $monthlyPayroll
        ];
    }
}
