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
    if (!academy_user_can($uid, 'config_qcm_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#3b82f6';
    $icon = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['icon'] ?? 'folder'));
    if ($name === '') academy_json(['success' => false, 'error' => 'Nom requis'], 400);
    try {
        if ($id > 0) {
            $db->prepare('UPDATE academy_qcm_categories SET name=?, description=?, color=?, icon=? WHERE id=? AND faction_key=?')
               ->execute([$name, $desc, $color, $icon, $id, $fk]);
        } else {
            $db->prepare('INSERT INTO academy_qcm_categories (faction_key, name, description, color, icon) VALUES (?,?,?,?,?)')
               ->execute([$fk, $name, $desc, $color, $icon]);
        }
        academy_json(['success' => true]);
    } catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $s = $db->prepare('SELECT faction_key FROM academy_qcm_categories WHERE id = ?'); $s->execute([$id]); $fk = $s->fetchColumn();
    if (!$fk) academy_json(['success' => false, 'error' => 'Catégorie introuvable'], 404);
    $meta = academy_faction_meta($fk);
    if (!academy_user_can($uid, 'config_qcm_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
    try { $db->prepare('DELETE FROM academy_qcm_categories WHERE id = ?')->execute([$id]); academy_json(['success' => true]); }
    catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}

academy_json(['success' => false, 'error' => 'Action inconnue'], 400);
