<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);

$id = (int)($_POST['id'] ?? 0);
$db = Database::getInstance()->getConnection();
$s = $db->prepare('SELECT faction_key FROM academy_matricules WHERE id = ?'); $s->execute([$id]);
$fk = $s->fetchColumn();
if (!$fk) academy_json(['success' => false, 'error' => 'Matricule introuvable'], 404);
$meta = academy_faction_meta($fk);
if (!academy_user_can((int)$_SESSION['user_id'], 'matricules_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
try {
    $db->prepare('UPDATE academy_matricules SET user_id=NULL, name_label=NULL, rank_label=NULL, note=NULL, assigned_by=NULL, assigned_at=NULL WHERE id=?')
       ->execute([$id]);
    academy_json(['success' => true]);
} catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
