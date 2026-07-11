<?php
/**
 * Charge les assets globaux MDT — modals de confirmation / alertes (remplace alert/confirm natifs côté UI).
 * Inclure depuis un footer panel après la définition de window.Company / window.Pearl (bloc <script> principal).
 */
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', '/assets');
}
$__mdtAssets = rtrim((string) ASSETS_PATH, '/');
$__mdtV = '1';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($__mdtAssets . '/css/mdt-confirm-modal.css?v=' . $__mdtV, ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars($__mdtAssets . '/js/mdt-confirm-modal.js?v=' . $__mdtV, ENT_QUOTES, 'UTF-8'); ?>"></script>
