<?php if (is_student_logged_in()): ?>
</div> <!-- End container-app -->

<?php
$stNav = current_student();
$isJoinedNav = !empty($stNav['entry_date']) || !empty($stNav['joined_batch_id']) || in_array($stNav['status'] ?? '', ['active', 'extended', 'course_completed', 'joining_confirmed']);
$isCompletedNav = ($stNav['status'] ?? '') === 'course_completed';
?>

<!-- Mobile Bottom Navigation Bar -->
<nav class="mobile-bottom-nav">
    <a href="/public/student/dashboard.php" class="nav-item <?= ($activePage === 'dashboard') ? 'active' : '' ?>">
        <i class="bi bi-grid-fill"></i>
        <span>Dashboard</span>
    </a>
    <?php if ($isCompletedNav): ?>
        <a href="/public/student/certificate.php" target="_blank" class="nav-item">
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>
    <?php elseif (!$isJoinedNav): ?>
        <a href="/public/student/queue.php" class="nav-item <?= ($activePage === 'queue') ? 'active' : '' ?>">
            <i class="bi bi-hourglass-split"></i>
            <span>Waiting</span>
        </a>
    <?php endif; ?>
    <a href="/public/student/fees.php" class="nav-item <?= ($activePage === 'fees') ? 'active' : '' ?>">
        <i class="bi bi-wallet2"></i>
        <span>Fees</span>
    </a>
    <a href="/public/student/requests.php" class="nav-item <?= ($activePage === 'requests') ? 'active' : '' ?>">
        <i class="bi bi-pencil-square"></i>
        <span>Requests</span>
    </a>
    <a href="/public/student/profile.php" class="nav-item <?= ($activePage === 'profile') ? 'active' : '' ?>">
        <i class="bi bi-person-fill"></i>
        <span>Profile</span>
    </a>
</nav>
<?php endif; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>