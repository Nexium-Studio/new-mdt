<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/map_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lssd_map_panel_access_ok()) {
    lssd_map_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lssd_map_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'JSON invalide']);
}

if (!lssd_map_verify_csrf($data['csrf'] ?? null)) {
    lssd_map_json_out(403, ['ok' => false, 'error' => 'Session expirée, rechargez la page']);
}

$mapId = (int) ($data['map_id'] ?? 0);
if ($mapId <= 0) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Plan invalide']);
}

$title = isset($data['title']) ? trim((string) $data['title']) : '';
$nature = isset($data['nature']) ? trim((string) $data['nature']) : '';
$mapLayer = $data['map_layer'] ?? 'atlas';
if (!in_array($mapLayer, ['atlas', 'satellite', 'road'], true)) {
    $mapLayer = 'atlas';
}

$allowedNature = ['operation', 'briefing', 'surveillance', 'patrouille', 'enquete', 'autre'];
if (!in_array($nature, $allowedNature, true)) {
    $nature = 'autre';
}

if (mb_strlen($title) < 2 || mb_strlen($title) > 255) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Titre invalide (2–255 caractères)']);
}

$strokes = $data['strokes'] ?? null;
if (!is_array($strokes)) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Tracés manquants']);
}

$enc = json_encode($strokes, JSON_UNESCAPED_UNICODE);
if ($enc === false || strlen($enc) > 4000000) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Tracés trop volumineux ou invalides']);
}

$viewport = $data['viewport'] ?? null;
$vpEnc = is_array($viewport) ? json_encode($viewport, JSON_UNESCAPED_UNICODE) : null;
if ($vpEnc !== null && strlen($vpEnc) > 1024) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Viewport invalide']);
}

$db = Database::getInstance()->getConnection();

try {
    $chk = $db->prepare('SELECT author_id FROM lssd_maps WHERE id = ? LIMIT 1');
    $chk->execute([$mapId]);
    $chkRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$chkRow) {
        lssd_map_json_out(404, ['ok' => false, 'error' => 'Plan introuvable']);
    }
    if (!lssd_map_user_can_edit((int)$chkRow['author_id'])) {
        lssd_map_json_out(403, ['ok' => false, 'error' => 'Vous n\'avez pas les droits pour modifier ce plan']);
    }

    $st = $db->prepare('
        UPDATE lssd_maps
        SET title = ?, nature = ?, map_layer = ?, strokes_json = ?, viewport_json = ?, revision = revision + 1
        WHERE id = ?
    ');
    $st->execute([$title, $nature, $mapLayer, $enc, $vpEnc, $mapId]);

    $rv = $db->prepare('SELECT revision FROM lssd_maps WHERE id = ?');
    $rv->execute([$mapId]);
    $rev = (int) $rv->fetchColumn();

    lssd_map_json_out(200, ['ok' => true, 'id' => $mapId, 'revision' => $rev]);
} catch (Throwable $e) {
    error_log('map_update: ' . $e->getMessage());
    lssd_map_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
