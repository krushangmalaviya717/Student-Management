-- =========================================================
-- DEVANGI SEWING CLASSES - Student Management System
-- Database Schema v1.0
-- Engine: MySQL 5.7+/8.0, InnoDB, utf8mb4
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- 1. ROLES & USERS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,          -- super_admin, admin, staff
    display_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 2. SETTINGS (key-value, configurable, no hard-coding)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    setting_group VARCHAR(50) DEFAULT 'general',  -- fees, course, batch, numbering...
    description VARCHAR(255) DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-day extension charge, kept as its own effective-dated table (12.7 of spec)
CREATE TABLE IF NOT EXISTS extension_charge_rates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    per_day_charge DECIMAL(10,2) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE DEFAULT NULL,       -- NULL = still active
    status ENUM('active','superseded') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 3. BATCHES
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,             -- e.g. "10:00 AM - 12:00 PM"
    batch_days VARCHAR(100) DEFAULT NULL,  -- e.g. Mon-Sat
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 8,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 4. STUDENTS (master profile + form/registration data)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Registration / Form identity
    form_no VARCHAR(30) NOT NULL UNIQUE,
    registration_no VARCHAR(30) DEFAULT NULL UNIQUE,   -- assigned only after Rs.500 confirmed
    form_submit_date DATETIME NOT NULL,
    form_status ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
    confirmed_at DATETIME DEFAULT NULL,

    -- Personal details
    name VARCHAR(150) NOT NULL,
    age INT UNSIGNED DEFAULT NULL,
    student_mobile VARCHAR(20) NOT NULL,
    husband_father_mobile VARCHAR(20) DEFAULT NULL,
    home_mobile VARCHAR(20) DEFAULT NULL,
    education VARCHAR(100) DEFAULT NULL,
    address TEXT NOT NULL,

    -- Sewing experience
    knows_sewing TINYINT(1) NOT NULL DEFAULT 0,
    previous_sewing_work TEXT DEFAULT NULL,

    -- Batch preference
    preferred_batch_id INT UNSIGNED DEFAULT NULL,   -- NULL if any-batch
    any_batch TINYINT(1) NOT NULL DEFAULT 0,
    joined_batch_id INT UNSIGNED DEFAULT NULL,       -- batch actually joined

    -- Expected / availability
    expected_joining_date DATE DEFAULT NULL,
    availability_ready_date DATE DEFAULT NULL,       -- student-declared future date
    availability_state ENUM('ready','future_date','date_not_confirmed') DEFAULT 'ready',

    -- Course lifecycle
    entry_date DATE DEFAULT NULL,
    base_expiry_date DATE DEFAULT NULL,     -- entry_date + configured course days, no adjustments
    final_expiry_date DATE DEFAULT NULL,    -- base + holiday adjustments + extensions
    course_status ENUM('not_started','active','completed','extended') DEFAULT 'not_started',

    -- Photo
    photo_path VARCHAR(255) DEFAULT NULL,

    -- Additional Details & Guardian
    hear_about_us VARCHAR(100) DEFAULT NULL,
    guardian_signature_name VARCHAR(150) DEFAULT NULL,
    guardian_relation VARCHAR(50) DEFAULT NULL,

    -- Pause / Resume
    is_paused TINYINT(1) NOT NULL DEFAULT 0,
    paused_at DATE DEFAULT NULL,
    paused_remaining_days INT DEFAULT 0,
    resume_ready_date DATE DEFAULT NULL,
    pause_reason TEXT DEFAULT NULL,

    -- Overall status
    status ENUM(
        'draft','confirmed',
        'waiting_ready','waiting_future_date','waiting_date_not_confirmed',
        'joining_confirmed','active','extended','course_completed',
        'cancelled','inactive'
    ) NOT NULL DEFAULT 'draft',

    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    is_imported TINYINT(1) NOT NULL DEFAULT 0,  -- migrated/historical record flag
    is_temporary TINYINT(1) NOT NULL DEFAULT 0, -- manually created temporary unrecorded student

    -- Locked Fee Structure (snapshotted at registration time to protect past students from future price increases)
    form_fee_amount DECIMAL(10,2) DEFAULT NULL,
    joining_fee_amount DECIMAL(10,2) DEFAULT NULL,
    second_payment_amount DECIMAL(10,2) DEFAULT NULL,
    total_course_fee DECIMAL(10,2) DEFAULT NULL,

    FOREIGN KEY (preferred_batch_id) REFERENCES batches(id),
    FOREIGN KEY (joined_batch_id) REFERENCES batches(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_students_name (name),
    INDEX idx_students_mobile (student_mobile),
    INDEX idx_students_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 5. WAITING QUEUE (batch-wise; supports Any-Batch multi-entry)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS waiting_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    batch_id INT UNSIGNED NOT NULL,
    priority_date DATETIME NOT NULL,   -- = students.confirmed_at, same across all any-batch rows
    state ENUM(
        'waiting_ready','waiting_future_date','waiting_date_not_confirmed',
        'call_pending','called_confirmed','called_call_later','called_not_available',
        'joined','removed'
    ) NOT NULL DEFAULT 'waiting_ready',
    future_date DATE DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    UNIQUE KEY uniq_student_batch_active (student_id, batch_id),
    INDEX idx_queue_batch_state (batch_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 6. STUDENT BATCH HISTORY (audit of batch assignment changes)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_batch_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    old_batch_id INT UNSIGNED DEFAULT NULL,
    new_batch_id INT UNSIGNED DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (old_batch_id) REFERENCES batches(id),
    FOREIGN KEY (new_batch_id) REFERENCES batches(id),
    FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 7. CALL LOGS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS call_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    called_by INT UNSIGNED DEFAULT NULL,
    call_datetime DATETIME NOT NULL,
    contact_method ENUM('phone','whatsapp','other') NOT NULL DEFAULT 'phone',
    outcome ENUM('confirmed','call_later','specific_date','cannot_join','no_response','other') NOT NULL,
    offered_batch_id INT UNSIGNED DEFAULT NULL,
    proposed_joining_date DATE DEFAULT NULL,
    follow_up_date DATE DEFAULT NULL,
    conversation_note TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (called_by) REFERENCES users(id),
    FOREIGN KEY (offered_batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 8. HOLIDAYS (batch-specific institute holidays)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,
    holiday_date DATE NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uniq_batch_holiday_date (batch_id, holiday_date)  -- prevents duplicate holiday entry
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 9. COURSE ADJUSTMENTS (holiday +1 day applied per student; leave notes)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_adjustments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    adjustment_type ENUM('institute_holiday','extension','manual_override','leave_note') NOT NULL,
    holiday_id INT UNSIGNED DEFAULT NULL,
    days_added INT NOT NULL DEFAULT 0,
    old_expiry_date DATE DEFAULT NULL,
    new_expiry_date DATE DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (holiday_id) REFERENCES holidays(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 10. EXTENSIONS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS extensions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    extension_days INT UNSIGNED NOT NULL,
    requested_date DATE DEFAULT NULL,
    old_expiry_date DATE NOT NULL,
    new_expiry_date DATE NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,

    calculated_amount DECIMAL(10,2) NOT NULL,   -- system-calculated total (sum of line items)
    final_amount DECIMAL(10,2) NOT NULL,        -- after manual override, if any
    is_manual_override TINYINT(1) NOT NULL DEFAULT 0,
    override_reason VARCHAR(255) DEFAULT NULL,

    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate-wise line items within one extension (spans rate-change dates -> multiple rows)
CREATE TABLE IF NOT EXISTS extension_line_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    extension_id INT UNSIGNED NOT NULL,
    charge_rate_id INT UNSIGNED NOT NULL,   -- snapshot reference to the rate used
    days INT UNSIGNED NOT NULL,
    rate_per_day DECIMAL(10,2) NOT NULL,    -- snapshot of the rate value at the time
    amount DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (extension_id) REFERENCES extensions(id),
    FOREIGN KEY (charge_rate_id) REFERENCES extension_charge_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 11. PAYMENTS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    stage ENUM('form_fee','joining_fee','second_payment','extension_fee','other') NOT NULL,
    extension_id INT UNSIGNED DEFAULT NULL,   -- set when stage = extension_fee
    amount DECIMAL(10,2) NOT NULL,
    method ENUM('cash','upi','bank_transfer','card','other') NOT NULL DEFAULT 'cash',
    reference_no VARCHAR(100) DEFAULT NULL,
    receipt_no VARCHAR(30) NOT NULL UNIQUE,
    status ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'success',
    is_manual TINYINT(1) NOT NULL DEFAULT 0,
    notes VARCHAR(255) DEFAULT NULL,
    collected_by INT UNSIGNED DEFAULT NULL,
    payment_date DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (extension_id) REFERENCES extensions(id),
    FOREIGN KEY (collected_by) REFERENCES users(id),
    INDEX idx_payments_student (student_id),
    INDEX idx_payments_stage (stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 12. INVOICES (primarily for extension charges)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(30) NOT NULL UNIQUE,
    student_id INT UNSIGNED NOT NULL,
    extension_id INT UNSIGNED DEFAULT NULL,
    invoice_date DATETIME NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_status ENUM('pending','paid','partial') NOT NULL DEFAULT 'pending',
    payment_id INT UNSIGNED DEFAULT NULL,
    collected_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (extension_id) REFERENCES extensions(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    FOREIGN KEY (collected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 13. REFERRALS / RELATIVES
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS referrals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    relative_student_id INT UNSIGNED DEFAULT NULL,  -- link to historical student if matched
    relative_name VARCHAR(150) DEFAULT NULL,
    relative_mobile VARCHAR(20) DEFAULT NULL,
    relative_address TEXT DEFAULT NULL,
    relation VARCHAR(50) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (relative_student_id) REFERENCES students(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 14. STUDENT PHOTOS (history; only Super Admin can replace)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    changed_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 15. AUDIT LOGS (generic, for every critical change)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    entity_type VARCHAR(50) NOT NULL,   -- e.g. 'student', 'payment', 'extension', 'holiday'
    entity_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(50) NOT NULL,        -- e.g. 'create', 'update', 'confirm_payment', 'batch_change'
    old_values TEXT DEFAULT NULL,       -- JSON snapshot
    new_values TEXT DEFAULT NULL,       -- JSON snapshot
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 21. STAFF
CREATE TABLE IF NOT EXISTS staff (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED DEFAULT NULL, name VARCHAR(150) NOT NULL, phone VARCHAR(20) NOT NULL, email VARCHAR(150) DEFAULT NULL, designation VARCHAR(100) NOT NULL DEFAULT 'Teacher', specialization VARCHAR(150) DEFAULT NULL, joining_date DATE DEFAULT NULL, salary DECIMAL(10,2) NOT NULL DEFAULT 0.00, salary_type ENUM('monthly','per_day','per_hour') NOT NULL DEFAULT 'monthly', address TEXT DEFAULT NULL, emergency_contact VARCHAR(20) DEFAULT NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active', notes TEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS staff_batches (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, staff_id INT UNSIGNED NOT NULL, batch_id INT UNSIGNED NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_staff_batch (staff_id, batch_id), FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE, FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'half_day', 'absent', 'paid_leave', 'holiday') NOT NULL DEFAULT 'present',
    half_day_session ENUM('morning', 'afternoon') DEFAULT NULL,
    check_in_time TIME DEFAULT NULL,
    check_out_time TIME DEFAULT NULL,
    working_hours DECIMAL(4,2) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    marked_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staff_date (staff_id, attendance_date),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_payroll (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    payroll_month VARCHAR(7) NOT NULL,
    total_days_in_month INT UNSIGNED NOT NULL DEFAULT 30,
    working_days INT UNSIGNED NOT NULL DEFAULT 0,
    present_days DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    half_days INT UNSIGNED NOT NULL DEFAULT 0,
    absent_days DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    paid_leaves DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    holidays_count INT UNSIGNED NOT NULL DEFAULT 0,
    payable_days DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gross_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    deductions DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('pending', 'paid', 'partial') NOT NULL DEFAULT 'pending',
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash', 'upi', 'bank_transfer', 'cheque') DEFAULT 'cash',
    payment_reference VARCHAR(100) DEFAULT NULL,
    payment_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staff_month (staff_id, payroll_month),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- SEED DATA
-- =========================================================

INSERT IGNORE INTO roles (name, display_name, description) VALUES
('super_admin', 'Super Admin', 'Full system access including settings, fees, and user management'),
('admin', 'Admin', 'Day-to-day operations: students, waiting list, payments, extensions'),
('staff', 'Staff / Teacher', 'View-only access to assigned batches and student profiles');

-- NOTE: the default Super Admin user is NOT inserted here.
-- Run install.php once after importing this schema — it creates the
-- Super Admin account using PHP's own password_hash(), so the stored
-- hash is guaranteed valid (never hand-craft a bcrypt hash in SQL).

INSERT IGNORE INTO batches (name, batch_days, start_time, end_time, capacity, is_active) VALUES
('10:00 AM - 12:00 PM', 'Mon-Sat', '10:00:00', '12:00:00', 8, 1),
('1:00 PM - 3:00 PM',   'Mon-Sat', '13:00:00', '15:00:00', 8, 1),
('3:00 PM - 5:00 PM',   'Mon-Sat', '15:00:00', '17:00:00', 8, 1),
('5:00 PM - 7:00 PM',   'Mon-Sat', '17:00:00', '19:00:00', 8, 1);

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group, description) VALUES
('business_name', 'Devangi Sewing Classes', 'general', 'Displayed on receipts/invoices'),
('course_duration_days', '45', 'course', 'Standard course length in calendar days'),
('form_fee', '500', 'fees', 'Non-refundable form confirmation fee'),
('joining_fee', '3500', 'fees', 'Fee due on actual joining (Day 1)'),
('second_payment_amount', '4000', 'fees', 'Second payment amount'),
('second_payment_due_days', '10', 'fees', 'Days within which second payment is due, counted from Entry Date'),
('form_no_prefix', 'DSC-F-', 'numbering', 'Prefix for auto-generated Form Numbers'),
('registration_no_prefix', 'DSC-R-', 'numbering', 'Prefix for auto-generated Registration Numbers'),
('receipt_no_prefix', 'DSC-RCPT-', 'numbering', 'Prefix for payment receipt numbers'),
('invoice_no_prefix', 'DSC-INV-', 'numbering', 'Prefix for extension invoice numbers'),
('default_batch_capacity', '8', 'batch', 'Default maximum active students per batch'),
('form_req_student_mobile', '1', 'form_fields', 'Require Student Mobile in admission/edit forms'),
('form_req_father_mobile', '0', 'form_fields', 'Require Husband/Father Mobile'),
('form_req_home_mobile', '0', 'form_fields', 'Require Home Mobile'),
('form_req_age', '0', 'form_fields', 'Require Student Age'),
('form_req_education', '0', 'form_fields', 'Require Education'),
('form_req_address', '1', 'form_fields', 'Require Full Address'),
('form_req_photo', '1', 'form_fields', 'Require Student Passport Photo'),
('form_req_knows_sewing', '0', 'form_fields', 'Require Sewing Experience Answer'),
('form_req_hear_about_us', '0', 'form_fields', 'Require Hear About Us Source'),
('form_req_referral', '0', 'form_fields', 'Require Referral Details'),
('form_req_guardian', '0', 'form_fields', 'Require Guardian Name & Relation'),
('form_vis_father_mobile', '1', 'form_fields', 'Show Husband/Father Mobile field'),
('form_vis_home_mobile', '1', 'form_fields', 'Show Home Mobile field'),
('form_vis_age', '1', 'form_fields', 'Show Age field'),
('form_vis_education', '1', 'form_fields', 'Show Education field'),
('form_vis_address', '1', 'form_fields', 'Show Full Address field'),
('form_vis_photo', '1', 'form_fields', 'Show Passport Photo upload'),
('form_vis_knows_sewing', '1', 'form_fields', 'Show Sewing Experience section'),
('form_vis_hear_about_us', '1', 'form_fields', 'Show Hear About Us section'),
('form_vis_referral', '1', 'form_fields', 'Show Referral Details section'),
('form_vis_guardian', '1', 'form_fields', 'Show Guardian Details section'),
('institute_tagline', 'Stitching & Fashion Designing Training Institute', 'branding', 'Displayed below institute name on printouts'),
('institute_phone', '', 'branding', 'Contact phone number displayed on receipts/forms'),
('institute_address', '', 'branding', 'Institute address displayed on receipts/forms'),
('receipt_terms_text', '• Form confirmation fee is non-refundable upon cancellation.\n• Official computer-generated receipt for institutional records.', 'branding', 'Terms printed at bottom of fee receipts'),
('admission_rules_text', 'ફોર્મ ભરતી વખતે ભરેલી રકમ કોઈપણ સંજોગો નામ કેન્સલ થાય તો પરત મળશે નહીં.\nજે વ્યક્તિના નામ નું ફોર્મ ભરેલું હશે તે જ વ્યક્તિએ આવવાનું રહેશે.\nજો કોઈપણ સંજોગોમાં નામ ચેન્જ કરવાનું થાય તો પણ ફોર્મ અને ફોર્મની રકમ ફરીથી ભરવાની રહેશે પણ જે વ્યક્તિની જગ્યાએ તેના સ્થાન મળી શકશે તેને વેઈટીંગ માં રહેવું પડશે નહીં. પણ કેન્સલ કરાવ્યા પછી તે સ્થાન કોઈપણ વ્યક્તિને મળશે નહીં.\nએન્ટ્રી કર્યા ના પહેલા જ દિવસે એન્ટ્રી ફી ફરજીયાત આપવાની રહેશે.\nએન્ટ્રી કર્યા પછી જો આવવાનું બંધ કરવામાં આવે તો એન્ટ્રીમાં ભરેલી ફી પરત આપવામાં આવશે નહીં.\nફીની બાકીની રકમ દસ દિવસમાં આપવાની રહેશે દસ દિવસ પછી જો આવવાનું બંધ કરવામાં આવે તો ભરેલી કોઈપણ રકમ પરત આપવામાં આવશે નહીં.\nકોઈપણ વહીવટ કરવા જે તે વ્યક્તિ નું ફોર્મ હોય તેને રૂબરૂ આવવાનું રહેશે બીજી કોઈ પણ વ્યક્તિનું સાંભળવામાં આવશે નહીં.\nવારો આવ્યા નો ફોન આવે જે તે વ્યક્તિએ આવવાની હા પાડ્યા પછી તે દિવસે ન આવે તો તે દિવસ તેનામાં ગણવામાં આવશે તેમ જો બે દિવસ ન આવે તો તેનું ફોર્મ રદ કરવામાં આવશે પછી તેણે ફરીથી ફોર્મ અને ફોર્મની રકમ ભરવાની રહેશે તેમજ વેઈટીંગમાં રહેવાનું રહેશે.\nવારો આવ્યા પછી એકવાર લેટ કરાવી શકાશે પરંતુ બીજીવાર વારો આવે એટલે ફરજીયાત આવવાનું રહેશે. અને જો ત્યારે પણ ન આવે તો ફોર્મ કેન્સલ કરવામાં આવશે તથા ભરેલી ફોર્મની રકમ પરત કરવામાં આવશે નહીં.\n16 વર્ષ થી નીચેની તેમજ 45 વર્ષથી ઉપરની બહેનોને શીખવવામાં આવશે નહીં.\nજે-તે વ્યક્તિનો ફોન નંબર આપ્યો હોય અને તે વ્યક્તિ ફોન પર જ નામ કેન્સલ કરાવી નાખશે તો પણ નામ કેન્સલ થઈ જશે પછી કોઈ તકરાર કરવી નહીં.\nજો કોઈ અગમ્ય કારણસર વચ્ચે અટકા વવાની ફરજ પડે તો તેની જાણ કર્યા ના ત્રીજા દિવસથી એના બાકી બચતા દિવસ ને 1.5 મહિના પછી આપવામાં આવશે પરંતુ ભરેલી રકમ પરત આપવામાં આવશે નહીં.\nશીખવા માટે ફૂલ સાઇઝ નું કાપડ લાવવાનું રહેશે. ટુકડામાં કાપડ ચાલશે નહીં.\nપહેલા બે જોડી ડ્રેસમાં મોટી સાઈઝ નું માપ જ ચાલશે નાની સાઈઝ નું માપ લાવવું નહીં.\nબેન્ચ શરૂ થાય ત્યારે સમયસર આવવું અને બેન્ચ પૂરી થાય ત્યારે તરત જ મશીન ખાલી કરવાનું રહેશે.\nદર રવિવારે રજા રહેશે રવિવાર સાથે જ 45 દિવસ ગણવામાં આવશે.\nતમારી કોઈ પણ કારણે પાડેલી રજા પરત આપવામાં આવશે નહીં.\nઅમારા પર્સનલ કારણસર કે તહેવાર નિમિત્તે પાડેલી રજા અમારે આપવાની રહેશે.\n45 દિવસ ઉપર એક પણ દિવસ આપવામાં આવશે નહીં વધારે દિવસ ભરવાની ફી અલગથી આપવાની રહેશે.\nલેડીસ ટેલર્સ ના કામ સિવાય નું બીજું કાંઈ પણ કામ શીખવવામાં આવશે નહીં.\nદરેક કપડા બોડી મેજરમેન્ટ થી શીખવવામાં આવશે.\nક્લાસ ની કોઈ સ્પેશિયલ વસ્તુ એકસ્ટ્રા બેન્ચ તેમજ એકસ્ટ્રા ફી ચૂકવીને શીખવવાની રહેશે તે રેગ્યુલર બેન્ચમાં શીખવવામાં આવશે નહીં તેમજ તે જૂના સ્ટુડન્ટ માટે જ રહેશે.\nબીજાને સિલાઈકામ શીખવાડતા હોય તેવા વ્યક્તિએ ક્લાસમાં શીખવા આવવું નહીં તો પણ ગેરકાયદેસર રીતે એન્ટ્રી કરશે અને પકડાઈ જશે તો તેની ભરેલી ફી પરત મળશે નહીં અને તેને જાણ થાય ત્યારથી જ ક્લાસ માંથી કાઢી મુકવામાં આવશે.\nસીવણ ક્લાસ માં ઑડિયો તેમજ વિડીયો રેકોર્ડીંગ કરવું નહીં.\nક્લાસમાં શીખ્યા પછી કોઈ કલાસીસ ચાલુ કરે તો તેને સીવણ ક્લાસમાં કંઈ પણ પૂછવા આવવાનો હક રહેશે નહીં.', 'branding', 'Rules printed on Page 2 of Admission Form (1 rule per line)'),
('admission_caution_text', 'ફોર્મ વ્યવસ્થિત વાંચીને સમજી વિચારીને ભરવું પછી તેના નિયમ વિરુદ્ધ કંઈપણ સાંભળવામાં આવશે નહીં.', 'branding', 'Caution banner text at bottom of Admission Form rules');

INSERT IGNORE INTO extension_charge_rates (per_day_charge, effective_from, status, created_by) VALUES
(150.00, CURDATE(), 'active', 1);

-- ---------------------------------------------------------
-- 17. PAYMENT WHATSAPP OTPS
-- ---------------------------------------------------------
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

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group, description) VALUES
('wa_otp_enabled', '1', 'whatsapp', 'Enable WhatsApp OTP verification for payments and receipts'),
('wa_phone_number_id', '', 'whatsapp', 'Meta WhatsApp Cloud API Phone Number ID'),
('wa_access_token', '', 'whatsapp', 'Meta WhatsApp Cloud API Permanent Access Token'),
('wa_api_version', 'v21.0', 'whatsapp', 'Meta Graph API Version'),
('wa_template_name', '', 'whatsapp', 'Approved Meta WhatsApp Template Name'),
('wa_template_lang', 'en', 'whatsapp', 'Meta WhatsApp Template Language Code'),
('wa_otp_mobile_1', '', 'whatsapp', 'Primary Admin/Owner WhatsApp Number for OTP'),
('wa_otp_mobile_2', '', 'whatsapp', 'Secondary Admin/Owner WhatsApp Number for OTP');


-- Default Super Admin User (Login: admin@devangistudent.com / Password: Admin@123)
INSERT IGNORE INTO users (id, role_id, name, email, phone, password_hash, status) 
VALUES (1, 1, 'Super Admin', 'admin@devangistudent.com', '9999999999', '$2y$10$3TmcfhpYQ.dn3MBAleCJ6.My7qet2iFdFYwf33J.6qLg522I6MCbS', 'active');


-- ---------------------------------------------------------
-- 18. STUDENT LEAVE & EXTENSION REQUESTS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    leave_type VARCHAR(50) NOT NULL DEFAULT 'leave',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT NOT NULL DEFAULT 1,
    reason TEXT DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_leave_student (student_id),
    INDEX idx_leave_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 19. STUDENT NOTICES
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_notices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    batch_id INT UNSIGNED DEFAULT NULL,
    priority ENUM('normal', 'important', 'urgent') NOT NULL DEFAULT 'normal',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notice_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 20. STUDENT LOGIN OTPS (WhatsApp Login)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_login_otps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    mobile VARCHAR(20) NOT NULL,
    otp_code VARCHAR(10) NOT NULL,
    status ENUM('pending', 'verified', 'expired') NOT NULL DEFAULT 'pending',
    sent_via ENUM('whatsapp', 'system') NOT NULL DEFAULT 'whatsapp',
    response_msg VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_login_otp_lookup (mobile, otp_code, status),
    INDEX idx_login_otp_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
