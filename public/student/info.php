<?php
$pageTitle = 'ક્લાસ વિશે માહિતી અને નિયમો - Devangi Classes';
$activePage = 'info';
$showPublicHeader = true;
$showBackBtn = true;
$backUrl = '/public/student/login.php';
require_once __DIR__ . '/includes/header.php';

$pdo = db();
$businessName = get_setting('business_name', 'Devangi Sewing Classes');
$tagline = get_setting('institute_tagline', 'Stitching & Fashion Designing Training Institute');
$phone = get_setting('institute_phone', '');
$address = get_setting('institute_address', '');

$formFee = (float)get_setting('form_fee', 500);
$joiningFee = (float)get_setting('joining_fee', 3500);
$secondPay = (float)get_setting('second_payment_amount', 4000);
$totalFee = $formFee + $joiningFee + $secondPay;
$courseDays = (int)get_setting('course_duration_days', 45);
$secondDueDays = (int)get_setting('second_payment_due_days', 10);

$rulesRaw = get_setting('admission_rules_text', '');
$rulesList = array_values(array_filter(array_map('trim', explode("\n", $rulesRaw))));
$cautionText = get_setting('admission_caution_text', '');

$batches = $pdo->query("SELECT id, name, start_time, end_time, batch_days FROM batches WHERE is_active = 1 ORDER BY start_time")->fetchAll();
?>

<div class="container-app" style="padding-top: 18px; padding-bottom: 40px;">

    <!-- Institute Overview Hero -->
    <div class="hero-card mb-3">
        <div class="hero-subtitle">Welcome to</div>
        <div class="hero-title"><?= htmlspecialchars($businessName) ?></div>
        <div class="mt-1" style="font-size: 0.85rem; color: #cbd5e1;">
            <?= htmlspecialchars($tagline) ?>
        </div>
        <?php if ($address || $phone): ?>
            <div class="mt-3 pt-2" style="border-top: 1px solid rgba(255,255,255,0.16); font-size: 0.8rem; color: #e2e8f0;">
                <?php if ($address): ?>
                    <div class="mb-1"><i class="bi bi-geo-alt-fill me-1 text-warning"></i> <?= htmlspecialchars($address) ?></div>
                <?php endif; ?>
                <?php if ($phone): ?>
                    <div><i class="bi bi-telephone-fill me-1 text-info"></i> <?= htmlspecialchars($phone) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Course & Fee Structure -->
    <div class="card-app">
        <div class="card-title">
            <span><i class="bi bi-wallet2 text-primary me-2"></i> કોર્સ અને ફી વિગત (Course &amp; Fees)</span>
        </div>

        <div class="info-row">
            <span class="info-label">કોર્સ સમયગાળો (Duration)</span>
            <span class="info-value fw-bold text-primary"><?= $courseDays ?> દિવસ</span>
        </div>
        <div class="info-row">
            <span class="info-label">૧. ફોર્મ ફી (રજીસ્ટ્રેશન સમયે)</span>
            <span class="info-value fw-bold">₹<?= number_format($formFee, 0) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">૨. પ્રથમ હપ્તો (ક્લાસ જોઈન થતી વખતે)</span>
            <span class="info-value fw-bold">₹<?= number_format($joiningFee, 0) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">૩. બીજો હપ્તો (<?= $secondDueDays ?> દિવસમાં)</span>
            <span class="info-value fw-bold">₹<?= number_format($secondPay, 0) ?></span>
        </div>
        <div class="info-row" style="border-bottom: none; background: #eff6ff; padding: 10px 12px; border-radius: 10px; margin-top: 8px;">
            <span class="info-label fw-bold text-dark">કુલ કોર્સ ફી (Total Course Fee)</span>
            <span class="info-value fw-bold text-primary" style="font-size: 1.05rem;">₹<?= number_format($totalFee, 0) ?></span>
        </div>
    </div>

    <!-- Batch Timings -->
    <div class="card-app">
        <div class="card-title">
            <span><i class="bi bi-clock-history text-success me-2"></i> ઉપલબ્ધ બેચ સમય (Batch Timings)</span>
        </div>
        <?php if (empty($batches)): ?>
            <div class="small text-muted">બેચ સમયની માહિતી માટે ઓફિસનો સંપર્ક કરો.</div>
        <?php else: ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($batches as $b): ?>
                    <div class="p-2 px-3 rounded-3 border bg-light d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold text-dark small"><?= htmlspecialchars($b['name']) ?></div>
                            <?php if (!empty($b['batch_days'])): ?>
                                <div class="text-muted" style="font-size: 0.74rem;"><?= htmlspecialchars($b['batch_days']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.78rem;">
                            <?= date('h:i A', strtotime($b['start_time'])) ?> - <?= date('h:i A', strtotime($b['end_time'])) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rules & Regulations -->
    <?php if (!empty($rulesList)): ?>
    <div class="card-app">
        <div class="card-title">
            <span><i class="bi bi-journal-check text-warning me-2"></i> ક્લાસના નિયમો (Rules &amp; Guidelines)</span>
        </div>
        <ol class="ps-3 mb-2 small text-dark" style="line-height: 1.65;">
            <?php foreach ($rulesList as $rule): ?>
                <li class="mb-2"><?= htmlspecialchars($rule) ?></li>
            <?php endforeach; ?>
        </ol>
        <?php if ($cautionText): ?>
            <div class="p-2 px-3 rounded-3 mt-2 small fw-semibold" style="background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA;">
                <i class="bi bi-exclamation-circle-fill me-1"></i> <?= htmlspecialchars($cautionText) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Bottom CTA Buttons -->
    <div class="d-grid gap-2 mt-3">
        <a href="/public/student/apply.php" class="visitor-btn-primary">
            <i class="bi bi-pencil-square fs-5"></i>
            <span>નવું એડમિશન ફોર્મ ભરો (Apply Now)</span>
        </a>
        <a href="/public/student/login.php" class="visitor-btn-outline">
            <i class="bi bi-box-arrow-in-right"></i>
            <span>પહેલેથી રજીસ્ટર છો? Login કરો</span>
        </a>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
