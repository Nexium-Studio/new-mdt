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
$stroke = $data['stroke'] ?? null;
if ($mapId <= 0 || !is_array($stroke)) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Paramètres invalides']);
}

$tool = $stroke['tool'] ?? 'pen';
if (!in_array($tool, ['pen', 'eraser', 'marker'], true)) {
    $tool = 'pen';
}

$points = $stroke['points'] ?? null;
if (!is_array($points) || count($points) < 2) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Tracé vide (points normalisés requis)']);
}
if (count($points) > 50000) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Tracé trop long']);
}

$sanitized = [];
foreach ($points as $pair) {
    if (!is_array($pair) || count($pair) < 2) {
        lspd_map_json_out(400, ['ok' => false, 'error' => 'Coordonnées invalides']);
    }
    if (!is_numeric($pair[0]) || !is_numeric($pair[1])) {
        lspd_map_json_out(400, ['ok' => false, 'error' => 'Coordonnées invalides']);
    }
    $nx = (float) $pair[0];
    $ny = (float) $pair[1];
    if ($nx < -0.15 || $nx > 1.15 || $ny < -0.15 || $ny > 1.15) {
        lspd_map_json_out(400, ['ok' => false, 'error' => 'Point hors plage']);
    }
    $sanitized[] = [round($nx, 6), round($ny, 6)];
}

$stroke['tool']   = $tool;
$stroke['id']    = $stroke['id'] ?? bin2hex(random_bytes(8));
$stroke['width'] = isset($stroke['width']) ? max(1, min(48, (int) $stroke['width'])) : 3;
$stroke['color'] = isset($stroke['color']) ? preg_replace('/[^#0-9a-fA-F]/', '', (string) $stroke['color']) : '#ef4444';
if ($stroke['color'] === '' || strlen($stroke['color']) < 4) {
    $stroke['color'] = '#ef4444';
}
$stroke['points'] = $sanitized;
unset($stroke['latlngs']);

$db = Database::getInstance()->getConnection();

try {
    $db->beginTransaction();
    $sel = $db->prepare('SELECT strokes_json, revision FROM lspd_maps WHERE id = ? FOR UPDATE');
    $sel->execute([$mapId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->rollBack();
        lspd_map_json_out(404, ['ok' => false, 'error' => 'Plan introuvable']);
    }

    $arr = json_decode((string) $row['strokes_json'], true);
    if (!is_array($arr)) {
        $arr = [];
    }
    $arr[] = $stroke;
    $enc = json_encode($arr, JSON_UNESCAPED_UNICODE);
    if ($enc === false || strlen($enc) > 4000000) {
        $db->rollBack();
        lspd_map_json_out(400, ['ok' => false, 'error' => 'Stockage plein']);
    }

    $up = $db->prepare('UPDATE lspd_maps SET strokes_json = ?, revision = revision + 1 WHERE id = ?');
    $up->execute([$enc, $mapId]);
    $db->commit();

    $rv = $db->prepare('SELECT revision FROM lspd_maps WHERE id = ?');
    $rv->execute([$mapId]);
    $rev = (int) $rv->fetchColumn();

    lspd_map_json_out(200, ['ok' => true, 'revision' => $rev]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('map_append_stroke: ' . $e->getMessage());
    lspd_map_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
