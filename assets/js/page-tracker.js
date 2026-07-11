/**
 * page-tracker.js v2 — Stockage en base de données (compatible FiveM CEF)
 *
 * Pourquoi pas localStorage :
 *   FiveM CEF réinitialise le localStorage à chaque ouverture de la tablette.
 *   On utilise à la place un endpoint PHP qui stocke la dernière page dans la
 *   colonne `last_page` de `remember_tokens` — persistant côté serveur.
 *
 * ── INSTALLATION ──
 * 1. Ajouter dans includes/footer.php (ou juste avant </body> de chaque page) :
 *      <script src="<?php echo ASSETS_PATH; ?>/js/page-tracker.js"></script>
 *
 * 2. S'assurer que remember_auth.php est à jour (version avec last_page)
 *
 * ── FONCTIONNEMENT ──
 * - Chaque page protégée envoie son URL en POST à remember_auth.php?save_page=1
 * - Quand la tablette s'ouvre, PHP lit last_page depuis la DB et redirige
 *   AVANT même que cette page se charge (voir le code à ajouter dans dashboard.php)
 */

(function () {
    'use strict';

    const SAVE_ENDPOINT = '/includes/remember_auth.php?save_page=1';

    /* Pages à NE PAS mémoriser */
    const EXCLUDED = [
        '/login.php', '/register.php', '/logout.php',
        '/index.php', '/forgot-password.php', '/auth/',
        '/discord-', '/discord_',
    ];

    const currentPath = window.location.pathname;
    const currentUrl  = window.location.href;

    function isExcluded(path) {
        return EXCLUDED.some(p => path.includes(p));
    }

    /* Envoi silencieux de la page courante au serveur */
    function savePage() {
        if (isExcluded(currentPath)) return;

        try {
            const fd = new FormData();
            fd.append('url', currentUrl);

            // sendBeacon = non-bloquant, survit même si la page change juste après
            if (navigator.sendBeacon) {
                navigator.sendBeacon(SAVE_ENDPOINT, fd);
            } else {
                fetch(SAVE_ENDPOINT, { method: 'POST', body: fd, keepalive: true })
                    .catch(() => {});
            }
        } catch (e) {}
    }

    /* Sauvegarder à chaque chargement de page */
    savePage();

    /* API publique (utile pour logout) */
    window.PageTracker = {
        save: savePage,
        clear: function () {
            try {
                const fd = new FormData();
                fd.append('url', '');
                navigator.sendBeacon ? navigator.sendBeacon(SAVE_ENDPOINT, fd)
                    : fetch(SAVE_ENDPOINT, { method: 'POST', body: fd }).catch(() => {});
            } catch (e) {}
        }
    };

})();