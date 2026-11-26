        </main>
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
