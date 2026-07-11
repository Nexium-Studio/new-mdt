<?php
// ════════════════════════════════════════════════════════════
// pages/companies/pearls-resort/includes/header.php
// VERSION AUTONOME — sans dépendance à panel_theme_auto.php
// ════════════════════════════════════════════════════════════
if (!function_exists('requireLogin')) {
    require_once dirname(__DIR__, 4) . '/config/config.php';
}
requireLogin();

if (($_SESSION['user_faction'] ?? '') !== 'PEARLS_RESORT'
    && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// Config DB sans crasher
$__panelName     = "Pearl's Resort";
$__panelSubtitle = 'Hôtel & Resort de luxe';
$__activePages   = [];
try {
    $__db = Database::getInstance()->getConnection();

    // Panel actif ?
    $__active = $__db->prepare("SELECT is_active FROM admin_panels WHERE panel_key = 'PEARLS_RESORT' LIMIT 1");
    $__active->execute(); $__active = $__active->fetch();
    if ($__active && !(bool)$__active['is_active']
        && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
        redirect(SITE_URL . '/pages/dashboard.php');
    }

    // Config personnalisée
    $__cfg = $__db->prepare("SELECT custom_name, custom_color, active_pages FROM admin_panel_config WHERE panel_key = 'PEARLS_RESORT' LIMIT 1");
    $__cfg->execute(); $__cfg = $__cfg->fetch();
    if ($__cfg) {
        if (!empty($__cfg['custom_name']))  $__panelName  = $__cfg['custom_name'];
        if (!empty($__cfg['active_pages'])) $__activePages = json_decode($__cfg['active_pages'], true) ?: [];
    }
} catch (Exception $__e) {}

// Toutes les pages
$__allPages = [
    ['file' => 'dashboard',      'label' => 'Dashboard',          'icon' => 'fas fa-chart-line',          'section' => ''],
    ['file' => 'chambres',       'label' => 'Chambres',           'icon' => 'fas fa-bed',                 'section' => 'Hôtel'],
    ['file' => 'reservations',   'label' => 'Réservations',       'icon' => 'fas fa-calendar-check',      'section' => ''],
    ['file' => 'effectifs',      'label' => 'Effectifs',          'icon' => 'fas fa-users',               'section' => 'RH'],
    ['file' => 'prises-service', 'label' => 'Prises de service',  'icon' => 'fas fa-clock',               'section' => ''],
    ['file' => 'sanctions',      'label' => 'Sanctions',          'icon' => 'fas fa-gavel',               'section' => ''],
    ['file' => 'stock',          'label' => 'Stock',              'icon' => 'fas fa-boxes',               'section' => 'Finances'],
    ['file' => 'vente',          'label' => 'Ventes',             'icon' => 'fas fa-shopping-cart',       'section' => ''],
    ['file' => 'facturation',    'label' => 'Facturation',        'icon' => 'fas fa-file-invoice-dollar', 'section' => ''],
    ['file' => 'depenses',       'label' => 'Dépenses',           'icon' => 'fas fa-money-bill-wave',     'section' => ''],
];

$__navPages = [];
foreach ($__allPages as $__p) {
    if (empty($__activePages) || ($__activePages[$__p['file']] ?? true)) {
        $__navPages[] = $__p;
    }
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> — <?php echo htmlspecialchars($__panelName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

        :root{
            --gold:#d4a853;
            --gold-dark:#a07830;
            --gold-dim:rgba(212,168,83,.12);
            --gold-glow:rgba(212,168,83,.3);
            --bg-base:#07090f;
            --bg-surf:#0c0f1a;
            --bg-card:rgba(14,18,30,.9);
            --border:rgba(212,168,83,.12);
            --border-2:rgba(212,168,83,.25);
            --text:#e8e4d8;
            --text-2:#9a9080;
            --text-3:#504840;
        }

        body{
            font-family:'DM Sans',sans-serif;
            background:var(--bg-base);
            color:var(--text);
            overflow-x:hidden;
            min-height:100vh;
            background-image:
                radial-gradient(ellipse 70% 50% at 15% 0%,rgba(212,168,83,.05) 0%,transparent 60%),
                radial-gradient(ellipse 50% 40% at 85% 100%,rgba(212,168,83,.04) 0%,transparent 60%);
        }

        /* SIDEBAR */
        .company-sidebar{
            position:fixed;left:0;top:0;width:270px;height:100vh;
            background:linear-gradient(180deg,#0c0f1a 0%,#07090f 100%);
            border-right:1px solid var(--border);
            overflow-y:auto;z-index:1000;
            display:flex;flex-direction:column;
        }
        .sidebar-header{
            padding:2rem 1.5rem 1.5rem;
            border-bottom:1px solid var(--border);flex-shrink:0;
        }
        .company-logo{
            width:56px;height:56px;
            background:linear-gradient(135deg,var(--gold),var(--gold-dark));
            border-radius:14px;
            display:flex;align-items:center;justify-content:center;
            font-size:1.6rem;margin-bottom:1rem;color:#0c0a06;
            box-shadow:0 6px 24px var(--gold-glow);
        }
        .company-name{
            font-family:'Cormorant Garamond',serif;
            font-size:1.3rem;font-weight:700;
            color:var(--gold);letter-spacing:.03em;margin-bottom:.2rem;
        }
        .company-subtitle{font-size:.78rem;color:var(--text-2);}
        .sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto;}
        .nav-section-title{
            padding:.75rem 1.5rem .35rem;
            font-size:.62rem;font-weight:700;
            text-transform:uppercase;letter-spacing:.14em;color:var(--text-3);
        }
        .nav-item{
            display:flex;align-items:center;gap:.75rem;
            padding:.8rem 1.5rem;
            color:var(--text-2);text-decoration:none;
            font-size:.875rem;font-weight:500;
            transition:all .25s;position:relative;
        }
        .nav-item::before{
            content:'';position:absolute;left:0;top:50%;
            transform:translateY(-50%);width:3px;height:0;
            background:var(--gold);transition:height .25s;border-radius:0 2px 2px 0;
        }
        .nav-item:hover,.nav-item.active{color:var(--gold);background:var(--gold-dim);}
        .nav-item:hover::before,.nav-item.active::before{height:55%;}
        .nav-item i{width:18px;text-align:center;font-size:.9rem;flex-shrink:0;}
        .nav-divider{height:1px;background:var(--border);margin:.75rem 1.5rem;}
        .sidebar-footer{padding:1.25rem 1.5rem;border-top:1px solid var(--border);flex-shrink:0;}
        .sidebar-user{display:flex;align-items:center;gap:.75rem;}
        .sidebar-avatar{
            width:34px;height:34px;border-radius:9px;
            background:var(--gold-dim);border:1px solid var(--border-2);
            display:grid;place-items:center;
            font-size:.85rem;font-weight:700;color:var(--gold);
            flex-shrink:0;overflow:hidden;
        }
        .sidebar-avatar img{width:100%;height:100%;object-fit:cover;}
        .sidebar-user-name{font-size:.83rem;font-weight:600;}
        .sidebar-user-rank{font-size:.7rem;color:var(--text-2);}

        /* MAIN */
        .main-content{margin-left:270px;min-height:100vh;display:flex;flex-direction:column;}
        .company-topbar{
            position:sticky;top:0;z-index:100;
            background:rgba(7,9,15,.92);backdrop-filter:blur(16px);
            border-bottom:1px solid var(--border);
            padding:1rem 2rem;
            display:flex;justify-content:space-between;align-items:center;
        }
        .page-title{
            font-family:'Cormorant Garamond',serif;
            font-size:1.6rem;font-weight:700;color:var(--text);
        }
        .user-badge{
            padding:.5rem 1.1rem;
            background:var(--gold-dim);border:1px solid var(--border-2);
            border-radius:20px;font-size:.8rem;font-weight:600;color:var(--gold);
        }
        .page-content{padding:2rem;flex:1;}

        /* CARDS */
        .card{
            background:var(--bg-card);border:1px solid var(--border);
            border-radius:16px;padding:1.75rem;margin-bottom:1.5rem;
            transition:border-color .25s;
        }
        .card:hover{border-color:var(--border-2);}
        .card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;}
        .card-title{
            font-family:'Cormorant Garamond',serif;
            font-size:1.1rem;font-weight:700;color:var(--text);
            display:flex;align-items:center;gap:.6rem;
        }
        .card-title i{color:var(--gold);font-size:.95rem;}

        /* STATS */
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:2rem;}
        .stat-card{
            background:var(--bg-card);border:1px solid var(--border);
            border-radius:14px;padding:1.25rem 1.5rem;
            display:flex;align-items:center;gap:1rem;
            transition:all .25s;position:relative;overflow:hidden;
        }
        .stat-card:hover{border-color:var(--border-2);transform:translateY(-2px);}
        .stat-icon{
            width:44px;height:44px;border-radius:11px;
            display:grid;place-items:center;font-size:1.15rem;
            color:var(--gold);background:var(--gold-dim);
            border:1px solid var(--border-2);flex-shrink:0;
        }
        .stat-label{font-size:.7rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.3rem;}
        .stat-value{font-family:'DM Mono',monospace;font-size:1.6rem;font-weight:700;line-height:1;}
        .stat-meta{font-size:.72rem;color:var(--text-2);margin-top:.25rem;}

        /* BUTTONS */
        .btn{
            display:inline-flex;align-items:center;gap:.5rem;
            padding:.7rem 1.4rem;border-radius:9px;
            font-size:.875rem;font-weight:600;cursor:pointer;
            text-decoration:none;border:none;font-family:inherit;
            transition:all .25s;white-space:nowrap;
        }
        .btn-primary{background:linear-gradient(135deg,var(--gold),var(--gold-dark));color:#0c0a06;box-shadow:0 3px 14px var(--gold-glow);}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 22px var(--gold-glow);}
        .btn-secondary{background:var(--gold-dim);color:var(--gold);border:1px solid var(--border-2);}
        .btn-secondary:hover{background:rgba(212,168,83,.2);}
        .btn-danger{background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.2);}
        .btn-danger:hover{background:#ef4444;color:#fff;}
        .btn-success{background:rgba(16,185,129,.12);color:#6ee7b7;border:1px solid rgba(16,185,129,.2);}
        .btn-success:hover{background:#10b981;color:#fff;}
        .btn-sm{padding:.45rem .9rem;font-size:.8rem;}

        /* BADGES */
        .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
        .badge-gold  {background:rgba(212,168,83,.15);color:#d4a853;border:1px solid rgba(212,168,83,.25);}
        .badge-green {background:rgba(16,185,129,.15);color:#6ee7b7;}
        .badge-red   {background:rgba(239,68,68,.15); color:#fca5a5;}
        .badge-orange{background:rgba(249,115,22,.15);color:#fdba74;}
        .badge-blue  {background:rgba(59,130,246,.15);color:#93c5fd;}
        .badge-slate {background:rgba(100,116,139,.15);color:#94a3b8;}
        .badge-purple{background:rgba(139,92,246,.15);color:#c4b5fd;}
        .badge-yellow{background:rgba(234,179,8,.15); color:#fde047;}

        /* FORMS */
        .form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.25rem;}
        .form-full{grid-column:1/-1;}
        .form-group{display:flex;flex-direction:column;gap:.4rem;}
        .form-label{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);}
        .form-input,.form-select,.form-textarea{
            width:100%;padding:.8rem 1rem;
            background:rgba(255,255,255,.03);border:1px solid var(--border);
            border-radius:8px;color:var(--text);font-family:inherit;
            font-size:.875rem;transition:border-color .2s,box-shadow .2s;outline:none;
        }
        .form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,168,83,.12);}
        .form-input::placeholder{color:var(--text-3);}
        .form-textarea{resize:vertical;min-height:90px;}
        select.form-select option{background:#0c0f1a;}

        /* TABLE */
        .table-wrap{overflow-x:auto;border-radius:0 0 14px 14px;}
        table{width:100%;border-collapse:collapse;}
        thead th{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-2);padding:10px 14px;background:rgba(255,255,255,.02);border-bottom:1px solid var(--border);white-space:nowrap;}
        tbody td{padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:.875rem;vertical-align:middle;}
        tbody tr:last-child td{border-bottom:none;}
        tbody tr:hover{background:rgba(212,168,83,.03);}

        /* MODALS */
        .modal-overlay{position:fixed;inset:0;background:rgba(3,4,8,.88);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;z-index:9999;}
        .modal-overlay.hidden{display:none;}
        .modal{background:linear-gradient(135deg,#0c0f1a,#07090f);border:1px solid var(--border-2);border-radius:20px;box-shadow:0 0 60px rgba(0,0,0,.6);width:90%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;}
        .modal-header{padding:1.5rem 2rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
        .modal-header h2{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:.7rem;}
        .modal-header h2 i{color:var(--gold);}
        .modal-close{width:36px;height:36px;border:1px solid var(--border);border-radius:9px;background:var(--gold-dim);color:var(--gold);font-size:1.2rem;cursor:pointer;display:grid;place-items:center;transition:all .25s;}
        .modal-close:hover{background:rgba(239,68,68,.15);color:#ef4444;border-color:rgba(239,68,68,.3);transform:rotate(90deg);}
        .modal-body{padding:1.75rem 2rem;overflow-y:auto;flex:1;}
        .modal-footer{padding:1.25rem 2rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.75rem;}

        /* ALERTS */
        .alert{padding:.9rem 1.25rem;border-radius:10px;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;font-size:.875rem;font-weight:500;}
        .alert-success{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);color:#6ee7b7;}
        .alert-error  {background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.25); color:#fca5a5;}
        .alert-info   {background:rgba(212,168,83,.08);border:1px solid rgba(212,168,83,.25);color:var(--gold);}
        .alert-warning{background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.25);color:#fdba74;}

        /* EMPTY */
        .empty-state{text-align:center;padding:3rem 1.5rem;color:var(--text-2);}
        .empty-state i{font-size:2.5rem;opacity:.25;display:block;margin-bottom:.75rem;}

        /* SEARCH */
        .search-bar{position:relative;}
        .search-bar i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-2);font-size:.8rem;}
        .search-bar input{width:100%;padding:.7rem .9rem .7rem 2.2rem;background:var(--gold-dim);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:inherit;font-size:.875rem;outline:none;transition:border-color .2s;}
        .search-bar input:focus{border-color:var(--gold);}
        .search-bar input::placeholder{color:var(--text-3);}

        .hidden{display:none!important;}

        @media(max-width:1024px){
            .company-sidebar{transform:translateX(-100%);}
            .main-content{margin-left:0;}
            .form-grid{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>

<aside class="company-sidebar">
    <div class="sidebar-header">
        <div class="company-logo"><i class="fas fa-hotel"></i></div>
        <div class="company-name"><?php echo htmlspecialchars($__panelName); ?></div>
        <div class="company-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
    </div>

    <nav class="sidebar-nav">
        <?php
        $__sec = '';
        foreach ($__navPages as $__p):
            if (!empty($__p['section']) && $__p['section'] !== $__sec):
                $__sec = $__p['section'];
                echo '<div class="nav-section-title">' . htmlspecialchars($__sec) . '</div>';
            endif;
        ?>
        <a href="<?php echo $__p['file']; ?>.php"
           class="nav-item<?php echo $currentPage === $__p['file'] ? ' active' : ''; ?>">
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
            } elseif (($_SESSION['user_faction'] ?? '') === 'PEARLS_RESORT') {
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
                        $__ivQ->execute(['PEARLS_RESORT', $__ivGrd]);
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

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar">
                <?php if (!empty($_SESSION['user_avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($_SESSION['user_avatar']); ?>" alt="">
                <?php else: ?>
                    <?php echo htmlspecialchars(mdt_session_display_initial()); ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="sidebar-user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
                <div class="sidebar-user-rank"><?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?></div>
            </div>
        </div>
    </div>
</aside>

<div class="main-content">
    <div class="company-topbar">
        <h1 class="page-title"><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?></h1>
        <span class="user-badge">
            <i class="fas fa-user-tie" style="margin-right:.4rem;"></i>
            <?php echo htmlspecialchars(mdt_session_display_name()); ?> —
            <?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?>
        </span>
    </div>
    <div class="page-content">