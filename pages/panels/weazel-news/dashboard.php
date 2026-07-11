<?php
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
if (($panelCfg['access'] ?? 'faction') === 'darknet') {
    requireDarknetPanelAccess();
} else {
    requireFactionPanelAccess($panelCfg['faction']);
}

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="mdt-panel-card">
    <h2>Bienvenue</h2>
    <p>
        Panel <strong><?php echo htmlspecialchars($panelCfg['name']); ?></strong> — structure de base.
        Les modules métier pourront être ajoutés progressivement.
    </p>
    <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="btn-mdt"><i class="fas fa-home"></i> Accueil MDT</a>
</div>

<?php include __DIR__ . '/includes/footer.php';
