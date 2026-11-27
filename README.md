# Devangi Sewing Classes — Student Management System

A robust, enterprise-grade Student Management System built with PHP 8.2 & MySQL (PDO), designed with clean separation of concerns, modular services, reusable components, and a RESTful API gateway. Built to run locally on XAMPP and easily deployable to Hostinger shared/business hosting.

---

## 🏗️ Architecture & Enhancements

1. **Clean Folder Structure**:
   - `api/`: RESTful JSON API endpoints for mobile and web integration.
   - `config/`: Modular environment and database connection layers (`.env`, `env.php`, `database.php`, `config.php`).
   - `includes/services/`: Dedicated business logic layer (`StudentService`, `BatchService`, `HolidayService`, `PaymentService`, `ExportService`).
   - `includes/components/`: Reusable UI modules (`kpi_card`, `status_badge`, `export_button`).
   - `public/`: Clean presentation views calling business services.

2. **Separation of Business Logic from UI**:
   - Web pages in `public/` act as thin controllers/views.
   - All database queries, transaction management, and calculations reside in `includes/services/`.

3. **No Duplicate or Dead Code**:
   - Consolidated Excel/CSV export engine (`ExportService`) with UTF-8 BOM encoding for Gujarati script.
   - Removed temporary test scripts and orphaned folders.

4. **Environment Configuration**:
   - Configuration separated into `.env` (with `.env.example` template).
   - Dynamic environment loading via `env('KEY', 'default')`.

5. **Centralized Error Handling**:
   - Graceful exception handlers on all web pages and API endpoints.
   - Error logging via PHP's `error_log()`.
   - User-friendly production error page (`public/500.php`).

6. **State & Session Management**:
   - Encapsulated in `Session` class (`includes/Session.php`).
   - Typed flash notifications (`Session::flash('success'|'danger'|'warning'|'info')`).
   - Secure CSRF validation.

7. **Scalable RESTful API**:
   - Standard JSON format (`ApiResponse::json` & `ApiResponse::error`).
   - Dual authentication: Session-based for frontend AJAX, Bearer token (`Authorization: Bearer <API_SECRET_KEY>`) for external clients.

---

## 📁 Project Structure

```
Student-Management/
├── api/                             # RESTful API Endpoints
│   ├── index.php                    # API Gateway & Documentation
│   ├── ApiResponse.php              # Standard JSON response helper
│   ├── ApiAuth.php                  # Authentication guard (Session/Token)
│   ├── students.php                 # Students API
│   ├── batches.php                  # Batches API
│   ├── holidays.php                 # Holidays API
│   ├── payments.php                 # Payments API
│   └── staff.php                    # Staff API
│
├── config/                          # Configuration & Environment
│   ├── .env.example                 # Environment template
│   ├── .env                         # Local environment settings
│   ├── env.php                      # Environment parser
│   ├── database.php                 # PDO database factory
│   └── config.php                   # Core config & error handling
│
├── database/                        # Schema & Migrations
│   └── schema.sql                   # Full 20-table MySQL schema
│
├── includes/                        # Core Application Layer
│   ├── auth.php                     # Authentication & role checks
│   ├── Session.php                  # State & session manager
│   ├── functions.php                # Helpers and utilities
│   ├── services/                    # Business Logic Layer
│   │   ├── StudentService.php       # Student domain logic
│   │   ├── BatchService.php         # Batch domain logic
│   │   ├── HolidayService.php       # Holiday domain logic
│   │   ├── PaymentService.php       # Payment domain logic
│   │   ├── StaffService.php         # Staff domain logic
│   │   ├── AttendanceService.php    # Staff attendance engine
│   │   ├── PayrollService.php       # Real-world payroll & salary engine
│   │   └── ExportService.php        # Reusable CSV export service
│   ├── components/                  # Reusable UI Components
│   │   ├── kpi_card.php             # KPI card renderer
│   │   ├── status_badge.php         # Status badge renderer
│   │   └── export_button.php        # Export button renderer
│   ├── course_engine.php            # 45-day course engine
│   ├── queue_engine.php             # Waiting list engine
│   └── layout_header.php            # Admin layout header
│
├── public/                          # Web UI Views
│   ├── assets/css/app.css           # Admin styling
│   ├── student/                     # Student Portal (PWA)
│   ├── students_list.php            # Student list & filters
│   ├── student_profile.php          # Student 360° profile
│   ├── batches.php                  # Batch management
│   ├── staff.php                    # Staff directory
│   ├── staff_attendance.php         # Staff daily & monthly attendance
│   ├── staff_payroll.php            # Staff payroll & salary slips
│   ├── holidays.php                 # Holiday management
│   ├── payments.php                 # Payment management
│   ├── waiting_list.php             # Waiting list queue
│   └── 500.php                      # Friendly error page
│
├── uploads/                         # Student photos
├── ARCHITECTURE.md                  # Comprehensive architectural guide
└── start.php                        # Local server starter
```

---

## 🚀 Running Locally

1. Ensure MySQL is running in XAMPP.
2. Configure credentials in `.env` (copied from `.env.example`).
3. Start the application:
   ```bash
   npm start
   ```
   Or run directly:
   ```bash
   C:\xampp\php\php.exe start.php
   ```
4. Access the application:
   - Student Portal (User Side / Main URL): `http://localhost:8000/` (opens `public/student/login.php`, Mobile: `9725340354`)
   - Class Admin Panel (Separate, Staff/Admin Only): `http://localhost:8000/devangi-admin-717` or `http://localhost:8000/public/login.php` (Login: `admin@example.com` / `admin123`)
   - API Gateway: `http://localhost:8000/api/index.php`

---

## 🌐 Deploying to Hostinger (cPanel / hPanel)

1. Create a MySQL database and user in Hostinger hPanel.
2. Update `.env` with Hostinger database credentials.
3. Upload project files to `public_html/`.
4. Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`.
5. Run schema import from `database/schema.sql`.
