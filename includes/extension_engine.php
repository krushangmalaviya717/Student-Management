<?php
/**
 * EXTENSION & PER-DAY CHARGE ENGINE
 * Implements spec section 12.5: extension days are billed using the
 * per-day rate(s) effective across the days being added. If the extension
 * spans a rate-change date, it is split into rate-wise line items.
 * Historical rates/invoices are NEVER recalculated when Settings change later.
 */
require_once __DIR__ . '/course_engine.php';

/**
 * Calculate rate-wise line items for `days` additional days, starting the
 * day after `fromDate` (i.e. the first new course day). Walks day-by-day
 * and groups consecutive days under the same effective rate.
 * Returns: ['line_items' => [...], 'total' => float]
 */
function calculate_extension_line_items(string $fromExpiryDate, int $days): array
{
    if ($days <= 0) {
        return ['line_items' => [], 'total' => 0.0];
    }

    $pdo = db();
    $startDate = (new DateTime($fromExpiryDate))->modify('+1 day');
    $endDate = (new DateTime($fromExpiryDate))->modify("+{$days} days");
    $startStr = $startDate->format('Y-m-d');
    $endStr = $endDate->format('Y-m-d');

    // Fetch all rates overlapping [startStr, endStr] in 1 range query instead of 1 query/day
    $stmt = $pdo->prepare(
        "SELECT id, per_day_charge, effective_from, effective_to
         FROM extension_charge_rates
         WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
         ORDER BY effective_from DESC"
    );
    $stmt->execute([$endStr, $startStr]);
    $rates = $stmt->fetchAll();

    $cursor = new DateTime($fromExpiryDate);
    $lineItems = []; // keyed by charge_rate_id
    $total = 0.0;

    for ($i = 0; $i < $days; $i++) {
        $cursor->modify('+1 day');
        $dateStr = $cursor->format('Y-m-d');

        $matchedRate = null;
        foreach ($rates as $r) {
            if ($r['effective_from'] <= $dateStr && ($r['effective_to'] === null || $r['effective_to'] >= $dateStr)) {
                $matchedRate = $r;
                break;
            }
        }
        if (!$matchedRate) {
            throw new RuntimeException("No Per-Day Extension Charge configured for $dateStr. Set one in Settings first.");
        }

        $rid = (int)$matchedRate['id'];
        if (!isset($lineItems[$rid])) {
            $lineItems[$rid] = ['charge_rate_id' => $rid, 'rate_per_day' => (float)$matchedRate['per_day_charge'], 'days' => 0];
        }
        $lineItems[$rid]['days']++;
        $total += (float)$matchedRate['per_day_charge'];
    }

    foreach ($lineItems as &$li) {
        $li['amount'] = $li['days'] * $li['rate_per_day'];
    }
    unset($li);

    return ['line_items' => array_values($lineItems), 'total' => round($total, 2)];
}

/**
 * Create and confirm an extension: recalculates expiry, stores rate-wise
 * line items, generates an invoice, and (optionally) applies a manual
 * override amount while preserving the original calculation in history.
 */
function create_extension(
    int $studentId, int $extensionDays, ?string $reason, int $approvedBy,
    ?float $manualOverrideAmount = null, ?string $overrideReason = null
): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $student = get_student($studentId);
        if (!$student) throw new RuntimeException('Student not found.');
        if (!$student['final_expiry_date']) throw new RuntimeException('Student has no active course to extend.');

        $oldExpiry = $student['final_expiry_date'];
        $calc = calculate_extension_line_items($oldExpiry, $extensionDays);
        $newExpiry = (new DateTime($oldExpiry))->modify("+{$extensionDays} days")->format('Y-m-d');

        $finalAmount = $manualOverrideAmount ?? $calc['total'];
        $isOverride = $manualOverrideAmount !== null && abs($manualOverrideAmount - $calc['total']) > 0.001;

        $pdo->prepare(
            "INSERT INTO extensions
                (student_id, extension_days, requested_date, old_expiry_date, new_expiry_date, reason,
                 calculated_amount, final_amount, is_manual_override, override_reason, approved_by, approved_at)
             VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $studentId, $extensionDays, $oldExpiry, $newExpiry, $reason,
            $calc['total'], $finalAmount, $isOverride ? 1 : 0, $isOverride ? $overrideReason : null, $approvedBy,
        ]);
        $extensionId = (int)$pdo->lastInsertId();

        $liStmt = $pdo->prepare(
            "INSERT INTO extension_line_items (extension_id, charge_rate_id, days, rate_per_day, amount)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($calc['line_items'] as $li) {
            $liStmt->execute([$extensionId, $li['charge_rate_id'], $li['days'], $li['rate_per_day'], $li['amount']]);
        }

        $pdo->prepare(
            "UPDATE students SET final_expiry_date = ?, course_status = 'extended', status = 'extended', updated_at = NOW() WHERE id = ?"
        )->execute([$newExpiry, $studentId]);

        $pdo->prepare(
            "INSERT INTO course_adjustments (student_id, adjustment_type, days_added, old_expiry_date, new_expiry_date, note, created_by)
             VALUES (?, 'extension', ?, ?, ?, ?, ?)"
        )->execute([$studentId, $extensionDays, $oldExpiry, $newExpiry, $reason, $approvedBy]);

        // Invoice
        $invoiceNo = generate_invoice_no();
        $pdo->prepare(
            "INSERT INTO invoices (invoice_no, student_id, extension_id, invoice_date, total_amount, payment_status, collected_by)
             VALUES (?, ?, ?, NOW(), ?, 'pending', ?)"
        )->execute([$invoiceNo, $studentId, $extensionId, $finalAmount, $approvedBy]);

        log_audit('extension', $extensionId, 'create', null, [
            'student_id' => $studentId, 'days' => $extensionDays,
            'calculated_amount' => $calc['total'], 'final_amount' => $finalAmount,
            'is_override' => $isOverride, 'invoice_no' => $invoiceNo,
        ]);

        $pdo->commit();

        // Extending keeps the seat occupied longer -> re-estimate waiting dates.
        if ($student['joined_batch_id']) {
            recalculate_expected_joining_dates((int)$student['joined_batch_id']);
        }

        return $extensionId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Update (or insert) the current per-day extension charge with an effective date. */
function update_extension_charge_rate(float $newRate, string $effectiveFrom, int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Close out the currently active rate the day before the new one starts.
        $dayBefore = (new DateTime($effectiveFrom))->modify('-1 day')->format('Y-m-d');
        $pdo->prepare(
            "UPDATE extension_charge_rates SET effective_to = ?, status = 'superseded', updated_by = ?
             WHERE status = 'active' AND effective_from < ?"
        )->execute([$dayBefore, $userId, $effectiveFrom]);

        $pdo->prepare(
            "INSERT INTO extension_charge_rates (per_day_charge, effective_from, status, created_by)
             VALUES (?, ?, 'active', ?)"
        )->execute([$newRate, $effectiveFrom, $userId]);

        log_audit('settings', null, 'update_extension_rate', null, [
            'new_rate' => $newRate, 'effective_from' => $effectiveFrom,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
