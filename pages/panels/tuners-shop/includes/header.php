<?php
/**
 * Tuner's Shop — structure LS Custom + thème MDT (couleur / logo / style)
 */
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';

$__garagePanelKey = 'TUNERS_SHOP';
$__garageFaction = 'TUNERS_SHOP';
$__garageDefaultColor = '#eab308';
$__garageLogoIcon = 'fa-screwdriver-wrench';

checkPageActive($__garagePanelKey);

$__lscAllPages = [
    ['file' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'section' => ''],
    ['file' => 'effectifs', 'label' => 'Effectifs', 'icon' => 'fas fa-users', 'section' => 'Ressources Humaines'],
    ['file' => 'prises-service', 'label' => 'Prises de Service', 'icon' => 'fas fa-clock', 'section' => ''],
    ['file' => 'sanctions', 'label' => 'Sanctions', 'icon' => 'fas fa-gavel', 'section' => ''],
    ['file' => 'stock', 'label' => 'Stock', 'icon' => 'fas fa-boxes', 'section' => 'Gestion'],
    ['file' => 'vente', 'label' => 'Vente', 'icon' => 'fas fa-shopping-cart', 'section' => ''],
    ['file' => 'facturation', 'label' => 'Facturation', 'icon' => 'fas fa-file-invoice-dollar', 'section' => ''],
    ['file' => 'depenses', 'label' => 'Dépenses', 'icon' => 'fas fa-money-bill-wave', 'section' => ''],
];
$__lscNavPages = getActiveNavItems($__garagePanelKey, $__lscAllPages);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$__pc = !empty($__panelColor) ? $__panelColor : $__garageDefaultColor;
if (!function_exists('garage_hex_rgb')) {
    function garage_hex_rgb(string $hex): array {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }
    function garage_darken(string $hex, float $amt): string {
        $rgb = garage_hex_rgb($hex);
        $r = max(0, (int) round($rgb[0] * (1 - $amt)));
        $g = max(0, (int) round($rgb[1] * (1 - $amt)));
        $b = max(0, (int) round($rgb[2] * (1 - $amt)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
    function garage_mix_white(string $hex, float $t): string {
        $rgb = garage_hex_rgb($hex);
        $r = min(255, (int) round($rgb[0] + (255 - $rgb[0]) * $t));
        $g = min(255, (int) round($rgb[1] + (255 - $rgb[1]) * $t));
        $b = min(255, (int) round($rgb[2] + (255 - $rgb[2]) * $t));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
$__grgb = garage_hex_rgb($__pc);
$__vd = garage_darken($__pc, 0.32);
$__vl = garage_mix_white($__pc, 0.28);
$__vx = garage_mix_white($__pc, 0.52);

$__hasPanelLogo = !empty($__panelLogoUrl) && preg_match('#^https?://#i', $__panelLogoUrl);
$__stamp = strtoupper(preg_replace('/[^A-Z0-9]/', '', $__garagePanelKey));
if (strlen($__stamp) > 14) {
    $__stamp = substr($__stamp, 0, 14);
}

$__brandParts = preg_split('/\s+/', $__panelName, 2);
$__brand1 = $__brandParts[0] ?? $__panelName;
$__brand2 = $__brandParts[1] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> — <?php echo htmlspecialchars($__panelName); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;800;900&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_PATH); ?>/css/panel-garage-lsc-shell.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_PATH); ?>/css/ls-custom.css">
<style>
:root {
    --violet:     <?php echo htmlspecialchars($__pc); ?>;
    --violet-d:   <?php echo htmlspecialchars($__vd); ?>;
    --violet-l:   <?php echo htmlspecialchars($__vl); ?>;
    --violet-xl:  <?php echo htmlspecialchars($__vx); ?>;
    --garage-vr: <?php echo (int) $__grgb[0]; ?>;
    --garage-vg: <?php echo (int) $__grgb[1]; ?>;
    --garage-vb: <?php echo (int) $__grgb[2]; ?>;
    --cyan:       #06b6d4;
    --cyan-l:     #67e8f9;
    --bg:         #07060d;
    --bg-b:       #0d0b16;
    --bg-c:       #120f1e;
    --bg-e:       #18152a;
    --bdr:        rgba(var(--garage-vr), var(--garage-vg), var(--garage-vb), .13);
    --bdr-h:      rgba(var(--garage-vr), var(--garage-vg), var(--garage-vb), .36);
    --txt:        #ede8ff;
    --txt-s:      #7c6fa0;
    --txt-m:      #3a3154;
    --green:      #22d3ee;
    --red:        #f43f5e;
    --yellow:     #fbbf24;
    --sidebar-w:  268px;
}
</style>
<?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body>

<aside class="lsc-sidebar">
    <div class="lsc-brand" data-stamp="<?php echo htmlspecialchars($__stamp); ?>">
        <?php if ($__hasPanelLogo): ?>
        <div class="lsc-logo lsc-logo--img" style="padding:0;overflow:hidden;background:transparent;box-shadow:none;">
            <img src="<?php echo htmlspecialchars($__panelLogoUrl); ?>" alt="" style="width:100%;height:100%;object-fit:contain;" loading="lazy" referrerpolicy="no-referrer">
        </div>
        <?php else: ?>
        <div class="lsc-logo"><i class="fas <?php echo htmlspecialchars($__garageLogoIcon); ?>"></i></div>
        <?php endif; ?>
        <div class="lsc-brand-name"><?php echo htmlspecialchars($__brand1); ?><?php if ($__brand2 !== ''): ?> <span><?php echo htmlspecialchars($__brand2); ?></span><?php endif; ?></div>
        <div class="lsc-brand-sub"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
    </div>

    <div class="lsc-user">
        <div class="lsc-user-dot"></div>
        <div>
            <div class="lsc-user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
            <div class="lsc-user-grade"><?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?></div>
        </div>
    </div>

    <nav class="lsc-nav">
        <?php
        $__sec = '';
        foreach ($__lscNavPages as $__p):
            if (!empty($__p['section']) && $__p['section'] !== $__sec):
                $__sec = $__p['section'];
                echo '<div class="lsc-section">' . htmlspecialchars($__sec) . '</div>';
            endif;
            $__act = ($currentPage === $__p['file']) ? ' active' : '';
        ?>
        <a href="<?php echo htmlspecialchars($__p['file']); ?>.php" class="lsc-link<?php echo $__act; ?>">
            <i class="<?php echo htmlspecialchars($__p['icon']); ?>"></i>
            <span><?php echo htmlspecialchars($__p['label']); ?></span>
        </a>
        <?php endforeach; ?>

        <div class="lsc-divider"></div>
        <?php if (hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])): ?>
        <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php" class="lsc-link">
            <i class="fas fa-layer-group"></i><span>Panel Staff</span>
        </a>
        <?php endif; ?>

        <?php
        if (!isset($__ivRes)) {
            $__ivRes = false;
            if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                $__ivRes = true;
            } elseif (($_SESSION['user_faction'] ?? '') === $__garageFaction) {
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
                        $__ivQ->execute([$__garageFaction, $__ivGrd]);
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
        <a href="info_vente.php" class="lsc-link<?php echo ($currentPage ?? '') === 'info_vente' ? ' active' : ''; ?>">
            <i class="fas fa-trophy"></i><span>Classement Ventes</span>
        </a>
        <?php endif; ?>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="lsc-link">
            <i class="fas fa-home"></i><span>Accueil MDT</span>
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="lsc-link">
            <i class="fas fa-right-from-bracket"></i><span>Déconnexion</span>
        </a>
    </nav>
</aside>

<main class="lsc-main">
    <div class="lsc-topbar">
        <div>
            <div class="lsc-topbar-title">
                <i class="fas fa-<?php echo htmlspecialchars($pageIcon ?? 'car-side'); ?>"></i>
                <?php echo htmlspecialchars($pageTitle ?? $__panelName); ?>
            </div>
            <div class="lsc-topbar-sub"><?php echo htmlspecialchars($__panelSubtitle); ?> — Panel interne</div>
        </div>
        <div class="lsc-topbar-right">
            <?php
            $__grade = htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? '');
            if ($__grade): ?>
            <span class="lsc-badge lsc-badge-cyan"><i class="fas fa-id-badge"></i><?php echo $__grade; ?></span>
            <?php endif; ?>
            <span class="lsc-badge"><i class="fas <?php echo htmlspecialchars($__garageLogoIcon); ?>"></i><?php echo htmlspecialchars(substr($__panelName, 0, 22)); ?></span>
        </div>
    </div>
    <div class="lsc-content">
