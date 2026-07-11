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
    lspd_map_json_out(403, ['ok' => false, 'error' => 'Session expirée, rechargez la page']);
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
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Titre invalide (2–255 caractères)']);
}

$strokes = $data['strokes'] ?? null;
if (!is_array($strokes)) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Tracés manquants']);
}

$enc = json_encode($strokes, JSON_UNESCAPED_UNICODE);
if ($enc === false || strlen($enc) > 4000000) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Tracés trop volumineux ou invalides']);
}

$viewport = $data['viewport'] ?? null;
$vpEnc = is_array($viewport) ? json_encode($viewport, JSON_UNESCAPED_UNICODE) : null;
if ($vpEnc !== null && strlen($vpEnc) > 1024) {
    lspd_map_json_out(400, ['ok' => false, 'error' => 'Viewport invalide']);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    lspd_map_json_out(401, ['ok' => false, 'error' => 'Non connecté']);
}

$db = Database::getInstance()->getConnection();

try {
    $st = $db->prepare('
        INSERT INTO lspd_maps (title, nature, map_layer, strokes_json, viewport_json, author_id, revision)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ');
    $st->execute([$title, $nature, $mapLayer, $enc, $vpEnc, $uid]);
    $id = (int) $db->lastInsertId();
    lspd_map_json_out(200, ['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    error_log('map_save: ' . $e->getMessage());
    lspd_map_json_out(500, ['ok' => false, 'error' => 'Erreur serveur (table lspd_maps absente ? Exécutez la migration SQL.)']);
}
