        </main>
        <footer style="text-align: center; padding: 16px 12px; font-size: 12px; color: #888; border-top: 1px solid #edf2f7; margin-top: 32px;">
            &copy; <?= date('Y') ?> Devangi Sewing Classes &bull; All Rights Reserved &bull; <span style="color: #10b981; font-weight: 600;">● System Active (v1.0.1)</span>
        </footer>
    </div>
</div>
<script>
(function () {
    var shell = document.getElementById('appShell');
    var toggle = document.getElementById('sidebarToggle');
    var closeBtn = document.getElementById('sidebarClose');
    var backdrop = document.getElementById('sidebarBackdrop');

    function closeSidebar() {
        if (shell) shell.classList.remove('sidebar-open');
    }

    if (toggle && shell) {
        toggle.addEventListener('click', function () {
            shell.classList.toggle('sidebar-open');
        });
    }
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });
})();
</script>
</body>
</html>
