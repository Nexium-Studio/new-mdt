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
    $catId = (int)($_POST['category_id'] ?? 0);
    $desc = trim((string)($_POST['description'] ?? ''));
    $oa = trim((string)($_POST['option_a'] ?? ''));
    $ob = trim((string)($_POST['option_b'] ?? ''));
    $oc = trim((string)($_POST['option_c'] ?? ''));
    $od = trim((string)($_POST['option_d'] ?? ''));
    $correct = in_array($_POST['correct'] ?? '', ['A','B','C','D'], true) ? $_POST['correct'] : 'A';
    $order = (int)($_POST['sort_order'] ?? 0);
    $active = !empty($_POST['is_active']) ? 1 : 0;

    if ($desc === '' || $oa === '' || $ob === '' || $catId <= 0) academy_json(['success' => false, 'error' => 'Description, 2 options et catégorie requises'], 400);
    if ($correct === 'C' && $oc === '') academy_json(['success' => false, 'error' => 'Option C vide mais marquée correcte'], 400);
    if ($correct === 'D' && $od === '') academy_json(['success' => false, 'error' => 'Option D vide mais marquée correcte'], 400);

    try {
        if ($id > 0) {
            $db->prepare('UPDATE academy_qcm_questions SET category_id=?, description=?, option_a=?, option_b=?, option_c=?, option_d=?, correct=?, sort_order=?, is_active=? WHERE id=? AND faction_key=?')
               ->execute([$catId, $desc, $oa, $ob, $oc ?: null, $od ?: null, $correct, $order, $active, $id, $fk]);
        } else {
            $db->prepare('INSERT INTO academy_qcm_questions (category_id, faction_key, description, option_a, option_b, option_c, option_d, correct, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)')
               ->execute([$catId, $fk, $desc, $oa, $ob, $oc ?: null, $od ?: null, $correct, $order, $active]);
        }
        academy_json(['success' => true]);
    } catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $s = $db->prepare('SELECT faction_key FROM academy_qcm_questions WHERE id = ?'); $s->execute([$id]); $fk = $s->fetchColumn();
    if (!$fk) academy_json(['success' => false, 'error' => 'Question introuvable'], 404);
    $meta = academy_faction_meta($fk);
    if (!academy_user_can($uid, 'config_qcm_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
    try { $db->prepare('DELETE FROM academy_qcm_questions WHERE id = ?')->execute([$id]); academy_json(['success' => true]); }
    catch (Exception $e) { academy_json(['success' => false, 'error' => $e->getMessage()], 500); }
}

academy_json(['success' => false, 'error' => 'Action inconnue'], 400);
