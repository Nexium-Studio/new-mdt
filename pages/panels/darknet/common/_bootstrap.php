<?php
/**
 * Bootstrap commun aux modules DarkNet inter-factions (Phase 3).
 * Expose : $db, $darknetSession, $dnFaction, $dnIsLeader, $dnIsStaff, $dnUserId, $dnCsrf.
 */
require_once dirname(__DIR__, 4) . '/config/config.php';
require_once dirname(__DIR__, 4) . '/includes/darknet_api.php';
require_once dirname(__DIR__, 4) . '/includes/mdt_display_name.php';

// [DEBUG TEMP] Force l'affichage des erreurs (override MDT_DISPLAY_ERRORS=false)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

requireLogin();

$db = Database::getInstance()->getConnection();
darknet_ensure_schema($db);

$darknetSession = darknet_require_auth($db);

$dnFaction  = $darknetSession['faction_key'] ?? '';
$dnIsLeader = darknet_session_can_manage_faction($darknetSession);
$dnIsStaff  = !empty($darknetSession['staff_bypass']);
$dnUserId   = (int) ($_SESSION['user_id'] ?? 0);
$dnCsrf     = darknet_ensure_csrf();

// Assure que la ligne bank existe (utilisée pour paiements du Black Market)
try {
    $db->prepare("INSERT IGNORE INTO darknet_bank (faction_key, clean_balance, dirty_balance) VALUES (?, 0, 0)")
        ->execute([$dnFaction]);
} catch (Throwable $e) {}
