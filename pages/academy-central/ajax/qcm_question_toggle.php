<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);
$id = (int)($_POST['id'] ?? 0);
$db = Database::getInstance()->getConnection();
$s = $db->prepare('SELECT faction_key, is_active FROM academy_qcm_questions WHERE id = ?');
$s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC);
if (!$r) academy_json(['success' => false, 'error' => 'Question introuvable'], 404);
$meta = academy_faction_meta($r['faction_key']);
$uid = (int)$_SESSION['user_id'];
if (!academy_user_can($uid, 'config_qcm_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
try {
    $db->prepare('UPDATE academy_qcm_questions SET is_active = ? WHERE id = ?')->execute([(int)$r['is_active'] ? 0 : 1, $id]);
    academy_json(['success' => true]);
} catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
