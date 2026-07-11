<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);
$fk = (string)($_POST['faction'] ?? '');
if (!academy_faction_exists($fk)) academy_json(['success' => false, 'error' => 'Faction invalide'], 400);
$uid = (int)$_SESSION['user_id'];
$meta = academy_faction_meta($fk);
if (!academy_user_can($uid, 'config_candidature_' . $meta['slug'], 'edit') && !academy_is_staff_bypass()) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);

$name = trim((string)($_POST['name'] ?? ''));
$desc = trim((string)($_POST['description'] ?? ''));
$active = !empty($_POST['is_active']) ? 1 : 0;
$fieldsJson = $_POST['fields'] ?? '[]';
$fields = json_decode($fieldsJson, true);
if (!is_array($fields)) academy_json(['success' => false, 'error' => 'Champs invalides'], 400);

$allowedTypes = array_keys(academy_candidature_field_types());
$clean = [];
foreach ($fields as $f) {
    $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($f['key'] ?? '')));
    if ($k === '') continue;
    $type = in_array($f['type'] ?? '', $allowedTypes, true) ? $f['type'] : 'texte_court';
    $clean[] = [
        'key' => $k,
        'label' => trim((string)($f['label'] ?? $k)),
        'type' => $type,
        'required' => !empty($f['required']),
        'order' => (int)($f['order'] ?? 0),
        'placeholder' => trim((string)($f['placeholder'] ?? '')),
    ];
}

try {
    $db = Database::getInstance()->getConnection();
    $db->prepare('INSERT INTO academy_candidature_config (faction_key, name, description, is_active, fields, updated_by) VALUES (?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), is_active=VALUES(is_active), fields=VALUES(fields), updated_by=VALUES(updated_by)')
       ->execute([$fk, $name ?: 'Candidature ' . $fk, $desc, $active, json_encode($clean, JSON_UNESCAPED_UNICODE), $uid]);
    academy_json(['success' => true]);
} catch (Exception $e) {
    academy_json(['success' => false, 'error' => $e->getMessage()], 500);
}
