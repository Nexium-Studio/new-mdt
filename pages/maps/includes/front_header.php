<?php
/**
 * pages/maps/includes/front_header.php
 * Shell front du Système de Cartes : <head> + navbar gauche (toujours visible).
 *
 * Attendu depuis la page appelante :
 *   $db, $uid, $navFactions (maps_navbar_factions), $mapsPageTitle, $mapsActiveNav
 *   (optionnels : $mapsMainClass, $mapsExtraHead)
 */
if (!defined('MDT_MAPS_FRONT')) {
    http_response_code(403);
    exit('Forbidden');
}

$mapsCssV = @filemtime(dirname(__DIR__, 3) . '/assets/css/maps/maps.css') ?: '1';

/** Visuel d'un item de navbar faction (logo MDT sinon icône FA). */
function maps_nav_visual(array $m): string
{
    if (!empty($m['logo'])) {
        return '<span class="maps-nav-ico"><img src="' . htmlspecialchars($m['logo']) . '" alt="" referrerpolicy="no-referrer"></span>';
    }
    return '<span class="maps-nav-ico"><i class="fa-solid fa-' . htmlspecialchars($m['icon']) . '"></i></span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($mapsPageTitle ?? 'Cartes'); ?> — MDT</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/maps/maps.css?v=<?php echo $mapsCssV; ?>">
    <?php if (!empty($mapsExtraHead)) { echo $mapsExtraHead; } ?>
</head>
<body class="maps-body">
<div class="maps-app">

    <aside class="maps-nav">
        <a href="<?php echo SITE_URL; ?>/pages/maps/index.php" class="maps-brand">
            <span class="maps-brand-icon"><i class="fa-solid fa-map-location-dot"></i></span>
            Cartes
        </a>

        <div class="maps-nav-section">
            <div class="maps-nav-label">Cartes publiques</div>
            <a href="<?php echo SITE_URL; ?>/pages/maps/editor.php?special=commune"
               class="maps-nav-item <?php echo ($mapsActiveNav ?? '') === 'commune' ? 'active' : ''; ?>" style="--n-color:#3b82f6">
                <span class="maps-nav-ico"><i class="fa-solid fa-city"></i></span>
                <span class="maps-nav-name">Map Commune</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/pages/maps/editor.php?special=gang"
               class="maps-nav-item <?php echo ($mapsActiveNav ?? '') === 'gang' ? 'active' : ''; ?>" style="--n-color:#8b5cf6">
                <span class="maps-nav-ico"><i class="fa-solid fa-users-viewfinder"></i></span>
                <span class="maps-nav-name">Map Gang</span>
            </a>
        </div>

        <?php if (!empty($navFactions)): ?>
        <div class="maps-nav-section">
            <div class="maps-nav-label">Cartes de faction</div>
            <?php foreach ($navFactions as $key => $m): ?>
            <a href="<?php echo SITE_URL; ?>/pages/maps/index.php?faction=<?php echo urlencode($key); ?>"
               class="maps-nav-item <?php echo ($mapsActiveNav ?? '') === $key ? 'active' : ''; ?>"
               style="--n-color:<?php echo htmlspecialchars($m['color']); ?>">
                <?php echo maps_nav_visual($m); ?>
                <span class="maps-nav-name">Map <?php echo htmlspecialchars($m['name']); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="maps-nav-foot">
            <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="maps-back">
                <i class="fa-solid fa-arrow-left"></i> Retour au MDT
            </a>
        </div>
    </aside>

    <main class="maps-main <?php echo htmlspecialchars($mapsMainClass ?? ''); ?>">
