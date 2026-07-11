<?php
/**
 * API Dashboard Builder — thème global (get / save).
 * Décision client : thème unique pour tous, stocké dans admin_settings.
 *
 * GET/POST ?action=get   → renvoie { mode, vars }
 *     POST ?action=save  → enregistre (réservé Dev/Admin via garde serveur)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/dashboard/permissions.php';
require_once __DIR__ . '/../../includes/dashboard/theme.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$db = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'get';

if ($action === 'get') {
    echo json_encode(['success' => true, 'theme' => dashboard_get_theme($db)]);
    exit;
}

if ($action === 'save') {
    dashboard_require_build_json(); // 403 si pas Dev/Admin

    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        $in = $_POST;
    }

    $allowed = ['dark', 'light', 'classic', 'custom'];
    $mode = (string) ($in['mode'] ?? 'classic');
    if (!in_array($mode, $allowed, true)) {
        $mode = 'classic';
    }

    $defaults = dashboard_theme_default_vars();
    $vars = [];
    $inVars = is_array($in['vars'] ?? null) ? $in['vars'] : [];
    foreach ($defaults as $k => $def) {
        $vars[$k] = mdt_validate_hex_color((string) ($inVars[$k] ?? ''), $def);
    }

    try {
        mdt_admin_settings_upsert($db, 'dashboard_theme_mode', $mode);
        mdt_admin_settings_upsert($db, 'dashboard_theme_custom', json_encode($vars));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }

    if (function_exists('mdt_audit_log')) {
        mdt_audit_log(
            (int) ($_SESSION['user_id'] ?? 0),
            'DASHBOARD',
            'Thème modifié',
            'Mode: ' . $mode,
            'settings',
            null
        );
    }

    echo json_encode(['success' => true, 'theme' => ['mode' => $mode, 'vars' => $vars]]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'unknown_action']);
