<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/map_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lspd_map_panel_access_ok()) {
    lspd_map_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lspd_map_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'JSON invalide']);
}

if (!lspd_map_verify_csrf($data['csrf'] ?? null)) {
    lspd_map_json_out(403, ['ok' => false, 'error' => 'Session expirée']);
}

$mapId = (int) ($data['map_id'] ?? 0);
// Colonnes lat/lng : positions normalisées sur l’image carte (0–1), pas du WGS84.
$lat = max(0.0, min(1.0, isset($data['lat']) ? (float) $data['lat'] : 0.0));
$lng = max(0.0, min(1.0, isset($data['lng']) ? (float) $data['lng'] : 0.0));
$displayName = isset($data['display_name']) ? trim((string) $data['display_name']) : '';
if ($displayName === '') {
    $displayName = 'Agent';
}
if ($mapId <= 0 || mb_strlen($displayName) > 191) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Paramètres invalides']);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    lspd_map_json_out(401, ['ok' => false, 'error' => 'Non connecté']);
}

$db = Database::getInstance()->getConnection();

try {
    $chk = $db->prepare('SELECT id FROM lspd_maps WHERE id = ? LIMIT 1');
    $chk->execute([$mapId]);
    if (!$chk->fetchColumn()) {
        lspd_map_json_out(404, ['ok' => false, 'error' => 'Plan introuvable']);
    }

    $sql = '
        INSERT INTO lspd_map_presence (map_id, user_id, display_name, lat, lng)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), lat = VALUES(lat), lng = VALUES(lng), updated_at = CURRENT_TIMESTAMP
    ';
    $db->prepare($sql)->execute([$mapId, $uid, $displayName, $lat, $lng]);
    lspd_map_json_out(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('map_presence: ' . $e->getMessage());
    lspd_map_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
