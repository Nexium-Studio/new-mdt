    </div><!-- /li-container -->
</main>

<footer class="li-footer">
    <div class="li-container li-footer-inner">
        <div class="li-footer-brand">
            <div class="li-footer-logo">
                <span class="li-wm-life">Life</span><span class="li-wm-invader">Invader</span>
            </div>
            <p class="li-footer-tagline">Le journal de référence à Lysoria — Indépendant, libre, sans concession.</p>
            <div class="li-footer-social">
                <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                <a href="#" aria-label="Facebook"><i class="fab fa-facebook"></i></a>
                <a href="#" aria-label="RSS"><i class="fas fa-rss"></i></a>
            </div>
        </div>
        <div class="li-footer-col">
            <div class="li-footer-title">Rubriques</div>
            <?php foreach (array_slice($LI_NAV_CATS, 0, 6) as $c): ?>
            <a href="<?php echo li_pub_url('category.php?slug='.urlencode($c['slug'])); ?>"><?php echo htmlspecialchars($c['nom']); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="li-footer-col">
            <div class="li-footer-title">À propos</div>
            <a href="#">La rédaction</a>
            <a href="#">Charte éditoriale</a>
            <a href="#">Mentions légales</a>
            <a href="<?php echo li_pub_url('communiques.php'); ?>">Communiqués officiels</a>
        </div>
        <div class="li-footer-col">
            <div class="li-footer-title">Nous contacter</div>
            <a href="mailto:redaction@lifeinvader.lys"><i class="fas fa-envelope"></i> redaction@lifeinvader.lys</a>
            <a href="#"><i class="fas fa-phone"></i> +1 (555) LI-NEWS</a>
            <a href="#"><i class="fas fa-map-marker-alt"></i> Vinewood Boulevard, LS</a>
        </div>
    </div>
    <div class="li-footer-bottom">
        <div class="li-container">
            <span>© <?php echo date('Y'); ?> <strong>Life Invader Media Corp.</strong> · Tous droits réservés</span>
            <span class="li-footer-credit">Propulsé par <a href="<?php echo SITE_URL; ?>">Nexium MDT</a></span>
        </div>
    </div>
</footer>

<script>
(function() {
    /* ── Theme toggle ───────────────────────────────────────── */
    const t = localStorage.getItem('li-public-theme');
    if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    const btn = document.getElementById('liThemeToggle');
    if (btn) {
        const upd = () => {
            const cur = document.documentElement.getAttribute('data-theme') || 'light';
            btn.innerHTML = cur === 'dark' ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        };
        upd();
        btn.addEventListener('click', () => {
            const cur = document.documentElement.getAttribute('data-theme') || 'light';
            const next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('li-public-theme', next);
            upd();
        });
    }

    /* ── Indicateurs de scroll dans le menu de navigation ────── */
    const navWrap  = document.querySelector('.li-masthead-nav');
    const navInner = document.querySelector('.li-nav-inner');
    if (navWrap && navInner) {
        const updNav = () => {
            const sl = navInner.scrollLeft;
            const max = navInner.scrollWidth - navInner.clientWidth;
            navWrap.classList.toggle('li-nav-can-scroll-left',  sl > 4);
            navWrap.classList.toggle('li-nav-can-scroll-right', sl < max - 4);
        };
        navInner.addEventListener('scroll', updNav, { passive: true });
        window.addEventListener('resize', updNav);
        // Active item visible au chargement
        requestAnimationFrame(() => {
            const active = navInner.querySelector('.li-nav-item.active');
            if (active) {
                const r  = active.getBoundingClientRect();
                const cr = navInner.getBoundingClientRect();
                if (r.left < cr.left || r.right > cr.right) {
                    navInner.scrollLeft = active.offsetLeft - 24;
                }
            }
            updNav();
        });
        // Scroll horizontal via molette (sans Shift) sur le menu
        navInner.addEventListener('wheel', (e) => {
            if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
                navInner.scrollLeft += e.deltaY;
                e.preventDefault();
            }
        }, { passive: false });
    }
})();
</script>

</body>
</html>
