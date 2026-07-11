<?php
/**
 * En-tête commun des panels /pages/panels/{slug}/.
 * Prérequis : $panelCfg (tableau depuis config.php), $pageTitle optionnel.
 */
if (!isset($panelCfg) || !is_array($panelCfg)) {
    exit('Configuration panel manquante.');
}

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/panel_pages.php';

$panelKey = $panelCfg['key'];
require_once dirname(__DIR__, 2) . '/config/panel_theme_auto.php';

checkPageActive($panelKey);

$__defaultNav = [
    ['file' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'section' => null],
];
$__navPages = getActiveNavItems($panelKey, $panelCfg['nav'] ?? $__defaultNav);

$panelBaseWeb = SITE_URL . '/pages/panels/' . $panelCfg['slug'];
$__currentFile = basename($_SERVER['PHP_SELF'], '.php');
$__sidebarIcon = $panelCfg['icon'] ?? 'fas fa-building';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> — <?php echo htmlspecialchars($panelCfg['name'] ?? $__panelName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: #0a0c14; color: #e2e8f0; min-height: 100vh; overflow-x: hidden; }

        .mdt-standard-sidebar {
            position: fixed; left: 0; top: 0; width: 270px; height: 100vh;
            background: linear-gradient(180deg, #111420, #0a0c14);
            border-right: 1px solid rgba(100,116,139,.12); overflow-y: auto; z-index: 1000;
            box-shadow: 0 0 40px rgba(0,0,0,.35);
        }
        .mdt-standard-sidebar::before {
            content: ''; position: absolute; top: 0; right: 0; width: 1px; height: 100%;
            background: linear-gradient(180deg, transparent, rgba(148,163,184,.35), transparent); opacity: .35;
        }

        .sidebar-header { padding: 1.75rem 1.5rem; border-bottom: 1px solid rgba(100,116,139,.1); position: relative; }
        .sidebar-header::after {
            content: ''; position: absolute; bottom: 0; left: 50%; transform: translateX(-50%);
            width: 55%; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(148,163,184,.45), transparent); opacity: .45;
        }

        .sidebar-logo { display: flex; align-items: center; gap: 1rem; }
        .sidebar-logo-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, rgba(100,116,139,.22), rgba(71,85,105,.12));
            border: 1px solid rgba(100,116,139,.28); border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #94a3b8; font-size: 1.35rem;
            box-shadow: 0 0 16px rgba(100,116,139,.15);
        }
        .sidebar-logo-text {
            font-family: 'Syne', sans-serif; font-size: 1.05rem; font-weight: 800;
            letter-spacing: .5px; color: #e2e8f0;
        }
        .sidebar-logo-subtitle {
            font-size: .65rem; color: #64748b; text-transform: uppercase; letter-spacing: 1.2px; margin-top: .25rem;
        }

        .sidebar-nav { padding: 1rem 0; flex: 1; }
        .nav-section-title {
            padding: .85rem 1.5rem .35rem; font-size: .62rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 2px; color: #475569;
        }
        .nav-item {
            display: flex; align-items: center; gap: .75rem; padding: .8rem 1.5rem;
            color: #64748b; text-decoration: none; font-weight: 500; font-size: .875rem;
            transition: all .2s; position: relative;
        }
        .nav-item::before {
            content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
            width: 3px; height: 0;
            background: linear-gradient(180deg, #64748b, #94a3b8); transition: height .2s;
        }
        .nav-item:hover, .nav-item.active { color: #94a3b8; background: rgba(100,116,139,.08); }
        .nav-item:hover::before, .nav-item.active::before { height: 55%; }
        .nav-item i { width: 20px; text-align: center; font-size: .9rem; }
        .nav-divider {
            height: 1px; background: linear-gradient(90deg, transparent, rgba(100,116,139,.15), transparent);
            margin: .75rem 1.5rem;
        }

        .sidebar-footer {
            position: sticky; bottom: 0; padding: 1.2rem 1.5rem; background: #0a0c14;
            border-top: 1px solid rgba(100,116,139,.1);
        }
        .user-card {
            display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem;
            background: rgba(100,116,139,.06); border: 1px solid rgba(100,116,139,.14); border-radius: 10px;
        }
        .user-avatar {
            width: 40px; height: 40px; border-radius: 10px;
            background: linear-gradient(135deg, #475569, #64748b);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .9rem; color: #e2e8f0;
        }
        .user-name { font-weight: 600; font-size: .85rem; color: #e2e8f0; }
        .user-role { font-size: .65rem; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }

        .main-content { margin-left: 270px; min-height: 100vh; }
        .mdt-standard-topbar {
            position: sticky; top: 0; z-index: 100;
            background: rgba(10,12,20,.96); backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(100,116,139,.1);
            padding: 1.2rem 2rem; display: flex; justify-content: space-between; align-items: center;
        }
        .mdt-standard-topbar::after {
            content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent 5%, #64748b 40%, #94a3b8 50%, #64748b 60%, transparent 95%);
            opacity: .28;
        }
        .topbar-left { display: flex; align-items: center; gap: 1rem; }
        .sidebar-toggle {
            display: none; padding: .55rem; background: rgba(100,116,139,.08);
            border: 1px solid rgba(100,116,139,.2); border-radius: 8px; color: #94a3b8; cursor: pointer;
        }
        .page-title {
            font-family: 'Syne', sans-serif; font-size: 1.15rem; font-weight: 800;
            letter-spacing: 2px; text-transform: uppercase; color: #e2e8f0;
        }
        .topbar-badge {
            padding: .5rem 1rem; background: rgba(100,116,139,.08);
            border: 1px solid rgba(100,116,139,.18); border-radius: 20px;
            font-size: .72rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px;
        }
        .page-content { padding: 2rem; max-width: 1400px; }

        .mdt-panel-card {
            background: rgba(15, 23, 41, 0.55); border: 1px solid rgba(59, 130, 246, 0.12);
            border-radius: 16px; padding: 2rem; margin-bottom: 1.5rem;
        }
        .mdt-panel-card h2 { font-size: 1.35rem; margin-bottom: 1rem; color: #e8edf5; }
        .mdt-panel-card p { color: #94a3b8; line-height: 1.7; margin-bottom: 1.25rem; }
        .btn-mdt {
            display: inline-flex; align-items: center; gap: .5rem; padding: .75rem 1.35rem;
            border-radius: 10px; font-weight: 600; font-size: .9rem; text-decoration: none;
            background: linear-gradient(135deg, var(--panel-color, #64748b), #475569);
            color: #fff; border: none; cursor: pointer; transition: transform .15s, box-shadow .15s;
        }
        .btn-mdt:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(0,0,0,.25); }

        @media (max-width: 1024px) {
            .mdt-standard-sidebar { transform: translateX(-100%); transition: transform .3s; }
            .mdt-standard-sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .sidebar-toggle { display: flex; align-items: center; justify-content: center; }
        }
    </style>
    <?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body>
    <aside class="mdt-standard-sidebar" id="mdtPanelSidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="sidebar-logo-icon"><i class="<?php echo htmlspecialchars($__sidebarIcon); ?>"></i></div>
                <div>
                    <div class="sidebar-logo-text"><?php echo htmlspecialchars($__panelName ?: $panelCfg['name']); ?></div>
                    <div class="sidebar-logo-subtitle"><?php echo htmlspecialchars($__panelSubtitle ?: ($panelCfg['subtitle'] ?? '')); ?></div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php
            $__sec = '';
            foreach ($__navPages as $__p) :
                if (!empty($__p['section']) && $__p['section'] !== $__sec) :
                    $__sec = $__p['section'];
                    echo '<div class="nav-section-title">' . htmlspecialchars($__sec) . '</div>';
                endif;
                $__active = ($__currentFile === ($__p['file'] ?? '')) ? 'active' : '';
            ?>
            <a href="<?php echo htmlspecialchars($panelBaseWeb . '/' . ($__p['file'] ?? 'dashboard') . '.php'); ?>" class="nav-item <?php echo $__active; ?>">
                <i class="<?php echo htmlspecialchars($__p['icon'] ?? 'fas fa-circle'); ?>"></i>
                <span><?php echo htmlspecialchars($__p['label'] ?? ''); ?></span>
            </a>
            <?php endforeach; ?>

            <div class="nav-divider"></div>

            <?php if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) : ?>
            <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php" class="nav-item">
                <i class="fas fa-layer-group"></i><span>Panel Staff</span>
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
            <div class="user-card">
                <div class="user-avatar"><?php echo htmlspecialchars(mdt_session_display_initial()); ?></div>
                <div>
                    <div class="user-name"><?php echo htmlspecialchars(mdt_session_display_name() ?: 'Utilisateur'); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? $_SESSION['user_faction'] ?? '—'); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <div class="mdt-standard-topbar">
            <div class="topbar-left">
                <button type="button" class="sidebar-toggle" aria-label="Menu" onclick="document.getElementById('mdtPanelSidebar').classList.toggle('show')">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="page-title"><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?></h1>
            </div>
            <div class="topbar-right">
                <span class="topbar-badge"><?php echo htmlspecialchars($panelCfg['name']); ?></span>
            </div>
        </div>
        <div class="page-content">
