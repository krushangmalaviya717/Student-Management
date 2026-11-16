# Architecture & Codebase Guide
**Devangi Sewing Classes - Student Management System**

---

## 1. Directory Structure

```
Student-Management/
├── api/                           # RESTful API Endpoints & Gateway
│   ├── index.php                  # API Directory & Health Check
│   ├── ApiResponse.php            # Unified JSON Response Formatter
│   ├── ApiAuth.php                # Authentication Guard (Session + Bearer Token)
│   ├── students.php               # Students CRUD and status actions API
│   ├── batches.php                # Batches and occupancy statistics API
│   ├── holidays.php               # Institute holidays API
│   └── payments.php               # Payment transactions and revenue metrics API
│
├── config/                        # Configuration & Environment Layer
│   ├── env.php                    # Lightweight .env loader & env() helper
│   ├── database.php               # PDO Database singleton connection manager
│   └── config.php                 # Core bootstrapping, error handler & session init
│
├── database/                      # SQL Schema & Migrations
│   └── schema.sql                 # Complete database schema
│
├── includes/                      # Application Business Logic & Services
│   ├── auth.php                   # Staff & Admin authentication helpers
│   ├── student_auth.php           # Student portal authentication
│   ├── functions.php              # Global helper functions & shortcuts
│   ├── Session.php                # Session & State Management
│   │
│   ├── services/                  # Business Logic Services
│   │   ├── StudentService.php     # Student workflows, search, completion, metrics
│   │   ├── BatchService.php       # Batch CRUD, capacity validation, stats
│   │   ├── HolidayService.php     # Holidays (single/range/custom), rollback
│   │   ├── PaymentService.php     # Payments, receipts, revenue summaries
│   │   └── ExportService.php      # Centralized CSV / Excel exports (UTF-8 BOM)
│   │
│   ├── components/                # Modular Reusable UI Components
│   │   ├── kpi_card.php           # Unified KPI summary cards
│   │   ├── status_badge.php       # Bilingual status badges (English + Gujarati)
│   │   └── export_button.php      # Standardized export button
│   │
│   ├── course_engine.php          # 45-day course calculation engine
│   ├── queue_engine.php           # Waiting list & FIFO priority engine
│   ├── payment_engine.php         # Payment logic & receipt sequencing
│   ├── extension_engine.php       # Extra-day extension calculations & invoices
│   ├── layout_header.php          # Admin portal navigation and topbar
│   └── layout_footer.php          # Admin portal footer
│
├── public/                        # Presentation Layer (Web Views)
│   ├── assets/css/app.css         # Admin styling
│   ├── student/                   # Student Portal (PWA ready)
│   ├── students_list.php          # Filtered student directory & exports
│   ├── student_profile.php        # 360° student record view
│   ├── student_register.php       # New registration form
│   ├── waiting_list.php           # FIFO batch waiting queue
│   ├── batches.php                # Batch capacity & schedule CRUD
│   ├── holidays.php               # Holiday calendar manager
│   ├── payments.php               # Fee payments & receipts
│   ├── dashboard.php              # Admin overview & live metrics
│   └── 500.php                    # Friendly production error view
│
├── uploads/photos/                # Uploaded student photos
├── .env.example                   # Environment configuration template
├── .env                           # Local environment configuration
├── ARCHITECTURE.md                # System architectural documentation
└── README.md                      # Setup & deployment instructions
```

---

## 2. Separation of Business Logic from UI

- **Views (`public/*.php`)**: Contain only presentation logic, form inputs, and modal rendering. They delegate all database queries, validation, calculations, and data mutation to **Services**.
- **Services (`includes/services/*.php`)**:
  - `StudentService`: Manages student lifecycle (Draft -> Confirmed -> Active -> Extended -> Completed), search queries, and exports.
  - `BatchService`: Manages batch capacity, schedules, active student validation, and safe soft/hard deletion.
  - `HolidayService`: Declares single holidays, vacations/ranges (with skip-Sundays), custom dates, and handles deletion with automatic expiry date rollback.
  - `PaymentService`: Records transactions, enforces amounts > 0, generates receipt numbers, and calculates dues.
  - `ExportService`: Replaces duplicate CSV export scripts with a single method supporting UTF-8 BOM encoding for Gujarati script.

---

## 3. Environment Configuration

All environment-specific configuration is separated from the code:
- **`.env`**: Stores local database credentials, base URL, timezone, debug flags, and secret keys.
- **`config/env.php`**: Parses `.env` and exposes `env($key, $default)` helper.
- **`config/database.php`**: Singleton connection that dynamically reads `.env` parameters without hardcoding passwords in repository files.

---

## 4. Centralized Error Handling & Logging

1. **Development (`APP_DEBUG=true`)**:
   - Detailed stack trace, file path, and error messages are rendered in a clean debug box.
2. **Production (`APP_DEBUG=false`)**:
   - Errors and exceptions are logged via PHP's `error_log()`.
   - Users are shown a friendly error page (`public/500.php`) without leaking sensitive database credentials.
3. **API Requests (`/api/*`)**:
   - Uncaught exceptions automatically return structured JSON:
     ```json
     {
       "success": false,
       "error": "Error description",
       "code": 400
     }
     ```

---

## 5. State Management & Sessions

- **`Session` Class (`includes/Session.php`)**:
  - Encapsulates `$_SESSION` access with `Session::get()`, `Session::set()`, `Session::has()`, and `Session::remove()`.
  - **Typed Flash Messages**: `Session::flash('success'|'danger'|'warning'|'info', $message)` queues user notifications that automatically clear upon display.
  - **CSRF Protection**: `Session::csrfToken()` and `Session::verifyCsrf()` guard against cross-site request forgery.
  - **User Context**: `Session::user()`, `Session::login()`, and `Session::logout()`.

---

## 6. Scalable RESTful API Structure

Located under `/api/`:
- **`api/ApiResponse.php`**: Uniform response formatter.
- **`api/ApiAuth.php`**: Supports session-based authentication (for browser fetch/AJAX) and Bearer Token (`Authorization: Bearer <API_SECRET_KEY>`) for external clients.
- **Endpoints**:
  - `GET /api/students.php` - Fetch students list or single student by ID
  - `POST /api/students.php` - Actions: `mark_completed`, `reopen_course`
  - `GET /api/batches.php` - Batches list & occupancy stats
  - `POST /api/batches.php` - Actions: `create`, `toggle_status`, `delete`
  - `GET /api/payments.php` - Filtered payment history & revenue metrics
  - `POST /api/payments.php` - Record student payment
  - `GET /api/holidays.php` - Holidays calendar
  - `POST /api/holidays.php` - Declare single/range holiday or delete
