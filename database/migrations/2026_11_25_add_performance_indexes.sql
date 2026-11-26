-- Proposed Performance Indexes (Review & approve before executing in MySQL)
-- Run manually in phpMyAdmin / MySQL CLI once approved.

ALTER TABLE students
    ADD INDEX idx_students_status_expiry (status, final_expiry_date),
    ADD INDEX idx_students_batch_status (joined_batch_id, status);

ALTER TABLE payments
    ADD INDEX idx_payments_status_date (status, payment_date);

ALTER TABLE invoices
    ADD INDEX idx_invoices_payment_status (payment_status);

ALTER TABLE call_logs
    ADD INDEX idx_call_logs_follow_up (follow_up_date);

ALTER TABLE audit_logs
    ADD INDEX idx_audit_created_at (created_at);
