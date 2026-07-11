<?php
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive('TRIADS');
$__triAllPages = [
    ['file'=>'dashboard', 'label'=>'Dashboard', 'icon'=>'fas fa-chart-line', 'section'=>''],
    ['file'=>'artistes', 'label'=>'Artistes', 'icon'=>'fas fa-microphone', 'section'=>'Artistique'],
    ['file'=>'productions', 'label'=>'Productions', 'icon'=>'fas fa-compact-disc', 'section'=>''],
    ['file'=>'studios', 'label'=>'Studios', 'icon'=>'fas fa-headphones', 'section'=>''],
    ['file'=>'contrats', 'label'=>'Contrats', 'icon'=>'fas fa-file-contract', 'section'=>''],
    ['file'=>'evenements', 'label'=>'Événements', 'icon'=>'fas fa-calendar-star', 'section'=>''],
    ['file'=>'effectifs', 'label'=>'Effectifs', 'icon'=>'fas fa-users', 'section'=>'Ressources Humaines'],
    ['file'=>'prises-service', 'label'=>'Prises de service', 'icon'=>'fas fa-clock', 'section'=>''],
    ['file'=>'sanctions', 'label'=>'Sanctions', 'icon'=>'fas fa-gavel', 'section'=>''],
    ['file'=>'stock', 'label'=>'Stock', 'icon'=>'fas fa-boxes-stacked', 'section'=>'Gestion Commerciale'],
    ['file'=>'vente', 'label'=>'Ventes', 'icon'=>'fas fa-shopping-cart', 'section'=>''],
    ['file'=>'facturation', 'label'=>'Facturation', 'icon'=>'fas fa-file-invoice-dollar', 'section'=>''],
    ['file'=>'depenses', 'label'=>'Dépenses', 'icon'=>'fas fa-money-bill-wave', 'section'=>''],
];
$__triNavPages = getActiveNavItems('TRIADS', $__triAllPages);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> · <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <style>

/* ═══════════════════════════════════════════
   ROOT & RESET
═══════════════════════════════════════════ */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --c:     <?php echo $companyColor; ?>;
    --c-dk:  color-mix(in srgb, var(--c) 65%, #000);
    --c-lt:  color-mix(in srgb, var(--c) 55%, #fff);
    --c-dim: color-mix(in srgb, var(--c) 20%, transparent);

    --bg:     #07090f;
    --bg-1:   #0c0f1c;
    --bg-2:   #111628;
    --bg-card:#0f1220;

    --border:   rgba(255,255,255,0.07);
    --border-c: rgba(139,92,246,0.22);

    --txt:  #e4eaf5;
    --txt-2:#8898b8;
    --txt-3:#4a5878;

    --green:  #22c55e;
    --red:    #ef4444;
    --yellow: #f59e0b;
    --blue:   #60a5fa;
    --pink:   #f472b6;

    --radius:    12px;
    --radius-sm: 8px;
    --sidebar-w: 258px;
    --font-d: 'Syne', sans-serif;
    --font-b: 'DM Sans', sans-serif;
    --tr:     all 0.2s ease;
}

body {
    font-family: var(--font-b);
    background: var(--bg);
    color: var(--txt);
    overflow-x: hidden;
    font-size: 14px;
    line-height: 1.5;
}
a { text-decoration: none; color: inherit; }
button { cursor: pointer; font-family: var(--font-b); }

/* ═══════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════ */
.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: var(--sidebar-w);
    background: var(--bg-1);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    z-index: 1000;
    overflow-y: auto; overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: var(--border-c) transparent;
}
.sidebar::-webkit-scrollbar { width: 3px; }
.sidebar::-webkit-scrollbar-thumb { background: var(--border-c); border-radius: 3px; }

.sidebar-brand {
    padding: 1.4rem 1.3rem 1.1rem;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    background: linear-gradient(160deg, color-mix(in srgb, var(--c) 7%, transparent), transparent 60%);
}
.brand-logo {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, var(--c), var(--c-dk));
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
    box-shadow: 0 0 22px var(--c-dim);
    margin-bottom: 0.8rem;
}
.brand-name {
    font-family: var(--font-d);
    font-size: 1.05rem; font-weight: 800;
    color: var(--txt); letter-spacing: 0.2px;
}
.brand-sub {
    font-size: 0.65rem; color: var(--txt-3);
    text-transform: uppercase; letter-spacing: 1.8px; margin-top: 0.15rem;
}

.sidebar-nav { flex: 1; padding: 0.6rem 0 1.25rem; }
.nav-section {
    padding: 1rem 1.3rem 0.3rem;
    font-size: 0.6rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 2px;
    color: var(--txt-3);
}
.nav-link {
    display: flex; align-items: center; gap: 0.7rem;
    padding: 0.55rem 1.3rem;
    color: var(--txt-2);
    font-size: 0.83rem; font-weight: 500;
    transition: var(--tr);
    border-left: 2px solid transparent;
}
.nav-link i { width: 16px; text-align: center; font-size: 0.82rem; flex-shrink: 0; }
.nav-link:hover, .nav-link.active {
    color: var(--c-lt);
    background: linear-gradient(90deg, color-mix(in srgb, var(--c) 9%, transparent), transparent);
    border-left-color: var(--c);
}
.nav-link.active { font-weight: 600; }
.nav-link.active i { color: var(--c); }
.nav-badge {
    margin-left: auto;
    background: var(--c-dim); color: var(--c-lt);
    font-size: 0.6rem; font-weight: 700;
    padding: 0.1rem 0.4rem; border-radius: 20px;
}
.nav-divider { height: 1px; background: var(--border); margin: 0.5rem 1.3rem; }

.sidebar-footer {
    padding: 0.9rem 1.3rem;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
    display: flex; flex-direction: column; gap: 0.2rem;
}
.sidebar-footer .nav-link {
    border-left: none; border-radius: var(--radius-sm);
    padding: 0.5rem 0.7rem;
}
.sidebar-footer .nav-link:hover {
    background: rgba(255,255,255,0.04);
    border-color: var(--border);
}

/* ═══════════════════════════════════════════
   MAIN
═══════════════════════════════════════════ */
.main { margin-left: var(--sidebar-w); min-height: 100vh; display: flex; flex-direction: column; }

.topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(7,9,15,0.93);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--border);
    padding: 0.85rem 1.7rem;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    flex-shrink: 0;
}
.topbar-title { font-family: var(--font-d); font-size: 1.2rem; font-weight: 800; letter-spacing: -0.2px; }
.user-chip {
    display: flex; align-items: center; gap: 0.45rem;
    padding: 0.4rem 0.85rem;
    background: var(--c-dim); border: 1px solid var(--border-c);
    border-radius: 20px; font-size: 0.75rem; font-weight: 600; color: var(--c-lt);
}
.page { flex: 1; padding: 1.5rem 1.7rem; }

/* ═══════════════════════════════════════════
   CARDS
═══════════════════════════════════════════ */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1.35rem;
    transition: border-color 0.25s;
}
.card:hover { border-color: var(--border-c); }

.card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1.2rem; gap: 1rem;
}
.card-title {
    font-family: var(--font-d); font-size: 0.96rem; font-weight: 700;
    display: flex; align-items: center; gap: 0.5rem;
}
.card-title i { color: var(--c); font-size: 0.88rem; }

/* ═══════════════════════════════════════════
   KPI GRID
═══════════════════════════════════════════ */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem; margin-bottom: 1.4rem;
}
.kpi {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1.15rem 1.3rem;
    position: relative; overflow: hidden;
    transition: var(--tr);
}
.kpi:hover { border-color: var(--border-c); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
.kpi::after {
    content: '';
    position: absolute; top: 0; right: 0;
    width: 75px; height: 75px;
    background: radial-gradient(circle at top right, var(--c-dim), transparent 70%);
    pointer-events: none;
}
.kpi-label  { font-size: 0.64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.4px; color: var(--txt-3); margin-bottom: 0.6rem; }
.kpi-value  { font-family: var(--font-d); font-size: 1.85rem; font-weight: 800; line-height: 1; color: var(--txt); margin-bottom: 0.35rem; }
.kpi-value.green  { color: var(--green); }
.kpi-value.purple { color: var(--c-lt); }
.kpi-value.blue   { color: var(--blue); }
.kpi-value.yellow { color: var(--yellow); }
.kpi-value.red    { color: var(--red); }
.kpi-sub { font-size: 0.7rem; color: var(--txt-3); display: flex; align-items: center; gap: 0.28rem; }
.kpi-sub .up { color: var(--green); } .kpi-sub .down { color: var(--red); }

/* ═══════════════════════════════════════════
   GRIDS
═══════════════════════════════════════════ */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr;         gap: 1.2rem; }
.grid-3 { display: grid; grid-template-columns: repeat(3,1fr);   gap: 1.2rem; }
.grid-4 { display: grid; grid-template-columns: repeat(4,1fr);   gap: 1rem;  }
.col-2  { grid-column: span 2; }

/* ═══════════════════════════════════════════
   BUTTONS
═══════════════════════════════════════════ */
.btn {
    display: inline-flex; align-items: center; gap: 0.42rem;
    padding: 0.55rem 1.05rem;
    border-radius: var(--radius-sm); font-weight: 600; font-size: 0.81rem;
    transition: var(--tr); border: 1px solid transparent;
    white-space: nowrap; font-family: var(--font-b); line-height: 1;
}
.btn i { font-size: 0.78rem; }
.btn-primary {
    background: linear-gradient(135deg, var(--c), var(--c-dk));
    color: #fff; box-shadow: 0 3px 12px var(--c-dim);
}
.btn-primary:hover { transform: translateY(-1px); filter: brightness(1.1); box-shadow: 0 6px 20px var(--c-dim); }
.btn-secondary { background: var(--c-dim); color: var(--c-lt); border-color: var(--border-c); }
.btn-secondary:hover { background: color-mix(in srgb, var(--c) 18%, transparent); }
.btn-ghost { background: transparent; color: var(--txt-2); border-color: var(--border); }
.btn-ghost:hover { background: rgba(255,255,255,0.05); color: var(--txt); }
.btn-danger  { background: rgba(239,68,68,0.1);  color: var(--red);    border-color: rgba(239,68,68,0.22); }
.btn-danger:hover  { background: rgba(239,68,68,0.2); }
.btn-success { background: rgba(34,197,94,0.1);  color: var(--green);  border-color: rgba(34,197,94,0.22); }
.btn-success:hover { background: rgba(34,197,94,0.2); }
.btn-warning { background: rgba(245,158,11,0.1); color: var(--yellow); border-color: rgba(245,158,11,0.22); }
.btn-warning:hover { background: rgba(245,158,11,0.2); }
.btn-sm  { padding: 0.35rem 0.75rem; font-size: 0.73rem; }
.btn-xs  { padding: 0.26rem 0.56rem; font-size: 0.67rem; }
.btn-lg  { padding: 0.7rem 1.35rem;  font-size: 0.88rem; }

/* ═══════════════════════════════════════════
   BADGES
═══════════════════════════════════════════ */
.badge {
    display: inline-flex; align-items: center; gap: 0.22rem;
    padding: 0.2rem 0.55rem; border-radius: 20px;
    font-size: 0.66rem; font-weight: 700; white-space: nowrap; border: 1px solid transparent;
}
.badge-purple { background: rgba(139,92,246,0.14); color: #a78bfa;    border-color: rgba(139,92,246,0.24); }
.badge-green  { background: rgba(34,197,94,0.11);  color: var(--green);border-color: rgba(34,197,94,0.2);  }
.badge-red    { background: rgba(239,68,68,0.11);  color: var(--red);  border-color: rgba(239,68,68,0.2);  }
.badge-yellow { background: rgba(245,158,11,0.11); color: var(--yellow);border-color: rgba(245,158,11,0.2);}
.badge-blue   { background: rgba(96,165,250,0.11); color: var(--blue); border-color: rgba(96,165,250,0.2); }
.badge-gray   { background: rgba(100,116,139,0.14);color: #94a3b8;     border-color: rgba(100,116,139,0.2);}
.badge-pink   { background: rgba(244,114,182,0.11);color: var(--pink); border-color: rgba(244,114,182,0.2);}
.badge-dot::before {
    content: ''; width: 5px; height: 5px;
    border-radius: 50%; background: currentColor; flex-shrink: 0;
}

/* ═══════════════════════════════════════════
   TABLES
═══════════════════════════════════════════ */
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
.table thead th {
    padding: 0.6rem 0.95rem;
    background: rgba(255,255,255,0.022);
    color: var(--txt-3); font-size: 0.62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1.2px;
    text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap;
}
.table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.14s; }
.table tbody tr:last-child { border-bottom: none; }
.table tbody tr:hover { background: rgba(255,255,255,0.016); }
.table td { padding: 0.7rem 0.95rem; vertical-align: middle; }
.table-empty { text-align: center; padding: 3rem !important; color: var(--txt-3) !important; }

/* ═══════════════════════════════════════════
   FORMS
═══════════════════════════════════════════ */
.form-grid   { display: grid; grid-template-columns: 1fr 1fr;    gap: 1rem; }
.form-grid-3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }
.form-full   { grid-column: 1 / -1; }
.form-group  { display: flex; flex-direction: column; gap: 0.32rem; }
.form-label  { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--txt-3); }
.form-input, .form-select, .form-textarea {
    width: 100%; padding: 0.62rem 0.88rem;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); color: var(--txt);
    font-family: var(--font-b); font-size: 0.83rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    -webkit-appearance: none;
}
.form-input::placeholder, .form-textarea::placeholder { color: var(--txt-3); }
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none; border-color: var(--c); box-shadow: 0 0 0 3px var(--c-dim);
}
.form-select option { background: var(--bg-2); }
.form-textarea { resize: vertical; min-height: 82px; }
.form-hint { font-size: 0.68rem; color: var(--txt-3); }

/* ═══════════════════════════════════════════
   MODALS
═══════════════════════════════════════════ */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(2,3,8,0.88);
    backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
    z-index: 9000; padding: 1rem;
}
.modal-overlay:not(.hidden) { display: flex; }
.modal {
    background: var(--bg-2); border: 1px solid var(--border-c);
    border-radius: 16px; box-shadow: 0 30px 70px rgba(0,0,0,0.5);
    width: 100%; max-width: 640px; max-height: 90vh;
    display: flex; flex-direction: column;
    animation: mIn 0.2s ease;
}
.modal-lg { max-width: 800px; }
.modal-sm { max-width: 440px; }
@keyframes mIn { from { opacity:0; transform:translateY(-10px) scale(0.98); } to { opacity:1; transform:none; } }

.modal-head {
    padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.modal-head h2 { font-family: var(--font-d); font-size: 0.96rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
.modal-head h2 i { color: var(--c); }
.modal-close {
    width: 30px; height: 30px; border-radius: 7px;
    background: transparent; border: 1px solid var(--border);
    color: var(--txt-2); font-size: 1rem;
    display: flex; align-items: center; justify-content: center;
    transition: var(--tr);
}
.modal-close:hover { background: rgba(239,68,68,0.15); color: var(--red); border-color: rgba(239,68,68,0.3); }
.modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
.modal-foot {
    padding: 1rem 1.5rem; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 0.55rem;
    flex-shrink: 0; background: rgba(0,0,0,0.12);
}

/* ═══════════════════════════════════════════
   ALERTS
═══════════════════════════════════════════ */
.alert {
    display: flex; align-items: center; gap: 0.65rem;
    padding: 0.75rem 1.05rem; border-radius: var(--radius-sm);
    font-size: 0.82rem; font-weight: 500; margin-bottom: 1.2rem; border: 1px solid;
}
.alert-success { background: rgba(34,197,94,0.08);  border-color: rgba(34,197,94,0.24);  color: var(--green); }
.alert-error   { background: rgba(239,68,68,0.08);  border-color: rgba(239,68,68,0.24);  color: var(--red); }
.alert-warning { background: rgba(245,158,11,0.08); border-color: rgba(245,158,11,0.24); color: var(--yellow); }
.alert-info    { background: rgba(96,165,250,0.08); border-color: rgba(96,165,250,0.24); color: var(--blue); }

/* ═══════════════════════════════════════════
   PROGRESS
═══════════════════════════════════════════ */
.progress { height: 5px; background: rgba(255,255,255,0.06); border-radius: 10px; overflow: hidden; }
.progress-fill { height: 100%; background: linear-gradient(90deg, var(--c), var(--c-lt)); border-radius: 10px; transition: width 0.5s; }
.progress-fill.green  { background: linear-gradient(90deg, #15803d, var(--green)); }
.progress-fill.yellow { background: linear-gradient(90deg, #b45309, var(--yellow)); }
.progress-fill.red    { background: linear-gradient(90deg, #b91c1c, var(--red)); }

/* ═══════════════════════════════════════════
   UTILS
═══════════════════════════════════════════ */
.hidden    { display: none !important; }
.flex      { display: flex; }
.f-center  { display: flex; align-items: center; }
.f-between { display: flex; align-items: center; justify-content: space-between; }
.f-wrap    { flex-wrap: wrap; }
.gap-1 { gap: 0.4rem; } .gap-2 { gap: 0.7rem; } .gap-3 { gap: 1rem; }
.mb-1 { margin-bottom: 0.5rem; } .mb-2 { margin-bottom: 1rem; }
.mb-3 { margin-bottom: 1.4rem; } .mb-4 { margin-bottom: 2rem; }
.mt-1 { margin-top: 0.5rem; } .mt-2 { margin-top: 1rem; }
.divider { height: 1px; background: var(--border); margin: 1.2rem 0; }

.txt-2 { color: var(--txt-2); } .txt-3 { color: var(--txt-3); }
.txt-green  { color: var(--green); }  .txt-red    { color: var(--red); }
.txt-yellow { color: var(--yellow); } .txt-blue   { color: var(--blue); }
.txt-purple { color: var(--c-lt); }   .txt-pink   { color: var(--pink); }
.txt-money { color: var(--green); font-weight: 700; font-variant-numeric: tabular-nums; }
.txt-neg   { color: var(--red);   font-weight: 700; }
.fw-700 { font-weight: 700; } .fw-800 { font-weight: 800; font-family: var(--font-d); }
.fs-sm  { font-size: 0.78rem; } .fs-xs  { font-size: 0.68rem; }
.nowrap { white-space: nowrap; }

.avatar {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; font-weight: 700; flex-shrink: 0;
}
.avatar-purple { background: rgba(139,92,246,0.18); color: #a78bfa; }
.avatar-green  { background: rgba(34,197,94,0.15);  color: var(--green); }
.avatar-blue   { background: rgba(96,165,250,0.15); color: var(--blue); }
.avatar-yellow { background: rgba(245,158,11,0.15); color: var(--yellow); }
.avatar-pink   { background: rgba(244,114,182,0.15);color: var(--pink); }

.filter-bar { display: flex; gap: 0.38rem; flex-wrap: wrap; }
.filter-btn {
    padding: 0.32rem 0.8rem; border-radius: 20px;
    border: 1px solid var(--border); background: transparent;
    color: var(--txt-2); font-size: 0.73rem; font-weight: 600;
    cursor: pointer; transition: var(--tr); font-family: var(--font-b);
}
.filter-btn:hover, .filter-btn.active {
    background: var(--c-dim); border-color: var(--border-c); color: var(--c-lt);
}

.toolbar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 0.7rem; flex-wrap: wrap; margin-bottom: 1.2rem;
}

/* ═══════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════ */
@media (max-width: 1100px) { .grid-4 { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
    .grid-2 { grid-template-columns: 1fr; }
    .grid-3 { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 640px) {
    .page { padding: 1rem; }
    .kpi-grid { grid-template-columns: 1fr 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    .grid-3, .grid-4 { grid-template-columns: 1fr; }
}

    </style>
    <?php echo $__panelThemeCSS; ?>
</head>
<body>

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">🎵</div>
        <?php echo htmlspecialchars($__panelName); ?>
        <?php echo htmlspecialchars($__panelSubtitle); ?>
    </div>

        <nav class="sidebar-nav">
        <?php
        $__triSec = '';
        foreach ($__triNavPages as $__p):
            if (!empty($__p['section']) && $__p['section'] !== $__triSec):
                $__triSec = $__p['section'];
                echo '<div class="nav-section-title">' . htmlspecialchars($__triSec) . '</div>';
            endif;
            $__active = ($currentPage === $__p['file']) ? ' active' : '';
        ?>
        <a href="<?php echo $__p['file']; ?>.php" class="nav-link<?php echo $__active; ?>">
            <i class="<?php echo $__p['icon']; ?>"></i><span><?php echo htmlspecialchars($__p['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        
        <?php
        // -- Acces info_vente (independant de faction_helper) ------
        if (!isset($__ivRes)) {
            $__ivRes = false;
            if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                $__ivRes = true;
            } elseif (($_SESSION['user_faction'] ?? '') === 'TRIADS') {
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
                        $__ivQ->execute(['TRIADS', $__ivGrd]);
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
<a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="nav-link">
            <i class="fas fa-home"></i><span>Accueil MDT</span>
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="nav-link" style="color:var(--red);">
            <i class="fas fa-sign-out-alt"></i><span>Déconnexion</span>
        </a>
    </div>
</aside>

<!-- ═══ MAIN ═══ -->
<main class="main">
    <header class="topbar">
        <div class="f-center gap-2">
            <h1 class="topbar-title"><?php echo htmlspecialchars($pageTitle ?? ''); ?></h1>
        </div>
        <div class="f-center gap-2">
            <div class="user-chip">
                <i class="fas fa-circle" style="color:var(--green);font-size:0.45rem;"></i>
                <?php echo htmlspecialchars(mdt_session_display_name() ?: '—'); ?>
                <span style="color:var(--txt-3);">·</span>
                <?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?>
            </div>
        </div>
    </header>
    <div class="page">