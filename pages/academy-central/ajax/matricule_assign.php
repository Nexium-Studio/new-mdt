<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);

$id = (int)($_POST['id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$rank = trim((string)($_POST['rank'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));

$db = Database::getInstance()->getConnection();
$s = $db->prepare('SELECT faction_key, number FROM academy_matricules WHERE id = ?'); $s->execute([$id]);
$mat = $s->fetch(PDO::FETCH_ASSOC);
if (!$mat) academy_json(['success' => false, 'error' => 'Matricule introuvable'], 404);

$meta = academy_faction_meta($mat['faction_key']);
$uid = (int)$_SESSION['user_id'];
if (!academy_user_can($uid, 'matricules_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);

try {
    if ($userId > 0) {
        $db->prepare('UPDATE academy_matricules SET user_id=?, name_label=?, rank_label=?, note=?, assigned_by=?, assigned_at=NOW() WHERE id=?')
           ->execute([$userId, $name ?: null, $rank ?: null, $note ?: null, $uid, $id]);
    } else {
        $db->prepare('UPDATE academy_matricules SET user_id=NULL, name_label=?, rank_label=?, note=?, assigned_by=?, assigned_at=NULL WHERE id=?')
           ->execute([$name ?: null, $rank ?: null, $note ?: null, $uid, $id]);
    }
    academy_json(['success' => true]);
} catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
