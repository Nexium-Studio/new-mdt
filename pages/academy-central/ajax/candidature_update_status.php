<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);

$id = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');
$note = trim((string)($_POST['note'] ?? ''));
if ($id <= 0 || !array_key_exists($status, academy_candidature_statuses())) {
    academy_json(['success' => false, 'error' => 'Paramètres invalides'], 400);
}

$db = Database::getInstance()->getConnection();
$s = $db->prepare('SELECT faction_key FROM academy_candidatures WHERE id = ? LIMIT 1');
$s->execute([$id]);
$fk = $s->fetchColumn();
if (!$fk) academy_json(['success' => false, 'error' => 'Candidature introuvable'], 404);

$uid = (int)$_SESSION['user_id'];
$meta = academy_faction_meta($fk);
if (!academy_user_can($uid, 'log_candidature_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) {
    academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
}

try {
    $db->prepare('UPDATE academy_candidatures SET status = ?, decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?')
       ->execute([$status, $note !== '' ? $note : null, $uid, $id]);
    academy_json(['success' => true]);
} catch (Exception $e) {
    academy_json(['success' => false, 'error' => $e->getMessage()], 500);
}
