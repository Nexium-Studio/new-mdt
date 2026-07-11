<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);
$action = $_GET['action'] ?? '';
$db = Database::getInstance()->getConnection();
$uid = (int)$_SESSION['user_id'];
$allowed = array_keys(academy_formation_statuses());

if ($action === 'assign') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $fid = (int)($_POST['formation_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', $allowed, true) ? $_POST['status'] : 'non_commence';
    if ($userId <= 0 || $fid <= 0) academy_json(['success' => false, 'error' => 'Paramètres invalides'], 400);
    $s = $db->prepare('SELECT faction_key FROM academy_formations WHERE id = ?'); $s->execute([$fid]); $fk = $s->fetchColumn();
    if (!$fk) academy_json(['success' => false, 'error' => 'Formation introuvable'], 404);
    $meta = academy_faction_meta($fk);
    if (!academy_user_can($uid, 'formations_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
    try {
        $db->prepare('INSERT INTO academy_user_formations (user_id, formation_id, status, evaluator_id, started_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status), evaluator_id=VALUES(evaluator_id)')
           ->execute([$userId, $fid, $status, $uid]);
        academy_json(['success' => true]);
    } catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', $allowed, true) ? $_POST['status'] : null;
    if ($id <= 0 || $status === null) academy_json(['success' => false, 'error' => 'Paramètres invalides'], 400);
    $s = $db->prepare('SELECT f.faction_key FROM academy_user_formations uf JOIN academy_formations f ON f.id = uf.formation_id WHERE uf.id = ?');
    $s->execute([$id]); $fk = $s->fetchColumn();
    if (!$fk) academy_json(['success' => false, 'error' => 'Introuvable'], 404);
    $meta = academy_faction_meta($fk);
    if (!academy_user_can($uid, 'formations_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
    try {
        $set = 'status = ?';
        $params = [$status];
        if ($status === 'valide' || $status === 'refuse') { $set .= ', completed_at = NOW()'; }
        $params[] = $uid; $params[] = $id;
        $set .= ', evaluator_id = ?';
        $db->prepare('UPDATE academy_user_formations SET ' . $set . ' WHERE id = ?')->execute($params);
        academy_json(['success' => true]);
    } catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}

academy_json(['success' => false, 'error' => 'Action inconnue'], 400);
