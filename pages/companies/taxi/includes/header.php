<?php
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive('TAXI');
$__taxiAllPages = [
    ['file'=>'dashboard', 'label'=>'Dashboard', 'icon'=>'fas fa-chart-line', 'section'=>''],
    ['file'=>'dispatch', 'label'=>'Dispatch', 'icon'=>'fas fa-broadcast-tower', 'section'=>'Opérations'],
    ['file'=>'courses', 'label'=>'Courses', 'icon'=>'fas fa-route', 'section'=>''],
    ['file'=>'chauffeurs', 'label'=>'Chauffeurs', 'icon'=>'fas fa-id-badge', 'section'=>''],
    ['file'=>'prises-service', 'label'=>'Prises de Service', 'icon'=>'fas fa-clock', 'section'=>''],
    ['file'=>'effectifs', 'label'=>'Effectifs', 'icon'=>'fas fa-users', 'section'=>'Ressources Humaines'],
    ['file'=>'sanctions', 'label'=>'Sanctions', 'icon'=>'fas fa-gavel', 'section'=>''],
    ['file'=>'clients', 'label'=>'Clients', 'icon'=>'fas fa-user-tie', 'section'=>'Commercial'],
    ['file'=>'tarifs', 'label'=>'Tarifs', 'icon'=>'fas fa-tags', 'section'=>''],
    ['file'=>'facturation', 'label'=>'Facturation', 'icon'=>'fas fa-file-invoice-dollar', 'section'=>'Finance'],
    ['file'=>'depenses', 'label'=>'Dépenses', 'icon'=>'fas fa-money-bill-wave', 'section'=>''],
    ['file'=>'stats', 'label'=>'Statistiques', 'icon'=>'fas fa-chart-bar', 'section'=>'Analyse'],
    ['file'=>'incidents', 'label'=>'Incidents', 'icon'=>'fas fa-exclamation-triangle', 'section'=>''],
];
$__taxiNavPages = getActiveNavItems('TAXI', $__taxiAllPages);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Panel'; ?> - <?php echo $companyName; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        :root {
            --col: #f59e0b;
            --col-dk: #b45309;
            --col-lt: #fcd34d;
            --bg:  #080b12;
            --bg2: #0c1018;
            --bg3: #111827;
            --border: rgba(245,158,11,0.18);
            --text: #f1f5f9;
            --muted: #64748b;
        }

        body { font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif; background:var(--bg); color:var(--text); overflow-x:hidden; }

        /* ── SIDEBAR ─────────────────────────────── */
        .sidebar {
            position:fixed; left:0; top:0;
            width:260px; height:100vh;
            background:linear-gradient(180deg,#0d1117 0%,var(--bg) 100%);
            border-right:1px solid var(--border);
            overflow-y:auto; z-index:1000;
            box-shadow:4px 0 30px rgba(0,0,0,.5);
        }

        .sidebar::-webkit-scrollbar { width:4px; }
        .sidebar::-webkit-scrollbar-track { background:transparent; }
        .sidebar::-webkit-scrollbar-thumb { background:rgba(245,158,11,.2); border-radius:4px; }

        .sb-header {
            padding:1.5rem 1.25rem 1.25rem;
            border-bottom:1px solid var(--border);
            background:linear-gradient(135deg,rgba(245,158,11,.1),transparent);
        }

        .sb-logo {
            display:flex; align-items:center; gap:.75rem; margin-bottom:.35rem;
        }

        .sb-logo-icon {
            width:42px; height:42px;
            background:linear-gradient(135deg,var(--col),var(--col-dk));
            border-radius:11px;
            display:flex; align-items:center; justify-content:center;
            font-size:1.3rem;
            box-shadow:0 4px 20px rgba(245,158,11,.4);
            flex-shrink:0;
        }

        .sb-company { font-size:1.05rem; font-weight:800; letter-spacing:-.2px; color:var(--text); }
        .sb-sub     { font-size:.75rem; color:var(--muted); margin-top:.15rem; padding-left:.1rem; }

        /* Statut dispatcher en ligne */
        .dispatcher-status {
            margin-top:.85rem;
            display:flex; align-items:center; gap:.5rem;
            padding:.5rem .75rem;
            background:rgba(245,158,11,.07);
            border:1px solid rgba(245,158,11,.18);
            border-radius:8px;
            font-size:.75rem; color:var(--col-lt);
            font-weight:600;
        }
        .dispatcher-status .live-dot {
            width:7px; height:7px; border-radius:50%;
            background:var(--col);
            animation:pulse 2s infinite;
        }
        @keyframes pulse { 0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.4);} 50%{box-shadow:0 0 0 5px rgba(245,158,11,0);} }

        .sb-nav { padding:1rem 0; }

        .nav-section {
            padding:.75rem 1.25rem .3rem;
            font-size:.66rem; font-weight:700;
            text-transform:uppercase; letter-spacing:2px;
            color:#374151;
        }

        .nav-item {
            display:flex; align-items:center; gap:.7rem;
            padding:.72rem 1.25rem;
            color:var(--muted);
            text-decoration:none;
            font-weight:500; font-size:.86rem;
            transition:all .22s;
            border-left:3px solid transparent;
            position:relative;
        }

        .nav-item:hover, .nav-item.active {
            color:var(--col-lt);
            background:rgba(245,158,11,.07);
            border-left-color:var(--col);
        }

        .nav-item i { width:17px; text-align:center; font-size:.88rem; }

        .nav-badge {
            margin-left:auto;
            background:rgba(239,68,68,.15);
            color:#f87171;
            border:1px solid rgba(239,68,68,.3);
            border-radius:20px;
            padding:.12rem .5rem;
            font-size:.7rem; font-weight:700;
        }

        .nav-badge.amber {
            background:rgba(245,158,11,.12);
            color:var(--col-lt);
            border-color:rgba(245,158,11,.3);
        }

        .nav-divider { height:1px; background:var(--border); margin:.65rem 1.25rem; }

        /* ── MAIN ───────────────────────────────── */
        .main { margin-left:260px; min-height:100vh; }

        .topbar {
            position:sticky; top:0; z-index:100;
            background:rgba(8,11,18,.96);
            backdrop-filter:blur(12px);
            border-bottom:1px solid var(--border);
            padding:1rem 1.75rem;
            display:flex; justify-content:space-between; align-items:center;
        }

        .topbar-left { display:flex; flex-direction:column; gap:.1rem; }

        .page-title  { font-size:1.3rem; font-weight:800; letter-spacing:-.4px; color:var(--text); }
        .page-sub    { font-size:.75rem; color:var(--muted); }

        .topbar-right { display:flex; align-items:center; gap:.6rem; }

        .user-pill {
            padding:.45rem .95rem;
            background:rgba(245,158,11,.1);
            border:1px solid rgba(245,158,11,.28);
            border-radius:20px;
            font-size:.8rem; font-weight:600;
            color:var(--col-lt);
            display:flex; align-items:center; gap:.4rem;
        }

        .page-content { padding:1.5rem 1.75rem; }

        /* ── CARDS ──────────────────────────────── */
        .card {
            background:rgba(13,17,23,.7);
            border:1px solid var(--border);
            border-radius:14px;
            padding:1.4rem;
            margin-bottom:1.4rem;
            transition:border-color .22s, box-shadow .22s;
        }
        .card:hover { border-color:rgba(245,158,11,.35); box-shadow:0 6px 28px rgba(0,0,0,.3); }

        .card-hd {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:1.15rem;
        }
        .card-title {
            font-size:.95rem; font-weight:700; color:var(--text);
            display:flex; align-items:center; gap:.55rem;
        }
        .card-title i { color:var(--col); }

        /* ── STAT CARDS ─────────────────────────── */
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1.1rem; margin-bottom:1.4rem; }

        .stat-card {
            background:rgba(13,17,23,.8);
            border:1px solid var(--border);
            border-radius:13px; padding:1.2rem;
            position:relative; overflow:hidden;
            transition:all .22s;
        }
        .stat-card:hover { border-color:rgba(245,158,11,.4); box-shadow:0 4px 20px rgba(245,158,11,.08); }
        .stat-card::after {
            content:''; position:absolute;
            top:-15px; right:-15px;
            width:70px; height:70px;
            background:radial-gradient(circle,rgba(245,158,11,.12),transparent 70%);
            border-radius:50%;
        }

        .stat-icon {
            width:40px; height:40px;
            background:rgba(245,158,11,.12);
            border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            color:var(--col); font-size:1.1rem;
            margin-bottom:.75rem;
        }

        .stat-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin-bottom:.25rem; }
        .stat-value { font-size:1.85rem; font-weight:800; color:var(--text); letter-spacing:-1px; line-height:1; }
        .stat-sub   { font-size:.73rem; color:var(--muted); margin-top:.3rem; }
        .stat-up    { color:#4ade80; }
        .stat-dn    { color:#f87171; }

        /* ── BUTTONS ────────────────────────────── */
        .btn {
            display:inline-flex; align-items:center; gap:.5rem;
            padding:.6rem 1.15rem;
            border-radius:9px; font-weight:600; font-size:.84rem;
            cursor:pointer; transition:all .22s;
            text-decoration:none; border:none; font-family:inherit;
        }
        .btn-primary  { background:linear-gradient(135deg,var(--col),var(--col-dk)); color:#000; box-shadow:0 3px 12px rgba(245,158,11,.35); }
        .btn-primary:hover { transform:translateY(-1px); box-shadow:0 5px 20px rgba(245,158,11,.5); }
        .btn-secondary{ background:rgba(245,158,11,.1); color:var(--col-lt); border:1px solid rgba(245,158,11,.28); }
        .btn-secondary:hover { background:rgba(245,158,11,.18); }
        .btn-danger   { background:rgba(239,68,68,.12); color:#f87171; border:1px solid rgba(239,68,68,.28); }
        .btn-success  { background:rgba(34,197,94,.12); color:#4ade80; border:1px solid rgba(34,197,94,.28); }
        .btn-info     { background:rgba(59,130,246,.1); color:#60a5fa; border:1px solid rgba(59,130,246,.25); }
        .btn-warning  { background:rgba(245,158,11,.12); color:var(--col-lt); border:1px solid rgba(245,158,11,.28); }
        .btn-sm  { padding:.38rem .8rem; font-size:.78rem; }
        .btn-xs  { padding:.28rem .6rem; font-size:.73rem; }

        /* ── FORMS ──────────────────────────────── */
        .form-grid  { display:grid; grid-template-columns:repeat(2,1fr); gap:1.1rem; }
        .form-grid3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1.1rem; }
        .form-full  { grid-column:1/-1; }
        .form-group { display:flex; flex-direction:column; gap:.4rem; }
        .form-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--muted); }

        .form-input, .form-select, .form-textarea {
            width:100%;
            padding:.7rem .9rem;
            background:var(--bg2);
            border:1px solid rgba(245,158,11,.18);
            border-radius:8px;
            color:var(--text);
            font-family:inherit; font-size:.85rem;
            transition:all .22s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline:none;
            border-color:var(--col);
            box-shadow:0 0 0 3px rgba(245,158,11,.12);
        }
        .form-select option { background:var(--bg2); }
        .form-textarea { resize:vertical; min-height:90px; }

        /* ── TABLES ─────────────────────────────── */
        .data-table { width:100%; border-collapse:collapse; }
        .data-table th {
            padding:.65rem .9rem;
            font-size:.7rem; font-weight:700;
            text-transform:uppercase; letter-spacing:1.5px;
            color:var(--muted);
            border-bottom:1px solid rgba(245,158,11,.1);
            text-align:left; white-space:nowrap;
        }
        .data-table td {
            padding:.8rem .9rem;
            border-bottom:1px solid rgba(245,158,11,.06);
            font-size:.84rem; color:#cbd5e1;
            vertical-align:middle;
        }
        .data-table tr:last-child td { border-bottom:none; }
        .data-table tr:hover td { background:rgba(245,158,11,.03); }

        /* ── BADGES ─────────────────────────────── */
        .badge {
            display:inline-flex; align-items:center; gap:.28rem;
            padding:.22rem .6rem;
            border-radius:20px;
            font-size:.7rem; font-weight:700;
            text-transform:uppercase; letter-spacing:.4px;
        }
        /* statuts course */
        .b-attente    { background:rgba(245,158,11,.12); color:var(--col-lt); border:1px solid rgba(245,158,11,.28); }
        .b-assignee   { background:rgba(59,130,246,.1);  color:#60a5fa;      border:1px solid rgba(59,130,246,.25); }
        .b-en_cours   { background:rgba(124,58,237,.12); color:#a78bfa;      border:1px solid rgba(124,58,237,.28); animation:blink-border 1.5s infinite; }
        .b-terminee   { background:rgba(34,197,94,.1);   color:#4ade80;      border:1px solid rgba(34,197,94,.25); }
        .b-annulee    { background:rgba(239,68,68,.1);   color:#f87171;      border:1px solid rgba(239,68,68,.25); }
        /* statuts chauffeur */
        .b-disponible   { background:rgba(34,197,94,.1);   color:#4ade80;  border:1px solid rgba(34,197,94,.25); }
        .b-en_course    { background:rgba(245,158,11,.12); color:var(--col-lt); border:1px solid rgba(245,158,11,.28); animation:blink-border 1.5s infinite; }
        .b-pause        { background:rgba(59,130,246,.1);  color:#60a5fa;  border:1px solid rgba(59,130,246,.25); }
        .b-hors_service { background:rgba(107,114,128,.12);color:#9ca3af;  border:1px solid rgba(107,114,128,.22); }
        /* paiement */
        .b-especes  { background:rgba(34,197,94,.08);   color:#4ade80;  border:1px solid rgba(34,197,94,.2); }
        .b-carte    { background:rgba(59,130,246,.08);  color:#60a5fa;  border:1px solid rgba(59,130,246,.2); }
        .b-virement { background:rgba(124,58,237,.08);  color:#a78bfa;  border:1px solid rgba(124,58,237,.2); }
        .b-compte   { background:rgba(245,158,11,.08);  color:var(--col-lt); border:1px solid rgba(245,158,11,.2); }
        /* urgente */
        .b-urgente  { background:rgba(239,68,68,.14);   color:#ef4444;  border:1px solid rgba(239,68,68,.3); }

        @keyframes blink-border { 0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.3);} 50%{box-shadow:0 0 0 3px rgba(245,158,11,0);} }

        /* ── MODALS ─────────────────────────────── */
        .modal-overlay {
            position:fixed; inset:0;
            background:rgba(0,0,0,.88);
            backdrop-filter:blur(8px);
            display:none; align-items:center; justify-content:center;
            z-index:9999;
        }
        .modal-overlay:not(.hidden) { display:flex; }

        .modal {
            background:linear-gradient(135deg,#0d1117,var(--bg));
            border:1px solid rgba(245,158,11,.28);
            border-radius:18px;
            box-shadow:0 0 60px rgba(245,158,11,.08),0 0 120px rgba(0,0,0,.6);
            width:90%; max-width:720px; max-height:92vh;
            display:flex; flex-direction:column; overflow:hidden;
        }
        .modal-lg { max-width:960px; }
        .modal-sm { max-width:480px; }

        .modal-header {
            padding:1.4rem 1.65rem;
            border-bottom:1px solid rgba(245,158,11,.12);
            display:flex; justify-content:space-between; align-items:center;
            background:rgba(245,158,11,.03);
        }
        .modal-header h2 {
            font-size:.98rem; font-weight:700; color:var(--text);
            display:flex; align-items:center; gap:.6rem;
        }
        .modal-header h2 i { color:var(--col); }

        .modal-close {
            width:34px; height:34px;
            border:1px solid rgba(245,158,11,.22); border-radius:9px;
            background:rgba(245,158,11,.07); color:var(--col-lt);
            font-size:1.2rem; cursor:pointer; transition:all .22s;
            display:flex; align-items:center; justify-content:center;
        }
        .modal-close:hover { background:rgba(239,68,68,.18); color:#ef4444; transform:rotate(90deg); }

        .modal-body   { padding:1.65rem; overflow-y:auto; flex:1; }
        .modal-footer {
            padding:1.15rem 1.65rem;
            border-top:1px solid rgba(245,158,11,.1);
            background:rgba(0,0,0,.2);
            display:flex; justify-content:flex-end; gap:.65rem;
        }

        /* ── ALERTS ─────────────────────────────── */
        .alert {
            padding:.85rem 1.15rem; border-radius:10px; margin-bottom:1.15rem;
            display:flex; align-items:center; gap:.6rem;
            font-weight:500; font-size:.85rem;
        }
        .alert-success { background:rgba(34,197,94,.08);  border:1px solid rgba(34,197,94,.28);  color:#4ade80; }
        .alert-error   { background:rgba(239,68,68,.08);  border:1px solid rgba(239,68,68,.28);  color:#f87171; }
        .alert-warning { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.28); color:var(--col-lt); }
        .alert-info    { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.28); color:#60a5fa; }

        /* ── MISC ───────────────────────────────── */
        .hidden { display:none !important; }
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
        .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1.1rem; }
        .grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:1.1rem; }

        .filters-bar {
            display:flex; align-items:center; gap:.65rem;
            flex-wrap:wrap; margin-bottom:1.25rem;
        }
        .search-wrap {
            display:flex; align-items:center; gap:.45rem;
            background:var(--bg2);
            border:1px solid rgba(245,158,11,.18);
            border-radius:9px; padding:.55rem .85rem;
            flex:1; min-width:220px;
        }
        .search-wrap i { color:var(--muted); font-size:.82rem; }
        .search-wrap input {
            background:transparent; border:none; color:var(--text);
            font-family:inherit; font-size:.84rem; width:100%; outline:none;
        }

        .pagination { display:flex; gap:.35rem; align-items:center; justify-content:center; margin-top:1.25rem; }
        .page-btn {
            width:32px; height:32px;
            display:flex; align-items:center; justify-content:center;
            border-radius:7px; font-size:.82rem; font-weight:600;
            cursor:pointer; text-decoration:none;
            border:1px solid rgba(245,158,11,.18); color:var(--muted);
            background:transparent; transition:all .2s;
        }
        .page-btn:hover, .page-btn.active { background:rgba(245,158,11,.14); color:var(--col-lt); border-color:rgba(245,158,11,.38); }

        /* Rating stars */
        .stars { color:var(--col); letter-spacing:.1rem; }

        /* Live indicator */
        .live-badge {
            display:inline-flex; align-items:center; gap:.35rem;
            padding:.25rem .6rem; border-radius:20px;
            background:rgba(239,68,68,.12);
            border:1px solid rgba(239,68,68,.28);
            color:#ef4444; font-size:.7rem; font-weight:700;
        }
        .live-badge .dot { width:6px; height:6px; border-radius:50%; background:#ef4444; animation:blink-red 1s infinite; }
        @keyframes blink-red { 0%,100%{opacity:1;} 50%{opacity:.2;} }

        @media (max-width:1024px) {
            .sidebar { transform:translateX(-100%); }
            .main { margin-left:0; }
            .form-grid,.form-grid3,.grid-2,.grid-3,.grid-4 { grid-template-columns:1fr; }
        }
        .sidebar-user{background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.12);border-radius:10px;padding:.6rem .85rem;display:flex;align-items:center;gap:.68rem}
        .sidebar-user-av{width:32px;height:32px;flex-shrink:0;background:linear-gradient(135deg,#3b82f6,#1d4ed8);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;color:#fff}
        .sidebar-user-name{font-size:.79rem;font-weight:700;color:var(--tx)}
        .sidebar-user-role{font-size:.63rem;color:var(--tx2)}
    </style>
    <?php echo $__panelThemeCSS; ?>
</head>
<body>

<!-- ═══ SIDEBAR ═══════════════════════════════════════════════════════════ -->
<aside class="sidebar">
    <div class="sb-header">
        <div class="sb-logo">
            <div class="sb-logo-icon">🚕</div>
            <div>
                <div class="sb-company"><?php echo htmlspecialchars($__panelName); ?></div>
                <div class="company-subtitle" style="font-size:.63rem;color:#a8906c;margin-top:.1rem"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
            </div>
        </div>
        <div class="sb-sub">Central de Dispatch</div>
        <div class="dispatcher-status">
            <div class="live-dot"></div>
            Dispatch opérationnel
        </div>
    </div>

    <nav class="sb-nav">
        <a href="dashboard.php"    class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='dashboard.php'   ?'active':''; ?>">
            <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
        </a>
        <a href="dispatch.php"     class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='dispatch.php'     ?'active':''; ?>">
            <i class="fas fa-broadcast-tower"></i><span>Dispatch</span>
            <span class="nav-badge" id="nb-attente">–</span>
        </a>
        <a href="courses.php"      class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='courses.php'      ?'active':''; ?>">
            <i class="fas fa-route"></i><span>Courses</span>
        </a>

        <div class="nav-section">Chauffeurs</div>
        <a href="chauffeurs.php"   class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='chauffeurs.php'   ?'active':''; ?>">
            <i class="fas fa-id-card"></i><span>Chauffeurs</span>
        </a>
        <a href="prises-service.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='prises-service.php'?'active':''; ?>">
            <i class="fas fa-clock"></i><span>Prises de Service</span>
        </a>
        <a href="effectifs.php"    class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='effectifs.php'    ?'active':''; ?>">
            <i class="fas fa-users"></i><span>Effectifs</span>
        </a>
        <a href="sanctions.php"    class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='sanctions.php'    ?'active':''; ?>">
            <i class="fas fa-gavel"></i><span>Sanctions</span>
        </a>

        <div class="nav-section">Clientèle</div>
        <a href="clients.php"      class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='clients.php'      ?'active':''; ?>">
            <i class="fas fa-address-book"></i><span>Clients</span>
        </a>
        <a href="tarifs.php"       class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='tarifs.php'       ?'active':''; ?>">
            <i class="fas fa-tag"></i><span>Tarifs & Zones</span>
        </a>

        <div class="nav-section">Finance</div>
        <a href="facturation.php"  class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='facturation.php'  ?'active':''; ?>">
            <i class="fas fa-file-invoice-dollar"></i><span>Facturation</span>
        </a>
        <a href="depenses.php"     class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='depenses.php'     ?'active':''; ?>">
            <i class="fas fa-money-bill-wave"></i><span>Dépenses</span>
        </a>
        <a href="stats.php"        class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='stats.php'        ?'active':''; ?>">
            <i class="fas fa-chart-bar"></i><span>Statistiques</span>
        </a>

        <div class="nav-divider"></div>
        <a href="incidents.php"    class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='incidents.php'    ?'active':''; ?>">
            <i class="fas fa-exclamation-triangle"></i><span>Incidents</span>
        </a>
        
        <?php
        // -- Acces info_vente (independant de faction_helper) ------
        if (!isset($__ivRes)) {
            $__ivRes = false;
            if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                $__ivRes = true;
            } elseif (($_SESSION['user_faction'] ?? '') === 'TAXI') {
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
                        $__ivQ->execute(['TAXI', $__ivGrd]);
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
                <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['user_faction_rank']??'Journaliste'); ?></div>
            </div>
        </div>
</aside>

<!-- ═══ MAIN ══════════════════════════════════════════════════════════════ -->
<main class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h1 class="page-title"><?php echo $pageTitle ?? ''; ?></h1>
            <?php if (isset($pageSubtitle)): ?>
                <div class="page-sub"><?php echo $pageSubtitle; ?></div>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <div class="user-pill">
                <i class="fas fa-user-circle"></i>
                <?php echo htmlspecialchars(mdt_session_display_name()); ?>
                &nbsp;·&nbsp;
                <?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?>
            </div>
        </div>
    </div>
    <div class="page-content">