<?php
// pages/companies/bennys/includes/header.php
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';

// Staff voit toutes les pages même désactivées — bypass checkPageActive
$__isStaff = hasRole(['*','Administrateur','Développeur','Staff','Modérateur']);
if (!$__isStaff) {
    checkPageActive('BENNYS');
}
if (!isset($__panelName))     $__panelName     = $companyName ?? '🔧 Tuner\'s Shop';
if (!isset($__panelSubtitle)) $__panelSubtitle  = 'Benny\'s Motorworks';
if (!isset($__panelThemeCSS)) $__panelThemeCSS  = '';

$__bennysAllPages = [
    ['file'=>'dashboard',      'label'=>'Dashboard',         'icon'=>'fas fa-chart-line',           'section'=>''],
    ['file'=>'effectifs',      'label'=>'Effectifs',         'icon'=>'fas fa-users',                'section'=>'Ressources Humaines'],
    ['file'=>'prises-service', 'label'=>'Prises de Service', 'icon'=>'fas fa-clock',                'section'=>''],
    ['file'=>'sanctions',      'label'=>'Sanctions',         'icon'=>'fas fa-gavel',                'section'=>''],
    ['file'=>'stock',          'label'=>'Stock',             'icon'=>'fas fa-boxes',                'section'=>'Gestion Commerciale'],
    ['file'=>'vente',          'label'=>'Vente',             'icon'=>'fas fa-cash-register',        'section'=>''],
    ['file'=>'facturation',    'label'=>'Facturation',       'icon'=>'fas fa-file-invoice-dollar',  'section'=>''],
    ['file'=>'depenses',       'label'=>'Dépenses',          'icon'=>'fas fa-money-bill-wave',      'section'=>''],
    ['file'=>'info_vente',     'label'=>'Classement Ventes', 'icon'=>'fas fa-trophy',               'section'=>'Performance'],
    ['file'=>'fourriere',      'label'=>'Fourrière',         'icon'=>'fas fa-truck-ramp-box',       'section'=>''],
];

// Staff : toutes les pages visibles sans filtrage
// Autres : seulement les pages actives dans la config panel
$__bennysNavPages = $__isStaff ? $__bennysAllPages : getActiveNavItems('BENNYS', $__bennysAllPages);
$currentPage      = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> — Tuner's Shop</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════════════════
   TUNER'S SHOP — Design System v3
   Polices système uniquement, textures 100% CSS, zéro dépendance externe
   ═══════════════════════════════════════════════════════════════════ */
@import url('data:text/css,'); /* no-op — no Google Fonts */

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --a:       #f59e0b;   /* amber primary */
    --a-d:     #92400e;   /* amber dark    */
    --a-l:     #fcd34d;   /* amber light   */
    --a-xl:    #fef9ee;   /* amber xlight  */
    --bg:      #080709;
    --bg-b:    #0d0b0f;
    --bg-c:    #121018;
    --bg-e:    #1a1622;
    --bdr:     rgba(245,158,11,.1);
    --bdr-h:   rgba(245,158,11,.3);
    --txt:     #ede8df;
    --txt-s:   #897a66;
    --txt-m:   #3a3028;
    --green:   #10b981;
    --red:     #ef4444;
    --blue:    #3b82f6;
    --sw:      268px;
    --topbar:  58px;
    --radius:  10px;
    --speed:   .18s;
}

html { scroll-behavior:smooth; }

body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: var(--bg);
    color: var(--txt);
    overflow-x: hidden;
    font-size: 14.5px;
    line-height: 1.5;
}

/* ── Texture mécanique custom (CSS pur, aucune image) ── */
body::before {
    content: '';
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background:
        repeating-linear-gradient(90deg, transparent, transparent 59px, rgba(245,158,11,.025) 59px, rgba(245,158,11,.025) 60px),
        repeating-linear-gradient(0deg,  transparent, transparent 59px, rgba(245,158,11,.018) 59px, rgba(245,158,11,.018) 60px),
        radial-gradient(ellipse at 15% 25%, rgba(245,158,11,.06) 0%, transparent 55%),
        radial-gradient(ellipse at 85% 75%, rgba(180,83,9,.05)   0%, transparent 55%);
}

/* ══ SIDEBAR ═══════════════════════════════════════════ */
.c-sidebar {
    position: fixed; left:0; top:0;
    width: var(--sw); height: 100vh;
    background: linear-gradient(170deg, #110e18 0%, #08070a 60%, #0d0b0f 100%);
    border-right: 1px solid var(--bdr);
    display: flex; flex-direction: column;
    overflow-y: auto; z-index: 1000;
    transition: transform var(--speed);
}
.c-sidebar::before {
    content: '';
    position: absolute; top:0; right:0; bottom:0; width:1px;
    background: linear-gradient(180deg,
        transparent 0%, var(--a) 30%, rgba(245,158,11,.4) 70%, transparent 100%);
    opacity: .2;
}

/* Brand */
.c-brand {
    padding: 1.3rem 1.3rem 1.1rem;
    border-bottom: 1px solid var(--bdr);
    position: relative; overflow: hidden;
}
.c-brand::after {
    content: '⚙ TUNER';
    position: absolute; bottom:.5rem; right:.9rem;
    font-size: .38rem; font-weight: 900; letter-spacing:.5em;
    color: var(--a); opacity: .12;
    transform: rotate(-90deg) translateX(50%);
    transform-origin: right center;
}
.c-logo-wrap {
    display: flex; align-items: center; gap: .85rem;
    margin-bottom: .7rem;
}
.c-logo {
    width: 48px; height: 48px; border-radius: 11px;
    background: linear-gradient(135deg, var(--a), var(--a-d));
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; color: #000; flex-shrink: 0;
    box-shadow: 0 0 22px rgba(245,158,11,.45), inset 0 1px 0 rgba(255,255,255,.2);
    position: relative; overflow: hidden;
    transition: box-shadow var(--speed);
}
.c-logo::after {
    content: '';
    position: absolute; top: -50%; left: -50%;
    width: 200%; height: 200%;
    background: linear-gradient(105deg, transparent 40%, rgba(255,255,255,.15) 50%, transparent 60%);
    animation: logo-shine 5s ease-in-out infinite;
}
@keyframes logo-shine { 0%,85%,100%{transform:translateX(-200%)} 90%{transform:translateX(200%)} }
.c-sidebar:hover .c-logo { box-shadow: 0 0 32px rgba(245,158,11,.65), inset 0 1px 0 rgba(255,255,255,.2); }
.c-brand-text {}
.c-brand-name {
    font-size: 1rem; font-weight: 800; color: var(--txt);
    letter-spacing: .04em; line-height: 1.2;
}
.c-brand-sub { font-size: .62rem; color: var(--txt-s); text-transform: uppercase; letter-spacing: .14em; margin-top: .1rem; }

/* User pill */
.c-user {
    margin: .7rem 1rem;
    padding: .55rem .85rem;
    background: rgba(245,158,11,.04);
    border: 1px solid rgba(245,158,11,.12);
    border-radius: 8px;
    display: flex; align-items: center; gap: .55rem;
    transition: background var(--speed), border-color var(--speed);
}
.c-user:hover { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.22); }
.c-user-dot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
    background: var(--green); box-shadow: 0 0 7px var(--green);
    animation: pulse-dot 2.5s ease-in-out infinite;
}
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.8)} }
.c-user-name  { font-size: .82rem; font-weight: 700; }
.c-user-grade { font-size: .66rem; color: var(--txt-s); }

/* Nav */
.c-nav { padding: .4rem 0 2rem; flex: 1; }
.c-section {
    padding: .8rem 1.3rem .25rem;
    font-size: .56rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .22em;
    color: var(--txt-m);
}
.c-link {
    display: flex; align-items: center; gap: .65rem;
    padding: .68rem 1.3rem;
    color: var(--txt-s); text-decoration: none;
    font-size: .84rem; font-weight: 600;
    transition: color var(--speed), background var(--speed);
    position: relative; overflow: hidden;
}
.c-link::before {
    content: '';
    position: absolute; left: 0; top: 50%; transform: translateY(-50%);
    width: 3px; height: 0;
    background: var(--a);
    border-radius: 0 3px 3px 0;
    transition: height .22s cubic-bezier(.34,1.56,.64,1);
    box-shadow: 0 0 10px var(--a);
}
.c-link::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(90deg, rgba(245,158,11,.07) 0%, transparent 100%);
    opacity: 0; transition: opacity var(--speed);
}
.c-link i { width: 15px; text-align: center; font-size: .8rem; transition: color var(--speed); }
.c-link:hover, .c-link.active {
    color: var(--a-l);
}
.c-link:hover::before, .c-link.active::before { height: 58%; }
.c-link:hover::after, .c-link.active::after { opacity: 1; }
.c-link.active { font-weight: 800; color: var(--a-l); }
.c-link.active i { color: var(--a); }
.c-link:active { transform: scale(.98); }
.c-divider { height: 1px; background: var(--bdr); margin: .65rem 1.3rem; }

/* ══ MAIN ══════════════════════════════════════════════ */
.c-main { margin-left: var(--sw); min-height: 100vh; position: relative; z-index: 1; }

.c-topbar {
    position: sticky; top: 0; z-index: 100; height: var(--topbar);
    background: rgba(8,7,9,.97);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--bdr);
    padding: 0 1.8rem;
    display: flex; justify-content: space-between; align-items: center;
}
.c-topbar::after {
    content: '';
    position: absolute; bottom: 0; left: 10%; right: 10%; height: 1px;
    background: linear-gradient(90deg, transparent, var(--a), transparent);
    opacity: .35;
}
.c-topbar-title {
    font-size: 1.05rem; font-weight: 800; color: var(--txt);
    letter-spacing: .03em; display: flex; align-items: center; gap: .6rem;
}
.c-topbar-title i { color: var(--a); }
.c-topbar-sub { font-size: .63rem; color: var(--txt-s); text-transform: uppercase; letter-spacing: .1em; margin-top: .08rem; }
.c-topbar-right { display: flex; align-items: center; gap: .55rem; }
.c-badge {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .3rem .85rem;
    background: rgba(245,158,11,.07);
    border: 1px solid rgba(245,158,11,.2);
    border-radius: 20px;
    font-size: .73rem; font-weight: 700;
    color: var(--a-l); letter-spacing: .05em;
    transition: background var(--speed), border-color var(--speed);
}
.c-badge:hover { background: rgba(245,158,11,.13); border-color: rgba(245,158,11,.35); }

.c-content { padding: 1.5rem 1.8rem; }

/* ══ COMPONENTS ════════════════════════════════════════ */
.card {
    background: var(--bg-c);
    border: 1px solid var(--bdr);
    border-radius: var(--radius); padding: 1.35rem;
    margin-bottom: 1.35rem;
    transition: border-color var(--speed), box-shadow var(--speed), transform var(--speed);
}
.card:hover { border-color: var(--bdr-h); box-shadow: 0 8px 28px rgba(0,0,0,.35); }

/* Buttons */
.btn {
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .6rem 1.15rem; border-radius: 8px;
    font-family: inherit; font-weight: 700; font-size: .83rem;
    cursor: pointer; transition: all var(--speed);
    text-decoration: none; border: none; letter-spacing: .03em;
    position: relative; overflow: hidden;
}
.btn::before {
    content: '';
    position: absolute; top: 50%; left: 50%;
    width: 0; height: 0; border-radius: 50%;
    background: rgba(255,255,255,.12);
    transform: translate(-50%,-50%);
    transition: width .4s ease, height .4s ease, opacity .4s;
    opacity: 0;
}
.btn:active::before { width: 200px; height: 200px; opacity: 1; }

.btn-primary {
    background: linear-gradient(135deg, var(--a), var(--a-d));
    color: #0a0800;
    box-shadow: 0 3px 12px rgba(245,158,11,.32);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(245,158,11,.48); filter: brightness(1.08); }
.btn-primary:active { transform: translateY(0); }

.btn-secondary {
    background: rgba(245,158,11,.07);
    color: var(--a-l);
    border: 1px solid rgba(245,158,11,.2);
}
.btn-secondary:hover { background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.4); transform: translateY(-1px); }

.btn-ghost { background: transparent; color: var(--txt-s); border: 1px solid var(--bdr); }
.btn-ghost:hover { background: var(--bg-e); color: var(--txt); border-color: var(--bdr-h); }

.btn-danger { background: rgba(239,68,68,.08); color: #fca5a5; border: 1px solid rgba(239,68,68,.22); }
.btn-danger:hover { background: rgba(239,68,68,.18); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(239,68,68,.2); }

.btn-success { background: rgba(16,185,129,.08); color: #6ee7b7; border: 1px solid rgba(16,185,129,.22); }
.btn-success:hover { background: rgba(16,185,129,.18); transform: translateY(-1px); }

/* RESET button — identité propre */
.btn-reset {
    background: linear-gradient(135deg, rgba(59,130,246,.15), rgba(37,99,235,.08));
    color: #93c5fd;
    border: 1px solid rgba(59,130,246,.3);
    position: relative; overflow: hidden;
}
.btn-reset::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(59,130,246,.2), transparent);
    opacity: 0; transition: opacity var(--speed);
}
.btn-reset:hover { background: rgba(59,130,246,.22); border-color: rgba(59,130,246,.55); transform: translateY(-1px) rotate(-3deg); box-shadow: 0 4px 16px rgba(59,130,246,.25); }
.btn-reset:hover i { animation: spin-reset .4s ease; }
.btn-reset:active { transform: rotate(-12deg) scale(.95); }
@keyframes spin-reset { 0%{transform:rotate(0)} 100%{transform:rotate(-360deg)} }

.btn-sm  { padding: .35rem .78rem; font-size: .76rem; }
.btn-xs  { padding: .2rem  .52rem; font-size: .69rem; }
.btn-lg  { padding: .78rem 1.5rem; font-size: .92rem; }
.btn-full{ width: 100%; justify-content: center; }

/* Forms */
.form-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1.1rem; }
.form-full { grid-column: 1/-1; }
.form-group { display: flex; flex-direction: column; gap: .32rem; }
.form-label {
    font-size: .63rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .13em; color: var(--txt-s);
}
.form-input, .form-select, .form-textarea {
    width: 100%; padding: .65rem .88rem;
    background: rgba(8,7,9,.8);
    border: 1px solid rgba(245,158,11,.16);
    border-radius: 7px; color: var(--txt);
    font-family: inherit; font-size: .88rem;
    transition: border-color var(--speed), box-shadow var(--speed), background var(--speed);
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none; border-color: var(--a);
    box-shadow: 0 0 0 3px rgba(245,158,11,.1);
    background: rgba(245,158,11,.03);
}
.form-input::placeholder { color: var(--txt-m); }
.form-select option { background: var(--bg-e); }
.form-textarea { resize: vertical; min-height: 88px; }

/* Modals */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.88);
    backdrop-filter: blur(10px);
    display: none; align-items: center; justify-content: center;
    z-index: 9999; padding: 1.5rem;
    animation: modal-in .18s ease;
}
.modal-overlay:not(.hidden) { display: flex; }
@keyframes modal-in { from{opacity:0} to{opacity:1} }
.modal {
    background: linear-gradient(145deg, var(--bg-e), var(--bg));
    border: 1px solid rgba(245,158,11,.25);
    border-radius: 14px;
    box-shadow: 0 0 80px rgba(0,0,0,.85), 0 0 40px rgba(245,158,11,.06);
    width: 90%; max-width: 640px; max-height: 90vh;
    display: flex; flex-direction: column; overflow: hidden;
    animation: modal-slide .22s cubic-bezier(.34,1.56,.64,1);
}
@keyframes modal-slide { from{transform:translateY(20px) scale(.97)} to{transform:translateY(0) scale(1)} }
.modal-lg { max-width: 900px; }
.modal-header {
    padding: 1.15rem 1.5rem;
    border-bottom: 1px solid var(--bdr);
    display: flex; justify-content: space-between; align-items: center;
    background: rgba(245,158,11,.025);
    position: relative;
}
.modal-header::after {
    content: ''; position: absolute; bottom:0; left:5%; right:5%; height:1px;
    background: linear-gradient(90deg, transparent, var(--a), transparent); opacity:.3;
}
.modal-header h2 { font-size: .95rem; font-weight: 800; color: var(--txt); display: flex; align-items: center; gap: .65rem; }
.modal-header h2 i { color: var(--a); }
.modal-close {
    width: 28px; height: 28px; border-radius: 6px;
    background: rgba(245,158,11,.06); border: 1px solid var(--bdr);
    color: var(--txt-s); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; transition: all var(--speed);
}
.modal-close:hover { background: rgba(239,68,68,.15); color: #fca5a5; transform: rotate(90deg); }
.modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
.modal-footer {
    padding: .95rem 1.5rem; border-top: 1px solid var(--bdr);
    background: rgba(0,0,0,.18); display: flex; justify-content: flex-end; gap: .55rem;
}

/* Alerts */
.alert {
    padding: .78rem 1.05rem; border-radius: 8px; margin-bottom: 1.25rem;
    display: flex; align-items: center; gap: .6rem; font-size: .86rem; font-weight: 600;
    animation: alert-pop .22s ease;
}
@keyframes alert-pop { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
.alert-success { background: rgba(16,185,129,.07); border: 1px solid rgba(16,185,129,.22); color: #6ee7b7; }
.alert-error   { background: rgba(239,68,68,.07);  border: 1px solid rgba(239,68,68,.22);  color: #fca5a5; }
.alert-info    { background: rgba(245,158,11,.07); border: 1px solid rgba(245,158,11,.22); color: var(--a-l); }
.alert-warning { background: rgba(251,191,36,.07); border: 1px solid rgba(251,191,36,.22); color: #fde68a; }

/* Badges */
.badge {
    display: inline-flex; align-items: center; gap: .22rem;
    padding: .18rem .52rem; border-radius: 4px;
    font-size: .62rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .06em;
}
.badge-amber { background: rgba(245,158,11,.1); color: var(--a-l);  border: 1px solid rgba(245,158,11,.2); }
.badge-green { background: rgba(16,185,129,.1);  color: #6ee7b7;    border: 1px solid rgba(16,185,129,.2); }
.badge-red   { background: rgba(239,68,68,.1);   color: #fca5a5;    border: 1px solid rgba(239,68,68,.2); }
.badge-blue  { background: rgba(59,130,246,.1);  color: #93c5fd;    border: 1px solid rgba(59,130,246,.2); }
.badge-gray  { background: rgba(100,116,139,.1); color: #94a3b8;    border: 1px solid rgba(100,116,139,.2); }
.badge-gold  { background: rgba(251,191,36,.12); color: #fde68a;    border: 1px solid rgba(251,191,36,.25); }
.badge-silver{ background: rgba(148,163,184,.1); color: #cbd5e1;    border: 1px solid rgba(148,163,184,.2); }
.badge-bronze{ background: rgba(180,83,9,.1);    color: #fbbf24;    border: 1px solid rgba(180,83,9,.2); }

/* KPI cards */
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(155px,1fr)); gap: .85rem; margin-bottom: 1.8rem; }
.kpi {
    background: var(--bg-c); border: 1px solid var(--bdr);
    border-radius: var(--radius); padding: 1rem 1.15rem;
    position: relative; overflow: hidden;
    transition: border-color var(--speed), transform var(--speed);
}
.kpi::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: var(--kpi-c, var(--a));
    box-shadow: 0 0 8px var(--kpi-c, var(--a));
}
.kpi:hover { border-color: var(--bdr-h); transform: translateY(-2px); }
.kpi-label { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .15em; color: var(--txt-s); margin-bottom: .35rem; }
.kpi-val { font-size: 1.7rem; font-weight: 900; color: var(--txt); line-height: 1; font-variant-numeric: tabular-nums; }
.kpi-sub { font-size: .67rem; color: var(--txt-s); margin-top: .2rem; }

/* Table */
.tbl-wrap { background: var(--bg-c); border: 1px solid var(--bdr); border-radius: var(--radius); overflow: hidden; }
.tbl { width: 100%; border-collapse: collapse; }
.tbl th { text-align: left; padding: .7rem 1rem; background: rgba(245,158,11,.04); border-bottom: 1px solid rgba(245,158,11,.14); font-size: .63rem; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: var(--txt-s); }
.tbl td { padding: .68rem 1rem; border-bottom: 1px solid rgba(255,255,255,.03); font-size: .83rem; }
.tbl tbody tr:last-child td { border-bottom: none; }
.tbl tbody tr { transition: background var(--speed); }
.tbl tbody tr:hover td { background: rgba(245,158,11,.03); }

.mono { font-family: 'Consolas','Courier New',monospace; }
.hidden { display: none !important; }

@media (max-width:1100px) {
    .c-sidebar { transform: translateX(-100%); }
    .c-main { margin-left: 0; }
    .form-grid { grid-template-columns: 1fr; }
    .kpi-grid { grid-template-columns: repeat(2,1fr); }
}
</style>
<?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body>

<aside class="c-sidebar">
    <div class="c-brand">
        <div class="c-logo-wrap">
            <div class="c-logo"><i class="fas fa-wrench"></i></div>
            <div class="c-brand-text">
                <div class="c-brand-name"><?php echo htmlspecialchars($__panelName ?? "Tuner's Shop"); ?></div>
                <div class="c-brand-sub">Benny's Motorworks</div>
            </div>
        </div>
    </div>
    <div class="c-user">
        <div class="c-user-dot"></div>
        <div>
            <div class="c-user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
            <div class="c-user-grade"><?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?></div>
        </div>
    </div>
    <nav class="c-nav">
        <?php
        $__sec = '';
        foreach ($__bennysNavPages as $__p):
            if (!empty($__p['section']) && $__p['section'] !== $__sec):
                $__sec = $__p['section'];
                echo '<div class="c-section">'.htmlspecialchars($__sec).'</div>';
            endif;
            $__act = ($currentPage === $__p['file']) ? ' active' : '';
        ?>
        <a href="<?php echo $__p['file']; ?>.php" class="c-link<?php echo $__act; ?>">
            <i class="<?php echo $__p['icon']; ?>"></i>
            <span><?php echo htmlspecialchars($__p['label']); ?></span>
        </a>
        <?php endforeach; ?>
        <div class="c-divider"></div>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="c-link">
            <i class="fas fa-house"></i><span>Accueil MDT</span>
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="c-link">
            <i class="fas fa-right-from-bracket"></i><span>Déconnexion</span>
        </a>
    </nav>
</aside>

<main class="c-main">
    <div class="c-topbar">
        <div>
            <div class="c-topbar-title">
                <i class="fas fa-<?php echo $pageIcon ?? 'wrench'; ?>"></i>
                <?php echo htmlspecialchars($pageTitle ?? "Tuner's Shop"); ?>
            </div>
            <div class="c-topbar-sub">Benny's Motorworks — Panel interne</div>
        </div>
        <div class="c-topbar-right">
            <span class="c-badge"><i class="fas fa-wrench"></i><?php echo htmlspecialchars($__panelName ?? "Tuner's Shop"); ?></span>
        </div>
    </div>
    <div class="c-content">