<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/course_engine.php';
require_login();

$pdo = db();
$studentId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT s.*, b.name AS batch_name, b.start_time, b.end_time, b.batch_days,
            r.relative_name, r.relative_mobile, r.relation
     FROM students s
     LEFT JOIN batches b ON b.id = COALESCE(s.joined_batch_id, s.preferred_batch_id)
     LEFT JOIN referrals r ON r.student_id = s.id
     WHERE s.id = ?"
);
$stmt->execute([$studentId]);
$s = $stmt->fetch();

if (!$s) {
    die('Student record not found.');
}
?>
<!DOCTYPE html>
<html lang="gu">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>દેવાંગી સીવણ ક્લાસીસ - Premium Admission Form & Rules</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anek+Gujarati:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
@import url('https://fonts.googleapis.com/css2?family=Anek+Gujarati:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

:root {
  --primary-rose: #B37682;
  --dark-rose: #B37682;
  --soft-rose: #D4A3AC;
  --rose-light: #FDF6F7;
  --gold-accent: #B37682;
  --gold-light: #F9F6F0;
  --dark-text: #211B1C;
  --secondary-text: #594E50;
  --border-color: #EADADF;
  --border-strong: #B37682;
  --white: #FFFFFF;
  --radius-sm: 5px;
  --radius-md: 8px;
  --radius-lg: 12px;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  background-color: #E5E0DC;
  color: var(--dark-text);
  font-family: 'Anek Gujarati', 'Plus Jakarta Sans', sans-serif;
  line-height: 1.34;
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
}

/* Floating Action Bar (Screen Only) */
.print-action-bar {
  position: sticky;
  top: 0;
  z-index: 1000;
  background-color: rgba(33, 27, 28, 0.94);
  backdrop-filter: blur(8px);
  color: var(--white);
  padding: 12px 24px;
  width: 100%;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
}

.print-bar-content {
  max-width: 900px;
  margin: 0 auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.print-bar-info {
  display: flex;
  flex-direction: column;
}

.print-bar-title {
  font-weight: 700;
  font-size: 15px;
  color: #F7E7EB;
}

.print-bar-desc {
  font-size: 12px;
  color: #BDB5B7;
}

.btn-print {
  background: linear-gradient(135deg, var(--primary-rose) 0%, var(--dark-rose) 100%);
  color: var(--white);
  border: 1px solid var(--gold-accent);
  padding: 9px 20px;
  border-radius: 25px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s ease;
  box-shadow: 0 3px 12px rgba(184, 123, 134, 0.4);
}

.btn-print:hover {
  transform: translateY(-1.5px);
  box-shadow: 0 5px 16px rgba(184, 123, 134, 0.6);
  background: linear-gradient(135deg, #C78A95 0%, var(--primary-rose) 100%);
}

/* Page Outer Wrapper for Preview */
.page-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 35px;
  padding: 30px 0;
}

/* A4 Sheet Dimensions */
.a4-page {
  width: 210mm;
  height: 297mm;
  background-color: var(--white);
  position: relative;
  overflow: hidden;
  padding: 6mm;
  box-shadow: 0 12px 35px rgba(0, 0, 0, 0.12);
  display: flex;
  flex-direction: column;
  border-radius: 2px;
}

/* Inset Decorative Frame */
.page-frame {
  height: 100%;
  width: 100%;
  border: 1.5px solid var(--primary-rose);
  padding: 6mm 7mm;
  position: relative;
  background-color: var(--white);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  box-shadow: inset 0 0 0 2px var(--white), inset 0 0 0 3px var(--gold-accent);
}

/* Corner Flourishes */
.corner-flourish {
  position: absolute;
  width: 14px;
  height: 14px;
  pointer-events: none;
  z-index: 10;
}

.corner-flourish.tl {
  top: 5px;
  left: 5px;
  border-top: 2px solid var(--primary-rose);
  border-left: 2px solid var(--primary-rose);
}

.corner-flourish.tr {
  top: 5px;
  right: 5px;
  border-top: 2px solid var(--primary-rose);
  border-right: 2px solid var(--primary-rose);
}

.corner-flourish.bl {
  bottom: 5px;
  left: 5px;
  border-bottom: 2px solid var(--primary-rose);
  border-left: 2px solid var(--primary-rose);
}

.corner-flourish.br {
  bottom: 5px;
  right: 5px;
  border-bottom: 2px solid var(--primary-rose);
  border-right: 2px solid var(--primary-rose);
}

.page-content-wrapper {
  display: flex;
  flex-direction: column;
  height: 100%;
  justify-content: space-between;
}

/* ================= PAGE 1: ENTRY FORM ================= */

.brand-center-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  position: relative;
  gap: 2px;
}

.brand-title-wrap h1 {
  font-family: 'Anek Gujarati', sans-serif;
  font-size: 54px;
  font-weight: 800;
  color: var(--dark-rose);
  letter-spacing: 2.5px;
  line-height: 1.1;
  text-align: center;
  text-transform: uppercase;
  white-space: nowrap;
}

.brand-tagline {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 12.5px;
  font-weight: 700;
  letter-spacing: 4px;
  color: #C5A059;
  text-transform: uppercase;
  margin-top: 2px;
  text-align: center;
  margin-left: 4px;
}

.unified-header-block {
  margin-bottom: 11px;
  padding: 8px 18px;
  background: linear-gradient(180deg, #FFFDFD 0%, #F8EEF0 100%);
  border: 1.5px solid #EADADF;
  border-radius: var(--radius-md);
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  box-shadow: 0 4px 12px rgba(184, 123, 134, 0.08);
}

.header-bottom-meta {
  width: 100%;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.header-bottom-meta > .meta-field-box:first-child {
  flex: 1;
  display: flex;
  justify-content: flex-start;
}

.header-bottom-meta > .meta-field-box:last-child {
  flex: 1;
  display: flex;
  justify-content: flex-end;
}

.admission-badge-center {
  background-color: var(--dark-rose);
  color: var(--white);
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-weight: 800;
  letter-spacing: 5px;
  font-size: 13.5px;
  text-transform: uppercase;
  padding: 6px 24px;
  border-radius: 14px;
  border: 1px solid var(--white);
  box-shadow: 0 0 0 1.5px var(--dark-rose), 0 3px 8px rgba(133, 72, 83, 0.3);
  display: flex;
  align-items: center;
  margin: 0 15px;
  flex-shrink: 0;
  text-align: center;
}

.meta-field-box {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  font-size: 14px;
}

.meta-field-box label {
  font-weight: 700;
  color: var(--dark-text);
  display: flex;
  flex-direction: column;
  line-height: 1.1;
}

.meta-field-box label span {
  font-size: 11.5px;
  color: var(--secondary-text);
  font-weight: 400;
  margin-top: 1px;
}

.input-underline-sm, .input-underline-date {
  font-weight: 800;
  color: var(--dark-text);
  font-size: 16.5px;
  padding: 0 4px;
  border-bottom: 1.5px solid var(--primary-rose);
  min-height: 18px;
  display: inline-block;
}

/* Date / Bench Summary Grid */
.date-summary-grid {
  display: grid;
  grid-template-columns: 1.05fr 0.9fr 1.05fr;
  gap: 10px;
  margin-bottom: 9px;
}

.date-card-item {
  background-color: var(--white);
  border: 1px solid var(--border-color);
  border-left: 3px solid var(--primary-rose);
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  gap: 8px;
}

.lbl-wrapper {
  display: flex;
  flex-direction: column;
  white-space: nowrap;
}

.lbl-wrapper .primary-lbl {
  font-weight: 700;
  font-size: 14.5px;
  color: var(--dark-text);
}

.lbl-wrapper .secondary-lbl {
  font-size: 11.5px;
  color: var(--secondary-text);
  font-family: 'Plus Jakarta Sans', sans-serif;
}

.input-fill-space {
  flex: 1;
  border-bottom: 1.5px dashed var(--primary-rose);
  min-height: 22px;
  font-weight: 800;
  font-size: 16.5px;
  color: #0f172a;
  padding-left: 6px;
  line-height: 1.2;
}

/* Student Info Top Block */
.student-info-top-block {
  display: flex;
  gap: 16px;
  align-items: stretch;
  margin-bottom: 8px;
}

.student-fields-left {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6.5px;
}

.input-row-single {
  background-color: var(--white);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-sm);
  padding: 6.5px 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.input-row-double {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

/* Passport Photo & Specimen Signature Right Column */
.student-photo-sign-right {
  display: flex;
  flex-direction: column;
  gap: 6.5px;
  width: 35mm;
  flex-shrink: 0;
}

.photo-frame-standard {
  width: 35mm;
  height: 45mm;
  border: 2px dashed var(--primary-rose);
  border-radius: var(--radius-md);
  background-color: var(--rose-light);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 2px;
  flex-shrink: 0;
  overflow: hidden;
}

.photo-frame-standard img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: top center;
}

.photo-icon-svg {
  width: 24px;
  height: 24px;
  margin-bottom: 4px;
  stroke: var(--soft-rose);
}

.photo-notice-text {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--soft-rose);
  line-height: 1.3;
}

.specimen-signature-box {
  width: 35mm;
  flex: 1;
  min-height: 18mm;
  border: 1px solid var(--border-color);
  border-radius: var(--radius-sm);
  background-color: var(--white);
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  align-items: center;
  padding: 4px 6px;
  box-sizing: border-box;
}

.specimen-sign-label {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--dark-text);
  border-top: 1.5px dashed var(--primary-rose);
  width: 100%;
  text-align: center;
  padding-top: 4px;
}

.specimen-sign-label span {
  font-size: 9.5px;
  font-weight: 500;
  color: var(--secondary-text);
  margin-left: 2px;
}

/* Address Block */
.full-width-address-block {
  display: flex;
  flex-direction: column;
  margin-bottom: 9px;
}

.address-row {
  align-items: flex-start;
  padding: 10px 14px;
}

.address-lines-container {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-top: 3px;
  padding-bottom: 2px;
}

/* Questionnaire Section */
.section-questionnaire {
  background-color: var(--white);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-md);
  padding: 9px 12px;
  margin-bottom: 9px;
}

.q-block-1 {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 7px;
  border-bottom: 1px dashed var(--border-color);
}

.q-label {
  font-weight: 700;
  font-size: 14px;
  color: var(--dark-text);
}

.q-label span {
  font-weight: 400;
  font-size: 12px;
  color: var(--secondary-text);
  font-family: 'Plus Jakarta Sans', sans-serif;
  margin-left: 4px;
}

.choices-row {
  display: flex;
  gap: 20px;
  align-items: center;
}

.choice-item {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 14px;
  font-weight: 700;
}

.choice-sub {
  font-size: 11px;
  color: var(--secondary-text);
  font-weight: 400;
}

.choice-box {
  width: 17px;
  height: 17px;
  border: 1.8px solid var(--primary-rose);
  border-radius: 3px;
  background-color: var(--white);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 800;
  color: var(--dark-rose);
}

.q-block-2 {
  padding-top: 7px;
}

.q2-fill {
  min-height: 22px;
  margin-top: 4px;
}

/* Turn Date Callout Box */
.turn-date-box {
  background-color: var(--rose-light);
  border: 1.5px solid var(--primary-rose);
  border-radius: var(--radius-md);
  padding: 8px 14px;
  text-align: center;
  margin-bottom: 9px;
}

.turn-date-main {
  font-size: 15.5px;
  font-weight: 800;
  color: var(--dark-text);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.date-fill-pill {
  background-color: var(--white);
  border: 1.5px solid var(--primary-rose);
  padding: 3px 16px;
  border-radius: 18px;
  color: var(--dark-rose);
  font-weight: 800;
  letter-spacing: 1px;
}

.turn-date-note {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--primary-rose);
  margin-top: 3px;
}

/* Reference / Referral Source Section */
.reference-section {
  background-color: var(--white);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-md);
  padding: 10px 14px;
}

.relative-header-pill {
  background-color: var(--primary-rose);
  color: var(--white);
  font-weight: 700;
  font-size: 14.5px;
  padding: 5px 18px;
  border-radius: 20px;
  text-align: center;
  margin-bottom: 10px;
  box-shadow: 0 2px 8px rgba(184, 123, 134, 0.2);
}

.relative-header-pill span {
  font-weight: 400;
  font-size: 12.5px;
  opacity: 0.95;
  font-family: 'Plus Jakarta Sans', sans-serif;
  margin-left: 4px;
}

.ref-top-options-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-bottom: 10px;
}

.ref-option-item {
  display: flex;
  align-items: center;
  gap: 8px;
  background-color: #FAF4F5;
  padding: 7px 10px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border-color);
}

.ref-label-wrap {
  display: flex;
  align-items: center;
  gap: 5px;
}

.ref-main-lbl {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--dark-text);
}

.ref-friend-block {
  background-color: #FAF4F5;
  border: 1px solid var(--border-color);
  border-left: 3.5px solid var(--primary-rose);
  border-radius: var(--radius-sm);
  padding: 8px 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ref-friend-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 4px;
  border-bottom: 1px dashed var(--border-color);
}

.ref-inputs-double {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

/* ================= PAGE 2: RULES & REGULATIONS ================= */

.rules-page-container {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  height: 100%;
  flex: 1;
}

.rules-banner {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  margin-top: 0px;
  margin-bottom: 0px;
}

.banner-ornament {
  height: 1.5px;
  flex: 1;
}

.banner-ornament.left {
  background: linear-gradient(90deg, transparent, var(--primary-rose));
}

.banner-ornament.right {
  background: linear-gradient(270deg, transparent, var(--primary-rose));
}

.rules-banner h2 {
  font-family: 'Anek Gujarati', sans-serif;
  font-size: 26px;
  font-weight: 800;
  color: var(--dark-rose);
  letter-spacing: 4px;
  padding: 0 14px;
  margin: 0;
  line-height: 1;
}

.rules-subtitle {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 12.5px;
  font-weight: 700;
  letter-spacing: 4px;
  color: #C5A059;
  text-transform: uppercase;
  margin-top: 10px;
  margin-left: 4px;
  text-align: center;
  margin-bottom: 5px;
}

.rules-wrapper {
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin-bottom: 12px;
  padding: 0 2px;
}

.rule-row-card {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 14.2px;
  line-height: 1.38;
  color: var(--dark-text);
  font-weight: 600;
}

.rule-num {
  font-weight: 800;
  color: var(--dark-rose);
  min-width: 26px;
  flex-shrink: 0;
  font-size: 14.8px;
  text-align: right;
}

.rule-body {
  flex: 1;
  font-weight: 600;
  text-align: justify;
}

.caution-pill-banner {
  background-color: var(--rose-light);
  border: 1.5px solid var(--primary-rose);
  border-radius: var(--radius-md);
  padding: 12px 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: var(--dark-rose);
  font-weight: 800;
  font-size: 13.5px;
  margin-top: -12px;
  margin-bottom: 14px;
  text-align: center;
}

.footer-signatures-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 16px;
  padding-top: 14px;
  padding-bottom: 2px;
  border-top: 1.5px dashed var(--border-color);
}

.signature-field {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  height: 65px;
}

.signature-line {
  width: 100%;
  border-bottom: 1.5px solid var(--dark-text);
  margin-bottom: 4px;
}

.signature-label {
  font-size: 13.8px;
  font-weight: 700;
  color: var(--dark-text);
}

/* ================= PRINT SETUP ================= */
@page {
  size: A4 portrait;
  margin: 0;
}

@media print {
  body {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  .no-print {
    display: none !important;
  }

  .page-wrapper {
    padding: 0 !important;
    gap: 0 !important;
    margin: 0 !important;
  }

  .a4-page {
    box-shadow: none !important;
    margin: 0 !important;
    width: 210mm !important;
    height: 297mm !important;
    page-break-after: always !important;
    break-after: page !important;
    border-radius: 0 !important;
    background-color: #FFFFFF !important;
  }

  .a4-page:last-child {
    page-break-after: auto !important;
    break-after: auto !important;
  }

  .page-frame {
    box-shadow: inset 0 0 0 2px #FFFFFF, inset 0 0 0 3px var(--gold-accent) !important;
  }
}
  </style>
</head>
<body>

  <!-- Screen Only Floating Print Action Bar -->
  <div class="no-print print-action-bar">
    <div class="print-bar-content">
      <div class="print-bar-info">
        <span class="print-bar-title">દેવાંગી સીવણ ક્લાસીસ — Premium Admission Form</span>
        <span class="print-bar-desc">A4 Print Ready (White Background with Premium Inset Frame)</span>
      </div>
      <button onclick="window.print()" class="btn-print">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="6 9 6 2 18 2 18 9"></polyline>
          <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
          <rect x="6" y="14" width="12" height="8"></rect>
        </svg>
        પ્રિન્ટ કરો (Print Form)
      </button>
    </div>
  </div>

  <div class="page-wrapper">

    <!-- PAGE 1: ENTRY FORM -->
    <section class="a4-page">
      <!-- Premium Inset Border Frame -->
      <div class="page-frame">
        <!-- Corner Flourishes -->
        <div class="corner-flourish tl"></div>
        <div class="corner-flourish tr"></div>
        <div class="corner-flourish bl"></div>
        <div class="corner-flourish br"></div>

        <div class="page-content-wrapper">

          <!-- Main Title -->
          <div class="brand-center-content" style="margin-bottom: 25px; margin-top: 20px;">
            <div class="brand-title-wrap">
              <h1>DEVANGI SEWING CLASSES</h1>
              <div class="brand-tagline">ACADEMY OF SEWING &amp; FASHION DESIGN</div>
            </div>
          </div>

          <!-- Header Section -->
          <header class="unified-header-block">
            <div class="header-bottom-meta">
              <div class="meta-field-box">
                <label>ફોર્મ નં. <span>( Form No. )</span></label>
                <div class="input-underline-sm"><?= e($s['form_no']) ?></div>
              </div>
              <div class="admission-badge-center">
                ADMISSION FORM
              </div>
              <div class="meta-field-box">
                <label>તારીખ : <span>( Date )</span></label>
                <div class="input-underline-date"><?= date('d / m / Y', strtotime($s['form_submit_date'])) ?></div>
              </div>
            </div>
          </header>

          <!-- Date & Bench Summary Grid -->
          <div class="date-summary-grid">
            <div class="date-card-item">
              <div class="lbl-wrapper">
                <span class="primary-lbl">એન્ટ્રી તા. :</span>
                <span class="secondary-lbl">( Entry Date )</span>
              </div>
              <div class="input-fill-space"><?= $s['entry_date'] ? format_date($s['entry_date']) : '' ?></div>
            </div>
            <div class="date-card-item">
              <div class="lbl-wrapper">
                <span class="primary-lbl">બેન્ચના દિવસ :</span>
                <span class="secondary-lbl">( Bench Day )</span>
              </div>
              <div class="input-fill-space"><?= e($s['batch_days'] ?? 'Mon - Sat') ?></div>
            </div>
            <div class="date-card-item">
              <div class="lbl-wrapper">
                <span class="primary-lbl">એક્સપાયરી તા. :</span>
                <span class="secondary-lbl">( Expiry Date )</span>
              </div>
              <div class="input-fill-space"><?= $s['final_expiry_date'] ? format_date($s['final_expiry_date']) : '' ?></div>
            </div>
          </div>

          <!-- Student Info Top Block (Left Fields + Right Passport Photo Frame) -->
          <div class="student-info-top-block">
            <div class="student-fields-left">
              <!-- Student Name -->
              <div class="input-row-single">
                <div class="lbl-wrapper">
                  <span class="primary-lbl">પૂરું નામ :</span>
                  <span class="secondary-lbl">( Student Name )</span>
                </div>
                <div class="input-fill-space"><?= e($s['name']) ?></div>
              </div>

              <!-- Student Mobile & Age -->
              <div class="input-row-double" style="grid-template-columns: 1.5fr 1fr;">
                <div class="input-row-single">
                  <div class="lbl-wrapper">
                    <span class="primary-lbl">સ્ટુડન્ટ નો મો.નં. :</span>
                    <span class="secondary-lbl">( Student Mobile No. )</span>
                  </div>
                  <div class="input-fill-space"><?= e($s['student_mobile']) ?></div>
                </div>
                <div class="input-row-single">
                  <div class="lbl-wrapper">
                    <span class="primary-lbl">ઉંમર:</span>
                    <span class="secondary-lbl">( Age )</span>
                  </div>
                  <div class="input-fill-space"><?= e($s['age'] ?: '') ?></div>
                </div>
              </div>

              <!-- Husband / Father Mobile -->
              <div class="input-row-single">
                <div class="lbl-wrapper">
                  <span class="primary-lbl">પતિ/પિતા નો મો.નં. :</span>
                  <span class="secondary-lbl">( Husband / Father Mobile No. )</span>
                </div>
                <div class="input-fill-space"><?= e($s['husband_father_mobile'] ?: '') ?></div>
              </div>

              <!-- House Mobile -->
              <div class="input-row-single">
                <div class="lbl-wrapper">
                  <span class="primary-lbl">ઘર નો મો.નં. :</span>
                  <span class="secondary-lbl">( House Mobile No. )</span>
                </div>
                <div class="input-fill-space"><?= e($s['home_mobile'] ?: '') ?></div>
              </div>

              <!-- Education & Bench Time -->
              <div class="input-row-double">
                <div class="input-row-single">
                  <div class="lbl-wrapper">
                    <span class="primary-lbl">અભ્યાસ :</span>
                    <span class="secondary-lbl">( Education )</span>
                  </div>
                  <div class="input-fill-space"><?= e($s['education'] ?: '') ?></div>
                </div>
                <div class="input-row-single">
                  <div class="lbl-wrapper">
                    <span class="primary-lbl">બેન્ચ:</span>
                    <span class="secondary-lbl">( Bench Time )</span>
                  </div>
                  <div class="input-fill-space"><?= e($s['batch_name'] ?: 'Any') ?></div>
                </div>
              </div>
            </div>

            <!-- Passport Photo & Specimen Signature Column -->
            <div class="student-photo-sign-right">
              <!-- Passport Photo Frame -->
              <div class="photo-frame-standard">
                <?php if ($s['photo_path']): ?>
                  <img src="<?= e(url($s['photo_path'])) ?>" alt="Student Photo">
                <?php else: ?>
                  <svg class="photo-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                  </svg>
                  <div class="photo-notice-text">
                    ફોટો<br>ફરજીયાત<br>રહેશે.
                  </div>
                <?php endif; ?>
              </div>

              <!-- Official Specimen Signature Box -->
              <div class="specimen-signature-box">
                <div class="specimen-sign-label">
                  સહી <span>( Signature )</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Full-Width Address Section -->
          <div class="full-width-address-block">
            <div class="input-row-single address-row">
              <div class="lbl-wrapper">
                <span class="primary-lbl">પૂરું સરનામું :</span>
                <span class="secondary-lbl">( Address )</span>
              </div>
              <div class="address-lines-container">
                <div class="input-fill-space"><?= e($s['address']) ?></div>
                <div class="input-fill-space"></div>
              </div>
            </div>
          </div>

          <!-- Questionnaire Card -->
          <div class="section-questionnaire">
            <div class="q-block-1">
              <div class="q-label">
                સીલાઈ કામ કરતા આવડે છે?
                <span>( Do You Know Sewing? )</span>
              </div>
              <div class="choices-row">
                <div class="choice-item">
                  <span>હા</span>
                  <span class="choice-sub">( Yes )</span>
                  <div class="choice-box"><?= $s['knows_sewing'] ? '✓' : '' ?></div>
                </div>
                <div class="choice-item">
                  <span>ના</span>
                  <span class="choice-sub">( No )</span>
                  <div class="choice-box"><?= !$s['knows_sewing'] ? '✓' : '' ?></div>
                </div>
              </div>
            </div>

            <div class="q-block-2">
              <div class="q-label">
                હા હોય તો તમે શું સિલાઈ કામ કરેલું છે?
                <span>( If Yes, What Kind Of Sewing Work Have You Done? )</span>
              </div>
              <div class="input-fill-space q2-fill"><?= e($s['previous_sewing_work'] ?: '') ?></div>
            </div>
          </div>

          <!-- Expected Turn Date Card -->
          <div class="turn-date-box">
            <div class="turn-date-main">
              <span>વાારો આવવાની અંદાજિત તા.</span>
              <div class="date-fill-pill">
                <?= $s['expected_joining_date'] ? format_date($s['expected_joining_date']) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; / 202&nbsp;&nbsp;' ?>
              </div>
            </div>
            <div class="turn-date-note">
              (ત્યાર પછી એક થી બે મહિના લેટ પણ થઈ શકે છે.)
            </div>
          </div>

          <!-- Reference / Referral Source Section -->
          <div class="reference-section">
            <div class="relative-header-pill">
              તમને ક્લાસીસ વિશે માહિતી ક્યાંથી મળી? <span>( How Did You Hear About Us? )</span>
            </div>
            
            <div class="ref-top-options-grid">
              <div class="ref-option-item">
                <div class="choice-box"><?= $s['hear_about_us'] === 'Instagram' ? '✓' : '' ?></div>
                <div class="ref-label-wrap">
                  <span class="ref-main-lbl">ઇન્સ્ટાગ્રામ</span>
                  <span class="choice-sub">( Instagram )</span>
                </div>
              </div>
              <div class="ref-option-item">
                <div class="choice-box"><?= $s['hear_about_us'] === 'Facebook' ? '✓' : '' ?></div>
                <div class="ref-label-wrap">
                  <span class="ref-main-lbl">ફેસબુક</span>
                  <span class="choice-sub">( Facebook )</span>
                </div>
              </div>
              <div class="ref-option-item">
                <div class="choice-box"><?= $s['hear_about_us'] === 'Board/Banner' ? '✓' : '' ?></div>
                <div class="ref-label-wrap">
                  <span class="ref-main-lbl">બોર્ડ / બેનર</span>
                  <span class="choice-sub">( Board / Banner )</span>
                </div>
              </div>
            </div>

            <div class="ref-friend-block">
              <div class="ref-friend-header">
                <div class="choice-box"><?= ($s['hear_about_us'] === 'Relative/Friend' || !empty($s['relative_name'])) ? '✓' : '' ?></div>
                <span class="ref-main-lbl">રિલેટિવ / ફ્રેન્ડ્સ દ્વારા આવ્યા હોય તો વિગત લખો :</span>
                <span class="choice-sub">( If Referred By Relative / Friend )</span>
              </div>
              
              <div class="ref-inputs-double">
                <div class="input-row-single">
                  <div class="lbl-wrapper">
                    <span class="primary-lbl">રેફરન્સ આપનારનું નામ :</span>
                    <span class="secondary-lbl">( Reference Person Name )</span>
                  </div>
                  <div class="input-fill-space"><?= e($s['relative_name'] ?: '') ?></div>
                </div>
                <div class="input-row-single">
                  <div class="lbl-wrapper">
                    <span class="primary-lbl">મો.નં. :</span>
                    <span class="secondary-lbl">( Mobile No. )</span>
                  </div>
                  <div class="input-fill-space"><?= e($s['relative_mobile'] ?: '') ?></div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- PAGE 2: RULES & REGULATIONS -->
    <section class="a4-page">
      <!-- Premium Inset Border Frame -->
      <div class="page-frame">
        <!-- Corner Flourishes -->
        <div class="corner-flourish tl"></div>
        <div class="corner-flourish tr"></div>
        <div class="corner-flourish bl"></div>
        <div class="corner-flourish br"></div>

        <div class="page-content-wrapper">

          <div class="rules-page-container">
            <div class="rules-header-block">
              <div class="rules-banner">
                <div class="banner-ornament left"></div>
                <h2>નિયમો</h2>
                <div class="banner-ornament right"></div>
              </div>
              <div class="rules-subtitle">TERMS &amp; CONDITIONS</div>
            </div>

            <!-- Single Column 25 Uniform Rules Stack -->
            <div class="rules-wrapper">
              <div class="rule-row-card">
                <span class="rule-num">1.</span>
                <span class="rule-body">ફોર્મ ભરતી વખતે ભરેલી રકમ કોઈપણ સંજોગો નામ કેન્સલ થાય તો પરત મળશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">2.</span>
                <span class="rule-body">જે વ્યક્તિના નામ નું ફોર્મ ભરેલું હશે તે જ વ્યક્તિએ આવવાનું રહેશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">3.</span>
                <span class="rule-body">જો કોઈપણ સંજોગોમાં નામ ચેન્જ કરવાનું થાય તો પણ ફોર્મ અને ફોર્મની રકમ ફરીથી ભરવાની રહેશે પણ જે વ્યક્તિની જગ્યાએ તેના સ્થાન મળી શકશે તેને વેઈટીંગ માં રહેવું પડશે નહીં. પણ કેન્સલ કરાવ્યા પછી તે સ્થાન કોઈપણ વ્યક્તિને મળશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">4.</span>
                <span class="rule-body">એન્ટ્રી કર્યા ના પહેલા જ દિવસે એન્ટ્રી ફી ફરજીયાત આપવાની રહેશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">5.</span>
                <span class="rule-body">એન્ટ્રી કર્યા પછી જો આવવાનું બંધ કરવામાં આવે તો એન્ટ્રીમાં ભરેલી ફી પરત આપવામાં આવશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">6.</span>
                <span class="rule-body">ફીની બાકીની રકમ દસ દિવસમાં આપવાની રહેશે દસ દિવસ પછી જો આવવાનું બંધ કરવામાં આવે તો ભરેલી કોઈપણ રકમ પરત આપવામાં આવશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">7.</span>
                <span class="rule-body">કોઈપણ વહીવટ કરવા જે તે વ્યક્તિ નું ફોર્મ હોય તેને રૂબરૂ આવવાનું રહેશે બીજી કોઈ પણ વ્યક્તિનું સાંભળવામાં આવશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">8.</span>
                <span class="rule-body">વાારો આવ્યા નો ફોન આવે જે તે વ્યક્તિએ આવવાની હા પાડ્યા પછી તે દિવસે ન આવે તો તે દિવસ તેનામાં ગણવામાં આવશે તેમ જો બે દિવસ ન આવે તો તેનું ફોર્મ રદ કરવામાં આવશે પછી તેણે ફરીથી ફોર્મ અને ફોર્મની રકમ ભરવાની રહેશે તેમજ વેઈટીંગમાં રહેવાનું રહેશે</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">9.</span>
                <span class="rule-body">વાારો આવ્યા પછી એકવાર લેટ કરાવી શકાશે પરંતુ બીજીવાર વારો આવે એટલે ફરજીયાત આવવાનું રહેશે. અને જો ત્યારે પણ ન આવે તો ફોર્મ કેન્સલ કરવામાં આવશે તથા ભરેલી ફોર્મની રકમ પરત કરવામાં આવશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">10.</span>
                <span class="rule-body">16 વર્ષ થી નીચેની તેમજ 45 વર્ષથી ઉપરની બહેનોને શીખવવામાં આવશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">11.</span>
                <span class="rule-body">જે-તે વ્યક્તિનો ફોન નંબર આપ્યો હોય અને તે વ્યક્તિ ફોન પર જ નામ કેન્સલ કરાવી નાખશે તો પણ નામ કેન્સલ થઈ જશે પછી કોઈ તકરાર કરવી નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">12.</span>
                <span class="rule-body">જો કોઈ અગમ્ય કારણસર વચ્ચે અટકા વવાની ફરજ પડે તો તેની જાણ કર્યા ના ત્રીજા દિવસથી એના બાકી બચતા દિવસ ને 1.5 મહિના પછી આપવામાં આવશે પરંતુ ભરેલી રકમ પરત આપવામાં આવશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">13.</span>
                <span class="rule-body">શીખવા માટે ફૂલ સાઇઝ નું કાપડ લાવવાનું રહેશે. ટુકડામાં કાપડ ચાલશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">14.</span>
                <span class="rule-body">પહેલા બે જોડી ડ્રેસમાં મોટી સાઈઝ નું માપ જ ચાલશે નાની સાઈઝ નું માપ લાવવું નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">15.</span>
                <span class="rule-body">બેન્ચ શરૂ થાય ત્યારે સમયસર આવવું અને બેન્ચ પૂરી થાય ત્યારે તરત જ મશીન ખાલી કરવાનું રહેશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">16.</span>
                <span class="rule-body">દર રવિવારે રજા રહેશે રવિવાર સાથે જ 45 દિવસ ગણવામાં આવશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">17.</span>
                <span class="rule-body">તમારી કોઈ પણ કારણે પાડેલી રજા પરત આપવામાં આવશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">18.</span>
                <span class="rule-body">અમારા પર્સનલ કારણસર કે તહેવાર નિમિત્તે પાડેલી રજા અમારે આપવાની રહેશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">19.</span>
                <span class="rule-body">45 દિવસ ઉપર એક પણ દિવસ આપવામાં આવશે નહીં વધારે દિવસ ભરવાની ફી અલગથી આપવાની રહેશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">20.</span>
                <span class="rule-body">લેડીસ ટેલર્સ ના કામ સિવાય નું બીજું કાંઈ પણ કામ શીખવવામાં આવશે નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">21.</span>
                <span class="rule-body">દરેક કપડા બોડી મેજરમેન્ટ થી શીખવવામાં આવશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">22.</span>
                <span class="rule-body">ક્લાસ ની કોઈ સ્પેશિયલ વસ્તુ એકસ્ટ્રા બેન્ચ તેમજ એકસ્ટ્રા ફી ચૂકવીને શીખવવાની રહેશે તે રેગ્યુલર બેન્ચમાં શીખવવામાં આવશે નહીં તેમજ તે જૂના સ્ટુડન્ટ માટે જ રહેશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">23.</span>
                <span class="rule-body">બીજાને સિલાઈકામ શીખવાડતા હોય તેવા વ્યક્તિએ ક્લાસમાં શીખવા આવવું નહીં તો પણ ગેરકાયદેસર રીતે એન્ટ્રી કરશે અને પકડાઈ જશે તો તેની ભરેલી ફી પરત મળશે નહીં અને તેને જાણ થાય ત્યારથી જ ક્લાસ માંથી કાઢી મુકવામાં આવશે.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">24.</span>
                <span class="rule-body">સીવણ ક્લાસ માં ઑડિયો તેમજ વિડીયો રેકોર્ડીંગ કરવું નહીં.</span>
              </div>
              <div class="rule-row-card">
                <span class="rule-num">25.</span>
                <span class="rule-body">ક્લાસમાં શીખ્યા પછી કોઈ કલાસીસ ચાલુ કરે તો તેને સીવણ ક્લાસમાં કંઈ પણ પૂછવા આવવાનો હક રહેશે નહીં.</span>
              </div>
            </div>

            <!-- Caution Notice Banner -->
            <div class="caution-pill-banner">
              <span>ફોર્મ વ્યવસ્થિત વાંચીને સમજી વિચારીને ભરવું પછી તેના નિયમ વિરુદ્ધ કંઈપણ સાંભળવામાં આવશે નહીં.</span>
            </div>
          </div>

          <!-- Signature Footer Section -->
          <footer class="footer-signatures-grid">
            <div class="signature-field">
              <div class="signature-line"></div>
              <div class="signature-label">સ્ટુડન્ટ ની સહી.</div>
            </div>
            <div class="signature-field">
              <div class="signature-line"></div>
              <div class="signature-label"><?= e($s['guardian_signature_name'] ?: 'ઘરના વડીલ ની સહી.') ?></div>
            </div>
            <div class="signature-field">
              <div class="signature-line"></div>
              <div class="signature-label"><?= e($s['guardian_relation'] ? 'સંબંધ : ' . $s['guardian_relation'] : 'સંબંધ') ?></div>
            </div>
          </footer>

        </div>
      </div>
    </section>

  </div>

</body>
</html>
