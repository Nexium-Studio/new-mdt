<?php
// Panel LTD Grapeseed — copie autonome
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive('LTD_GRRAPESEED');

$__ltdPanelKey = 'LTD_GRRAPESEED';
$__ltdAllPages = [
    ['file'=>'dashboard', 'label'=>'Dashboard', 'icon'=>'fas fa-chart-line', 'section'=>''],
    ['file'=>'effectifs', 'label'=>'Effectifs', 'icon'=>'fas fa-users', 'section'=>'Ressources humaines'],
    ['file'=>'prises-service', 'label'=>'Prises de service', 'icon'=>'fas fa-clock', 'section'=>''],
    ['file'=>'sanctions', 'label'=>'Sanctions', 'icon'=>'fas fa-gavel', 'section'=>''],
    ['file'=>'stock', 'label'=>'Stock', 'icon'=>'fas fa-boxes', 'section'=>'Gestion commerciale'],
    ['file'=>'vente', 'label'=>'Vente', 'icon'=>'fas fa-shopping-cart', 'section'=>''],
    ['file'=>'facturation', 'label'=>'Facturation', 'icon'=>'fas fa-file-invoice-dollar', 'section'=>''],
    ['file'=>'depenses', 'label'=>'Dépenses', 'icon'=>'fas fa-money-bill-wave', 'section'=>''],
];
$__ltdNavPages = getActiveNavItems($__ltdPanelKey, $__ltdAllPages);
$currentPage   = basename($_SERVER['PHP_SELF'], '.php');

$companyColor = $__panelColor ?? ($companyColor ?? '#0ea5e9');
$__hasPanelLogo = !empty($__panelLogoUrl) && preg_match('#^https?://#i', $__panelLogoUrl);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> — <?php echo htmlspecialchars($__panelName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --company-color: <?php echo htmlspecialchars($companyColor); ?>;
            --company-muted: color-mix(in srgb, var(--company-color) 35%, #94a3b8);
            --surface: rgba(12, 16, 28, 0.92);
            --surface-2: rgba(18, 24, 40, 0.65);
            --border: rgba(255, 255, 255, 0.06);
            --text: #e8edf5;
            --text-dim: #94a3b8;
        }
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #05070d;
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }
        .company-sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 268px;
            height: 100vh;
            background: var(--surface);
            border-right: 1px solid var(--border);
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar-header {
            padding: 1.5rem 1.25rem 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .company-logo {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: color-mix(in srgb, var(--company-color) 18%, transparent);
            border: 1px solid color-mix(in srgb, var(--company-color) 35%, transparent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            color: var(--company-color);
            margin-bottom: 0.9rem;
        }
        .company-logo--img {
            padding: 6px;
            background: rgba(255,255,255,0.04);
        }
        .company-logo--img img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .company-name {
            font-family: 'Syne', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #f1f5f9;
            line-height: 1.25;
        }
        .company-subtitle {
            font-size: 0.78rem;
            color: var(--text-dim);
            margin-top: 0.2rem;
            font-weight: 500;
        }
        .sidebar-nav { padding: 0.75rem 0 1.25rem; }
        .nav-section-title {
            padding: 0.85rem 1.25rem 0.35rem;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #64748b;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.65rem 1.25rem;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.88rem;
            transition: color 0.15s, background 0.15s;
            border-left: 3px solid transparent;
        }
        .nav-item:hover {
            color: var(--text);
            background: rgba(255,255,255,0.04);
        }
        .nav-item.active {
            color: var(--company-color);
            background: color-mix(in srgb, var(--company-color) 8%, transparent);
            border-left-color: var(--company-color);
        }
        .nav-item i { width: 1.1rem; text-align: center; opacity: 0.9; }
        .nav-divider-lite {
            height: 1px;
            background: var(--border);
            margin: 0.5rem 1.25rem;
        }
        .main-content {
            margin-left: 268px;
            min-height: 100vh;
        }
        .company-topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(5, 7, 13, 0.88);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        .page-title {
            font-family: 'Syne', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #f8fafc;
        }
        .user-badge {
            padding: 0.45rem 0.95rem;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-dim);
        }
        .page-content { padding: 1.75rem 1.75rem 2.5rem; }
        .card {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            text-decoration: none;
            border: none;
            font-family: inherit;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--company-color), color-mix(in srgb, var(--company-color) 65%, #000));
            color: #fff;
            box-shadow: 0 4px 20px color-mix(in srgb, var(--company-color) 28%, transparent);
        }
        .btn-primary:hover { transform: translateY(-1px); }
        .btn-secondary {
            background: transparent;
            color: var(--company-muted);
            border: 1px solid color-mix(in srgb, var(--company-color) 28%, transparent);
        }
        .btn-sm { padding: 0.45rem 0.85rem; font-size: 0.82rem; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem; }
        .form-full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .form-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
        }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.75rem 0.9rem;
            background: rgba(0,0,0,0.25);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-family: inherit;
            font-size: 0.9rem;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: color-mix(in srgb, var(--company-color) 45%, transparent);
        }
        .form-textarea { resize: vertical; min-height: 96px; }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(3, 5, 12, 0.9);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .modal-overlay:not(.hidden) { display: flex; }
        .modal {
            background: #0c101c;
            border: 1px solid var(--border);
            border-radius: 16px;
            width: 90%;
            max-width: 640px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 { font-size: 1rem; font-weight: 700; color: var(--text); }
        .modal-close {
            width: 38px;
            height: 38px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: rgba(255,255,255,0.04);
            color: var(--text-dim);
            cursor: pointer;
        }
        .modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        .alert {
            padding: 0.85rem 1.15rem;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-size: 0.9rem;
        }
        .alert-success { background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.25); color: #4ade80; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.25); color: #f87171; }
        .hidden { display: none !important; }
        @media (max-width: 1024px) {
            .company-sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body>
    <aside class="company-sidebar">
        <div class="sidebar-header">
            <?php if ($__hasPanelLogo): ?>
            <div class="company-logo company-logo--img">
                <img src="<?php echo htmlspecialchars($__panelLogoUrl); ?>" alt="" referrerpolicy="no-referrer" loading="lazy">
            </div>
            <?php else: ?>
            <div class="company-logo"><i class="fas fa-basket-shopping"></i></div>
            <?php endif; ?>
            <div class="company-name"><?php echo htmlspecialchars($__panelName); ?></div>
            <div class="company-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
        </div>
        <nav class="sidebar-nav">
            <?php
            $__ltdSec = '';
            foreach ($__ltdNavPages as $__p):
                if (!empty($__p['section']) && $__p['section'] !== $__ltdSec):
                    $__ltdSec = $__p['section'];
                    echo '<div class="nav-section-title">' . htmlspecialchars($__ltdSec) . '</div>';
                endif;
                $__isActive = ($currentPage === $__p['file']) ? ' active' : '';
            ?>
            <a href="<?php echo htmlspecialchars($__p['file']); ?>.php" class="nav-item<?php echo $__isActive; ?>">
                <i class="<?php echo htmlspecialchars($__p['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($__p['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <div class="nav-divider-lite"></div>

            <?php
            if (!isset($__ivRes)) {
                $__ivRes = false;
                if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                    $__ivRes = true;
                } elseif (($_SESSION['user_faction'] ?? '') === 'LTD_GRRAPESEED') {
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
                            $__ivQ->execute(['LTD_GRRAPESEED', $__ivGrd]);
                            $__ivR = $__ivQ->fetch(PDO::FETCH_ASSOC);
                            if ($__ivR && !empty($__ivR['can_recruit'])) {
                                $__ivRes = true;
                            }
                        }
                    } catch (Exception $__ivEx) {
                    }
                }
            }
            if ($__ivRes):
            ?>
            <a href="info_vente.php" class="nav-item<?php echo ($currentPage ?? '') === 'info_vente' ? ' active' : ''; ?>">
                <i class="fas fa-trophy"></i><span>Classement ventes</span>
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
                <span style="opacity:.5">·</span>
                <?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?>
            </span>
        </div>
        <div class="page-content">
