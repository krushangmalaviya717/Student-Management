<?php
/**
 * PaymentService
 * Business logic for student fee payments, duplicate protection, due limitations,
 * security PIN validation, receipts, summaries, and accounting.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/ExportService.php';
require_once __DIR__ . '/WhatsAppService.php';

class PaymentService
{
    /**
     * Verify Payment WhatsApp OTP, configured hashed Master PIN, or User Password
     * with brute-force rate limiting (5 failures -> 5-minute cooldown).
     */
    public static function verifySecretPin(string $pin, int $userId): bool
    {
        $pin = trim($pin);
        if ($pin === '') {
            return false;
        }

        // Enforce rate limit (per user/session)
        $rateKey = '_pin_verify_attempts_' . $userId;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $state = $_SESSION[$rateKey] ?? ['attempts' => 0, 'locked_until' => 0];
            if (($state['locked_until'] ?? 0) > time()) {
                $remainingSec = $state['locked_until'] - time();
                $mins = max(1, (int)ceil($remainingSec / 60));
                throw new RuntimeException("Too many failed PIN/OTP attempts. Please wait {$mins} minute(s) before trying again.");
            }
        }

        $verified = false;

        // 1. Check 6-digit WhatsApp OTP first
        if (WhatsAppService::verifyOtp($pin)) {
            $verified = true;
        }

        $pdo = db();

        // 2. Check explicitly configured Master Payment Secret PIN (no insecure '1234' fallback)
        if (!$verified) {
            $configuredPin = trim((string)get_setting('payment_secret_pin', ''));
            if ($configuredPin !== '') {
                $hashInfo = password_get_info($configuredPin);
                if (!empty($hashInfo['algo'])) {
                    $verified = password_verify($pin, $configuredPin);
                } elseif (hash_equals($configuredPin, $pin)) {
                    // Transparently upgrade legacy plaintext configured PIN to bcrypt hash
                    $verified = true;
                    set_setting('payment_secret_pin', password_hash($pin, PASSWORD_DEFAULT), $userId);
                }
            }
        }

        // 3. Also check if the user entered their own account login password
        if (!$verified) {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();
            if ($hash && password_verify($pin, $hash)) {
                $verified = true;
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($verified) {
                unset($_SESSION[$rateKey]);
            } else {
                $state = $_SESSION[$rateKey] ?? ['attempts' => 0, 'locked_until' => 0];
                $state['attempts'] = ($state['attempts'] ?? 0) + 1;
                if ($state['attempts'] >= 5) {
                    $state['locked_until'] = time() + 300;
                    $state['attempts'] = 0;
                }
                $_SESSION[$rateKey] = $state;
            }
        }

        return $verified;
    }

    /**
     * Update Payment Secret PIN (stored hashed; rejects trivial defaults)
     */
    public static function updateSecretPin(string $newPin, int $actorId): bool
    {
        $newPin = trim($newPin);
        if (strlen($newPin) < 4) {
            throw new InvalidArgumentException('New secret PIN must be at least 4 characters long.');
        }
        if (in_array($newPin, ['1234', '0000', '1111', '123456'], true)) {
            throw new InvalidArgumentException('Please choose a stronger secret PIN (weak common PINs are not allowed).');
        }

        $hashedPin = password_hash($newPin, PASSWORD_DEFAULT);
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_group, description, updated_by)
            VALUES ('payment_secret_pin', ?, 'security', 'Hashed Secret PIN required to authorize and record payment entries', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
        ");
        $stmt->execute([$hashedPin, $actorId]);

        log_audit('settings', 0, 'update_payment_pin', null, ['updated_by' => $actorId]);
        return true;
    }

    /**
     * Get 360-degree Fee Ledger & Dues for a specific student
     */
    public static function getStudentFeeLedger(int $studentId): array
    {
        $pdo = db();
        $stStmt = $pdo->prepare("SELECT id, name, form_no, registration_no, entry_date, expected_joining_date, status, form_status FROM students WHERE id = ?");
        $stStmt->execute([$studentId]);
        $student = $stStmt->fetch();

        if (!$student) {
            throw new RuntimeException("Student record not found (ID: {$studentId}).");
        }

        // Standard Fee Configuration from Settings
        $formFeeAmt = (float)get_setting('form_fee', 500);
        $joiningFeeAmt = (float)get_setting('joining_fee', 3500);
        $secondPaymentAmt = (float)get_setting('second_payment_amount', 4000);
        $totalCourseFee = $formFeeAmt + $joiningFeeAmt + $secondPaymentAmt;

        // Fetch all successful payments for this student
        $pStmt = $pdo->prepare("
            SELECT stage, COALESCE(SUM(amount), 0) AS paid_amount, COUNT(*) as tx_count
            FROM payments 
            WHERE student_id = ? AND status = 'success' 
            GROUP BY stage
        ");
        $pStmt->execute([$studentId]);
        $paidByStage = [];
        foreach ($pStmt->fetchAll() as $row) {
            $paidByStage[$row['stage']] = [
                'amount' => (float)$row['paid_amount'],
                'count' => (int)$row['tx_count']
            ];
        }

        $paidForm = $paidByStage['form_fee']['amount'] ?? 0.0;
        $paidJoining = $paidByStage['joining_fee']['amount'] ?? 0.0;
        $paidSecond = $paidByStage['second_payment']['amount'] ?? 0.0;
        $paidExtension = $paidByStage['extension_fee']['amount'] ?? ($paidByStage['extension']['amount'] ?? 0.0);
        $paidOther = $paidByStage['other']['amount'] ?? 0.0;

        // Stage 1: Form Fee
        $isFormPaid = ($paidForm >= $formFeeAmt) || ($student['form_status'] === 'confirmed');
        $formDue = $isFormPaid ? 0.0 : max(0.0, $formFeeAmt - $paidForm);

        // Stage 2: Joining Fee
        $isJoiningPaid = ($paidJoining >= $joiningFeeAmt);
        $joiningDue = $isJoiningPaid ? 0.0 : max(0.0, $joiningFeeAmt - $paidJoining);

        // Stage 3: Second Payment (Final Fee)
        $isSecondPaid = ($paidSecond >= $secondPaymentAmt);
        $secondDue = $isSecondPaid ? 0.0 : max(0.0, $secondPaymentAmt - $paidSecond);

        // Stage 4: Extension Fees (Strict calculation from total extension charges minus total paid)
        $extStmt = $pdo->prepare("SELECT COALESCE(SUM(final_amount), 0) FROM extensions WHERE student_id = ?");
        $extStmt->execute([$studentId]);
        $totalExtensionFee = (float)$extStmt->fetchColumn();

        $invStmt = $pdo->prepare("
            SELECT id, extension_id, invoice_no, total_amount, payment_status, invoice_date 
            FROM invoices 
            WHERE student_id = ? AND payment_status != 'paid'
            ORDER BY id ASC
        ");
        $invStmt->execute([$studentId]);
        $unpaidInvoices = $invStmt->fetchAll();

        // Mathematical guarantee: extension due cannot exceed (totalExtensionFee - paidExtension)
        $extDue = max(0.0, $totalExtensionFee - $paidExtension);
        $extTotalRequired = max($totalExtensionFee, $extDue + $paidExtension);

        $totalPaidCourse = $paidForm + $paidJoining + $paidSecond;
        $totalPaidOverall = $totalPaidCourse + $paidExtension + $paidOther;
        $totalDueCourse = $formDue + $joiningDue + $secondDue;
        $totalDueOverall = $totalDueCourse + $extDue;

        // Stage Eligibility Flags & Restrictions (Duplicate Prevention & Due Max Caps)
        $stages = [
            'form_fee' => [
                'label' => 'Form Fee (₹500)',
                'is_paid' => $isFormPaid,
                'paid_amount' => $paidForm,
                'required_amount' => $formFeeAmt,
                'due_amount' => $formDue,
                'is_allowed' => !$isFormPaid,
                'blocked_reason' => $isFormPaid ? 'Form fee has already been paid for this student.' : null
            ],
            'joining_fee' => [
                'label' => '1st Payment on Joining (₹3,500)',
                'is_paid' => $isJoiningPaid,
                'paid_amount' => $paidJoining,
                'required_amount' => $joiningFeeAmt,
                'due_amount' => $joiningDue,
                'is_allowed' => !$isJoiningPaid,
                'blocked_reason' => $isJoiningPaid ? 'Joining fee has already been paid for this student.' : null
            ],
            'second_payment' => [
                'label' => '2nd Payment / Final Installment (₹4,000)',
                'is_paid' => $isSecondPaid,
                'paid_amount' => $paidSecond,
                'required_amount' => $secondPaymentAmt,
                'due_amount' => $secondDue,
                'is_allowed' => !$isSecondPaid,
                'blocked_reason' => $isSecondPaid ? 'Second payment installment has already been paid in full.' : null
            ],
            'extension_fee' => [
                'label' => 'Extension Fee',
                'is_paid' => ($extDue <= 0 && $paidExtension > 0),
                'paid_amount' => $paidExtension,
                'required_amount' => $extTotalRequired,
                'due_amount' => $extDue,
                'is_allowed' => ($extDue > 0),
                'blocked_reason' => ($extDue <= 0) ? 'No pending extension fee due for this student.' : null,
                'invoices' => $unpaidInvoices
            ],
            'other' => [
                'label' => 'Other / Miscellaneous Fee',
                'is_paid' => false,
                'paid_amount' => $paidOther,
                'required_amount' => $paidOther,
                'due_amount' => 0.0,
                'is_allowed' => true,
                'blocked_reason' => null
            ]
        ];

        return [
            'student' => $student,
            'total_course_fee' => $totalCourseFee,
            'total_fee_overall' => $totalCourseFee + $extTotalRequired + $paidOther,
            'total_paid_course' => $totalPaidCourse,
            'total_paid_overall' => $totalPaidOverall,
            'total_due_course' => $totalDueCourse,
            'total_due_overall' => $totalDueOverall,
            'is_fully_paid' => ($totalDueOverall <= 0),
            'stages' => $stages,
            'unpaid_invoices' => $unpaidInvoices
        ];
    }

    /**
     * Record a payment with strict Duplicate Prevention, Due Limit, and Security Password Verification
     */
    public static function recordPayment(
        int $studentId, 
        string $stage, 
        float $amount, 
        string $method, 
        ?string $referenceNo, 
        int $collectedBy, 
        ?string $secretPin = null,
        ?int $extensionId = null,
        bool $bypassPin = false
    ): int {
        require_once __DIR__ . '/../queue_engine.php';
        
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Please select a student.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        // 1. SECURITY PASSWORD / PIN VALIDATION
        if (!$bypassPin) {
            if (empty($secretPin)) {
                throw new InvalidArgumentException('Security PIN, OTP, or password is required.');
            }
            if (!self::verifySecretPin($secretPin, $collectedBy)) {
                throw new InvalidArgumentException('Invalid security PIN / OTP or password. Authorization failed.');
            }
        }

        // 2. FETCH STUDENT LEDGER TO VALIDATE DUPLICATE & OVER-PAYMENT
        $ledger = self::getStudentFeeLedger($studentId);
        $stageInfo = $ledger['stages'][$stage] ?? null;

        if (!$stageInfo) {
            throw new InvalidArgumentException('Invalid payment stage.');
        }

        // DUPLICATE PAYMENT CHECK
        if (!$stageInfo['is_allowed'] && $stage !== 'other') {
            throw new InvalidArgumentException($stageInfo['blocked_reason'] ?: 'Payment is not allowed for this stage.');
        }

        // DUE AMOUNT OVER-PAYMENT LIMIT CHECK
        if ($stage !== 'other') {
            $maxAllowed = (float)$stageInfo['due_amount'];
            if ($maxAllowed <= 0) {
                throw new InvalidArgumentException('No pending balance remains for this stage. Duplicate payments are not allowed.');
            }
            if ($amount > $maxAllowed) {
                $formattedMax = number_format($maxAllowed, 2);
                throw new InvalidArgumentException("Amount cannot exceed the pending balance of ₹{$formattedMax} (entered: ₹" . number_format($amount, 2) . ').');
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $receiptNo = generate_receipt_no();
            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    student_id, stage, extension_id, amount, method, 
                    reference_no, receipt_no, status, collected_by, payment_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'success', ?, NOW())
            ");
            $stmt->execute([
                $studentId, $stage, $extensionId, $amount, $method, 
                $referenceNo, $receiptNo, $collectedBy
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // If extension payment, close matching invoices
            if ($stage === 'extension_fee' || $extensionId) {
                if ($extensionId) {
                    $pdo->prepare("UPDATE invoices SET payment_status = 'paid', payment_id = ? WHERE extension_id = ?")
                        ->execute([$paymentId, $extensionId]);
                } else {
                    // Settle unpaid invoices up to paid amount
                    $remainingPay = $amount;
                    foreach ($ledger['unpaid_invoices'] as $inv) {
                        $invTotal = (float)$inv['total_amount'];
                        if ($remainingPay >= $invTotal) {
                            $pdo->prepare("UPDATE invoices SET payment_status = 'paid', payment_id = ? WHERE id = ?")
                                ->execute([$paymentId, $inv['id']]);
                            $remainingPay -= $invTotal;
                        }
                    }
                }
            }

            log_audit('payment', $paymentId, 'create', null, [
                'student_id' => $studentId, 
                'student_name' => $ledger['student']['name'],
                'stage' => $stage, 
                'amount' => $amount, 
                'receipt_no' => $receiptNo,
                'method' => $method
            ]);

            $pdo->commit();

            if ($stage === 'form_fee') {
                confirm_form_payment($studentId, $collectedBy);
            }

            return $paymentId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Delete / Revert an erroneous or duplicate payment record
     */
    public static function deletePayment(int $paymentId, int $actorId, string $secretPin, ?string $reason = null): bool
    {
        // 1. PIN verification
        if (!self::verifySecretPin($secretPin, $actorId)) {
            throw new InvalidArgumentException('Invalid security PIN / OTP or password.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Fetch payment
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? FOR UPDATE");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new RuntimeException('Payment record not found.');
            }

            $studentId = (int)$payment['student_id'];
            $stage = $payment['stage'];
            $receiptNo = $payment['receipt_no'];

            // 2. Unlink any invoices that were marked paid with this payment_id
            $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE payment_id = ?");
            $invStmt->execute([$paymentId]);
            $linkedInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($linkedInvoices as $inv) {
                // Check if another valid successful payment exists for this student & stage
                $altStmt = $pdo->prepare("
                    SELECT id FROM payments 
                    WHERE student_id = ? AND stage IN ('extension_fee', 'extension') AND status = 'success' AND id != ?
                    ORDER BY id DESC LIMIT 1
                ");
                $altStmt->execute([$studentId, $paymentId]);
                $altPaymentId = $altStmt->fetchColumn();

                if ($altPaymentId) {
                    $pdo->prepare("UPDATE invoices SET payment_id = ? WHERE id = ?")
                        ->execute([$altPaymentId, $inv['id']]);
                } else {
                    $pdo->prepare("UPDATE invoices SET payment_status = 'pending', payment_id = NULL WHERE id = ?")
                        ->execute([$inv['id']]);
                }
            }

            // 3. Delete the payment record
            $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$paymentId]);

            // 4. Log audit trail
            log_audit('payment', $paymentId, 'delete_payment', $payment, [
                'deleted_by' => $actorId,
                'reason' => $reason ?: 'Duplicate or incorrect payment deleted by admin',
                'receipt_no' => $receiptNo
            ]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function buildPaymentFilterWhere(array $filters, array &$params): string
    {
        $stage = $filters['stage'] ?? '';
        $method = $filters['method'] ?? '';
        $search = trim($filters['search'] ?? '');
        $from = $filters['from'] ?? '';
        $to = $filters['to'] ?? '';
        $where = " WHERE p.status = 'success'";

        if ($stage !== '') {
            $where .= " AND p.stage = ?";
            $params[] = $stage;
        }
        if ($method !== '') {
            $where .= " AND p.method = ?";
            $params[] = $method;
        }
        if ($from !== '') {
            $where .= " AND p.payment_date >= ?";
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where .= " AND p.payment_date <= ?";
            $params[] = $to . ' 23:59:59';
        }
        if ($search !== '') {
            $where .= " AND (p.receipt_no LIKE ? OR p.reference_no LIKE ? OR s.name LIKE ? OR s.student_mobile LIKE ? OR s.registration_no LIKE ? OR s.form_no LIKE ?)";
            $like = "%$search%";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        return $where;
    }

    /**
     * Count and total amount across all matching payments (for pagination)
     */
    public static function getPaymentsSummary(array $filters = []): array
    {
        $pdo = db();
        $params = [];
        $where = self::buildPaymentFilterWhere($filters, $params);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(p.amount), 0) AS total_amount
            FROM payments p
            JOIN students s ON s.id = p.student_id"
            . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        return [
            'count' => (int)($row['cnt'] ?? 0),
            'total_amount' => (float)($row['total_amount'] ?? 0),
        ];
    }

    /**
     * Get filtered list of payments
     */
    public static function getPayments(array $filters = []): array
    {
        $pdo = db();
        $limit = max(1, (int)($filters['limit'] ?? 300));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $params = [];
        $where = self::buildPaymentFilterWhere($filters, $params);

        $sql = "SELECT p.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, u.name AS collected_by_name
                FROM payments p
                JOIN students s ON s.id = p.student_id
                LEFT JOIN users u ON u.id = p.collected_by"
                . $where
                . " ORDER BY p.payment_date DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Aggregate payment and dues statistics
     */
    public static function getPaymentMetrics(): array
    {
        $pdo = db();
        $totalCollected = (float)$pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success'"
        )->fetchColumn();

        $todayCollected = (float)$pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success' AND DATE(payment_date) = CURDATE()"
        )->fetchColumn();

        $monthCollected = (float)$pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success' AND YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())"
        )->fetchColumn();

        $totalTransactions = (int)$pdo->query(
            "SELECT COUNT(*) FROM payments WHERE status = 'success'"
        )->fetchColumn();

        // Calculate all pending dues
        $dues = self::getAllPendingDues();
        $totalPendingDues = 0.0;
        foreach ($dues as $d) {
            $totalPendingDues += $d['amount'];
        }

        return [
            'total_collected' => $totalCollected,
            'today_collected' => $todayCollected,
            'month_collected' => $monthCollected,
            'total_transactions' => $totalTransactions,
            'total_pending_dues' => $totalPendingDues,
            'pending_students_count' => count(array_unique(array_column($dues, 'student_id')))
        ];
    }

    /**
     * Get list of all pending dues across all students
     */
    public static function getAllPendingDues(): array
    {
        $pdo = db();
        $joiningFeeAmt = (float)get_setting('joining_fee', 3500);
        $secondPaymentAmt = (float)get_setting('second_payment_amount', 4000);
        $dueDays = (int)get_setting('second_payment_due_days', 10);

        $students = $pdo->query("
            SELECT id, name, registration_no, form_no, student_mobile, entry_date, expected_joining_date, status 
            FROM students 
            WHERE status IN ('confirmed', 'waiting_ready', 'joining_confirmed', 'active', 'extended', 'course_completed')
              AND is_temporary = 0
            ORDER BY name ASC
        ")->fetchAll();

        $dues = [];

        foreach ($students as $st) {
            $stId = (int)$st['id'];

            // Get paid amounts by stage
            $stmtP = $pdo->prepare("SELECT stage, SUM(amount) as paid_amount FROM payments WHERE student_id = ? AND status = 'success' GROUP BY stage");
            $stmtP->execute([$stId]);
            $paid = $stmtP->fetchAll(PDO::FETCH_KEY_PAIR);

            $entryDate = $st['entry_date'] ?: $st['expected_joining_date'];

            // 1. Joining Fee
            if (!isset($paid['joining_fee']) || (float)$paid['joining_fee'] < $joiningFeeAmt) {
                $rem = $joiningFeeAmt - (float)($paid['joining_fee'] ?? 0);
                if ($rem > 0) {
                    $dues[] = [
                        'student_id' => $stId,
                        'student_name' => $st['name'],
                        'registration_no' => $st['registration_no'] ?: $st['form_no'],
                        'student_mobile' => $st['student_mobile'],
                        'stage' => 'joining_fee',
                        'fee_label' => '1st Payment on Joining',
                        'amount' => $rem,
                        'due_date' => $entryDate ? date('d-M-Y', strtotime($entryDate)) : 'On Joining',
                        'is_urgent' => false
                    ];
                }
            }

            // 2. Second Payment
            if ($st['entry_date']) {
                $remSec = $secondPaymentAmt - (float)($paid['second_payment'] ?? 0);
                if ($remSec > 0) {
                    $dueDate = date('d-M-Y', strtotime($st['entry_date'] . " + {$dueDays} days"));
                    $isUrgent = (strtotime($dueDate) < time());
                    $dues[] = [
                        'student_id' => $stId,
                        'student_name' => $st['name'],
                        'registration_no' => $st['registration_no'] ?: $st['form_no'],
                        'student_mobile' => $st['student_mobile'],
                        'stage' => 'second_payment',
                        'fee_label' => '2nd Payment Installment',
                        'amount' => $remSec,
                        'due_date' => $dueDate,
                        'is_urgent' => $isUrgent
                    ];
                }
            }

            // 3. Extension Fee
            $stmtExt = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE student_id = ? AND payment_status != 'paid'");
            $stmtExt->execute([$stId]);
            $extTotal = (float)$stmtExt->fetchColumn();
            if ($extTotal > 0) {
                $dues[] = [
                    'student_id' => $stId,
                    'student_name' => $st['name'],
                    'registration_no' => $st['registration_no'] ?: $st['form_no'],
                    'student_mobile' => $st['student_mobile'],
                    'stage' => 'extension_fee',
                    'fee_label' => 'Extension Fee',
                    'amount' => $extTotal,
                    'due_date' => 'Immediate',
                    'is_urgent' => true
                ];
            }
        }

        return $dues;
    }
}
