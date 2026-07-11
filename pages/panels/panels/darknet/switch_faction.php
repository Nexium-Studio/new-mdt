<?php
/**
 * Switcher de faction pour les staffs : change la faction "vue" dans le DarkNet.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
require_once dirname(__DIR__, 3) . '/includes/darknet_api.php';

requireLogin();

if (!darknet_is_staff_bypass()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fk = (string) ($_POST['faction_key'] ?? '');
    darknet_staff_set_viewing_faction($fk);
}

$back = $_SERVER['HTTP_REFERER'] ?? (SITE_URL . '/pages/panels/darknet/dashboard.php');
redirect($back);
