<?php
/**
 * Déconnexion DarkNet — invalide la session DarkNet puis redirige.
 * Ne touche PAS à la session MDT principale.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
require_once dirname(__DIR__, 3) . '/includes/darknet_api.php';

requireLogin();

$db = Database::getInstance()->getConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$faction = (string) ($_SESSION['user_faction'] ?? '');

if ($userId > 0 && $faction !== '') {
    darknet_logout($db, $userId, $faction);
}

redirect(SITE_URL . '/pages/dashboard.php');
