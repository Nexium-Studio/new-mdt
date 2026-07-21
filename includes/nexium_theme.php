<?php
/**
 * Thème clair / sombre partagé — charte graphique Nexium Studio.
 *
 * Le choix de l'utilisateur est mémorisé dans localStorage ('nexium-theme') et
 * appliqué sur l'attribut data-theme de <html>. Comme la clé est identique sur
 * toutes les pages, activer le mode clair (ou sombre) sur une page bascule
 * automatiquement toutes les autres.
 *
 * Utilisation dans une page :
 *   1. <head> tout en haut  : nexium_theme_head($defautTheme);   // évite le flash
 *   2. dans la barre / coin : nexium_theme_toggle();             // le bouton
 *   3. avant </body>        : nexium_theme_script();             // la bascule
 *   4. CSS                  : echo nexium_theme_toggle_css();    // styles du bouton
 */

if (!function_exists('nexium_theme_head')) {

    /**
     * Script à placer le plus tôt possible dans <head> : applique le thème
     * mémorisé (ou le défaut serveur) avant le premier rendu, sans clignotement.
     */
    function nexium_theme_head(string $default = 'dark'): void
    {
        $default = in_array($default, ['dark', 'light', 'auto'], true) ? $default : 'dark';
        ?>
<script>
(function () {
    var def = <?php echo json_encode($default); ?>;
    var saved = null;
    try { saved = localStorage.getItem('nexium-theme'); } catch (e) {}
    var t = (saved === 'light' || saved === 'dark') ? saved : def;
    if (t === 'auto') {
        t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
    }
    document.documentElement.setAttribute('data-theme', t);
})();
</script>
<?php
    }

    /**
     * Le bouton de bascule (icône soleil / lune selon le thème courant).
     * Nécessite Font Awesome. Ajoutez la classe voulue via $extraClass.
     */
    function nexium_theme_toggle(string $extraClass = ''): void
    {
        $cls = trim('theme-toggle ' . $extraClass);
        ?>
<button type="button" class="<?php echo htmlspecialchars($cls); ?>" id="themeToggle"
        aria-label="Basculer entre thème clair et sombre"
        title="Basculer entre thème clair et sombre">
    <i class="fa-solid fa-sun theme-toggle-sun" aria-hidden="true"></i>
    <i class="fa-solid fa-moon theme-toggle-moon" aria-hidden="true"></i>
</button>
<?php
    }

    /**
     * Script de bascule + persistance. À inclure une fois avant </body>.
     */
    function nexium_theme_script(): void
    {
        ?>
<script>
(function () {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var cur  = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        var next = cur === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('nexium-theme', next); } catch (e) {}
    });
    // Synchronise l'onglet si le thème change ailleurs (autre onglet / page).
    window.addEventListener('storage', function (e) {
        if (e.key === 'nexium-theme' && (e.newValue === 'light' || e.newValue === 'dark')) {
            document.documentElement.setAttribute('data-theme', e.newValue);
        }
    });
})();
</script>
<?php
    }

    /**
     * Styles du bouton de bascule, communs à toutes les pages.
     * À concaténer dans le <style> de la page.
     */
    function nexium_theme_toggle_css(): string
    {
        return <<<CSS
        .theme-toggle {
            width: 40px; height: 40px;
            display: inline-grid; place-items: center;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text-2);
            cursor: pointer;
            font-size: .95rem;
            line-height: 1;
            transition: color .15s, border-color .15s, background .15s;
            flex-shrink: 0;
        }
        .theme-toggle:hover { color: var(--text); border-color: var(--border-strong); }
        .theme-toggle i { pointer-events: none; }
        :root[data-theme="dark"]  .theme-toggle-moon { display: none; }
        :root[data-theme="light"] .theme-toggle-sun  { display: none; }
CSS;
    }
}
