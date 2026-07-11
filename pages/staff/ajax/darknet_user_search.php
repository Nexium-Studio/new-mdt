<?php
/**
 * Recherche d'utilisateurs pour assignation (panel config_illegale.php).
 * GET : q (string), faction (string, optionnel)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/darknet_api.php';

requireLogin();
if (!darknet_staff_can_manage()) {
    darknet_json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

$db = Database::getInstance()->getConnection();
$q = isset($_GET['q']) ? (string) $_GET['q'] : '';
$faction = isset($_GET['faction']) ? trim((string) $_GET['faction']) : '';

$users = darknet_search_users($db, $q, $faction !== '' ? $faction : null, 15);

darknet_json_out(['ok' => true, 'users' => $users]);
