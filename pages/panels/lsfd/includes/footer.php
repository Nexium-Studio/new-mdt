<?php
// Composants LSFD (Lsfd*) actifs sur toutes les pages, avant la fermeture du body.
include __DIR__ . '/components.php';
require_once dirname(__DIR__, 4) . '/pages/panel-base/render_footer.php';
