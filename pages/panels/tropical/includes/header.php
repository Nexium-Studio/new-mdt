<?php
/**
 * Tropical — panel autonome (structure Haute Luxury + thème LS / MDT)
 */
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';

$__venuePanelKey = 'TROPICAL';
$__venueFaction  = 'TROPICAL';

checkPageActive($__venuePanelKey);

$__venueAllPages = [
    ['file' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'section' => ''],
    ['file' => 'effectifs', 'label' => 'Effectifs', 'icon' => 'fas fa-users', 'section' => 'Ressources humaines'],
    ['file' => 'prises-service', 'label' => 'Prises de service', 'icon' => 'fas fa-clock', 'section' => ''],
    ['file' => 'sanctions', 'label' => 'Sanctions', 'icon' => 'fas fa-gavel', 'section' => ''],
    ['file' => 'stock', 'label' => 'Stock', 'icon' => 'fas fa-boxes', 'section' => 'Gestion commerciale'],
    ['file' => 'vente', 'label' => 'Vente', 'icon' => 'fas fa-shopping-cart', 'section' => ''],
    ['file' => 'facturation', 'label' => 'Facturation', 'icon' => 'fas fa-file-invoice-dollar', 'section' => ''],
    ['file' => 'depenses', 'label' => 'Dépenses', 'icon' => 'fas fa-money-bill-wave', 'section' => ''],
];
$__venueNavPages = getActiveNavItems($__venuePanelKey, $__venueAllPages);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$companyColor = $__panelColor ?? ($companyColor ?? '#14b8a6');
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
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;0,9..40,700&family=Orbitron:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_PATH); ?>/css/panel-venue-theme.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_PATH); ?>/css/panel-venue-dashboard.css">
    <style>:root { --company-color: <?php echo htmlspecialchars($companyColor); ?>; }</style>
    <?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body>
    <aside class="company-sidebar">
        <div class="sidebar-header">
            <?php if ($__hasPanelLogo): ?>
            <div class="company-logo company-logo--img">
                <img src="<?php echo htmlspecialchars($__panelLogoUrl); ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            </div>
            <?php else: ?>
            <div class="company-logo"><i class="fas fa-umbrella-beach"></i></div>
            <?php endif; ?>
            <div class="company-name"><?php echo htmlspecialchars($__panelName); ?></div>
            <div class="company-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
        </div>
        <nav class="sidebar-nav">
            <?php
            $__venueSec = '';
            foreach ($__venueNavPages as $__p):
                if (!empty($__p['section']) && $__p['section'] !== $__venueSec):
                    $__venueSec = $__p['section'];
                    echo '<div class="nav-section-title">' . htmlspecialchars($__venueSec) . '</div>';
                endif;
                $__isActive = ($currentPage === $__p['file']) ? ' active' : '';
            ?>
            <a href="<?php echo htmlspecialchars($__p['file']); ?>.php" class="nav-item<?php echo $__isActive; ?>">
                <i class="<?php echo htmlspecialchars($__p['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($__p['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <div style="height:1px;background:color-mix(in srgb,var(--pv-accent) 12%,transparent);margin:1rem 1.5rem;"></div>

            <?php
            if (!isset($__ivRes)) {
                $__ivRes = false;
                if (hasRole(['*', 'Administrateur', 'Developpeur', 'Staff', 'Moderateur', 'Helper'])) {
                    $__ivRes = true;
                } elseif (($_SESSION['user_faction'] ?? '') === $__venueFaction) {
                    try {
                        $__ivDb2 = Database::getInstance()->getConnection();
                        $__ivGrd = $_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? '';
                        if (!$__ivGrd) {
                            $__ivSt = $__ivDb2->prepare('SELECT COALESCE(faction_grade,faction_rank,\'\') FROM users WHERE id=? LIMIT 1');
                            $__ivSt->execute([$_SESSION['user_id'] ?? 0]);
                            $__ivGrd = (string) ($__ivSt->fetchColumn() ?: '');
                        }
                        if ($__ivGrd) {
                            $__ivQ = $__ivDb2->prepare(
                                'SELECT fg.can_recruit FROM faction_grades fg'
                                . ' JOIN factions f ON f.id=fg.faction_id'
                                . ' WHERE f.faction_key=? AND fg.name=? LIMIT 1'
                            );
                            $__ivQ->execute([$__venueFaction, $__ivGrd]);
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
                <span style="opacity:.45">·</span>
                <?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?>
            </span>
        </div>
        <div class="page-content">
