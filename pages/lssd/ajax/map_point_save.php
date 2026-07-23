<?php
declare(strict_types=1);

/**
 * map_point_save.php — Crée ou met à jour un point (upsert par id).
 * Les points sont stockés dans strokes_json avec le flag type=point.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/map_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lssd_map_panel_access_ok()) {
    lssd_map_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lssd_map_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'JSON invalide']);
}
if (!lssd_map_verify_csrf($data['csrf'] ?? null)) {
    lssd_map_json_out(403, ['ok' => false, 'error' => 'Session expirée']);
}

$mapId = (int)($data['map_id'] ?? 0);
$point = $data['point'] ?? null;
if ($mapId <= 0 || !is_array($point)) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Paramètres invalides']);
}

/* Validation */
$pointId = '';
if (isset($point['id']) && is_string($point['id'])) {
    $pointId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $point['id']) ?? '';
}
if ($pointId === '') {
    $pointId = 'p_' . bin2hex(random_bytes(8));
}

$x = isset($point['x']) && is_numeric($point['x']) ? (float)$point['x'] : -1;
$y = isset($point['y']) && is_numeric($point['y']) ? (float)$point['y'] : -1;
if ($x < -0.15 || $x > 1.15 || $y < -0.15 || $y > 1.15) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Position hors plage']);
}

$title = isset($point['title']) ? trim((string)$point['title']) : '';
if ($title === '') {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Titre requis']);
}
if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);

$color = isset($point['color']) ? (string)$point['color'] : '#10b981';
$color = preg_replace('/[^#0-9a-fA-F]/', '', $color);
if (!is_string($color) || strlen($color) < 4 || strlen($color) > 7) {
    $color = '#10b981';
}

$author = isset($point['author']) ? trim((string)$point['author']) : '';
if ($author === '' && function_exists('mdt_session_display_name')) {
    $author = (string) mdt_session_display_name();
}
if ($author === '') $author = (string)($_SESSION['user_username'] ?? 'Agent');
if ($author === '') $author = 'Agent';
if (mb_strlen($author) > 191) $author = mb_substr($author, 0, 191);

$newPoint = [
    'id'     => $pointId,
    'type'   => 'point',
    'x'      => round($x, 6),
    'y'      => round($y, 6),
    'title'  => $title,
    'color'  => $color,
    'author' => $author,
];

$db = Database::getInstance()->getConnection();

try {
    $db->beginTransaction();

    $sel = $db->prepare('SELECT strokes_json FROM lssd_maps WHERE id = ? FOR UPDATE');
    $sel->execute([$mapId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->rollBack();
        lssd_map_json_out(404, ['ok' => false, 'error' => 'Plan introuvable']);
    }

    $arr = json_decode((string)$row['strokes_json'], true);
    if (!is_array($arr)) $arr = [];

    /* Upsert : remplacer si id existe, sinon append */
    $found = false;
    foreach ($arr as $i => $item) {
        if (is_array($item) && isset($item['id']) && $item['id'] === $pointId) {
            $arr[$i] = $newPoint;
            $found = true;
            break;
        }
    }
    if (!$found) $arr[] = $newPoint;

    $enc = json_encode($arr, JSON_UNESCAPED_UNICODE);
    if ($enc === false || strlen($enc) > 4000000) {
        $db->rollBack();
        lssd_map_json_out(400, ['ok' => false, 'error' => 'Stockage plein']);
    }

    $up = $db->prepare('UPDATE lssd_maps SET strokes_json = ?, revision = revision + 1 WHERE id = ?');
    $up->execute([$enc, $mapId]);
    $db->commit();

    $rv = $db->prepare('SELECT revision FROM lssd_maps WHERE id = ?');
    $rv->execute([$mapId]);
    $rev = (int)$rv->fetchColumn();

    lssd_map_json_out(200, [
        'ok'       => true,
        'revision' => $rev,
        'point'    => $newPoint,
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('map_point_save: ' . $e->getMessage());
    lssd_map_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}