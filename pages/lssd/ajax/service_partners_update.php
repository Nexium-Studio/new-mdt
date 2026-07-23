<?php
/**
 * POST JSON — Met à jour les binômes actifs du shift de l'utilisateur.
 * Payload: { csrf, partners: [user_id, user_id, ...] }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/service_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lssd_service_access_ok()) {
    lssd_service_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lssd_service_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    lssd_service_json_out(400, ['ok' => false, 'error' => 'Payload invalide']);
}
if (!lssd_service_verify_csrf($data['csrf'] ?? null)) {
    lssd_service_json_out(403, ['ok' => false, 'error' => 'Session expirée, rechargez la page']);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    lssd_service_json_out(401, ['ok' => false, 'error' => 'Non authentifié']);
}

$desired = $data['partners'] ?? [];
if (!is_array($desired)) {
    lssd_service_json_out(400, ['ok' => false, 'error' => 'Liste de binômes invalide']);
}
if (count($desired) > 20) {
    lssd_service_json_out(400, ['ok' => false, 'error' => 'Trop de binômes (20 maximum)']);
}

$db = Database::getInstance()->getConnection();
lssd_service_ensure_schema($db);

$shift = lssd_service_active_shift($db, $uid);
if (!$shift) {
    lssd_service_json_out(409, ['ok' => false, 'error' => "Vous n'êtes pas en service"]);
}

try {
    $result = lssd_service_set_partners($db, (int) $shift['id'], $uid, $desired);
    lssd_service_json_out(200, [
        'ok'       => true,
        'shift_id' => (int) $shift['id'],
        'partners' => $result['partners'],
        'freed'    => $result['freed'],
    ]);
} catch (Throwable $e) {
    error_log('service_partners_update: ' . $e->getMessage());
    lssd_service_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
