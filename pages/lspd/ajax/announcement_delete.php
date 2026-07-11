<?php
/**
 * POST JSON — Supprimer (soft delete → is_active=0) une annonce LSPD.
 * Payload: { csrf, id }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/announcements_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lspd_ann_user_can_manage()) {
    lspd_ann_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lspd_ann_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}
if (!lspd_ann_verify_csrf($data['csrf'] ?? null)) {
    lspd_ann_json_out(403, ['ok' => false, 'error' => 'Session expirée, rechargez la page']);
}

$id = (int) ($data['id'] ?? 0);
if ($id <= 0) {
    lspd_ann_json_out(400, ['ok' => false, 'error' => 'Identifiant invalide']);
}

$db = Database::getInstance()->getConnection();
$res = lspd_ann_delete($db, $id);

lspd_ann_json_out($res['ok'] ? 200 : 400, $res);
