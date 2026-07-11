<?php
/**
 * GET JSON — Liste des officiers LSPD actuellement en service.
 * Utilisé pour rafraîchir la vue côté client sans recharger la page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/service_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!lspd_service_access_ok()) {
    lspd_service_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}

$db = Database::getInstance()->getConnection();
lspd_service_ensure_schema($db);

$uid = (int) ($_SESSION['user_id'] ?? 0);

try {
    $officers = lspd_service_list_active($db);
    $mine = null;
    foreach ($officers as $o) {
        if ((int) $o['user_id'] === $uid) {
            $mine = $o;
            break;
        }
    }

    $stats  = lspd_service_stats($db, $uid);
    $nowTs  = lspd_service_now_ts($db);

    lspd_service_json_out(200, [
        'ok'       => true,
        'current'  => $mine,
        'officers' => $officers,
        'stats'    => $stats,
        'now_ts'   => $nowTs,
    ]);
} catch (Throwable $e) {
    error_log('service_list: ' . $e->getMessage());
    lspd_service_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
