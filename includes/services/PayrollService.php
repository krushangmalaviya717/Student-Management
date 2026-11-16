<?php
/**
 * PayrollService
 * Business logic for Salary calculations, holiday adjustments, deductions and payslips.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/AttendanceService.php';

class PayrollService
{
    /**
     * Calculate monthly salary preview for a single staff member
     */
    public static function calculateSalary(int $staffId, int $year, int $month): array
    {
        $pdo = db();
        $stStmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
        $stStmt->execute([$staffId]);
        $staff = $stStmt->fetch();

        if (!$staff) {
            throw new RuntimeException("Staff ID {$staffId} not found");
        }

        $monthStr = sprintf('%04d-%02d', $year, $month);
        $totalDays = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);

        // Fetch monthly attendance summary from AttendanceService
        $register = AttendanceService::getMonthlyRegister($year, $month, $staffId);
        $summary = $register['matrix'][0]['summary'] ?? [
            'present' => 0,
            'half_day' => 0,
            'absent' => 0,
            'paid_leave' => 0,
            'holiday' => $register['total_holidays'],
            'total_days' => $totalDays,
            'payable_days' => $totalDays,
            'loss_of_pay_days' => 0
        ];

        $baseSalary = (float)$staff['salary'];
        $salaryType = $staff['salary_type'] ?? 'monthly';
        $dailyRate = $totalDays > 0 ? round($baseSalary / $totalDays, 2) : 0.00;

        $presentDays = (float)$summary['present'];
        $halfDays = (int)$summary['half_day'];
        $absentDays = (float)$summary['absent'];
        $paidLeaves = (float)$summary['paid_leave'];
        $holidaysCount = (int)$summary['holiday'];

        // Loss of Pay Days = Unexcused Absents + (Half Days * 0.5)
        // Institute Holidays & Sundays have ZERO loss of pay!
        $lossOfPayDays = $absentDays + ($halfDays * 0.5);
        $lossOfPayAmount = round($lossOfPayDays * $dailyRate, 2);

        // Effective Payable Days = Total Days in month - Loss of Pay Days
        $payableDays = max(0, $totalDays - $lossOfPayDays);

        // Real-world Salary calculation:
        // For monthly salary: Base Salary - Loss of Pay
        if ($salaryType === 'monthly') {
            $grossSalary = max(0, round($baseSalary - $lossOfPayAmount, 2));
        } else {
            // For daily/hourly rate: Daily Rate * Payable Days
            $grossSalary = max(0, round($dailyRate * $payableDays, 2));
        }

        // Check if an existing payroll record exists for saved bonus/deductions
        $pStmt = $pdo->prepare("SELECT * FROM staff_payroll WHERE staff_id = ? AND payroll_month = ?");
        $pStmt->execute([$staffId, $monthStr]);
        $existingPayroll = $pStmt->fetch();

        $bonus = $existingPayroll ? (float)$existingPayroll['bonus'] : 0.00;
        $extraDeductions = $existingPayroll ? (float)$existingPayroll['deductions'] : 0.00;

        $netSalary = max(0, round($grossSalary + $bonus - $extraDeductions, 2));

        return [
            'staff' => $staff,
            'payroll_month' => $monthStr,
            'year' => $year,
            'month' => $month,
            'month_name' => date('F Y', strtotime("{$monthStr}-01")),
            'total_days' => $totalDays,
            'working_days' => $register['working_days'],
            'present_days' => $presentDays,
            'half_days' => $halfDays,
            'absent_days' => $absentDays,
            'paid_leaves' => $paidLeaves,
            'holidays_count' => $holidaysCount,
            'payable_days' => $payableDays,
            'loss_of_pay_days' => $lossOfPayDays,
            'loss_of_pay_amount' => $lossOfPayAmount,
            'base_salary' => $baseSalary,
            'salary_type' => $salaryType,
            'daily_rate' => $dailyRate,
            'gross_salary' => $grossSalary,
            'bonus' => $bonus,
            'deductions' => $extraDeductions,
            'net_salary' => $netSalary,
            'existing_payroll' => $existingPayroll
        ];
    }

    /**
     * Save or update payroll record
     */
    public static function savePayroll(int $staffId, string $month, array $data, int $actorId = 1): int
    {
        $pdo = db();
        list($y, $m) = explode('-', $month);
        $calc = self::calculateSalary($staffId, (int)$y, (int)$m);

        $bonus = isset($data['bonus']) && is_numeric($data['bonus']) ? (float)$data['bonus'] : $calc['bonus'];
        $deductions = isset($data['deductions']) && is_numeric($data['deductions']) ? (float)$data['deductions'] : $calc['deductions'];
        $grossSalary = isset($data['gross_salary']) && is_numeric($data['gross_salary']) ? (float)$data['gross_salary'] : $calc['gross_salary'];
        $netSalary = max(0, round($grossSalary + $bonus - $deductions, 2));
        $paymentStatus = in_array($data['payment_status'] ?? '', ['pending', 'paid', 'partial'], true) ? $data['payment_status'] : 'pending';
        $paidAmount = ($paymentStatus === 'paid') ? $netSalary : (float)($data['paid_amount'] ?? 0);
        $paymentMethod = $data['payment_method'] ?? 'cash';
        $paymentReference = trim($data['payment_reference'] ?? '') ?: null;
        $paymentDate = !empty($data['payment_date']) ? $data['payment_date'] : ($paymentStatus === 'paid' ? date('Y-m-d') : null);
        $notes = trim($data['notes'] ?? '') ?: null;

        $stmt = $pdo->prepare("
            INSERT INTO staff_payroll (
                staff_id, payroll_month, total_days_in_month, working_days,
                present_days, half_days, absent_days, paid_leaves, holidays_count,
                payable_days, base_salary, daily_rate, gross_salary, deductions,
                bonus, net_salary, payment_status, paid_amount, payment_method,
                payment_reference, payment_date, notes, created_by
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?
            ) ON DUPLICATE KEY UPDATE
                total_days_in_month = VALUES(total_days_in_month),
                working_days = VALUES(working_days),
                present_days = VALUES(present_days),
                half_days = VALUES(half_days),
                absent_days = VALUES(absent_days),
                paid_leaves = VALUES(paid_leaves),
                holidays_count = VALUES(holidays_count),
                payable_days = VALUES(payable_days),
                base_salary = VALUES(base_salary),
                daily_rate = VALUES(daily_rate),
                gross_salary = VALUES(gross_salary),
                deductions = VALUES(deductions),
                bonus = VALUES(bonus),
                net_salary = VALUES(net_salary),
                payment_status = VALUES(payment_status),
                paid_amount = VALUES(paid_amount),
                payment_method = VALUES(payment_method),
                payment_reference = VALUES(payment_reference),
                payment_date = VALUES(payment_date),
                notes = VALUES(notes),
                updated_at = NOW()
        ");

        $stmt->execute([
            $staffId, $month, $calc['total_days'], $calc['working_days'],
            $calc['present_days'], $calc['half_days'], $calc['absent_days'], $calc['paid_leaves'], $calc['holidays_count'],
            $calc['payable_days'], $calc['base_salary'], $calc['daily_rate'], $grossSalary, $deductions,
            $bonus, $netSalary, $paymentStatus, $paidAmount, $paymentMethod,
            $paymentReference, $paymentDate, $notes, $actorId
        ]);

        $payrollId = (int)$pdo->lastInsertId();
        if ($payrollId === 0) {
            $pStmt = $pdo->prepare("SELECT id FROM staff_payroll WHERE staff_id = ? AND payroll_month = ?");
            $pStmt->execute([$staffId, $month]);
            $payrollId = (int)$pStmt->fetchColumn();
        }

        log_audit('staff_payroll', $payrollId, 'save_payroll', null, [
            'staff_id' => $staffId,
            'month' => $month,
            'net_salary' => $netSalary,
            'status' => $paymentStatus
        ]);

        return $payrollId;
    }

    /**
     * Auto-generate payroll for all active staff for a month
     */
    public static function generateAllForMonth(string $month, int $actorId = 1): int
    {
        $pdo = db();
        $staffList = $pdo->query("SELECT id FROM staff WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;

        foreach ($staffList as $sId) {
            self::savePayroll((int)$sId, $month, [], $actorId);
            $count++;
        }

        return $count;
    }

    /**
     * Mark payroll as paid
     */
    public static function markAsPaid(int $payrollId, array $paymentData, int $actorId = 1): bool
    {
        $pdo = db();
        $p = self::getPayrollById($payrollId);
        if (!$p) {
            throw new RuntimeException("Payroll record not found");
        }

        $paidAmount = isset($paymentData['paid_amount']) && is_numeric($paymentData['paid_amount']) 
            ? (float)$paymentData['paid_amount'] 
            : (float)$p['net_salary'];
        
        $paymentStatus = ($paidAmount >= $p['net_salary']) ? 'paid' : 'partial';
        $paymentMethod = $paymentData['payment_method'] ?? 'cash';
        $paymentRef = trim($paymentData['payment_reference'] ?? '') ?: null;
        $paymentDate = !empty($paymentData['payment_date']) ? $paymentData['payment_date'] : date('Y-m-d');
        $notes = trim($paymentData['notes'] ?? '') ?: $p['notes'];

        $stmt = $pdo->prepare("
            UPDATE staff_payroll SET
                payment_status = ?,
                paid_amount = ?,
                payment_method = ?,
                payment_reference = ?,
                payment_date = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $paymentStatus, $paidAmount, $paymentMethod,
            $paymentRef, $paymentDate, $notes, $payrollId
        ]);

        log_audit('staff_payroll', $payrollId, 'record_payment', ['old_status' => $p['payment_status']], [
            'status' => $paymentStatus,
            'paid_amount' => $paidAmount,
            'method' => $paymentMethod
        ]);

        return true;
    }

    /**
     * Get payroll list for a specific month
     */
    public static function getPayrollList(string $month, ?string $status = null): array
    {
        $pdo = db();
        $params = [$month];
        $where = "WHERE sp.payroll_month = ?";

        if ($status && in_array($status, ['pending', 'paid', 'partial'], true)) {
            $where .= " AND sp.payment_status = ?";
            $params[] = $status;
        }

        $sql = "
            SELECT sp.*, 
                   s.name AS staff_name, 
                   s.phone AS staff_phone, 
                   s.designation AS staff_designation,
                   s.salary AS registered_salary
            FROM staff_payroll sp
            JOIN staff s ON s.id = sp.staff_id
            {$where}
            ORDER BY sp.payment_status ASC, s.name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get single payroll details by ID
     */
    public static function getPayrollById(int $id): ?array
    {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT sp.*, 
                   s.name AS staff_name, 
                   s.phone AS staff_phone, 
                   s.email AS staff_email,
                   s.designation AS staff_designation,
                   s.specialization AS staff_specialization,
                   s.joining_date AS staff_joining_date,
                   s.address AS staff_address
            FROM staff_payroll sp
            JOIN staff s ON s.id = sp.staff_id
            WHERE sp.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get monthly payroll statistics
     */
    public static function getMonthlyStats(string $month): array
    {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_records,
                COALESCE(SUM(base_salary), 0) AS total_base,
                COALESCE(SUM(gross_salary), 0) AS total_gross,
                COALESCE(SUM(deductions), 0) AS total_deductions,
                COALESCE(SUM(bonus), 0) AS total_bonus,
                COALESCE(SUM(net_salary), 0) AS total_net,
                COALESCE(SUM(paid_amount), 0) AS total_paid,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) AS pending_count
            FROM staff_payroll
            WHERE payroll_month = ?
        ");
        $stmt->execute([$month]);
        $stats = $stmt->fetch();

        $activeStaffCount = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status = 'active'")->fetchColumn();

        return [
            'total_active_staff' => $activeStaffCount,
            'total_records' => (int)($stats['total_records'] ?? 0),
            'total_base' => (float)($stats['total_base'] ?? 0),
            'total_gross' => (float)($stats['total_gross'] ?? 0),
            'total_deductions' => (float)($stats['total_deductions'] ?? 0),
            'total_bonus' => (float)($stats['total_bonus'] ?? 0),
            'total_net' => (float)($stats['total_net'] ?? 0),
            'total_paid' => (float)($stats['total_paid'] ?? 0),
            'total_pending' => (float)(($stats['total_net'] ?? 0) - ($stats['total_paid'] ?? 0)),
            'paid_count' => (int)($stats['paid_count'] ?? 0),
            'pending_count' => (int)($stats['pending_count'] ?? 0)
        ];
    }
}
