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

class PaymentService
{
    /**
     * Verify Payment Security PIN or User Password
     */
    public static function verifySecretPin(string $pin, int $userId): bool
    {
        $pin = trim($pin);
        if ($pin === '') {
            return false;
        }

        $pdo = db();
        
        // 1. Check configured Payment Secret PIN in settings
        $configuredPin = get_setting('payment_secret_pin', '1234');
        if ($pin === $configuredPin) {
            return true;
        }

        // 2. Also check if the user entered their own account login password
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if ($hash && password_verify($pin, $hash)) {
            return true;
        }

        return false;
    }

    /**
     * Update Payment Secret PIN
     */
    public static function updateSecretPin(string $newPin, int $actorId): bool
    {
        $newPin = trim($newPin);
        if (strlen($newPin) < 4) {
            throw new InvalidArgumentException('નવો સિક્રેટ પિન ઓછામાં ઓછો 4 અક્ષર/આંકડાનો હોવો જોઈએ.');
        }

        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_group, description, updated_by)
            VALUES ('payment_secret_pin', ?, 'security', 'Secret PIN required to authorize and record payment entries', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
        ");
        $stmt->execute([$newPin, $actorId]);

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
            throw new RuntimeException("વિદ્યાર્થી રેકોર્ડ મળ્યો નથી (ID: {$studentId}).");
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
                'label' => 'Form Fee (ફોર્મ ફી - ₹500)',
                'is_paid' => $isFormPaid,
                'paid_amount' => $paidForm,
                'required_amount' => $formFeeAmt,
                'due_amount' => $formDue,
                'is_allowed' => !$isFormPaid,
                'blocked_reason' => $isFormPaid ? 'આ વિદ્યાર્થી દ્વારા ફોર્મ ફી (₹500) પહેલેથી ભરાઈ ગયેલ છે.' : null
            ],
            'joining_fee' => [
                'label' => '1st Payment on Joining (એન્ટ્રી ફી - ₹3,500)',
                'is_paid' => $isJoiningPaid,
                'paid_amount' => $paidJoining,
                'required_amount' => $joiningFeeAmt,
                'due_amount' => $joiningDue,
                'is_allowed' => !$isJoiningPaid,
                'blocked_reason' => $isJoiningPaid ? 'આ વિદ્યાર્થી દ્વારા જોઈનિંગ ફી (₹3,500) પહેલેથી ભરાઈ ગયેલ છે.' : null
            ],
            'second_payment' => [
                'label' => '2nd Payment (બીજો હપ્તો / બાકી ફી - ₹4,000)',
                'is_paid' => $isSecondPaid,
                'paid_amount' => $paidSecond,
                'required_amount' => $secondPaymentAmt,
                'due_amount' => $secondDue,
                'is_allowed' => !$isSecondPaid,
                'blocked_reason' => $isSecondPaid ? 'આ વિદ્યાર્થી દ્વારા બીજો હપ્તો (₹4,000) પહેલેથી સંપૂર્ણ ભરાઈ ગયેલ છે.' : null
            ],
            'extension_fee' => [
                'label' => 'Extension Fee (વધારાના દિવસોની ફી)',
                'is_paid' => ($extDue <= 0 && $paidExtension > 0),
                'paid_amount' => $paidExtension,
                'required_amount' => $extTotalRequired,
                'due_amount' => $extDue,
                'is_allowed' => ($extDue > 0),
                'blocked_reason' => ($extDue <= 0) ? 'આ વિદ્યાર્થી માટે વધારાના દિવસોની કોઈ બાકી ફી નથી.' : null,
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
            throw new InvalidArgumentException('મહેરબાની કરીને વિદ્યાર્થી પસંદ કરો.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('પેમેન્ટ રકમ શૂન્ય કરતાં વધારે હોવી જોઈએ.');
        }

        // 1. SECURITY PASSWORD / PIN VALIDATION
        if (!$bypassPin) {
            if (empty($secretPin)) {
                throw new InvalidArgumentException('સિક્યોરિટી પાસવર્ડ / PIN દાખલ કરવો ફરજિયાત છે.');
            }
            if (!self::verifySecretPin($secretPin, $collectedBy)) {
                throw new InvalidArgumentException('દાખલ કરેલ સિક્રેટ પાસવર્ડ / PIN ખોટો છે! પેમેન્ટ સિક્યોરિટી ચકાસણી નિષ્ફળ ગઈ.');
            }
        }

        // 2. FETCH STUDENT LEDGER TO VALIDATE DUPLICATE & OVER-PAYMENT
        $ledger = self::getStudentFeeLedger($studentId);
        $stageInfo = $ledger['stages'][$stage] ?? null;

        if (!$stageInfo) {
            throw new InvalidArgumentException('અમાન્ય પેમેન્ટ તબક્કો (Invalid stage).');
        }

        // DUPLICATE PAYMENT CHECK
        if (!$stageInfo['is_allowed'] && $stage !== 'other') {
            throw new InvalidArgumentException($stageInfo['blocked_reason'] ?: 'આ તબક્કા માટે પેમેન્ટ સ્વીકાર્ય નથી.');
        }

        // DUE AMOUNT OVER-PAYMENT LIMIT CHECK
        if ($stage !== 'other') {
            $maxAllowed = (float)$stageInfo['due_amount'];
            if ($maxAllowed <= 0) {
                throw new InvalidArgumentException("આ તબક્કા માટે કોઈ બાકી રકમ નથી (સંપૂર્ણ રકમ પહેલેથી ભરાઈ ગયેલ છે). ડુપ્લિકેટ પેમેન્ટ સ્વીકાર્ય નથી.");
            }
            if ($amount > $maxAllowed) {
                $formattedMax = number_format($maxAllowed, 2);
                throw new InvalidArgumentException("આ તબક્કા માટે બાકી રકમ (₹{$formattedMax}) કરતાં વધુ રકમ ભરી શકાશે નહીં. તમે દાખલ કરેલ રકમ: ₹" . number_format($amount, 2));
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
            throw new InvalidArgumentException('દાખલ કરેલ સિક્રેટ પાસવર્ડ / PIN ખોટો છે!');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Fetch payment
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? FOR UPDATE");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new RuntimeException('પેમેન્ટ રેકોર્ડ મળ્યો નથી.');
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

    /**
     * Get filtered list of payments
     */
    public static function getPayments(array $filters = []): array
    {
        $pdo = db();
        $stage = $filters['stage'] ?? '';
        $method = $filters['method'] ?? '';
        $search = trim($filters['search'] ?? '');
        $from = $filters['from'] ?? '';
        $to = $filters['to'] ?? '';
        $limit = (int)($filters['limit'] ?? 300);

        $sql = "SELECT p.*, s.name AS student_name, s.registration_no, s.form_no, s.student_mobile, u.name AS collected_by_name
                FROM payments p
                JOIN students s ON s.id = p.student_id
                LEFT JOIN users u ON u.id = p.collected_by
                WHERE p.status = 'success'";
        $params = [];

        if ($stage !== '') {
            $sql .= " AND p.stage = ?";
            $params[] = $stage;
        }
        if ($method !== '') {
            $sql .= " AND p.method = ?";
            $params[] = $method;
        }
        if ($from !== '') {
            $sql .= " AND p.payment_date >= ?";
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $sql .= " AND p.payment_date <= ?";
            $params[] = $to . ' 23:59:59';
        }
        if ($search !== '') {
            $sql .= " AND (p.receipt_no LIKE ? OR p.reference_no LIKE ? OR s.name LIKE ? OR s.student_mobile LIKE ? OR s.registration_no LIKE ? OR s.form_no LIKE ?)";
            $like = "%$search%";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $sql .= " ORDER BY p.payment_date DESC LIMIT " . max(1, $limit);

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
                        'fee_label' => '1st Payment on Joining (એન્ટ્રી ફી)',
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
                        'fee_label' => '2nd Payment (બીજો હપ્તો)',
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
                    'fee_label' => 'Extension Fee (વધારાના દિવસો)',
                    'amount' => $extTotal,
                    'due_date' => 'Immediate (તાત્કાલિક)',
                    'is_urgent' => true
                ];
            }
        }

        return $dues;
    }
}
