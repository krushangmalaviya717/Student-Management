<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student_auth.php';

$pdo = db();
$studentId = 0;

// Allow logged in student OR logged in admin/staff
if (is_student_logged_in()) {
    $s = current_student();
    $studentId = (int)$s['id'];
} elseif (is_logged_in()) {
    $studentId = (int)($_GET['id'] ?? 0);
} else {
    redirect('public/student/login.php');
    exit;
}

if ($studentId <= 0) {
    die('વિદ્યાર્થી મળ્યો નથી.');
}

$stmt = $pdo->prepare(
    "SELECT s.*, b.name AS batch_name, b.start_time, b.end_time
     FROM students s
     LEFT JOIN batches b ON s.joined_batch_id = b.id
     WHERE s.id = ?"
);
$stmt->execute([$studentId]);
$st = $stmt->fetch();

if (!$st) {
    die('Student record not found.');
}

$startDate = !empty($st['entry_date']) ? date('d F, Y', strtotime($st['entry_date'])) : '07 October, 2026';
$endDate = !empty($st['final_expiry_date']) ? date('d F, Y', strtotime($st['final_expiry_date'])) : date('d F, Y');
$certNo = 'DSC-CERT-' . str_pad((string)$st['id'], 5, '0', STR_PAD_LEFT);
$businessName = get_setting('business_name', 'DEVANGI SEWING CLASSES');
?>
<!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Course Certificate - <?= htmlspecialchars($st['name']) ?></title>
<style>
    *, *::before, *::after {
        box-sizing: border-box;
    }
    body {
        margin: 0;
        padding: 24px;
        background: #f1f5f9;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: #0f172a;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .no-print {
        max-width: 960px;
        margin: 0 auto 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 18px;
        font-size: 13.5px;
        font-weight: 600;
        border-radius: 6px;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid transparent;
        transition: all 0.2s;
    }
    .btn-secondary {
        background: #fff;
        color: #334155;
        border-color: #cbd5e1;
    }
    .btn-secondary:hover { background: #f8fafc; }
    .btn-primary {
        background: #4338ca;
        color: #fff;
    }
    .btn-primary:hover { background: #3730a3; }

    /* Certificate Outer Frame */
    .certificate-container {
        max-width: 960px;
        margin: 0 auto;
        background: #ffffff;
        padding: 24px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        border-radius: 8px;
    }
    .certificate-border {
        border: 4px double #4338ca;
        padding: 30px 40px;
        position: relative;
        background: radial-gradient(circle at center, #ffffff 60%, #fafafa 100%);
    }
    .inner-border {
        border: 1.5px solid #c7d2fe;
        padding: 24px 30px;
        text-align: center;
        position: relative;
    }

    /* Header */
    .cert-badge {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        color: #4338ca;
        background: #eef2ff;
        padding: 4px 14px;
        border-radius: 20px;
        margin-bottom: 10px;
    }
    .cert-institute {
        font-size: 28px;
        font-weight: 900;
        letter-spacing: 1.5px;
        color: #1e1b4b;
        margin: 0;
        text-transform: uppercase;
    }
    .cert-sub {
        font-size: 13px;
        color: #64748b;
        margin-top: 4px;
        font-weight: 500;
        letter-spacing: 0.5px;
    }
    .cert-divider-line {
        width: 140px;
        height: 2px;
        background: #4338ca;
        margin: 16px auto;
    }

    /* Title */
    .cert-title {
        font-family: Georgia, 'Times New Roman', serif;
        font-size: 26px;
        font-style: italic;
        color: #312e81;
        margin: 8px 0;
        letter-spacing: 1px;
    }
    .cert-present-to {
        font-size: 13.5px;
        color: #64748b;
        margin-top: 8px;
        letter-spacing: 0.8px;
        text-transform: uppercase;
    }

    /* Student Name */
    .student-name {
        font-family: Georgia, 'Times New Roman', serif;
        font-size: 30px;
        font-weight: bold;
        color: #0f172a;
        margin: 10px 0 6px;
        border-bottom: 2px solid #e0e7ff;
        display: inline-block;
        padding: 0 30px 4px;
    }
    .student-meta {
        font-size: 12.5px;
        color: #475569;
        font-family: monospace;
        margin-bottom: 14px;
    }

    /* Body Text */
    .cert-body {
        font-size: 14px;
        color: #334155;
        line-height: 1.7;
        max-width: 720px;
        margin: 0 auto 20px;
    }
    .cert-course-name {
        font-size: 16px;
        font-weight: 800;
        color: #1e1b4b;
    }

    /* Signatures & Seal */
    .cert-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-top: 36px;
        padding-top: 10px;
    }
    .footer-col {
        width: 200px;
        text-align: center;
    }
    .sign-line {
        border-top: 1.5px solid #0f172a;
        margin-bottom: 6px;
        width: 170px;
        margin-left: auto;
        margin-right: auto;
    }
    .sign-caption {
        font-size: 12px;
        color: #475569;
        font-weight: 600;
    }
    .cert-seal {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        border: 2px dashed #4338ca;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        color: #4338ca;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .cert-no-tag {
        position: absolute;
        bottom: 8px;
        left: 12px;
        font-size: 10.5px;
        color: #94a3b8;
        font-family: monospace;
    }

    /* Print settings */
    @page {
        size: A4 landscape;
        margin: 8mm;
    }
    @media print {
        body {
            background: #fff !important;
            padding: 0 !important;
        }
        .no-print {
            display: none !important;
        }
        .certificate-container {
            box-shadow: none !important;
            padding: 0 !important;
            max-width: 100% !important;
        }
        .certificate-border {
            border: 4px double #000000 !important;
            padding: 24px 30px !important;
        }
        .inner-border {
            border: 1.5px solid #000000 !important;
            padding: 18px 24px !important;
        }
        .cert-badge {
            background: #f1f5f9 !important;
            color: #000000 !important;
        }
        .cert-institute, .cert-title, .student-name, .cert-course-name {
            color: #000000 !important;
        }
        .cert-divider-line {
            background: #000000 !important;
        }
    }
</style>
</head>
<body>

<div class="no-print">
    <a href="javascript:history.back()" class="btn btn-secondary">&larr; પાછા જાઓ</a>
    <div>
        <button onclick="window.print()" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Print / Save Certificate (A4)
        </button>
    </div>
</div>

<div class="certificate-container">
    <div class="certificate-border">
        <div class="inner-border">
            <div class="cert-badge">Official Training Certification</div>
            <h1 class="cert-institute"><?= htmlspecialchars($businessName) ?></h1>
            <div class="cert-sub">Stitching &amp; Fashion Designing Training Institute</div>
            
            <div class="cert-divider-line"></div>

            <div class="cert-title">Certificate of Course Completion</div>
            <div class="cert-present-to">This is proudly presented to</div>

            <div class="student-name"><?= htmlspecialchars($st['name']) ?></div>
            <div class="student-meta">Registration No: <strong><?= htmlspecialchars($st['registration_no'] ?: $st['form_no']) ?></strong></div>

            <div class="cert-body">
                For successfully fulfilling all institutional requirements and completing the comprehensive course in
                <div class="cert-course-name">Basic to Advanced Stitching &amp; Fashion Designing Course (45 Days)</div>
                demonstrating proficiency in garment drafting, pattern cutting, blouse &amp; dress stitching, finishing, and fashion techniques.
                <div style="margin-top: 6px; font-weight: 500;">
                    Course Duration: <strong><?= $startDate ?></strong> to <strong><?= $endDate ?></strong>
                </div>
            </div>

            <div class="cert-footer">
                <div class="footer-col">
                    <div style="height: 35px;"></div>
                    <div class="sign-line"></div>
                    <div class="sign-caption">Course Instructor / Teacher</div>
                </div>

                <div class="footer-col">
                    <div class="cert-seal">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>OFFICIAL SEAL</span>
                    </div>
                </div>

                <div class="footer-col">
                    <div style="height: 35px;"></div>
                    <div class="sign-line"></div>
                    <div class="sign-caption">Director / Authorized Signatory<br><small><?= htmlspecialchars($businessName) ?></small></div>
                </div>
            </div>

            <div class="cert-no-tag">Cert No: <?= htmlspecialchars($certNo) ?></div>
        </div>
    </div>
</div>

</body>
</html>