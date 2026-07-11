<?php
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive('LIFE_INVADER');

$__liAllPages = [
    ['file'=>'dashboard',      'label'=>'Salle de rédaction', 'icon'=>'fas fa-broadcast-tower', 'section'=>'Pilotage'],
    ['file'=>'articles',       'label'=>'Articles',           'icon'=>'fas fa-newspaper',       'section'=>'Rédaction'],
    ['file'=>'redaction',      'label'=>'Terrain & Missions', 'icon'=>'fas fa-map-marker-alt',  'section'=>''],
    ['file'=>'communiques',    'label'=>'Communiqués',        'icon'=>'fas fa-envelope-open-text','section'=>''],
    ['file'=>'effectifs',      'label'=>'Journalistes',       'icon'=>'fas fa-user-edit',       'section'=>'Ressources Humaines'],
    ['file'=>'prises-service', 'label'=>'Prises de service',  'icon'=>'fas fa-clock',           'section'=>''],
    ['file'=>'sanctions',      'label'=>'Sanctions',          'icon'=>'fas fa-gavel',           'section'=>''],
    ['file'=>'facturation',    'label'=>'Facturation',        'icon'=>'fas fa-file-invoice-dollar','section'=>'Finance'],
    ['file'=>'depenses',       'label'=>'Dépenses',           'icon'=>'fas fa-receipt',         'section'=>''],
];
$__liNavPages = getActiveNavItems('LIFE_INVADER', $__liAllPages);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Life Invader'); ?> · Life Invader News</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
/* ============================================================
   LIFE INVADER NEWS · Design System "Newsroom"
   CMS éditorial moderne — palette presse contemporaine
   ============================================================ */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

/* ── DARK (par défaut) ─────────────────────────────────────── */
:root, [data-theme="dark"] {
    /* Accent presse — bleu profond */
    --li:        #4f7cff;
    --li-h:      #3b62e5;
    --li-d:      #2845b9;
    --li-l:      #7c9aff;
    --li-soft:   rgba(79,124,255,.10);
    --li-softer: rgba(79,124,255,.05);
    --li-bd:     rgba(79,124,255,.22);
    --li-bd-h:   rgba(79,124,255,.38);

    /* Surfaces — gris ardoise éditorial */
    --bg:        #0a0d14;
    --bg-2:      #0e131c;
    --surface:   #131825;
    --surface-2: #181f2e;
    --surface-3: #1f2738;
    --elev:      #1b2233;

    /* Bordures */
    --bd:        rgba(148,163,184,.10);
    --bd-2:      rgba(148,163,184,.16);
    --bd-h:      rgba(148,163,184,.28);

    /* Texte */
    --tx:        #f1f5f9;
    --tx-2:      #cbd5e1;
    --tx-3:      #94a3b8;
    --tx-mute:   #64748b;
    --tx-faint:  #475569;

    /* Sémantique */
    --info:      #3b82f6;
    --warn:      #f59e0b;
    --danger:    #ef4444;
    --success:   #10b981;
    --neutral:   #94a3b8;

    /* Métriques */
    --r-sm:  8px;
    --r-md:  10px;
    --r-lg:  14px;
    --r-xl:  18px;
    --r-pill: 999px;
    --shadow-1: 0 1px 2px rgba(0,0,0,.25);
    --shadow-2: 0 4px 14px rgba(0,0,0,.30);
    --shadow-3: 0 12px 40px rgba(0,0,0,.40);
    --company-color: <?php echo $companyColor ?? '#4f7cff'; ?>;

    /* ── Rétrocompatibilité (anciennes inline-styles des pages héritées) ── */
    --tx2:    var(--tx-3);
    --mu:     var(--tx-mute);
    --bd2:    var(--bd-2);
    --s1:     var(--surface);
    --s2:     var(--surface-2);
    --li2:    var(--li-h);
    --li3:    var(--li-d);
    --li-glow:rgba(79,124,255,.32);
    --li-dim: var(--li-softer);
    --r:      var(--r-lg);
}

/* ── LIGHT MODE ────────────────────────────────────────────── */
[data-theme="light"] {
    --li:        #2563eb;
    --li-h:      #1d4ed8;
    --li-d:      #1e3a8a;
    --li-l:      #3b82f6;
    --li-soft:   rgba(37,99,235,.08);
    --li-softer: rgba(37,99,235,.04);
    --li-bd:     rgba(37,99,235,.22);
    --li-bd-h:   rgba(37,99,235,.40);

    --bg:        #f7f8fb;
    --bg-2:      #eef1f7;
    --surface:   #ffffff;
    --surface-2: #f8fafc;
    --surface-3: #f1f5f9;
    --elev:      #ffffff;

    --bd:        rgba(15,23,42,.08);
    --bd-2:      rgba(15,23,42,.14);
    --bd-h:      rgba(15,23,42,.22);

    --tx:        #0f172a;
    --tx-2:      #1e293b;
    --tx-3:      #475569;
    --tx-mute:   #64748b;
    --tx-faint:  #94a3b8;

    --shadow-1:  0 1px 2px rgba(15,23,42,.06);
    --shadow-2:  0 4px 14px rgba(15,23,42,.08);
    --shadow-3:  0 12px 40px rgba(15,23,42,.14);

    --tx2:    var(--tx-3);
    --mu:     var(--tx-mute);
    --bd2:    var(--bd-2);
    --s1:     var(--surface);
    --s2:     var(--surface-2);
    --li2:    var(--li-h);
    --li3:    var(--li-d);
    --li-glow:rgba(37,99,235,.22);
    --li-dim: var(--li-softer);
}

html, body { background: var(--bg); }
body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    color: var(--tx);
    overflow-x: hidden;
    min-height: 100vh;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    transition: background-color .2s ease, color .2s ease;
}
::selection { background: var(--li-soft); color: var(--li); }
a { color: inherit; text-decoration: none; }

/* ════════════════════════════════════════════════════════════
   SIDEBAR — newsroom navigation
═════════════════════════════════════════════════════════════ */
.company-sidebar {
    position: fixed; left: 0; top: 0; width: 264px; height: 100vh;
    background: var(--surface);
    border-right: 1px solid var(--bd);
    z-index: 1001; display: flex; flex-direction: column;
    overflow-y: auto; transition: transform .3s cubic-bezier(.4,0,.2,1);
}
.sidebar-header {
    padding: 1.2rem 1.25rem 1rem;
    border-bottom: 1px solid var(--bd);
    flex-shrink: 0;
}
.sidebar-brand { display: flex; align-items: center; gap: .75rem; }
.company-logo {
    width: 42px; height: 42px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--li), var(--li-d));
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; color: #fff;
    box-shadow: 0 4px 14px var(--li-glow);
}
.company-name {
    font-family: 'Playfair Display', serif;
    font-size: 1.15rem; font-weight: 800; color: var(--tx);
    letter-spacing: -.01em;
}
.company-subtitle {
    font-size: .64rem; color: var(--tx-mute);
    margin-top: .12rem; text-transform: uppercase;
    letter-spacing: .12em; font-weight: 600;
}

.sidebar-nav { padding: .8rem 0; flex: 1; }
.nav-section-title {
    padding: .85rem 1.25rem .35rem;
    font-size: .6rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .14em;
    color: var(--tx-faint);
}
.nav-item {
    display: flex; align-items: center; gap: .65rem;
    padding: .58rem 1.25rem;
    color: var(--tx-3); text-decoration: none;
    font-weight: 500; font-size: .85rem;
    border-left: 2.5px solid transparent;
    transition: all .15s ease;
}
.nav-item:hover {
    color: var(--tx); background: var(--li-softer);
    border-left-color: var(--li-bd);
}
.nav-item.active {
    color: var(--li); background: var(--li-soft);
    border-left-color: var(--li); font-weight: 700;
}
.nav-item i { width: 17px; text-align: center; font-size: .85rem; flex-shrink: 0; }
.nav-badge {
    margin-left: auto;
    background: var(--li-soft); color: var(--li);
    border-radius: 999px; font-size: .6rem; font-weight: 700;
    padding: .1rem .42rem;
}
.nav-badge.red {
    background: rgba(239,68,68,.14); color: #ef4444;
}
.nav-divider { height: 1px; background: var(--bd); margin: .6rem 1.25rem; }

.sidebar-user {
    padding: .85rem 1.25rem;
    border-top: 1px solid var(--bd);
    display: flex; align-items: center; gap: .7rem;
}
.sidebar-user-av {
    width: 34px; height: 34px;
    background: linear-gradient(135deg, var(--li), var(--li-d));
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: .82rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.sidebar-user-name { font-size: .82rem; font-weight: 700; color: var(--tx); }
.sidebar-user-role { font-size: .66rem; color: var(--tx-mute); }

/* ════════════════════════════════════════════════════════════
   TOPBAR & CONTENT
═════════════════════════════════════════════════════════════ */
.main-content {
    margin-left: 264px; min-height: 100vh;
    display: flex; flex-direction: column;
    background: var(--bg);
}
.company-topbar {
    position: sticky; top: 0; z-index: 100;
    background: var(--surface);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--bd);
    padding: .85rem 1.6rem;
    display: flex; justify-content: space-between; align-items: center; gap: 1rem;
}
.topbar-left { display: flex; align-items: center; gap: .85rem; }
.page-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.2rem; font-weight: 700; color: var(--tx);
    letter-spacing: -.01em;
}
.page-subtitle {
    font-size: .72rem; color: var(--tx-3);
    margin-top: .1rem;
}
.user-badge {
    padding: .42rem .9rem;
    background: var(--li-soft);
    border: 1px solid var(--li-bd);
    border-radius: 999px; font-weight: 600; font-size: .74rem;
    color: var(--li); display: flex; align-items: center; gap: .45rem;
    white-space: nowrap;
}

/* Theme toggle */
.theme-toggle {
    width: 36px; height: 36px;
    border-radius: 10px;
    border: 1px solid var(--bd);
    background: var(--surface-2);
    color: var(--tx-2);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all .15s ease;
    font-size: .9rem;
}
.theme-toggle:hover {
    border-color: var(--li-bd);
    color: var(--li);
    background: var(--li-soft);
}
[data-theme="dark"] .theme-toggle .icon-sun { display: inline-block; }
[data-theme="dark"] .theme-toggle .icon-moon { display: none; }
[data-theme="light"] .theme-toggle .icon-sun { display: none; }
[data-theme="light"] .theme-toggle .icon-moon { display: inline-block; }

.page-content { padding: 1.7rem 1.8rem; flex: 1; max-width: 1600px; width: 100%; }

/* ════════════════════════════════════════════════════════════
   PAGE HEADER
═════════════════════════════════════════════════════════════ */
.page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 1rem; margin-bottom: 1.4rem;
}
.page-header-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.55rem; font-weight: 700; color: var(--tx);
    letter-spacing: -.02em;
    display: flex; align-items: center; gap: .6rem;
}
.page-header-title i { color: var(--li); font-size: 1.3rem; }
.page-header-sub { font-size: .82rem; color: var(--tx-3); margin-top: .25rem; }
.page-header-actions { display: flex; gap: .55rem; flex-wrap: wrap; }

/* ════════════════════════════════════════════════════════════
   MOBILE HAMBURGER
═════════════════════════════════════════════════════════════ */
.mob-menu-btn {
    display: none; width: 36px; height: 36px;
    border: 1px solid var(--bd); border-radius: 9px;
    background: var(--surface-2); color: var(--tx-2);
    font-size: 1rem; cursor: pointer;
    align-items: center; justify-content: center;
    transition: all .15s; flex-shrink: 0;
}
.mob-menu-btn:hover { background: var(--li-soft); color: var(--li); }
.sidebar-backdrop {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.6); backdrop-filter: blur(4px); z-index: 1000;
}
.sidebar-backdrop.open { display: block; }

/* ════════════════════════════════════════════════════════════
   CARDS
═════════════════════════════════════════════════════════════ */
.card {
    background: var(--surface); border: 1px solid var(--bd);
    border-radius: var(--r-lg); padding: 1.3rem; margin-bottom: 1.2rem;
    transition: all .18s;
}
.card:hover { border-color: var(--bd-h); }
.card-tight { padding: 1rem; }

/* ════════════════════════════════════════════════════════════
   BUTTONS
═════════════════════════════════════════════════════════════ */
.btn {
    display: inline-flex; align-items: center; gap: .48rem;
    padding: .56rem 1.05rem; border-radius: var(--r-md);
    font-weight: 600; font-size: .82rem; cursor: pointer;
    text-decoration: none; border: 1px solid transparent;
    font-family: 'Inter', sans-serif;
    transition: all .14s ease;
    user-select: none; white-space: nowrap;
}
.btn:hover { transform: translateY(-1px); }
.btn:active { transform: translateY(0); }

.btn-primary {
    background: linear-gradient(135deg, var(--li), var(--li-d));
    color: #fff; box-shadow: 0 3px 12px var(--li-glow);
}
.btn-primary:hover { box-shadow: 0 5px 18px var(--li-glow); }

.btn-secondary {
    background: var(--surface-2); border-color: var(--bd);
    color: var(--tx-2);
}
.btn-secondary:hover { background: var(--surface-3); border-color: var(--bd-h); color: var(--tx); }

.btn-ghost {
    background: transparent; border-color: var(--bd);
    color: var(--tx-3);
}
.btn-ghost:hover { background: var(--surface-2); color: var(--tx); }

.btn-success { background: rgba(16,185,129,.10); border-color: rgba(16,185,129,.28); color: #10b981; }
.btn-success:hover { background: rgba(16,185,129,.18); }
.btn-warn { background: rgba(245,158,11,.10); border-color: rgba(245,158,11,.28); color: #f59e0b; }
.btn-warn:hover { background: rgba(245,158,11,.18); }
.btn-danger { background: rgba(239,68,68,.10); border-color: rgba(239,68,68,.28); color: #ef4444; }
.btn-danger:hover { background: rgba(239,68,68,.18); }
.btn-purple { background: rgba(139,92,246,.10); border-color: rgba(139,92,246,.28); color: #8b5cf6; }
.btn-purple:hover { background: rgba(139,92,246,.18); }
.btn-cyan { background: rgba(6,182,212,.10); border-color: rgba(6,182,212,.28); color: #06b6d4; }
.btn-cyan:hover { background: rgba(6,182,212,.18); }
.btn-sm { padding: .32rem .72rem; font-size: .74rem; border-radius: var(--r-sm); }
.btn-icon { padding: .35rem; width: 32px; height: 32px; justify-content: center; border-radius: var(--r-sm); }

/* ════════════════════════════════════════════════════════════
   FORMS
═════════════════════════════════════════════════════════════ */
.form-grid  { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
.form-grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
.form-full  { grid-column: 1 / -1; }
.form-group { display: flex; flex-direction: column; gap: .32rem; }
.form-label {
    font-size: .7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: var(--tx-3);
}
.form-input, .form-select, .form-textarea {
    width: 100%; padding: .62rem .85rem;
    background: var(--surface-2); border: 1px solid var(--bd);
    border-radius: var(--r-md); color: var(--tx);
    font-family: 'Inter', sans-serif; font-size: .85rem;
    transition: all .14s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none; border-color: var(--li);
    box-shadow: 0 0 0 3px var(--li-soft);
    background: var(--surface);
}
.form-input::placeholder, .form-textarea::placeholder { color: var(--tx-mute); }
.form-select option { background: var(--surface); color: var(--tx); }
.form-textarea { resize: vertical; min-height: 90px; line-height: 1.55; }
.form-editor { min-height: 220px; font-size: .88rem; line-height: 1.7; }
.form-hint { font-size: .7rem; color: var(--tx-mute); margin-top: .15rem; }

/* ════════════════════════════════════════════════════════════
   WYSIWYG RICH EDITOR
═════════════════════════════════════════════════════════════ */
.rt-editor {
    border: 1px solid var(--bd); border-radius: var(--r-md);
    background: var(--surface-2);
    overflow: hidden;
    transition: all .14s;
}
.rt-editor.focused {
    border-color: var(--li);
    box-shadow: 0 0 0 3px var(--li-soft);
}
.rt-toolbar {
    display: flex; flex-wrap: wrap; gap: .15rem;
    padding: .45rem .55rem;
    background: var(--surface);
    border-bottom: 1px solid var(--bd);
    position: sticky; top: 0; z-index: 5;
}
.rt-btn {
    width: 30px; height: 30px;
    border: 1px solid transparent;
    background: transparent;
    border-radius: 7px;
    color: var(--tx-3);
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .78rem;
    transition: all .12s;
}
.rt-btn:hover { background: var(--li-soft); color: var(--li); }
.rt-btn.active { background: var(--li-soft); color: var(--li); border-color: var(--li-bd); }
.rt-sep { width: 1px; background: var(--bd); margin: .25rem .2rem; }
.rt-select {
    height: 30px; padding: 0 .55rem;
    background: transparent; border: 1px solid transparent;
    border-radius: 7px;
    color: var(--tx-3); font-size: .74rem;
    cursor: pointer;
    font-family: 'Inter', sans-serif;
}
.rt-select:hover { background: var(--li-soft); color: var(--li); }
.rt-color-wrap { position: relative; display: inline-flex; }
.rt-color-input {
    position: absolute; inset: 0; opacity: 0; cursor: pointer;
}
.rt-content {
    min-height: 240px; max-height: 600px; overflow-y: auto;
    padding: 1rem 1.1rem;
    background: var(--surface-2);
    color: var(--tx);
    font-size: .9rem; line-height: 1.75;
    outline: none;
}
.rt-content:empty::before {
    content: attr(data-placeholder);
    color: var(--tx-mute);
    pointer-events: none;
}
.rt-content h1 { font-family: 'Playfair Display', serif; font-size: 1.8rem; margin: .7rem 0 .5rem; font-weight: 700; }
.rt-content h2 { font-family: 'Playfair Display', serif; font-size: 1.45rem; margin: .65rem 0 .45rem; font-weight: 700; }
.rt-content h3 { font-size: 1.18rem; margin: .55rem 0 .4rem; font-weight: 700; }
.rt-content p { margin: .55rem 0; }
.rt-content ul, .rt-content ol { padding-left: 1.5rem; margin: .55rem 0; }
.rt-content li { margin-bottom: .2rem; }
.rt-content a { color: var(--li); text-decoration: underline; }
.rt-content blockquote {
    border-left: 3px solid var(--li);
    padding: .35rem 1rem;
    margin: .65rem 0;
    color: var(--tx-2); font-style: italic;
    background: var(--li-softer);
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
}
.rt-content img { max-width: 100%; height: auto; border-radius: var(--r-md); margin: .6rem 0; }
.rt-content hr { border: none; height: 1px; background: var(--bd); margin: 1rem 0; }

/* ════════════════════════════════════════════════════════════
   MODALS
═════════════════════════════════════════════════════════════ */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.78);
    backdrop-filter: blur(6px);
    z-index: 9999;
    display: none; align-items: flex-start; justify-content: center;
    padding: 1.2rem 1rem; overflow-y: auto;
}
.modal-overlay:not(.hidden) { display: flex; }
.hidden { display: none !important; }
.modal {
    background: var(--surface);
    border: 1px solid var(--bd);
    border-top: 3px solid var(--li);
    border-radius: 16px;
    width: 100%; max-width: 720px;
    display: flex; flex-direction: column;
    box-shadow: var(--shadow-3);
    animation: modalIn .2s ease;
    margin: auto 0;
}
.modal-xl { max-width: 1100px; }
.modal-lg { max-width: 920px; }
.modal-sm { max-width: 460px; }
@keyframes modalIn { from { opacity: 0; transform: translateY(12px) scale(.98); } to { opacity: 1; transform: none; } }
.modal-header {
    position: sticky; top: 0; z-index: 10;
    padding: 1rem 1.4rem;
    border-bottom: 1px solid var(--bd);
    display: flex; justify-content: space-between; align-items: center;
    background: var(--surface);
    border-radius: 16px 16px 0 0;
    flex-shrink: 0;
}
.modal-header h2 {
    font-family: 'Playfair Display', serif;
    font-size: 1.05rem; font-weight: 700; color: var(--tx);
    display: flex; align-items: center; gap: .5rem;
}
.modal-header h2 i { color: var(--li); }
.modal-close {
    width: 30px; height: 30px;
    border: 1px solid var(--bd); border-radius: 8px;
    background: var(--surface-2); color: var(--tx-3);
    font-size: .92rem; cursor: pointer; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    transition: all .14s;
}
.modal-close:hover { background: rgba(239,68,68,.14); color: #ef4444; border-color: rgba(239,68,68,.3); }
.modal-body { padding: 1.4rem; overflow: visible; flex: 1; }
.modal-footer {
    position: sticky; bottom: 0; z-index: 10;
    padding: .9rem 1.4rem;
    border-top: 1px solid var(--bd);
    display: flex; justify-content: flex-end; gap: .5rem;
    background: var(--surface);
    border-radius: 0 0 16px 16px;
}


/* ════════════════════════════════════════════════════════════
   ALERTS
═════════════════════════════════════════════════════════════ */
.alert {
    padding: .85rem 1.05rem; border-radius: var(--r-md);
    margin-bottom: 1.2rem;
    display: flex; align-items: center; gap: .55rem;
    font-weight: 600; font-size: .85rem;
    border: 1px solid;
}
.alert-success { background: rgba(16,185,129,.10); border-color: rgba(16,185,129,.28); color: #10b981; }
.alert-error   { background: rgba(239,68,68,.10);  border-color: rgba(239,68,68,.28);  color: #ef4444; }
.alert-warning { background: rgba(245,158,11,.10); border-color: rgba(245,158,11,.28); color: #f59e0b; }
.alert-info    { background: rgba(79,124,255,.10); border-color: rgba(79,124,255,.28); color: var(--li); }

/* ════════════════════════════════════════════════════════════
   STATS (KPI)
═════════════════════════════════════════════════════════════ */
.li-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: .9rem; margin-bottom: 1.4rem;
}
.li-stat {
    background: var(--surface); border: 1px solid var(--bd);
    border-radius: var(--r-lg); padding: 1.1rem 1.2rem;
    display: flex; align-items: center; gap: .8rem;
    transition: all .18s; position: relative; overflow: hidden;
}
.li-stat:hover {
    border-color: var(--li-bd);
    transform: translateY(-2px);
    box-shadow: var(--shadow-2);
}
.li-stat-ico {
    width: 42px; height: 42px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.08rem; color: #fff;
    background: linear-gradient(135deg, var(--li), var(--li-d));
    flex-shrink: 0;
}
.li-stat-ico.ico-amber  { background: linear-gradient(135deg, #f59e0b, #d97706); }
.li-stat-ico.ico-violet { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
.li-stat-ico.ico-cyan   { background: linear-gradient(135deg, #06b6d4, #0891b2); }
.li-stat-ico.ico-emerald{ background: linear-gradient(135deg, #10b981, #059669); }
.li-stat-ico.ico-red    { background: linear-gradient(135deg, #ef4444, #dc2626); }
.li-stat-ico.ico-slate  { background: linear-gradient(135deg, #64748b, #475569); }
.li-stat-val {
    font-family: 'Playfair Display', serif;
    font-size: 1.4rem; font-weight: 700; color: var(--tx);
    line-height: 1;
}
.li-stat-lbl {
    font-size: .68rem; color: var(--tx-3);
    margin-top: .26rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
}
.li-stat-sub {
    font-size: .72rem; color: var(--li);
    margin-top: .14rem; font-weight: 600;
}

/* ════════════════════════════════════════════════════════════
   TABLE
═════════════════════════════════════════════════════════════ */
.li-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.li-table th {
    text-align: left; padding: .75rem .95rem;
    background: var(--surface-2);
    border-bottom: 2px solid var(--bd-2);
    font-size: .65rem; text-transform: uppercase;
    letter-spacing: .08em; color: var(--tx-3); font-weight: 700;
}
.li-table td {
    padding: .78rem .95rem;
    border-bottom: 1px solid var(--bd);
    color: var(--tx); vertical-align: middle;
}
.li-table tbody tr:hover td { background: var(--li-softer); }
.li-table tbody tr:last-child td { border-bottom: none; }

/* ════════════════════════════════════════════════════════════
   BADGES
═════════════════════════════════════════════════════════════ */
.badge {
    display: inline-flex; align-items: center; gap: .28rem;
    padding: .22rem .6rem; border-radius: 999px;
    font-size: .65rem; font-weight: 700; border: 1px solid;
    white-space: nowrap;
}
.badge-soft     { background: var(--surface-2); color: var(--tx-3); border-color: var(--bd-2); }
.badge-actif    { background: var(--li-soft); color: var(--li); border-color: var(--li-bd); }
.badge-acte_final { background: linear-gradient(135deg, rgba(245,158,11,.16), rgba(245,158,11,.08)); color: #f59e0b; border-color: rgba(245,158,11,.32); }
.badge-brouillon { background: rgba(100,116,139,.12); color: #94a3b8; border-color: rgba(100,116,139,.28); }
.badge-revision  { background: rgba(245,158,11,.12); color: #f59e0b; border-color: rgba(245,158,11,.28); }
.badge-publie    { background: rgba(16,185,129,.12); color: #10b981; border-color: rgba(16,185,129,.28); }
.badge-refuse    { background: rgba(239,68,68,.12);  color: #ef4444; border-color: rgba(239,68,68,.28); }
.badge-archive   { background: rgba(71,85,105,.12);  color: #64748b; border-color: rgba(71,85,105,.28); }
.badge-attente   { background: rgba(245,158,11,.12); color: #f59e0b; border-color: rgba(245,158,11,.28); }
.badge-normale   { background: var(--li-soft); color: var(--li); border-color: var(--li-bd); }
.badge-importante{ background: rgba(245,158,11,.12); color: #f59e0b; border-color: rgba(245,158,11,.28); }
.badge-urgente   { background: rgba(239,68,68,.12); color: #ef4444; border-color: rgba(239,68,68,.28); }
.badge-flash     { background: rgba(239,68,68,.22); color: #fff; border-color: #ef4444; animation: bFlash 1.2s ease-in-out infinite; }
@keyframes bFlash { 0%, 100% { opacity: 1; } 50% { opacity: .58; } }
.badge-assigne   { background: var(--li-soft); color: var(--li); border-color: var(--li-bd); }
.badge-en_cours  { background: rgba(245,158,11,.12); color: #f59e0b; border-color: rgba(245,158,11,.28); }
.badge-termine   { background: rgba(16,185,129,.12); color: #10b981; border-color: rgba(16,185,129,.28); }
.badge-annule    { background: rgba(239,68,68,.12); color: #ef4444; border-color: rgba(239,68,68,.28); }
.badge-recu      { background: var(--li-soft); color: var(--li); border-color: var(--li-bd); }
.badge-en_traitement { background: rgba(245,158,11,.12); color: #f59e0b; border-color: rgba(245,158,11,.28); }
.badge-rejete    { background: rgba(239,68,68,.12); color: #ef4444; border-color: rgba(239,68,68,.28); }

/* ════════════════════════════════════════════════════════════
   ARTICLE CARDS
═════════════════════════════════════════════════════════════ */
.articles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 1.15rem;
}
.art-card {
    background: var(--surface); border: 1px solid var(--bd);
    border-radius: var(--r-lg); overflow: hidden;
    transition: all .2s; display: flex; flex-direction: column;
}
.art-card:hover {
    border-color: var(--li-bd);
    transform: translateY(-3px);
    box-shadow: var(--shadow-2);
}
.art-card-thumb {
    width: 100%; aspect-ratio: 16/9;
    background: var(--surface-2);
    background-size: cover; background-position: center;
    position: relative; overflow: hidden;
}
.art-card-thumb-placeholder {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    color: var(--tx-faint); font-size: 2rem;
    background: linear-gradient(135deg, var(--surface-2), var(--surface-3));
}
.art-card-stripe { height: 3px; }
.art-card-body { padding: 1.05rem; flex: 1; display: flex; flex-direction: column; }
.art-card-meta {
    display: flex; align-items: center; gap: .4rem;
    margin-bottom: .55rem; flex-wrap: wrap;
}
.art-card-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.02rem; font-weight: 700;
    color: var(--tx); margin-bottom: .35rem; line-height: 1.32;
    display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden;
}
.art-card-chapeau {
    font-size: .78rem; color: var(--tx-3); line-height: 1.55;
    margin-bottom: .85rem;
    display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden;
}
.art-card-footer {
    display: flex; justify-content: space-between; align-items: center;
    padding-top: .7rem; border-top: 1px solid var(--bd);
    gap: .4rem; flex-wrap: wrap; margin-top: auto;
}
.art-card-stats { display: flex; gap: .65rem; font-size: .7rem; color: var(--tx-3); }
.art-card-stats span { display: flex; align-items: center; gap: .25rem; }
.art-card-stats i { font-size: .65rem; color: var(--li); }

/* ════════════════════════════════════════════════════════════
   FILTERS BAR
═════════════════════════════════════════════════════════════ */
.filters-bar {
    background: var(--surface); border: 1px solid var(--bd);
    border-radius: var(--r-lg); padding: .9rem 1.15rem;
    display: flex; gap: .75rem; align-items: flex-end;
    flex-wrap: wrap; margin-bottom: 1.2rem;
}
.filter-group {
    display: flex; flex-direction: column; gap: .28rem;
    flex: 1; min-width: 130px;
}
.filter-lbl {
    font-size: .65rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--tx-3);
}

/* ════════════════════════════════════════════════════════════
   WIDGETS
═════════════════════════════════════════════════════════════ */
.li-widget {
    background: var(--surface); border: 1px solid var(--bd);
    border-radius: var(--r-lg); padding: 1.2rem; margin-bottom: 1.2rem;
}
.li-widget-title {
    font-size: .85rem; font-weight: 700; color: var(--tx);
    margin-bottom: .9rem;
    display: flex; align-items: center; gap: .45rem;
    font-family: 'Inter', sans-serif;
}
.li-widget-title i { color: var(--li); }

.fin-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: .55rem 0; border-bottom: 1px solid var(--bd);
    font-size: .84rem;
}
.fin-row:last-child { border-bottom: none; }
.fin-lbl { color: var(--tx-3); display: flex; align-items: center; gap: .4rem; }
.fin-val { font-weight: 700; font-family: 'JetBrains Mono', monospace; font-size: .85rem; }
.fin-pos { color: #10b981 !important; }
.fin-neg { color: #ef4444 !important; }
.fin-neu { color: var(--li) !important; }
.fin-big { font-size: 1.14rem !important; color: #10b981 !important; }
.fin-row-hi {
    background: var(--li-softer);
    margin: .4rem -.55rem;
    padding: .65rem .55rem; border-radius: var(--r-sm);
}

/* ════════════════════════════════════════════════════════════
   JOURNALISTES ROW (top journalistes)
═════════════════════════════════════════════════════════════ */
.journ-row {
    display: flex; align-items: center; gap: .72rem;
    padding: .65rem .8rem;
    background: var(--surface-2);
    border: 1px solid var(--bd); border-radius: var(--r-md);
    margin-bottom: .45rem; transition: all .14s;
}
.journ-row:hover { background: var(--surface-3); border-color: var(--bd-h); }
.journ-rank {
    width: 28px; height: 28px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: .85rem; color: #fff; flex-shrink: 0;
}
.rk-gold { background: linear-gradient(135deg, #f59e0b, #d97706); }
.rk-silver { background: linear-gradient(135deg, #94a3b8, #64748b); }
.rk-bronze { background: linear-gradient(135deg, #b45309, #92400e); }
.rk-other { background: linear-gradient(135deg, var(--li), var(--li-d)); }
.journ-name { font-size: .85rem; font-weight: 700; color: var(--tx); }
.journ-sub  { font-size: .68rem; color: var(--tx-3); }
.journ-val  { font-size: .8rem; font-weight: 700; color: var(--li); font-family: 'JetBrains Mono', monospace; }

/* ════════════════════════════════════════════════════════════
   QUICK ACTIONS
═════════════════════════════════════════════════════════════ */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: .8rem; margin-bottom: 1.4rem;
}
.qa-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: .5rem; padding: 1.15rem .8rem;
    background: var(--surface); border: 1px solid var(--bd);
    border-radius: var(--r-lg);
    cursor: pointer; text-decoration: none;
    transition: all .15s; color: var(--tx-3); text-align: center;
}
.qa-btn:hover {
    background: var(--li-soft); border-color: var(--li-bd);
    color: var(--li);
    transform: translateY(-2px);
    box-shadow: var(--shadow-2);
}
.qa-ico {
    width: 42px; height: 42px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.12rem; color: #fff;
}
.qa-lbl { font-size: .74rem; font-weight: 700; }

/* ════════════════════════════════════════════════════════════
   SECTION TITLES & EMPTY STATES
═════════════════════════════════════════════════════════════ */
.sec-title {
    font-size: .65rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .14em;
    color: var(--li); margin: 1rem 0 .65rem;
    display: flex; align-items: center; gap: .42rem;
    padding-bottom: .42rem; border-bottom: 1px solid var(--bd);
}
.empty-state { text-align: center; padding: 2.5rem 2rem; }
.empty-state i { font-size: 2.5rem; color: var(--tx-faint); display: block; margin-bottom: .8rem; }
.empty-state h3 { font-family: 'Playfair Display', serif; font-size: 1.15rem; color: var(--tx-2); margin-bottom: .4rem; }
.empty-state p { color: var(--tx-3); margin-bottom: 1rem; font-size: .88rem; }

/* ════════════════════════════════════════════════════════════
   SCROLLBARS
═════════════════════════════════════════════════════════════ */
html { scrollbar-width: thin; scrollbar-color: var(--bd-2) transparent; }
*::-webkit-scrollbar { width: 12px; height: 12px; }
*::-webkit-scrollbar-track { background: transparent; }
*::-webkit-scrollbar-thumb {
    background: var(--bd-2);
    border-radius: 999px;
    border: 3px solid var(--bg);
    transition: background .2s;
}
*::-webkit-scrollbar-thumb:hover { background: var(--li); }
*::-webkit-scrollbar-thumb:active { background: var(--li-h); }
*::-webkit-scrollbar-corner { background: transparent; }
.modal-body::-webkit-scrollbar-thumb,
.rt-content::-webkit-scrollbar-thumb { border-color: var(--surface); }
.li-table-wrap::-webkit-scrollbar { height: 8px; }

/* ════════════════════════════════════════════════════════════
   STAT TABS (filter tabs)
═════════════════════════════════════════════════════════════ */
.stat-tab { display: flex; gap: .42rem; margin-bottom: 1.1rem; flex-wrap: wrap; }
.stat-tab-item {
    padding: .42rem .9rem; border-radius: 999px;
    font-size: .74rem; font-weight: 600;
    border: 1px solid var(--bd); color: var(--tx-3);
    text-decoration: none; cursor: pointer;
    transition: all .14s; background: var(--surface);
}
.stat-tab-item:hover, .stat-tab-item.active { background: var(--li-soft); border-color: var(--li-bd); color: var(--li); }
.stat-tab-item.active-publie { background: rgba(16,185,129,.10); border-color: rgba(16,185,129,.28); color: #10b981; }
.stat-tab-item.active-revision { background: rgba(245,158,11,.10); border-color: rgba(245,158,11,.28); color: #f59e0b; }
.stat-tab-item.active-brouillon { background: rgba(100,116,139,.12); border-color: rgba(100,116,139,.28); color: #94a3b8; }
.stat-tab-item.active-refuse { background: rgba(239,68,68,.10); border-color: rgba(239,68,68,.28); color: #ef4444; }

/* ════════════════════════════════════════════════════════════
   PICKER (dropdown dynamique réutilisable)
═════════════════════════════════════════════════════════════ */
.picker { position: relative; }
.picker-trigger {
    display: flex; align-items: center; gap: .55rem;
    padding: .62rem .85rem;
    background: var(--surface-2); border: 1px solid var(--bd);
    border-radius: var(--r-md); cursor: pointer;
    transition: all .14s; color: var(--tx);
}
.picker-trigger:hover { border-color: var(--li-bd); background: var(--surface); }
.picker.open .picker-trigger { border-color: var(--li); box-shadow: 0 0 0 3px var(--li-soft); }
.picker-trigger .ph { color: var(--tx-mute); }
.picker-trigger .caret { margin-left: auto; color: var(--tx-3); transition: transform .18s; }
.picker.open .picker-trigger .caret { transform: rotate(180deg); }
.picker-panel {
    position: absolute; top: calc(100% + 6px); left: 0; right: 0;
    background: var(--surface);
    border: 1px solid var(--bd);
    border-radius: var(--r-md);
    box-shadow: var(--shadow-2);
    z-index: 5000;
    max-height: 320px; overflow: hidden;
    display: none; flex-direction: column;
}
.picker.open .picker-panel { display: flex; }
.picker-search {
    padding: .55rem .8rem;
    border-bottom: 1px solid var(--bd);
}
.picker-search input {
    width: 100%; padding: .42rem .65rem;
    background: var(--surface-2); border: 1px solid var(--bd);
    border-radius: var(--r-sm); color: var(--tx);
    font-size: .82rem; outline: none;
}
.picker-search input:focus { border-color: var(--li); }
.picker-list { flex: 1; overflow-y: auto; max-height: 260px; }
.picker-item {
    padding: .55rem .85rem;
    cursor: pointer; transition: all .12s;
    display: flex; align-items: center; gap: .55rem;
    color: var(--tx-2);
    border-bottom: 1px solid var(--bd);
}
.picker-item:last-child { border-bottom: none; }
.picker-item:hover, .picker-item.kbd-focus { background: var(--li-soft); color: var(--li); }
.picker-empty { padding: 1.2rem; text-align: center; color: var(--tx-mute); font-size: .82rem; }

/* ════════════════════════════════════════════════════════════
   RESPONSIVE
═════════════════════════════════════════════════════════════ */
@media (max-width: 1024px) {
    .company-sidebar { transform: translateX(-100%); width: 280px; }
    .company-sidebar.open { transform: translateX(0); box-shadow: 8px 0 40px rgba(0,0,0,.5); }
    .main-content { margin-left: 0; }
    .mob-menu-btn { display: flex; }
    .form-grid, .form-grid3 { grid-template-columns: 1fr; }
    .company-topbar { padding: .75rem 1.1rem; }
    .page-title { font-size: 1.05rem; }
    .li-stats { grid-template-columns: repeat(2, 1fr); }
    .quick-actions { grid-template-columns: repeat(2, 1fr); }
    .page-header { flex-direction: column; align-items: stretch; }
    .page-header-actions { flex-direction: row; flex-wrap: wrap; }
}
@media (max-width: 480px) {
    .li-stats { grid-template-columns: 1fr; }
    .modal-overlay { padding: .5rem; }
    .modal-body { padding: 1rem; }
    .modal-header, .modal-footer { padding: .85rem 1rem; }
    .articles-grid { grid-template-columns: 1fr; }
    .user-badge .badge-txt { display: none; }
    .page-content { padding: 1.1rem; }
}
@media print {
    .company-sidebar, .company-topbar, .btn, .filters-bar, .modal-overlay, .no-print { display: none !important; }
    .main-content { margin-left: 0 !important; }
    body { background: white !important; color: black !important; }
}
    </style>
<?php echo $__panelThemeCSS; ?>
</head>
<body>

<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>

<aside class="company-sidebar" id="companySidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <div class="company-logo"><i class="fas fa-newspaper"></i></div>
            <div>
                <?php echo htmlspecialchars($__panelName); ?>
                <?php echo htmlspecialchars($__panelSubtitle); ?>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php
        $__liSec = '';
        foreach ($__liNavPages as $__p):
            if (!empty($__p['section']) && $__p['section'] !== $__liSec):
                $__liSec = $__p['section'];
                echo '<div class="nav-section-title">' . htmlspecialchars($__liSec) . '</div>';
            endif;
            $__active = ($currentPage === $__p['file']) ? ' active' : '';
        ?>
        <a href="<?php echo $__p['file']; ?>.php" class="nav-item<?php echo $__active; ?>">
            <i class="<?php echo $__p['icon']; ?>"></i><span><?php echo htmlspecialchars($__p['label']); ?></span>
        </a>
        <?php endforeach; ?>

        <?php
        // -- Accès info_vente (indépendant) -----------------
        if (!isset($__ivRes)) {
            $__ivRes = false;
            if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                $__ivRes = true;
            } elseif (($_SESSION['user_faction'] ?? '') === 'LIFE_INVADER') {
                try {
                    $__ivDb2 = Database::getInstance()->getConnection();
                    $__ivGrd = $_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? '';
                    if (!$__ivGrd) {
                        $__ivSt = $__ivDb2->prepare('SELECT COALESCE(faction_grade,faction_rank,\'\') FROM users WHERE id=? LIMIT 1');
                        $__ivSt->execute([$_SESSION['user_id'] ?? 0]);
                        $__ivGrd = (string)($__ivSt->fetchColumn() ?: '');
                    }
                    if ($__ivGrd) {
                        $__ivQ = $__ivDb2->prepare(
                            'SELECT fg.can_recruit FROM faction_grades fg'
                            .' JOIN factions f ON f.id=fg.faction_id'
                            .' WHERE f.faction_key=? AND fg.name=? LIMIT 1'
                        );
                        $__ivQ->execute(['LIFE_INVADER', $__ivGrd]);
                        $__ivR = $__ivQ->fetch(PDO::FETCH_ASSOC);
                        if ($__ivR && !empty($__ivR['can_recruit'])) $__ivRes = true;
                    }
                } catch (Exception $__ivEx) {}
            }
        }
        if ($__ivRes):
        ?>
        <a href="info_vente.php" class="nav-item<?php echo ($currentPage??'')==='info_vente'?' active':''; ?>">
            <i class="fas fa-trophy"></i><span>Classement Ventes</span>
        </a>
        <?php endif; ?>

        <div class="nav-divider"></div>
        <a href="<?php echo SITE_URL; ?>/pages/companies/life-invader/public/index.php" class="nav-item" target="_blank" rel="noopener">
            <i class="fas fa-globe"></i><span>Site public</span>
        </a>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="nav-item">
            <i class="fas fa-home"></i><span>Accueil MDT</span>
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt"></i><span>Déconnexion</span>
        </a>
    </nav>
    <div class="sidebar-user">
        <div class="sidebar-user-av"><?php echo htmlspecialchars(mdt_session_display_initial()); ?></div>
        <div>
            <div class="sidebar-user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
            <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['user_faction_rank'] ?? 'Journaliste'); ?></div>
        </div>
    </div>
</aside>

<main class="main-content">
    <div class="company-topbar">
        <div class="topbar-left">
            <button class="mob-menu-btn" id="menuToggle" onclick="toggleSidebar()" aria-label="Menu"><i class="fas fa-bars" id="menuIcon"></i></button>
            <div>
                <h1 class="page-title"><?php echo htmlspecialchars($pageTitle ?? ''); ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                <div class="page-subtitle"><?php echo htmlspecialchars($pageSubtitle); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem">
            <button class="theme-toggle" onclick="toggleTheme()" title="Basculer thème">
                <i class="fas fa-sun icon-sun"></i>
                <i class="fas fa-moon icon-moon"></i>
            </button>
            <span class="user-badge">
                <i class="fas fa-user-edit"></i>
                <span class="badge-txt"><?php echo htmlspecialchars(mdt_session_display_name()); ?> · <?php echo htmlspecialchars($_SESSION['user_faction_rank'] ?? 'Journaliste'); ?></span>
            </span>
        </div>
    </div>
    <div class="page-content">
