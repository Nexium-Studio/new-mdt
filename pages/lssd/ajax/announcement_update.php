<?php
/**
 * POST JSON — Modifier une annonce LSSD.
 * Payload: { csrf, id, title, content, color, image_url }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/announcements_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lssd_ann_user_can_manage()) {
    lssd_ann_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lssd_ann_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}
if (!lssd_ann_verify_csrf($data['csrf'] ?? null)) {
    lssd_ann_json_out(403, ['ok' => false, 'error' => 'Session expirée, rechargez la page']);
}

$id = (int) ($data['id'] ?? 0);
if ($id <= 0) {
    lssd_ann_json_out(400, ['ok' => false, 'error' => 'Identifiant invalide']);
}

$db = Database::getInstance()->getConnection();

$res = lssd_ann_update($db, $id, [
    'title'     => $data['title']     ?? '',
    'content'   => $data['content']   ?? '',
    'color'     => $data['color']     ?? '',
    'image_url' => $data['image_url'] ?? '',
]);

lssd_ann_json_out($res['ok'] ? 200 : 400, $res);
