<?php
/**
 * POST JSON — Créer une annonce LSSD.
 * Payload: { csrf, title, content, color, image_url }
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

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    lssd_ann_json_out(401, ['ok' => false, 'error' => 'Non authentifié']);
}

$db = Database::getInstance()->getConnection();

// Auteur : display_name RP si dispo, sinon username
$authorName = '';
$authorRank = null;
try {
    $st = $db->prepare('
        SELECT u.username, u.faction_rank, p.firstname, p.lastname
        FROM users u
        LEFT JOIN profiles p ON p.id = u.profile_id
        WHERE u.id = ? LIMIT 1
    ');
    $st->execute([$uid]);
    $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $authorName = mdt_rp_display_name($me['firstname'] ?? null, $me['lastname'] ?? null, (string) ($me['username'] ?? ''));
    $authorRank = $me['faction_rank'] !== null ? (string) $me['faction_rank'] : null;
} catch (Throwable $e) {
    $authorName = (string) ($_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? 'LSSD');
}

$res = lssd_ann_create($db, $uid, $authorName, $authorRank, [
    'title'     => $data['title']     ?? '',
    'content'   => $data['content']   ?? '',
    'color'     => $data['color']     ?? '',
    'image_url' => $data['image_url'] ?? '',
]);

lssd_ann_json_out($res['ok'] ? 200 : 400, $res);
