<?php
declare(strict_types=1);

/**
 * map_point_delete.php — Supprime un point par son id.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/map_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lspd_map_panel_access_ok()) {
    lspd_map_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lspd_map_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'JSON invalide']);
}
if (!lspd_map_verify_csrf($data['csrf'] ?? null)) {
    lspd_map_json_out(403, ['ok' => false, 'error' => 'Session expirée']);
}

$mapId   = (int)($data['map_id'] ?? 0);
$pointId = '';
if (isset($data['point_id']) && is_string($data['point_id'])) {
    $pointId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['point_id']) ?? '';
}
if ($mapId <= 0 || $pointId === '') {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Paramètres invalides']);
}

$db = Database::getInstance()->getConnection();

try {
    $db->beginTransaction();

    $sel = $db->prepare('SELECT strokes_json FROM lspd_maps WHERE id = ? FOR UPDATE');
    $sel->execute([$mapId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->rollBack();
        lspd_map_json_out(404, ['ok' => false, 'error' => 'Plan introuvable']);
    }

    $arr = json_decode((string)$row['strokes_json'], true);
    if (!is_array($arr)) $arr = [];

    $filtered = [];
    $removed  = false;
    foreach ($arr as $item) {
        if (is_array($item) && isset($item['id']) && $item['id'] === $pointId) {
            $removed = true;
            continue;
        }
        $filtered[] = $item;
    }

    if (!$removed) {
        $db->rollBack();
        lspd_map_json_out(404, ['ok' => false, 'error' => 'Point introuvable']);
    }

    $enc = json_encode($filtered, JSON_UNESCAPED_UNICODE);
    if ($enc === false) {
        $db->rollBack();
        lspd_map_json_out(500, ['ok' => false, 'error' => 'Encodage échoué']);
    }

    $up = $db->prepare('UPDATE lspd_maps SET strokes_json = ?, revision = revision + 1 WHERE id = ?');
    $up->execute([$enc, $mapId]);
    $db->commit();

    $rv = $db->prepare('SELECT revision FROM lspd_maps WHERE id = ?');
    $rv->execute([$mapId]);
    $rev = (int)$rv->fetchColumn();

    lspd_map_json_out(200, [
        'ok'       => true,
        'revision' => $rev,
        'deleted'  => $pointId,
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('map_point_delete: ' . $e->getMessage());
    lspd_map_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}