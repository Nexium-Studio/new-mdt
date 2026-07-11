<?php
/**
 * Shell concession (base LS Motors) — panel Luxury Auto
 */
$panelCfg = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive($panelCfg['key']);

$__dealershipNavPages = [
    ['file' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'section' => ''],
    ['file' => 'stock', 'label' => 'Stock', 'icon' => 'fas fa-warehouse', 'section' => 'Gestion Véhicules'],
    ['file' => 'vente', 'label' => 'Ventes', 'icon' => 'fas fa-handshake', 'section' => ''],
    ['file' => 'plaques', 'label' => 'Plaques', 'icon' => 'fas fa-id-card', 'section' => ''],
    ['file' => 'essais', 'label' => 'Essais', 'icon' => 'fas fa-key', 'section' => ''],
    ['file' => 'reprises', 'label' => 'Reprises', 'icon' => 'fas fa-exchange-alt', 'section' => ''],
    ['file' => 'clients', 'label' => 'Clients', 'icon' => 'fas fa-users', 'section' => 'Gestion Clients'],
    ['file' => 'commissions', 'label' => 'Commissions', 'icon' => 'fas fa-dollar-sign', 'section' => ''],
    ['file' => 'effectifs', 'label' => 'Effectifs', 'icon' => 'fas fa-users', 'section' => 'Ressources Humaines'],
    ['file' => 'prises-service', 'label' => 'Prises de Service', 'icon' => 'fas fa-clock', 'section' => ''],
    ['file' => 'sanctions', 'label' => 'Sanctions', 'icon' => 'fas fa-gavel', 'section' => ''],
    ['file' => 'facturation', 'label' => 'Facturation', 'icon' => 'fas fa-file-invoice-dollar', 'section' => 'Finance'],
    ['file' => 'depenses', 'label' => 'Dépenses', 'icon' => 'fas fa-money-bill-wave', 'section' => ''],
];
$__dealershipNavPages = getActiveNavItems($panelCfg['key'], $__dealershipNavPages);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$__accent = !empty($__panelColor) ? $__panelColor : ($companyColor ?? ($panelCfg['color'] ?? '#6366f1'));
$__hex = ltrim($__accent, '#');
if (strlen($__hex) === 3) {
    $__hex = $__hex[0] . $__hex[0] . $__hex[1] . $__hex[1] . $__hex[2] . $__hex[2];
}
$__lmR = hexdec(substr($__hex, 0, 2));
$__lmG = hexdec(substr($__hex, 2, 2));
$__lmB = hexdec(substr($__hex, 4, 2));

$__hasLogo = !empty($__panelLogoUrl) && preg_match('#^https?://#i', $__panelLogoUrl);

if (!isset($lmHeaderSlides) || !is_array($lmHeaderSlides)) {
    $lmHeaderSlides = [
        ['icon' => 'fa-gem', 'title' => 'Luxury Auto', 'line' => 'Prestige, confort et sélection haut de gamme.'],
        ['icon' => 'fa-crown', 'title' => 'Prestige', 'line' => 'Stock, finance et équipe sur un même écran.'],
        ['icon' => 'fa-road', 'title' => 'Votre route', 'line' => 'Objectifs, classement et suivi en temps réel.'],
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> — <?php echo htmlspecialchars($__panelName ?? ($companyName ?? $panelCfg['name'])); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_PATH); ?>/css/ls-motors.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_PATH); ?>/css/<?php echo htmlspecialchars($panelCfg['skin_stylesheet'] ?? 'dealership-luxury-auto.css'); ?>">
    <style>
        :root {
            --company-color: <?php echo htmlspecialchars($__accent); ?>;
            --lm-r: <?php echo (int) $__lmR; ?>;
            --lm-g: <?php echo (int) $__lmG; ?>;
            --lm-b: <?php echo (int) $__lmB; ?>;
        }
        .company-sidebar {
            scrollbar-width: thin;
            scrollbar-color: color-mix(in srgb, var(--company-color) 65%, #475569) rgba(8, 12, 18, 0.95);
        }
        .company-sidebar::-webkit-scrollbar { width: 7px; }
        .company-sidebar::-webkit-scrollbar-track {
            background: rgba(5, 8, 12, 0.85);
            border-radius: 999px;
            margin: 6px 0;
        }
        .company-sidebar::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: linear-gradient(
                180deg,
                color-mix(in srgb, var(--company-color) 85%, #1e293b),
                color-mix(in srgb, var(--company-color) 45%, #334155)
            );
            border: 2px solid rgba(5, 8, 12, 0.85);
            box-shadow: 0 0 12px color-mix(in srgb, var(--company-color) 25%, transparent);
        }
        .company-sidebar::-webkit-scrollbar-thumb:hover {
            background: color-mix(in srgb, var(--company-color) 70%, #94a3b8);
        }
        .company-sidebar::-webkit-scrollbar-corner { background: transparent; }
    </style>
    <?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body class="<?php echo htmlspecialchars($panelCfg['body_class'] ?? ''); ?>">
    <aside class="company-sidebar">
        <div class="sidebar-header">
            <?php if ($__hasLogo): ?>
            <div class="company-logo company-logo--img">
                <img src="<?php echo htmlspecialchars($__panelLogoUrl); ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            </div>
            <?php else: ?>
            <div class="company-logo">
                <i class="fas fa-car-side"></i>
            </div>
            <?php endif; ?>
            <div class="company-name"><?php echo htmlspecialchars($__panelName); ?></div>
            <div class="company-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
        </div>

        <nav class="sidebar-nav">
            <?php
            $_navSec = '';
            foreach ($__dealershipNavPages as $__p):
                if (!empty($__p['section']) && $__p['section'] !== $_navSec):
                    $_navSec = $__p['section'];
                    echo '<div class="nav-section-title">' . htmlspecialchars($_navSec) . '</div>';
                endif;
                $__isActive = ($currentPage === $__p['file']) ? ' active' : '';
            ?>
            <a href="<?php echo htmlspecialchars($__p['file']); ?>.php" class="nav-item<?php echo $__isActive; ?>">
                <i class="<?php echo htmlspecialchars($__p['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($__p['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <div style="height:1px;background:linear-gradient(90deg,transparent,color-mix(in srgb,var(--company-color) 25%,transparent),transparent);margin:1rem 1.35rem;"></div>

            <?php
            $__fk = $panelCfg['faction'];
            if (!isset($__ivRes)) {
                $__ivRes = false;
                if (hasRole(['*', 'Administrateur', 'Développeur', 'Developpeur', 'Staff', 'Modérateur', 'Moderateur', 'Helper'])) {
                    $__ivRes = true;
                } elseif (($_SESSION['user_faction'] ?? '') === $__fk) {
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
                            $__ivQ->execute([$__fk, $__ivGrd]);
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
                <span style="opacity:.4">·</span>
                <?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?>
            </span>
        </div>

        <div class="lm-header-slider" data-lm-slider aria-roledescription="carousel">
            <div class="lm-header-slider__viewport">
                <div class="lm-header-slider__track">
                    <?php foreach ($lmHeaderSlides as $lmSlide): ?>
                    <article class="lm-header-slider__slide">
                        <div class="lm-header-slider__slide-inner">
                            <span class="lm-header-slider__icon" aria-hidden="true">
                                <i class="fas <?php echo htmlspecialchars($lmSlide['icon'] ?? 'fa-circle'); ?>"></i>
                            </span>
                            <div class="lm-header-slider__text">
                                <strong class="lm-header-slider__title"><?php echo htmlspecialchars($lmSlide['title'] ?? ''); ?></strong>
                                <span class="lm-header-slider__line"><?php echo htmlspecialchars($lmSlide['line'] ?? ''); ?></span>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="lm-header-slider__nav lm-header-slider__nav--prev" aria-label="Message précédent">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button type="button" class="lm-header-slider__nav lm-header-slider__nav--next" aria-label="Message suivant">
                <i class="fas fa-chevron-right"></i>
            </button>
            <div class="lm-header-slider__dots" role="tablist" aria-label="Slides"></div>
        </div>

        <div class="page-content">
