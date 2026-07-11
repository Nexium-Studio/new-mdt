<?php
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive('DYNASTY');
$__dynAllPages = [
    ['file'=>'dashboard',      'label'=>'Vue d\'ensemble',    'icon'=>'fas fa-chart-line',          'section'=>'Pilotage'],
    ['file'=>'biens',          'label'=>'Catalogue',           'icon'=>'fas fa-building',            'section'=>'Portefeuille'],
    ['file'=>'locations',      'label'=>'Locations',           'icon'=>'fas fa-key',                 'section'=>''],
    ['file'=>'ventes',         'label'=>'Ventes',              'icon'=>'fas fa-handshake',           'section'=>''],
    ['file'=>'contrats',       'label'=>'Contrats',            'icon'=>'fas fa-file-signature',      'section'=>''],
    ['file'=>'effectifs',      'label'=>'Équipe',              'icon'=>'fas fa-users',               'section'=>'Ressources Humaines'],
    ['file'=>'prises-service', 'label'=>'Prises de service',   'icon'=>'fas fa-clock',               'section'=>''],
    ['file'=>'sanctions',      'label'=>'Sanctions',           'icon'=>'fas fa-gavel',               'section'=>''],
    ['file'=>'facturation',    'label'=>'Facturation',         'icon'=>'fas fa-file-invoice-dollar', 'section'=>'Finance'],
    ['file'=>'depenses',       'label'=>'Dépenses',            'icon'=>'fas fa-receipt',             'section'=>''],
];
$__dynNavPages = getActiveNavItems('DYNASTY', $__dynAllPages);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> · Dynasty Immobilier</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
/* ============================================================
   DYNASTY IMMOBILIER · Design System "Lumière"
   CRM immobilier moderne — palette apaisée
   ============================================================ */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    /* Vert maîtrisé — accent uniquement */
    --dy:        #10b981;
    --dy-h:      #059669;
    --dy-d:      #047857;
    --dy-l:      #34d399;
    --dy-soft:   rgba(16,185,129,.10);
    --dy-softer: rgba(16,185,129,.05);
    --dy-bd:     rgba(16,185,129,.20);
    --dy-bd-h:   rgba(16,185,129,.35);

    /* Surfaces — graphite professionnel */
    --bg:        #0b0f14;
    --bg-2:      #0f141b;
    --surface:   #131922;
    --surface-2: #182030;
    --surface-3: #1e2738;
    --elev:      #1a2333;

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
    --r-sm:      8px;
    --r-md:      10px;
    --r-lg:      14px;
    --r-xl:      18px;
    --r-pill:    999px;
    --shadow-1:  0 1px 2px rgba(0,0,0,.25), 0 1px 1px rgba(0,0,0,.18);
    --shadow-2:  0 4px 14px rgba(0,0,0,.25);
    --shadow-3:  0 12px 40px rgba(0,0,0,.32);
    --company-color: <?php echo $companyColor ?? '#10b981'; ?>;

    /* ── Rétrocompatibilité (anciennes variables utilisées en inline) ── */
    --tx2:       var(--tx-3);
    --mu:        var(--tx-mute);
    --bd2:       var(--bd-2);
    --s1:        var(--surface);
    --s2:        var(--surface-2);
    --dy2:       var(--dy-h);
    --dy3:       var(--dy-d);
    --dy-glow:   rgba(16,185,129,.30);
    --dy-dim:    var(--dy-softer);
    --r:         var(--r-lg);
}

html, body { background: var(--bg); }
body {
    font-family: 'Inter','Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;
    color: var(--tx);
    overflow-x: hidden;
    font-size: 14px;
    -webkit-font-smoothing: antialiased;
    line-height: 1.55;
}

/* ============================================================
   SIDEBAR
   ============================================================ */
.company-sidebar {
    position: fixed; left:0; top:0;
    width: 264px; height: 100vh;
    background: var(--bg-2);
    border-right: 1px solid var(--bd);
    overflow-y: auto; z-index: 1000;
    display: flex; flex-direction: column;
}

.sidebar-header {
    padding: 1.25rem 1.25rem 1.1rem;
    border-bottom: 1px solid var(--bd);
    flex-shrink: 0;
}

.sidebar-brand {
    display: flex; align-items: center; gap: .75rem; margin-bottom: 0;
}

.company-logo {
    width: 42px; height: 42px; flex-shrink: 0;
    background: linear-gradient(140deg, var(--dy-l), var(--dy-h));
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; color: #fff;
    box-shadow: 0 4px 14px rgba(16,185,129,.25);
}

.company-name     { font-size: .98rem; font-weight: 700; color: var(--tx); line-height: 1.15; letter-spacing:-.01em; }
.company-subtitle { font-size: .68rem; color: var(--tx-mute); margin-top: .15rem; letter-spacing: .03em; font-weight:500; }

/* Mini carte utilisateur (en bas) */
.sidebar-user {
    margin: .75rem 1rem 1rem;
    background: var(--surface);
    border: 1px solid var(--bd-2);
    border-radius: 11px;
    padding: .65rem .8rem;
    display: flex; align-items: center; gap: .65rem;
}
.sidebar-user-av {
    width: 34px; height: 34px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--dy), var(--dy-d));
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; font-weight: 700; color: #fff;
}
.sidebar-user-name { font-size: .8rem; font-weight: 600; color: var(--tx); line-height:1.2; }
.sidebar-user-rank { font-size: .68rem; color: var(--tx-3); margin-top:.1rem; }

/* Navigation */
.sidebar-nav { padding: 1rem 0 .5rem; flex: 1; overflow-y: auto; }

.nav-section-title {
    padding: 1.05rem 1.25rem .35rem;
    font-size: .62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .14em;
    color: var(--tx-mute);
}

.nav-item {
    display: flex; align-items: center; gap: .7rem;
    margin: .12rem .65rem;
    padding: .58rem .7rem;
    border-radius: 9px;
    color: var(--tx-3); text-decoration: none;
    font-weight: 500; font-size: .835rem;
    transition: background .15s, color .15s;
    position: relative;
}
.nav-item:hover { color: var(--tx); background: var(--surface); }
.nav-item.active {
    color: var(--tx);
    background: var(--dy-soft);
    font-weight: 600;
}
.nav-item.active::before {
    content:''; position:absolute; left:-.65rem; top:18%; bottom:18%;
    width: 3px; background: var(--dy); border-radius: 0 4px 4px 0;
}
.nav-item i {
    width: 18px; text-align: center; font-size: .87rem;
    flex-shrink: 0;
    color: var(--tx-mute);
    transition: color .15s;
}
.nav-item.active i, .nav-item:hover i { color: var(--dy); }

.nav-badge {
    margin-left: auto;
    background: var(--dy-soft);
    color: var(--dy);
    border-radius: 10px;
    font-size: .63rem; font-weight: 700;
    padding: .1rem .45rem;
}

.nav-divider { height: 1px; background: var(--bd); margin: .85rem 1.25rem; }

/* ============================================================
   TOPBAR
   ============================================================ */
.main-content { margin-left: 264px; min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); }

.company-topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(11,15,20,.88);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--bd);
    padding: .85rem 1.85rem;
    display: flex; justify-content: space-between; align-items: center; gap: 1rem;
}

.page-title { font-size: 1.1rem; font-weight: 700; color: var(--tx); letter-spacing: -.01em; }
.page-sub   { font-size: .72rem; color: var(--tx-3); margin-top: .1rem; font-weight: 500; }

.user-badge {
    padding: .42rem .85rem;
    background: var(--surface);
    border: 1px solid var(--bd-2);
    border-radius: 999px;
    font-weight: 600; font-size: .74rem; color: var(--tx-2);
    display: flex; align-items: center; gap: .5rem;
}
.user-badge i { color: var(--dy); font-size: .78rem; }

.page-content { padding: 1.6rem 1.85rem; flex: 1; max-width: 1600px; width: 100%; }

/* ============================================================
   COMPOSANTS GLOBAUX
   ============================================================ */

/* Cards */
.card {
    background: var(--surface);
    border: 1px solid var(--bd);
    border-radius: var(--r-lg);
    padding: 1.35rem;
    margin-bottom: 1.25rem;
    transition: border-color .15s, box-shadow .15s;
}
.card:hover { border-color: var(--bd-2); }
.card-flat { background: var(--bg-2); }
.card-tight { padding: 1rem; }
.card-padded { padding: 1.6rem; }

.card-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: .85rem; margin-bottom: 1rem; padding-bottom: .85rem;
    border-bottom: 1px solid var(--bd);
}
.card-title {
    font-size: .92rem; font-weight: 700; color: var(--tx);
    display: flex; align-items: center; gap: .55rem;
    letter-spacing: -.01em;
}
.card-title i { color: var(--dy); font-size: .9rem; }
.card-sub { font-size: .73rem; color: var(--tx-3); margin-top: .15rem; font-weight: 500; }

/* Page header (titre + actions) */
.page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 1rem; flex-wrap: wrap;
    margin-bottom: 1.4rem; padding-bottom: 1.2rem;
    border-bottom: 1px solid var(--bd);
}
.page-header-title { font-size:1.4rem; font-weight:700; color:var(--tx); letter-spacing:-.02em; display:flex; align-items:center; gap:.65rem; }
.page-header-title i { color: var(--dy); font-size: 1.2rem; }
.page-header-sub   { font-size:.8rem; color:var(--tx-3); margin-top:.25rem; }
.page-header-actions { display:flex; gap:.55rem; flex-wrap:wrap; }

/* Boutons */
.btn {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .58rem 1.05rem; border-radius: var(--r-md);
    font-weight: 600; font-size: .82rem; cursor: pointer;
    text-decoration: none; border: 1px solid transparent;
    font-family: inherit;
    transition: background .15s, border-color .15s, transform .12s, box-shadow .15s, color .15s;
    white-space: nowrap;
    line-height: 1;
}
.btn:hover { transform: translateY(-1px); }
.btn:active { transform: translateY(0); }
.btn i { font-size: .82rem; }

.btn-primary {
    background: var(--dy);
    color: #fff;
    box-shadow: 0 2px 8px rgba(16,185,129,.25);
}
.btn-primary:hover { background: var(--dy-h); box-shadow: 0 4px 14px rgba(16,185,129,.32); }

.btn-secondary {
    background: var(--surface-2);
    border-color: var(--bd-2);
    color: var(--tx-2);
}
.btn-secondary:hover { background: var(--surface-3); border-color: var(--bd-h); color: var(--tx); }

.btn-ghost {
    background: transparent;
    border-color: var(--bd-2);
    color: var(--tx-3);
}
.btn-ghost:hover { background: var(--surface); color: var(--tx); border-color: var(--bd-h); }

.btn-danger {
    background: rgba(239,68,68,.1);
    border-color: rgba(239,68,68,.25);
    color: #f87171;
}
.btn-danger:hover { background: rgba(239,68,68,.18); border-color: rgba(239,68,68,.45); color: #fca5a5; }

.btn-warn {
    background: rgba(245,158,11,.1);
    border-color: rgba(245,158,11,.25);
    color: #fbbf24;
}
.btn-warn:hover { background: rgba(245,158,11,.18); border-color: rgba(245,158,11,.45); }

.btn-sm    { padding: .42rem .85rem; font-size: .76rem; }
.btn-xs    { padding: .3rem .65rem; font-size: .72rem; }
.btn-icon  { padding: 0; width: 34px; height: 34px; justify-content: center; }
.btn-icon-sm { padding: 0; width: 28px; height: 28px; justify-content: center; }

/* Formulaires */
.form-grid  { display: grid; grid-template-columns: repeat(2,1fr); gap: 1rem; }
.form-grid3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }
.form-grid4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 1rem; }
.form-full  { grid-column: 1/-1; }
.form-group { display: flex; flex-direction: column; gap: .42rem; }
.form-label {
    font-size: .72rem; font-weight: 600;
    color: var(--tx-2);
    letter-spacing: .01em;
}
.form-label-req::after { content:' *'; color:var(--danger); }
.form-input, .form-select, .form-textarea {
    width: 100%; padding: .62rem .85rem;
    background: var(--bg-2);
    border: 1px solid var(--bd-2);
    border-radius: var(--r-md);
    color: var(--tx);
    font-family: inherit;
    font-size: .85rem; transition: border-color .15s, box-shadow .15s, background .15s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: var(--dy);
    background: var(--surface);
    box-shadow: 0 0 0 3px var(--dy-soft);
}
.form-input::placeholder, .form-textarea::placeholder { color: var(--tx-mute); }
.form-select option  { background: var(--surface-2); color: var(--tx); }
.form-textarea       { resize: vertical; min-height: 88px; line-height: 1.55; }
.form-hint           { font-size: .7rem; color: var(--tx-3); margin-top: .12rem; }

/* Switch (toggle) */
.form-switch { display:inline-flex; align-items:center; gap:.55rem; cursor:pointer; user-select:none; font-size:.82rem; color:var(--tx-2); }
.form-switch input { display:none; }
.form-switch .sw {
    width: 36px; height: 20px; background: var(--surface-3); border-radius: 999px;
    position: relative; transition: background .15s;
}
.form-switch .sw::after {
    content:''; position:absolute; top:2px; left:2px;
    width:16px; height:16px; background:#fff; border-radius:50%;
    transition: left .18s;
}
.form-switch input:checked + .sw { background: var(--dy); }
.form-switch input:checked + .sw::after { left:18px; }

/* ============================================================
   MODALS
   ============================================================ */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(2,6,14,.78);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 9999;
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding: 2.5rem 1.25rem;
    overflow-y: auto;
}
.modal-overlay:not(.hidden) { display: flex; }
.hidden { display: none !important; }

.modal {
    background: var(--surface);
    border: 1px solid var(--bd-2);
    border-radius: var(--r-xl);
    width: 100%; max-width: 680px;
    display: flex; flex-direction: column;
    box-shadow: var(--shadow-3);
    animation: modalIn .2s cubic-bezier(.32,.72,.4,1);
    margin: auto;
    overflow: hidden;
}
.modal-lg { max-width: 920px; }
.modal-xl { max-width: 1100px; }
.modal-sm { max-width: 460px; }

@keyframes modalIn {
    from { opacity:0; transform:translateY(10px) scale(.985); }
    to   { opacity:1; transform:none; }
}

.modal-header {
    padding: 1.15rem 1.5rem;
    border-bottom: 1px solid var(--bd);
    display: flex; justify-content: space-between; align-items: center; gap:1rem;
    background: var(--surface);
}
.modal-header h2 {
    font-size: .98rem; font-weight: 700; color: var(--tx);
    display: flex; align-items: center; gap: .55rem;
    letter-spacing: -.01em;
}
.modal-header h2 i { color: var(--dy); font-size: .95rem; }
.modal-header .h-sub { font-size: .7rem; color: var(--tx-3); margin-top: .15rem; font-weight: 500; }

.modal-close {
    width: 32px; height: 32px;
    border: 1px solid var(--bd-2);
    border-radius: 8px;
    background: transparent;
    color: var(--tx-3); font-size: 1rem; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .15s; flex-shrink: 0;
}
.modal-close:hover { background: rgba(239,68,68,.12); color: #f87171; border-color: rgba(239,68,68,.28); }

.modal-body {
    padding: 1.4rem 1.5rem;
    flex: 1;
}

.modal-footer {
    padding: .95rem 1.5rem;
    border-top: 1px solid var(--bd);
    display: flex; justify-content: flex-end; gap: .55rem;
    background: var(--bg-2);
}

/* ============================================================
   HAMBURGER MOBILE
   ============================================================ */
.mob-menu-btn {
    display: none;
    width: 38px; height: 38px;
    border: 1px solid var(--bd-2);
    border-radius: 9px;
    background: var(--surface);
    color: var(--tx-2); font-size: 1rem;
    cursor: pointer; align-items: center; justify-content: center;
    flex-shrink: 0; transition: background .15s, color .15s;
}
.mob-menu-btn:hover { background: var(--surface-2); color: var(--tx); }

.sidebar-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(2,6,14,.6);
    backdrop-filter: blur(4px);
    z-index: 999;
}
.sidebar-backdrop.open { display: block; }

/* Alerts */
.alert {
    padding: .82rem 1.05rem; border-radius: var(--r-md);
    margin-bottom: 1.25rem; display: flex; align-items: center;
    gap: .55rem; font-weight: 500; font-size: .85rem;
    border: 1px solid;
}
.alert i { font-size: .95rem; flex-shrink:0; }
.alert-success { background:rgba(16,185,129,.08); border-color:rgba(16,185,129,.22); color:#6ee7b7; }
.alert-error   { background:rgba(239,68,68,.08); border-color:rgba(239,68,68,.22); color:#fca5a5; }
.alert-warning { background:rgba(245,158,11,.08); border-color:rgba(245,158,11,.22); color:#fbbf24; }
.alert-info    { background:rgba(59,130,246,.08); border-color:rgba(59,130,246,.22); color:#93c5fd; }

/* ============================================================
   STATS / KPI
   ============================================================ */
.dy-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px,1fr));
    gap: .9rem; margin-bottom: 1.4rem;
}
.dy-stat {
    background: var(--surface);
    border: 1px solid var(--bd);
    border-radius: var(--r-lg);
    padding: 1.15rem 1.3rem;
    display: flex; align-items: center; gap: 1rem;
    transition: border-color .15s, transform .12s;
    position: relative; overflow: hidden;
}
.dy-stat:hover { border-color: var(--bd-2); transform: translateY(-1px); }
.dy-stat-ico {
    width: 42px; height: 42px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; color: var(--dy); flex-shrink: 0;
    background: var(--dy-soft);
    border: 1px solid var(--dy-bd);
}
.dy-stat-ico.ico-blue   { color:#60a5fa; background:rgba(59,130,246,.10);  border-color:rgba(59,130,246,.22); }
.dy-stat-ico.ico-amber  { color:#fbbf24; background:rgba(245,158,11,.10);  border-color:rgba(245,158,11,.22); }
.dy-stat-ico.ico-red    { color:#f87171; background:rgba(239,68,68,.10);   border-color:rgba(239,68,68,.22); }
.dy-stat-ico.ico-violet { color:#a78bfa; background:rgba(139,92,246,.10);  border-color:rgba(139,92,246,.22); }
.dy-stat-ico.ico-cyan   { color:#22d3ee; background:rgba(6,182,212,.10);   border-color:rgba(6,182,212,.22); }
.dy-stat-ico.ico-slate  { color:#cbd5e1; background:rgba(148,163,184,.10); border-color:rgba(148,163,184,.22); }

.dy-stat-val { font-family:'Plus Jakarta Sans',sans-serif; font-size:1.45rem; font-weight:700; color:var(--tx); line-height:1.05; letter-spacing:-.02em; }
.dy-stat-lbl { font-size:.72rem; color:var(--tx-3); margin-top:.32rem; font-weight:600; }
.dy-stat-sub { font-size:.7rem; color:var(--tx-mute); margin-top:.2rem; font-weight:500; }
.dy-stat-sub.up   { color:#34d399; }
.dy-stat-sub.down { color:#f87171; }

/* ============================================================
   TABLEAU
   ============================================================ */
.dy-table-wrap { background: var(--surface); border:1px solid var(--bd); border-radius: var(--r-lg); overflow:hidden; }
.dy-table { width:100%; border-collapse:collapse; font-size:.84rem; }
.dy-table th {
    text-align:left; padding:.85rem 1rem;
    background: var(--bg-2);
    border-bottom: 1px solid var(--bd);
    font-size:.7rem; text-transform:uppercase; letter-spacing:.04em;
    color:var(--tx-3); font-weight:700;
    white-space: nowrap;
}
.dy-table td { padding:.85rem 1rem; border-bottom:1px solid var(--bd); color:var(--tx-2); vertical-align:middle; }
.dy-table tbody tr { transition: background .12s; }
.dy-table tbody tr:hover td { background:var(--bg-2); }
.dy-table tbody tr:last-child td { border-bottom:none; }
.dy-table .col-mono { font-family:'JetBrains Mono',monospace; font-size:.78rem; }
.dy-table .col-strong { color: var(--tx); font-weight: 600; }
.dy-table .col-num { text-align:right; font-variant-numeric: tabular-nums; font-weight: 600; color: var(--tx); }

/* ============================================================
   BADGES
   ============================================================ */
.badge {
    display:inline-flex; align-items:center; gap:.28rem;
    padding:.22rem .6rem; border-radius:999px;
    font-size:.68rem; font-weight:600;
    border:1px solid; white-space:nowrap;
    line-height:1;
}
.badge i { font-size: .56rem; }
.badge-disponible   { background:rgba(16,185,129,.10);  color:#34d399; border-color:rgba(16,185,129,.28); }
.badge-loue         { background:rgba(59,130,246,.10);  color:#60a5fa; border-color:rgba(59,130,246,.28); }
.badge-vendu        { background:rgba(148,163,184,.10); color:#cbd5e1; border-color:rgba(148,163,184,.28); }
.badge-reserve      { background:rgba(245,158,11,.10);  color:#fbbf24; border-color:rgba(245,158,11,.28); }
.badge-indisponible { background:rgba(239,68,68,.10);   color:#fca5a5; border-color:rgba(239,68,68,.28); }
.badge-actif        { background:rgba(16,185,129,.10);  color:#34d399; border-color:rgba(16,185,129,.28); }
.badge-termine      { background:rgba(148,163,184,.10); color:#cbd5e1; border-color:rgba(148,163,184,.28); }
.badge-resilie      { background:rgba(239,68,68,.10);   color:#fca5a5; border-color:rgba(239,68,68,.28); }
.badge-impaye       { background:rgba(245,158,11,.10);  color:#fbbf24; border-color:rgba(245,158,11,.28); }
.badge-en_cours     { background:rgba(245,158,11,.10);  color:#fbbf24; border-color:rgba(245,158,11,.28); }
.badge-signe        { background:rgba(16,185,129,.10);  color:#34d399; border-color:rgba(16,185,129,.28); }
.badge-acte_final   { background:rgba(16,185,129,.14);  color:#6ee7b7; border-color:rgba(16,185,129,.34); }
.badge-annule       { background:rgba(239,68,68,.10);   color:#fca5a5; border-color:rgba(239,68,68,.28); }
.badge-paid         { background:rgba(16,185,129,.10);  color:#34d399; border-color:rgba(16,185,129,.28); }
.badge-pending      { background:rgba(245,158,11,.10);  color:#fbbf24; border-color:rgba(245,158,11,.28); }
.badge-soft         { background:var(--surface-2); color:var(--tx-3); border-color:var(--bd-2); }

/* ============================================================
   CARTES BIENS (catalogue)
   ============================================================ */
.biens-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px,1fr));
    gap: 1.1rem;
}
.bien-card {
    background: var(--surface);
    border: 1px solid var(--bd); border-radius: var(--r-lg); overflow: hidden;
    transition: border-color .15s, transform .15s, box-shadow .15s; cursor: pointer;
    display: flex; flex-direction: column;
}
.bien-card:hover { border-color: var(--bd-2); transform: translateY(-2px); box-shadow: var(--shadow-2); }
.bien-photo {
    height: 150px;
    background: linear-gradient(135deg, var(--surface-2), var(--surface));
    display: flex; align-items: center; justify-content: center;
    font-size: 2.4rem; color: var(--tx-mute);
    position: relative; border-bottom: 1px solid var(--bd);
}
.bien-photo .ov-statut { position:absolute; top:.7rem; right:.7rem; }
.bien-photo .ov-ref    {
    position:absolute; bottom:.6rem; left:.7rem;
    font-family:'JetBrains Mono',monospace; font-size:.64rem; color:var(--tx-3);
    background:rgba(0,0,0,.55); padding:.14rem .42rem; border-radius:5px;
    border:1px solid var(--bd);
}
.bien-body { padding: 1rem 1.1rem; flex:1; display:flex; flex-direction:column; }
.bien-type-tag {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.18rem .55rem; background:var(--dy-soft);
    color:var(--dy); border:1px solid var(--dy-bd);
    border-radius:999px; font-size:.64rem; font-weight:600;
    text-transform:uppercase; letter-spacing:.04em; margin-bottom:.55rem;
    width: fit-content;
}
.bien-titre   { font-size:.96rem; font-weight:700; color:var(--tx); margin-bottom:.18rem; line-height:1.3; letter-spacing:-.01em; }
.bien-adr     { font-size:.74rem; color:var(--tx-3); margin-bottom:.75rem; display:flex; align-items:center; gap:.3rem; }
.bien-adr i   { color: var(--dy); font-size: .7rem; }
.bien-specs   { display:flex; gap:.7rem; flex-wrap:wrap; margin-bottom:.75rem; }
.bien-spec    { display:flex; align-items:center; gap:.3rem; font-size:.74rem; color:var(--tx-3); }
.bien-spec i  { color:var(--dy); font-size:.7rem; }
.bien-prix-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(90px,1fr)); gap:.45rem; margin-bottom:.8rem; }
.bien-prix-item { background:var(--bg-2); border:1px solid var(--bd); border-radius:8px; padding:.55rem; text-align:center; }
.bien-prix-lbl  { font-size:.6rem; color:var(--tx-mute); text-transform:uppercase; letter-spacing:.04em; margin-bottom:.15rem; font-weight:600; }
.bien-prix-val  { font-family:'Plus Jakarta Sans',sans-serif; font-size:.86rem; font-weight:700; color:var(--dy); letter-spacing:-.01em; }
.bien-actions   { display:flex; gap:.4rem; padding-top:.75rem; margin-top:auto; border-top:1px solid var(--bd); }

/* ============================================================
   FILTRES / BARRES OUTILS
   ============================================================ */
.filters-bar {
    background: var(--surface);
    border: 1px solid var(--bd);
    border-radius: var(--r-lg);
    padding: 1rem 1.2rem;
    display:flex; gap:.85rem;
    align-items:flex-end; flex-wrap:wrap; margin-bottom: 1.2rem;
}
.filter-group { display:flex; flex-direction:column; gap:.32rem; flex:1; min-width:130px; }
.filter-lbl   { font-size:.68rem; font-weight:600; color:var(--tx-3); letter-spacing:.02em; }

/* Search input avec icône */
.search-input { position: relative; }
.search-input i {
    position:absolute; left:.85rem; top:50%; transform:translateY(-50%);
    color: var(--tx-mute); font-size:.78rem; pointer-events:none;
}
.search-input .form-input { padding-left: 2.3rem; }

/* ============================================================
   QUICK ACTIONS
   ============================================================ */
.quick-actions {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
    gap:.75rem; margin-bottom:1.4rem;
}
.qa-btn {
    display:flex; flex-direction:row; align-items:center; gap:.85rem;
    padding:1rem 1.1rem;
    background: var(--surface);
    border:1px solid var(--bd);
    border-radius:var(--r-lg); cursor:pointer; text-decoration:none;
    transition: border-color .15s, background .15s, transform .12s;
    color:var(--tx-2);
    font-family: inherit;
    text-align: left;
}
.qa-btn:hover { background: var(--surface-2); border-color: var(--bd-h); transform: translateY(-1px); }
.qa-ico {
    width:38px; height:38px; flex-shrink:0;
    border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:1rem; color: var(--dy);
    background: var(--dy-soft);
    border: 1px solid var(--dy-bd);
}
.qa-ico.qa-blue   { color:#60a5fa; background:rgba(59,130,246,.10);  border-color:rgba(59,130,246,.22); }
.qa-ico.qa-amber  { color:#fbbf24; background:rgba(245,158,11,.10);  border-color:rgba(245,158,11,.22); }
.qa-ico.qa-violet { color:#a78bfa; background:rgba(139,92,246,.10);  border-color:rgba(139,92,246,.22); }
.qa-ico.qa-cyan   { color:#22d3ee; background:rgba(6,182,212,.10);   border-color:rgba(6,182,212,.22); }
.qa-lbl { font-size:.83rem; font-weight:600; color: var(--tx); line-height:1.15; }
.qa-sub { font-size:.7rem; color: var(--tx-3); margin-top:.18rem; font-weight: 500; }

/* ============================================================
   WIDGETS FINANCE / TOP / EXPIRY
   ============================================================ */
.dy-widget {
    background: var(--surface);
    border:1px solid var(--bd);
    border-radius: var(--r-lg);
    padding:1.35rem;
    margin-bottom:1.25rem;
}
.dy-widget-title {
    font-size:.92rem; font-weight:700; color:var(--tx);
    margin-bottom:1.1rem; display:flex; align-items:center; gap:.5rem;
    letter-spacing:-.01em;
    padding-bottom: .85rem; border-bottom: 1px solid var(--bd);
}
.dy-widget-title i { color:var(--dy); font-size: .9rem; }
.dy-widget-title .tag { margin-left:auto; font-size:.65rem; font-weight:500; color:var(--tx-mute); }

.fin-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:.6rem 0; border-bottom:1px solid var(--bd);
    font-size:.85rem;
}
.fin-row:last-child { border-bottom:none; }
.fin-lbl { color:var(--tx-3); display:flex; align-items:center; gap:.5rem; font-weight: 500; }
.fin-lbl i { color: var(--tx-mute); font-size:.78rem; width:14px; text-align:center; }
.fin-val { font-weight:700; font-variant-numeric: tabular-nums; color: var(--tx); }
.fin-pos { color:var(--dy) !important; }
.fin-neg { color:#f87171 !important; }
.fin-neu { color:#60a5fa !important; }
.fin-big { font-size:1.25rem !important; color:var(--dy) !important; letter-spacing:-.02em; }
.fin-row-hi {
    background: var(--bg-2);
    margin:.5rem -.6rem;
    padding:.85rem .85rem;
    border-radius:9px;
    border-bottom: none;
    border: 1px solid var(--dy-bd);
}

/* Agents (top) */
.agent-row {
    display:flex; align-items:center; gap:.8rem;
    padding:.7rem .8rem;
    background: var(--bg-2);
    border:1px solid var(--bd);
    border-radius:10px; margin-bottom:.45rem;
    transition: background .15s;
}
.agent-row:hover { background: var(--surface-2); }
.agent-rank {
    width:30px; height:30px; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    font-weight:800; font-size:.85rem; color:#fff; flex-shrink:0;
}
.rk-gold   { background: linear-gradient(135deg,#f59e0b,#d97706); }
.rk-silver { background: linear-gradient(135deg,#94a3b8,#64748b); }
.rk-bronze { background: linear-gradient(135deg,#b45309,#92400e); }
.rk-other  { background: linear-gradient(135deg,var(--dy),var(--dy-d)); }
.agent-info { flex:1; min-width:0; }
.agent-name { font-weight:600; color:var(--tx); font-size:.85rem; }
.agent-role { font-size:.7rem; color:var(--tx-3); margin-top:.1rem; }
.agent-ca   { font-variant-numeric: tabular-nums; font-size:.85rem; font-weight:700; color:var(--dy); }

/* Locations expiration */
.loc-rows { display:flex; flex-direction:column; gap:.45rem; }
.loc-row  {
    display:flex; align-items:center; gap:.85rem;
    padding:.7rem .9rem;
    background: var(--bg-2); border:1px solid var(--bd);
    border-left:3px solid var(--dy);
    border-radius:0 9px 9px 0; transition:background .15s;
}
.loc-row:hover { background:var(--surface-2); }
.loc-row.exp7  { border-left-color:var(--warn); }
.loc-row.exp2  { border-left-color:var(--danger); }
.lr-bien  { flex:1; min-width:0; }
.lr-t     { font-weight:600; color:var(--tx); font-size:.85rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.lr-sub   { font-size:.7rem; color:var(--tx-3); margin-top: .1rem; }
.lr-dates { text-align:right; font-size:.7rem; color:var(--tx-3); white-space:nowrap; }
.lr-dates strong { display:block; font-size:.82rem; font-weight:700; }

/* Équipements tags */
.equip-tags { display:flex; flex-wrap:wrap; gap:.3rem; }
.equip-tag  {
    padding:.18rem .55rem;
    background:var(--surface-2);
    border:1px solid var(--bd-2);
    border-radius:999px; font-size:.66rem; color:var(--tx-2);
    font-weight:500; cursor:pointer; transition: all .12s; user-select:none;
}
.equip-tag:hover { background:var(--dy-soft); border-color:var(--dy-bd); color: var(--dy); }
.equip-tag.on { background:var(--dy-soft); border-color:var(--dy-bd-h); color:var(--dy); font-weight: 600; }

/* Empty state */
.empty-state { text-align:center; padding:3rem 2rem; color:var(--tx-3); }
.empty-state i { font-size:2.4rem; color:var(--tx-mute); display:block; margin-bottom:.85rem; opacity:.6; }
.empty-state h3 { font-size:.95rem; color:var(--tx-2); font-weight:600; margin-bottom:.4rem; }
.empty-state p { color:var(--tx-3); margin-bottom:1.1rem; font-size:.84rem; }

/* Section title */
.sec-title {
    font-size:.7rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.1em; color:var(--tx-3); margin:1.1rem 0 .8rem;
    display:flex; align-items:center; gap:.5rem;
    padding-bottom:.55rem; border-bottom:1px solid var(--bd);
}
.sec-title i { color:var(--dy); font-size:.78rem; }

/* Scrollbars */
::-webkit-scrollbar { width:8px; height:8px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background:var(--bd-2); border-radius:5px; }
::-webkit-scrollbar-thumb:hover { background:var(--bd-h); }

/* Picker dropdown (réutilisable) */
.picker {
    position: relative;
}
.picker-input {
    width:100%; padding:.62rem .85rem; padding-right: 2.2rem;
    background:var(--bg-2); border:1px solid var(--bd-2); border-radius:var(--r-md);
    color:var(--tx); font-family:inherit; font-size:.85rem;
    cursor:pointer;
    transition: border-color .15s, box-shadow .15s, background .15s;
    display: flex; align-items: center; justify-content: space-between;
    gap: .55rem; min-height: 40px;
}
.picker-input:hover, .picker.open .picker-input {
    border-color:var(--dy); background: var(--surface);
}
.picker.open .picker-input { box-shadow: 0 0 0 3px var(--dy-soft); }
.picker-input .ph { color: var(--tx-mute); font-weight: 500; }
.picker-input .picked { display:flex; align-items:center; gap:.5rem; min-width: 0; flex: 1; }
.picker-input .picked-av {
    width: 24px; height: 24px; border-radius: 6px;
    background: var(--dy-soft); color: var(--dy);
    display: flex; align-items: center; justify-content: center;
    font-size: .68rem; font-weight: 700; flex-shrink: 0;
}
.picker-input .picked-txt { overflow:hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--tx); font-weight: 600; }
.picker-input .picked-sub { color: var(--tx-3); font-weight: 500; font-size: .74rem; margin-left: .25rem; }
.picker-input .caret { color: var(--tx-mute); font-size:.7rem; flex-shrink:0; }

.picker-panel {
    position: absolute; top: calc(100% + 6px); left:0; right:0;
    background: var(--surface);
    border: 1px solid var(--bd-2);
    border-radius: var(--r-md);
    box-shadow: var(--shadow-3);
    z-index: 200;
    display: none;
    flex-direction: column;
    max-height: 360px;
    overflow: hidden;
}
.picker.open .picker-panel { display: flex; }
.picker-search { padding: .65rem; border-bottom: 1px solid var(--bd); position: relative; }
.picker-search i {
    position:absolute; left:1.05rem; top:50%; transform:translateY(-50%);
    color: var(--tx-mute); font-size:.78rem;
}
.picker-search input {
    width:100%; padding:.5rem .75rem .5rem 2.1rem;
    background:var(--bg-2); border:1px solid var(--bd-2); border-radius:8px;
    color:var(--tx); font-size:.82rem; font-family:inherit; outline:none;
}
.picker-search input:focus { border-color: var(--dy); }
.picker-list { overflow-y: auto; flex: 1; padding: .35rem; }
.picker-item {
    display:flex; align-items:center; gap:.6rem;
    padding:.55rem .65rem; border-radius:8px;
    cursor:pointer;
    transition: background .12s;
}
.picker-item:hover, .picker-item.active { background: var(--surface-2); }
.picker-item.selected { background: var(--dy-soft); }
.picker-item-av {
    width: 30px; height: 30px; border-radius: 7px;
    background: var(--dy-soft); color: var(--dy);
    display: flex; align-items: center; justify-content: center;
    font-size: .76rem; font-weight: 700; flex-shrink: 0;
}
.picker-item-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 7px; }
.picker-item-info { flex:1; min-width:0; }
.picker-item-name { font-weight: 600; font-size: .83rem; color: var(--tx); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.picker-item-sub  { font-size: .7rem; color: var(--tx-3); margin-top: .1rem; }
.picker-item-check { color: var(--dy); font-size: .85rem; flex-shrink: 0; opacity: 0; }
.picker-item.selected .picker-item-check { opacity: 1; }
.picker-empty { padding: 1.5rem 1rem; text-align: center; color: var(--tx-3); font-size: .82rem; }
.picker-empty i { display:block; font-size: 1.3rem; margin-bottom: .45rem; color: var(--tx-mute); }

/* Tooltip simple */
[data-tip] { position: relative; }
[data-tip]:hover::after {
    content: attr(data-tip);
    position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
    background: var(--surface-3); color: var(--tx);
    padding: .35rem .6rem; border-radius: 6px;
    font-size: .7rem; white-space: nowrap;
    border: 1px solid var(--bd-2);
    pointer-events: none; z-index: 9999;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media(max-width:1024px) {
    .company-sidebar {
        transform: translateX(-100%);
        transition: transform .28s cubic-bezier(.4,0,.2,1);
        z-index: 1001;
        width: 280px;
        box-shadow: none;
    }
    .company-sidebar.open {
        transform: translateX(0);
        box-shadow: 8px 0 40px rgba(0,0,0,.45);
    }
    .main-content { margin-left: 0; }
    .mob-menu-btn { display: flex; }
    .form-grid, .form-grid3, .form-grid4 { grid-template-columns: 1fr; }
    .company-topbar { padding: .75rem 1.1rem; gap: .55rem; }
    .page-content { padding: 1.1rem; }
    .page-title { font-size: 1rem; }
    .dy-stats { grid-template-columns: repeat(2,1fr); }
    .quick-actions { grid-template-columns: repeat(2,1fr); }
    .page-header { flex-direction: column; align-items: stretch; }
}

@media(max-width:560px) {
    .dy-stats { grid-template-columns: 1fr; }
    .quick-actions { grid-template-columns: 1fr; }
    .modal-overlay { padding: 1rem .6rem; }
    .modal { border-radius: 14px; }
    .modal-body { padding: 1.1rem; }
    .modal-header, .modal-footer { padding: .95rem 1.1rem; }
    .biens-grid { grid-template-columns: 1fr; }
    .user-badge span.badge-txt { display: none; }
    .page-header-actions { flex-direction: column; align-items: stretch; }
    .page-header-actions .btn { justify-content: center; }
}
    </style>
    <?php echo $__panelThemeCSS; ?>
</head>
<body>

<!-- Backdrop sidebar mobile -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>

<aside class="company-sidebar" id="companySidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <div class="company-logo"><i class="fas fa-building"></i></div>
            <div>
                <?php echo htmlspecialchars($__panelName); ?>
                <?php echo htmlspecialchars($__panelSubtitle); ?>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php
        $dynSection = '';
        foreach ($__dynNavPages as $__p):
            if (!empty($__p['section']) && $__p['section'] !== $dynSection):
                $dynSection = $__p['section'];
                echo '<div class="nav-section-title">' . htmlspecialchars($dynSection) . '</div>';
            endif;
            $__active = ($currentPage === $__p['file']) ? ' active' : '';
        ?>
        <a href="<?php echo $__p['file']; ?>.php" class="nav-item<?php echo $__active; ?>">
            <i class="<?php echo $__p['icon']; ?>"></i><span><?php echo htmlspecialchars($__p['label']); ?></span>
        </a>
        <?php endforeach; ?>

        <?php
        // -- Accès info_vente --
        if (!isset($__ivRes)) {
            $__ivRes = false;
            if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                $__ivRes = true;
            } elseif (($_SESSION['user_faction'] ?? '') === 'DYNASTY') {
                try {
                    $__ivDb2 = Database::getInstance()->getConnection();
                    $__ivGrd = $_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? '';
                    if (!$__ivGrd) {
                        $__ivSt = $__ivDb2->prepare('SELECT COALESCE(faction_grade,faction_rank,\'\') FROM users WHERE id=? LIMIT 1');
                        $__ivSt->execute([$_SESSION['user_id'] ?? 0]);
                        $__ivGrd = (string)($__ivSt->fetchColumn() ?: '');
                    }
                    if ($__ivGrd) {
                        $__ivQ = $__ivDb2->prepare('SELECT fg.can_recruit FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id WHERE f.faction_key=? AND fg.name=? LIMIT 1');
                        $__ivQ->execute(['DYNASTY', $__ivGrd]);
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
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="nav-item"><i class="fas fa-th-large"></i><span>Accueil MDT</span></a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="nav-item"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
    </nav>
    <div class="sidebar-user">
        <div class="sidebar-user-av"><?php echo htmlspecialchars(mdt_session_display_initial()); ?></div>
        <div style="min-width:0;flex:1;">
            <div class="sidebar-user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
            <div class="sidebar-user-rank"><?php echo htmlspecialchars($_SESSION['user_faction_rank']??'Agent'); ?></div>
        </div>
    </div>
</aside>

<main class="main-content">
    <div class="company-topbar">
        <div style="display:flex;align-items:center;gap:.75rem;">
            <button class="mob-menu-btn" id="menuToggle" onclick="toggleSidebar()" aria-label="Menu">
                <i class="fas fa-bars" id="menuIcon"></i>
            </button>
            <div>
                <div class="page-title"><?php echo htmlspecialchars($pageTitle??''); ?></div>
                <?php if (!empty($pageSubtitle)): ?>
                <div class="page-sub"><?php echo htmlspecialchars($pageSubtitle); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <span class="user-badge">
            <i class="fas fa-user-tie"></i>
            <span class="badge-txt">
                <?php echo htmlspecialchars(mdt_session_display_name()); ?>
                &nbsp;·&nbsp;
                <?php echo htmlspecialchars($_SESSION['user_faction_rank']??'Agent'); ?>
            </span>
        </span>
    </div>
    <div class="page-content">