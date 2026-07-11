<?php
// ════════════════════════════════════════════════════════════
// pages/companies/ammunation/includes/header.php
// ════════════════════════════════════════════════════════════
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive('AMMUNATION');

$__ammunationAllPages = [
    ['file'=>'dashboard',       'label'=>'Dashboard',         'icon'=>'fas fa-chart-line',         'section'=>''],
    ['file'=>'effectifs',       'label'=>'Effectifs',         'icon'=>'fas fa-users',              'section'=>'Ressources Humaines'],
    ['file'=>'prises-service',  'label'=>'Prises de Service', 'icon'=>'fas fa-clock',              'section'=>''],
    ['file'=>'sanctions',       'label'=>'Sanctions',         'icon'=>'fas fa-gavel',              'section'=>''],
    ['file'=>'stock',           'label'=>'Stock',             'icon'=>'fas fa-boxes',              'section'=>'Gestion Commerciale'],
    ['file'=>'vente',           'label'=>'Point de Vente',    'icon'=>'fas fa-cash-register',      'section'=>''],
    ['file'=>'facturation',     'label'=>'Facturation',       'icon'=>'fas fa-file-invoice-dollar','section'=>''],
    ['file'=>'depenses',        'label'=>'Dépenses',          'icon'=>'fas fa-money-bill-wave',    'section'=>''],
];
$__ammunationNavPages = getActiveNavItems('AMMUNATION', $__ammunationAllPages);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Panel'; ?> — <?php echo $companyName; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        :root {
            --cc:   #dc2626;
            --cc-d: #991b1b;
            --cc-l: #fca5a5;
            --r:    220;
            --g:    38;
            --b:    38;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #080c17;
            color: #e2e8f0;
            overflow-x: hidden;
        }

        /* ── SIDEBAR ─────────────────────────────────────────── */
        .company-sidebar {
            position: fixed; left:0; top:0;
            width: 268px; height: 100vh;
            background: linear-gradient(180deg, #0d1526 0%, #080c17 100%);
            border-right: 1px solid rgba(220,38,38,.18);
            overflow-y: auto; z-index:1000;
            box-shadow: 4px 0 40px rgba(0,0,0,.4);
        }
        .company-sidebar::-webkit-scrollbar { width:4px; }
        .company-sidebar::-webkit-scrollbar-thumb { background:rgba(220,38,38,.3); border-radius:2px; }

        .sidebar-header {
            padding: 1.75rem 1.5rem 1.5rem;
            border-bottom: 1px solid rgba(220,38,38,.12);
            position: relative; overflow: hidden;
        }
        .sidebar-header::before {
            content:'';
            position:absolute; top:-30px; right:-30px;
            width:120px; height:120px;
            background: radial-gradient(circle, rgba(220,38,38,.15) 0%, transparent 70%);
            pointer-events:none;
        }
        .company-logo {
            width:52px; height:52px;
            background: linear-gradient(135deg, var(--cc), var(--cc-d));
            border-radius:13px;
            display:flex; align-items:center; justify-content:center;
            font-size:1.5rem; margin-bottom:.875rem;
            box-shadow: 0 6px 24px rgba(220,38,38,.35);
        }
        .company-name {
            font-size:1.15rem; font-weight:800;
            color:#f1f5f9; letter-spacing:-.3px;
        }
        .company-subtitle { font-size:.8rem; color:#64748b; margin-top:.2rem; }

        .sidebar-nav { padding: 1rem 0 2rem; }

        .nav-section-title {
            padding: .875rem 1.5rem .4rem;
            font-size:.65rem; font-weight:700;
            text-transform:uppercase; letter-spacing:.12em;
            color:#475569;
        }
        .nav-item {
            display:flex; align-items:center; gap:.7rem;
            padding:.8rem 1.5rem;
            color:#64748b; text-decoration:none;
            font-weight:500; font-size:.875rem;
            transition:all .22s; position:relative;
        }
        .nav-item::before {
            content:''; position:absolute; left:0; top:50%;
            transform:translateY(-50%);
            width:3px; height:0;
            background: var(--cc);
            border-radius:0 3px 3px 0;
            transition:height .22s;
            box-shadow:0 0 8px var(--cc);
        }
        .nav-item:hover, .nav-item.active {
            color: var(--cc-l);
            background: rgba(220,38,38,.07);
        }
        .nav-item:hover::before, .nav-item.active::before { height:65%; }
        .nav-item i { width:18px; text-align:center; font-size:.9rem; }
        .nav-divider { height:1px; background:rgba(220,38,38,.1); margin:.75rem 1.5rem; }

        /* ── MAIN CONTENT ───────────────────────────────────── */
        .main-content { margin-left:268px; min-height:100vh; }

        .company-topbar {
            position:sticky; top:0; z-index:100;
            background:rgba(8,12,23,.93); backdrop-filter:blur(12px);
            border-bottom:1px solid rgba(220,38,38,.14);
            padding:1.1rem 2rem;
            display:flex; justify-content:space-between; align-items:center;
        }
        .page-title {
            font-size:1.35rem; font-weight:800;
            letter-spacing:-.4px; color:#f1f5f9;
        }
        .user-badge {
            padding:.5rem 1.1rem;
            background:rgba(220,38,38,.1);
            border:1px solid rgba(220,38,38,.25);
            border-radius:20px;
            font-weight:600; font-size:.82rem;
            color:var(--cc-l);
        }
        .page-content { padding:1.75rem 2rem; }

        /* ── CARDS ─────────────────────────────────────────── */
        .card {
            background: rgba(13,21,38,.7);
            border: 1px solid rgba(220,38,38,.14);
            border-radius:14px; padding:1.5rem;
            margin-bottom:1.5rem; transition:all .25s;
        }
        .card:hover {
            border-color:rgba(220,38,38,.3);
            box-shadow:0 6px 28px rgba(0,0,0,.25);
        }
        .card-title {
            font-size:1rem; font-weight:700;
            color:#f1f5f9; margin-bottom:1.25rem;
            display:flex; align-items:center; gap:.6rem;
        }
        .card-title i { color:var(--cc); }

        /* ── BUTTONS ─────────────────────────────────────────── */
        .btn {
            display:inline-flex; align-items:center; gap:.55rem;
            padding:.7rem 1.35rem;
            border-radius:9px; font-weight:600; font-size:.875rem;
            cursor:pointer; transition:all .22s;
            text-decoration:none; border:none; font-family:inherit;
        }
        .btn-primary {
            background:linear-gradient(135deg, var(--cc), var(--cc-d));
            color:#fff;
            box-shadow:0 3px 12px rgba(220,38,38,.3);
        }
        .btn-primary:hover { transform:translateY(-2px); box-shadow:0 5px 20px rgba(220,38,38,.4); }
        .btn-secondary {
            background:rgba(220,38,38,.09);
            color:var(--cc-l);
            border:1px solid rgba(220,38,38,.25);
        }
        .btn-secondary:hover { background:rgba(220,38,38,.15); border-color:rgba(220,38,38,.4); }
        .btn-ghost { background:transparent; color:#64748b; border:1px solid rgba(255,255,255,.08); }
        .btn-ghost:hover { background:rgba(255,255,255,.05); color:#e2e8f0; }
        .btn-sm { padding:.45rem .9rem; font-size:.8rem; }
        .btn-danger { background:rgba(239,68,68,.12); color:#f87171; border:1px solid rgba(239,68,68,.25); }
        .btn-danger:hover { background:rgba(239,68,68,.22); }

        /* ── FORMS ─────────────────────────────────────────── */
        .form-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:1.25rem; }
        .form-full  { grid-column:1/-1; }
        .form-group { display:flex; flex-direction:column; gap:.4rem; }
        .form-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#64748b; }
        .form-input, .form-select, .form-textarea {
            width:100%; padding:.75rem .95rem;
            background:#0a0f1e; border:1px solid rgba(220,38,38,.18);
            border-radius:8px; color:#e2e8f0;
            font-family:inherit; font-size:.875rem; transition:all .22s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline:none; border-color:var(--cc);
            box-shadow:0 0 0 3px rgba(220,38,38,.12);
        }
        .form-input::placeholder { color:#334155; }
        .form-select option { background:#0d1526; }
        .form-textarea { resize:vertical; min-height:90px; }

        /* ── MODALS ─────────────────────────────────────────── */
        .modal-overlay {
            position:fixed; inset:0;
            background:rgba(0,0,0,.8); backdrop-filter:blur(8px);
            display:none; align-items:center; justify-content:center;
            z-index:9999; padding:1.5rem;
        }
        .modal-overlay:not(.hidden) { display:flex; }
        .modal {
            background:linear-gradient(140deg, #0d1526 0%, #080c17 100%);
            border:1px solid rgba(220,38,38,.28);
            border-radius:18px;
            box-shadow:0 0 80px rgba(0,0,0,.6);
            width:90%; max-width:680px; max-height:90vh;
            display:flex; flex-direction:column; overflow:hidden;
        }
        .modal-sm { max-width:460px; }
        .modal-header {
            padding:1.5rem 1.75rem;
            border-bottom:1px solid rgba(220,38,38,.14);
            display:flex; justify-content:space-between; align-items:center;
            background:rgba(220,38,38,.04);
        }
        .modal-header h2 { font-size:1rem; font-weight:700; color:#f1f5f9; display:flex; align-items:center; gap:.65rem; }
        .modal-header h2 i { color:var(--cc); }
        .modal-close {
            width:36px; height:36px;
            border:1px solid rgba(220,38,38,.2); border-radius:9px;
            background:rgba(220,38,38,.07); color:#f87171;
            font-size:1.25rem; cursor:pointer; transition:all .22s;
            display:flex; align-items:center; justify-content:center;
        }
        .modal-close:hover { background:rgba(239,68,68,.22); transform:rotate(90deg); }
        .modal-body { padding:1.75rem; overflow-y:auto; flex:1; }
        .modal-footer {
            padding:1.25rem 1.75rem;
            border-top:1px solid rgba(220,38,38,.1);
            background:rgba(0,0,0,.2);
            display:flex; justify-content:flex-end; gap:.75rem;
        }

        /* ── ALERTS ─────────────────────────────────────────── */
        .alert {
            padding:.9rem 1.25rem; border-radius:10px;
            margin-bottom:1.5rem; display:flex;
            align-items:center; gap:.7rem; font-weight:500;
            font-size:.875rem;
        }
        .alert-success { background:rgba(34,197,94,.09); border:1px solid rgba(34,197,94,.25); color:#4ade80; }
        .alert-error   { background:rgba(239,68,68,.09); border:1px solid rgba(239,68,68,.25);  color:#f87171; }
        .alert-info    { background:rgba(59,130,246,.09); border:1px solid rgba(59,130,246,.25); color:#93c5fd; }
        .alert-warning { background:rgba(245,158,11,.09); border:1px solid rgba(245,158,11,.25); color:#fcd34d; }

        /* ── TABLES ─────────────────────────────────────────── */
        .data-table { width:100%; border-collapse:collapse; }
        .data-table th {
            text-align:left; padding:.85rem 1rem;
            background:rgba(220,38,38,.07);
            border-bottom:2px solid rgba(220,38,38,.2);
            font-size:.72rem; font-weight:700;
            text-transform:uppercase; letter-spacing:.1em;
            color:#94a3b8;
        }
        .data-table td { padding:.85rem 1rem; border-bottom:1px solid rgba(255,255,255,.05); font-size:.875rem; }
        .data-table tr:hover td { background:rgba(220,38,38,.04); }
        .data-table tr:last-child td { border-bottom:none; }

        /* ── BADGES ─────────────────────────────────────────── */
        .badge {
            display:inline-flex; align-items:center; gap:.35rem;
            padding:.3rem .7rem; border-radius:6px;
            font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
        }
        .badge-green  { background:rgba(34,197,94,.12);  color:#4ade80;  border:1px solid rgba(34,197,94,.2); }
        .badge-red    { background:rgba(239,68,68,.12);  color:#f87171;  border:1px solid rgba(239,68,68,.2); }
        .badge-yellow { background:rgba(245,158,11,.12); color:#fcd34d;  border:1px solid rgba(245,158,11,.2); }
        .badge-blue   { background:rgba(59,130,246,.12); color:#93c5fd;  border:1px solid rgba(59,130,246,.2); }
        .badge-gray   { background:rgba(100,116,139,.12);color:#94a3b8;  border:1px solid rgba(100,116,139,.2); }
        .badge-orange { background:rgba(249,115,22,.12); color:#fb923c;  border:1px solid rgba(249,115,22,.2); }

        /* ── MISC ─────────────────────────────────────────── */
        .hidden { display:none !important; }
        .text-muted { color:#64748b; }
        .text-green { color:#4ade80; }
        .text-red   { color:#f87171; }
        .text-blue  { color:#93c5fd; }
        .text-yellow{ color:#fcd34d; }
        .mono { font-family:'Courier New',monospace; }

        @media (max-width:1024px) {
            .company-sidebar { transform:translateX(-100%); }
            .main-content { margin-left:0; }
            .form-grid { grid-template-columns:1fr; }
        }
    </style>
    <?php echo $__panelThemeCSS; ?>
</head>
<body>
<aside class="company-sidebar">
    <div class="sidebar-header">
        <div class="company-logo"><i class="fas fa-crosshairs"></i></div>
        <div class="company-name"><?php echo htmlspecialchars($__panelName); ?></div>
        <div class="company-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
    </div>
    <nav class="sidebar-nav">
        <?php
        $__sec = '';
        foreach ($__ammunationNavPages as $__p):
            if (!empty($__p['section']) && $__p['section'] !== $__sec):
                $__sec = $__p['section'];
                echo '<div class="nav-section-title">' . htmlspecialchars($__sec) . '</div>';
            endif;
            $__active = ($currentPage === $__p['file']) ? ' active' : '';
        ?>
        <a href="<?php echo $__p['file']; ?>.php" class="nav-item<?php echo $__active; ?>">
            <i class="<?php echo $__p['icon']; ?>"></i>
            <span><?php echo htmlspecialchars($__p['label']); ?></span>
        </a>
        <?php endforeach; ?>
        <div class="nav-divider"></div>
        
        <?php
        // -- Acces info_vente (independant de faction_helper) ------
        if (!isset($__ivRes)) {
            $__ivRes = false;
            if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                $__ivRes = true;
            } elseif (($_SESSION['user_faction'] ?? '') === 'AMMUNATION') {
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
                        $__ivQ->execute(['AMMUNATION', $__ivGrd]);
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
</aside>

<main class="main-content">
    <div class="company-topbar">
        <h1 class="page-title"><?php echo htmlspecialchars($pageTitle ?? ''); ?></h1>
        <span class="user-badge">
            <?php echo htmlspecialchars(mdt_session_display_name()); ?>
            &nbsp;·&nbsp;
            <?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?>
        </span>
    </div>
    <div class="page-content">