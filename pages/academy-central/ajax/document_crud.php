<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);
$action = $_GET['action'] ?? '';
$db = Database::getInstance()->getConnection();
$uid = (int)$_SESSION['user_id'];

if ($action === 'save') {
    $fk = (string)($_POST['faction'] ?? '');
    if (!academy_faction_exists($fk)) academy_json(['success' => false, 'error' => 'Faction invalide'], 400);
    $meta = academy_faction_meta($fk);
    if (!academy_user_can($uid, 'documents_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);

    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $catId = (int)($_POST['category_id'] ?? 0);
    $content = academy_sanitize_html((string)($_POST['content'] ?? ''));
    if ($title === '') academy_json(['success' => false, 'error' => 'Titre requis'], 400);

    try {
        if ($id > 0) {
            $db->prepare('UPDATE academy_documents SET category_id=?, title=?, content=? WHERE id=? AND faction_key=?')
               ->execute([$catId ?: null, $title, $content, $id, $fk]);
            academy_json(['success' => true, 'id' => $id]);
        } else {
            $db->prepare('INSERT INTO academy_documents (category_id, faction_key, title, content, created_by) VALUES (?,?,?,?,?)')
               ->execute([$catId ?: null, $fk, $title, $content, $uid]);
            academy_json(['success' => true, 'id' => (int)$db->lastInsertId()]);
        }
    } catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $s = $db->prepare('SELECT faction_key FROM academy_documents WHERE id = ?'); $s->execute([$id]); $fk = $s->fetchColumn();
    if (!$fk) academy_json(['success' => false, 'error' => 'Document introuvable'], 404);
    $meta = academy_faction_meta($fk);
    if (!academy_user_can($uid, 'documents_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
    try { $db->prepare('DELETE FROM academy_documents WHERE id = ?')->execute([$id]); academy_json(['success' => true]); }
    catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}

academy_json(['success' => false, 'error' => 'Action inconnue'], 400);
