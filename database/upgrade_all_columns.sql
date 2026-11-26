-- =======================================================================
-- DEVANGI SEWING CLASSES - COMPLETE COMPATIBILITY & MIGRATION SCRIPT
-- Run this once on MySQL to ensure 100% schema compatibility across all pages.
-- =======================================================================

USE devangi_sms;

-- 1. Ensure students table has all feature columns
ALTER TABLE students 
    ADD COLUMN IF NOT EXISTS hear_about_us VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS guardian_signature_name VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS guardian_relation VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_paused TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS paused_at DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS paused_remaining_days INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS resume_ready_date DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pause_reason TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_temporary TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Ensure payments table has manual receipt and notes columns
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS is_manual TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS notes VARCHAR(255) DEFAULT NULL;

-- 3. Ensure WhatsApp OTP table exists for payment & manual receipt security
CREATE TABLE IF NOT EXISTS payment_otps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    otp_code VARCHAR(10) NOT NULL,
    context ENUM('payment','manual_receipt','delete_payment') NOT NULL DEFAULT 'payment',
    student_name VARCHAR(150) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    stage_label VARCHAR(150) NOT NULL,
    method VARCHAR(50) NOT NULL,
    details_json TEXT DEFAULT NULL,
    sent_to_1 VARCHAR(20) DEFAULT NULL,
    sent_to_2 VARCHAR(20) DEFAULT NULL,
    status ENUM('pending','verified','expired') NOT NULL DEFAULT 'pending',
    requested_by INT UNSIGNED DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_code_status (otp_code, status),
    INDEX idx_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Ensure WhatsApp settings exist
INSERT IGNORE INTO settings (setting_key, setting_value, setting_group, description) VALUES
('wa_otp_enabled', '0', 'whatsapp', 'Enable WhatsApp OTP verification for payments and receipts'),
('wa_phone_number_id', '', 'whatsapp', 'Meta WhatsApp Cloud API Phone Number ID'),
('wa_access_token', '', 'whatsapp', 'Meta WhatsApp Cloud API Permanent Access Token'),
('wa_api_version', 'v21.0', 'whatsapp', 'Meta Graph API Version'),
('wa_template_name', '', 'whatsapp', 'Approved Meta WhatsApp Template Name'),
('wa_template_lang', 'en', 'whatsapp', 'Meta WhatsApp Template Language Code'),
('wa_otp_mobile_1', '', 'whatsapp', 'Primary Admin/Owner WhatsApp Number for OTP'),
('wa_otp_mobile_2', '', 'whatsapp', 'Secondary Admin/Owner WhatsApp Number for OTP');

-- 5. Add performance indexes for smooth loading
ALTER TABLE students
    ADD INDEX IF NOT EXISTS idx_students_status_expiry (status, final_expiry_date),
    ADD INDEX IF NOT EXISTS idx_students_batch_status (joined_batch_id, status),
    ADD INDEX IF NOT EXISTS idx_students_paused (is_paused);

ALTER TABLE payments
    ADD INDEX IF NOT EXISTS idx_payments_status_date (status, payment_date);

ALTER TABLE invoices
    ADD INDEX IF NOT EXISTS idx_invoices_payment_status (payment_status);

ALTER TABLE call_logs
    ADD INDEX IF NOT EXISTS idx_call_logs_follow_up (follow_up_date);

ALTER TABLE audit_logs
    ADD INDEX IF NOT EXISTS idx_audit_created_at (created_at);
