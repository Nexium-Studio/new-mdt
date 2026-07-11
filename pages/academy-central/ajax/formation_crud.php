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
    if (!academy_user_can($uid, 'formations_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
    $id = (int)($_POST['id'] ?? 0);
    $catId = (int)($_POST['category_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#10b981';
    $icon = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['icon'] ?? 'chalkboard-user'));
    $active = !empty($_POST['is_active']) ? 1 : 0;
    if ($name === '') academy_json(['success' => false, 'error' => 'Nom requis'], 400);
    try {
        if ($id > 0) $db->prepare('UPDATE academy_formations SET category_id=?, name=?, description=?, color=?, icon=?, is_active=? WHERE id=? AND faction_key=?')
                         ->execute([$catId ?: null, $name, $desc, $color, $icon, $active, $id, $fk]);
        else $db->prepare('INSERT INTO academy_formations (category_id, faction_key, name, description, color, icon, is_active) VALUES (?,?,?,?,?,?,?)')
                  ->execute([$catId ?: null, $fk, $name, $desc, $color, $icon, $active]);
        academy_json(['success' => true]);
    } catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $s = $db->prepare('SELECT faction_key FROM academy_formations WHERE id = ?'); $s->execute([$id]); $fk = $s->fetchColumn();
    if (!$fk) academy_json(['success' => false, 'error' => 'Formation introuvable'], 404);
    $meta = academy_faction_meta($fk);
    if (!academy_user_can($uid, 'formations_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
    try { $db->prepare('DELETE FROM academy_formations WHERE id = ?')->execute([$id]); academy_json(['success' => true]); }
    catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}
academy_json(['success' => false, 'error' => 'Action inconnue'], 400);
