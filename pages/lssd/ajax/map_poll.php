<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/map_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!lssd_map_panel_access_ok()) {
    lssd_map_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'ID manquant']);
}

$db = Database::getInstance()->getConnection();

try {
    $db->exec('DELETE FROM lssd_map_presence WHERE updated_at < DATE_SUB(NOW(), INTERVAL 25 SECOND)');

    $m = $db->prepare('SELECT id, title, nature, map_layer, strokes_json, viewport_json, revision, updated_at FROM lssd_maps WHERE id = ? LIMIT 1');
    $m->execute([$id]);
    $map = $m->fetch(PDO::FETCH_ASSOC);
    if (!$map) {
        lssd_map_json_out(404, ['ok' => false, 'error' => 'Plan introuvable']);
    }

    $p = $db->prepare('SELECT user_id, display_name, lat, lng FROM lssd_map_presence WHERE map_id = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 20 SECOND)');
    $p->execute([$id]);
    $rows = $p->fetchAll(PDO::FETCH_ASSOC);
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $presence = [];
    foreach ($rows as $r) {
        $presence[] = [
            'user_id'      => (int) $r['user_id'],
            'display_name' => (string) $r['display_name'],
            'lat'          => (float) $r['lat'],
            'lng'          => (float) $r['lng'],
            'self'         => (int) $r['user_id'] === $uid,
        ];
    }

    $strokes = json_decode((string) $map['strokes_json'], true);
    if (!is_array($strokes)) {
        $strokes = [];
    }

    lssd_map_json_out(200, [
        'ok'        => true,
        'revision'  => (int) $map['revision'],
        'strokes'   => $strokes,
        'map_layer' => $map['map_layer'],
        'viewport'  => json_decode((string) ($map['viewport_json'] ?: '{}'), true) ?: null,
        'presence'  => $presence,
        'updated_at'=> $map['updated_at'],
    ]);
} catch (Throwable $e) {
    error_log('map_poll: ' . $e->getMessage());
    lssd_map_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
