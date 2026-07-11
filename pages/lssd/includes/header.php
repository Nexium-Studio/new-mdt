<?php
require_once dirname(__DIR__, 3) . '/config/panel_pages.php';
require_once dirname(__DIR__, 3) . '/config/panel_theme_auto.php';
checkPageActive('LSSD');

$__lssdAllPages = [
    ['file' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'section' => null, 'locked' => true],
];
$__lssdNavPages = getActiveNavItems('LSSD', $__lssdAllPages);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'LSSD Panel'; ?> - MDT</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: #080d0a; color: #e2efe8; min-height: 100vh; overflow-x: hidden; }

        /* ── SIDEBAR ── */
        .lssd-sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: linear-gradient(180deg, #0d1410, #080d0a); border-right: 1px solid rgba(16,185,129,.12); overflow-y: auto; z-index: 1000; box-shadow: 0 0 40px rgba(16,185,129,.06); }
        .lssd-sidebar::before { content: ''; position: absolute; top: 0; right: 0; width: 1px; height: 100%; background: linear-gradient(180deg, transparent, #10b981, transparent); opacity: .25; }

        .sidebar-header { padding: 1.75rem 1.5rem; border-bottom: 1px solid rgba(16,185,129,.1); position: relative; }
        .sidebar-header::after { content: ''; position: absolute; bottom: 0; left: 50%; transform: translateX(-50%); width: 60%; height: 1px; background: linear-gradient(90deg, transparent, #10b981, transparent); opacity: .4; }

        .sidebar-logo { display: flex; align-items: center; gap: 1rem; }
        .sidebar-logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.1)); border: 1px solid rgba(16,185,129,.3); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #10b981; font-size: 1.4rem; box-shadow: 0 0 16px rgba(16,185,129,.18); }
        .sidebar-logo-text { font-family: 'Syne', sans-serif; font-size: 1.1rem; font-weight: 800; letter-spacing: 2px; background: linear-gradient(135deg, #10b981, #34d399); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .sidebar-logo-subtitle { font-size: .6rem; color: #475569; text-transform: uppercase; letter-spacing: 1.5px; margin-top: .2rem; }

        .sidebar-nav { padding: 1rem 0; }
        .nav-section-title { padding: .9rem 1.5rem .4rem; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; color: rgba(16,185,129,.45); }
        .nav-item { display: flex; align-items: center; gap: .75rem; padding: .8rem 1.5rem; color: #4b7a62; text-decoration: none; font-weight: 500; font-size: .875rem; transition: all .2s; position: relative; }
        .nav-item::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 0; background: linear-gradient(180deg, #10b981, #34d399); transition: height .2s; box-shadow: 0 0 8px rgba(16,185,129,.5); }
        .nav-item:hover, .nav-item.active { color: #10b981; background: rgba(16,185,129,.06); }
        .nav-item:hover::before, .nav-item.active::before { height: 60%; }
        .nav-item i { width: 20px; text-align: center; font-size: .875rem; }
        .nav-divider { height: 1px; background: linear-gradient(90deg, transparent, rgba(16,185,129,.12), transparent); margin: .75rem 1.5rem; }

        .sidebar-footer { position: sticky; bottom: 0; padding: 1.25rem 1.5rem; background: #080d0a; border-top: 1px solid rgba(16,185,129,.1); }
        .user-card { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; background: rgba(16,185,129,.05); border: 1px solid rgba(16,185,129,.14); border-radius: 10px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #059669, #10b981); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .9rem; color: #fff; box-shadow: 0 0 12px rgba(16,185,129,.3); }
        .user-name { font-weight: 600; font-size: .85rem; color: #e2efe8; }
        .user-role { font-size: .68rem; color: #4b7a62; text-transform: uppercase; letter-spacing: 1px; }

        /* ── MAIN ── */
        .main-content { margin-left: 260px; min-height: 100vh; }
        .lssd-topbar { position: sticky; top: 0; z-index: 100; background: rgba(8,13,10,.96); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(16,185,129,.1); padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .lssd-topbar::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 5%, #10b981 35%, #34d399 50%, #10b981 70%, transparent 95%); opacity: .3; }
        .topbar-left { display: flex; align-items: center; gap: 1.5rem; }
        .sidebar-toggle { display: none; padding: .55rem; background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.2); border-radius: 8px; color: #10b981; cursor: pointer; }
        .page-title { font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; background: linear-gradient(135deg, #10b981, #34d399); -webkit-background-clip: text; -webkit-text-fill-color: transparent; filter: drop-shadow(0 0 8px rgba(16,185,129,.3)); }
        .topbar-badge { padding: .5rem 1rem; background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.18); border-radius: 20px; font-size: .72rem; font-weight: 600; color: #34d399; text-transform: uppercase; letter-spacing: 1px; }
        .page-content { padding: 2rem; }

        @media (max-width: 1024px) {
            .lssd-sidebar { transform: translateX(-100%); transition: transform .3s; }
            .lssd-sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .sidebar-toggle { display: flex; }
        }
    </style>
    <?php echo $__panelThemeCSS; ?>
</head>
<body>
<?php $__currentFile = basename($_SERVER['PHP_SELF'], '.php'); ?>
    <aside class="lssd-sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="sidebar-logo-icon"><i class="fas fa-star"></i></div>
                <div>
                    <div class="sidebar-logo-text"><?php echo htmlspecialchars($__panelName); ?></div>
                    <div class="sidebar-logo-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($__lssdNavPages as $__p):
                $__active = ($__currentFile === $__p['file']) ? 'active' : '';
            ?>
            <a href="<?php echo SITE_URL; ?>/pages/lssd/<?php echo $__p['file']; ?>.php"
               class="nav-item <?php echo $__active; ?>">
                <i class="<?php echo htmlspecialchars($__p['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($__p['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <div class="nav-divider"></div>

            <?php if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])): ?>
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
                    <div class="user-role">LSSD</div>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <div class="lssd-topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('show')">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="page-title"><?php echo $pageTitle ?? 'LSSD Panel'; ?></h1>
            </div>
            <div class="topbar-right">
                <span class="topbar-badge">⭐ <?php echo htmlspecialchars($__panelName); ?></span>
            </div>
        </div>
        <div class="page-content">