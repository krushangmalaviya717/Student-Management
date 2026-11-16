<?php
require_once __DIR__ . '/includes/functions.php';
$db = db();
echo "--- INVOICES ---\n";
print_r($db->query("SELECT id, invoice_number, student_id, extension_id, total_amount, payment_status, payment_id, created_at FROM invoices WHERE student_id = 1")->fetchAll(PDO::FETCH_ASSOC));
echo "--- EXTENSIONS ---\n";
print_r($db->query("SELECT id, student_id, extension_days, fee_per_day, total_fee, payment_status, is_active FROM extensions WHERE student_id = 1")->fetchAll(PDO::FETCH_ASSOC));
echo "--- PAYMENTS ---\n";
print_r($db->query("SELECT id, receipt_no, amount, stage, status FROM payments WHERE student_id = 1")->fetchAll(PDO::FETCH_ASSOC));
unlink(__FILE__);
