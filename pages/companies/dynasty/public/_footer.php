
<footer class="dy-foot">
    <div class="dy-container dy-foot-inner">
        <div>&copy; <?php echo date('Y'); ?> Dynasty — Immobilier de Lysoria.</div>
        <div>Estimations indicatives · Site RP</div>
    </div>
</footer>

<script>
/* Bascule de thème, préférence mémorisée en localStorage. */
(function () {
    var btn = document.getElementById('dyTheme');
    if (!btn) return;
    var icon = btn.querySelector('i');
    function refresh() {
        var dark = document.documentElement.getAttribute('data-theme') !== 'light';
        icon.className = dark ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
    }
    refresh();
    btn.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('dy-theme', next); } catch (e) {}
        refresh();
        // Le rendu de la carte dépend de couleurs CSS : on la laisse se redessiner.
        if (window.__dyMap) window.__dyMap.draw();
    });
})();
</script>
</body>
</html>
