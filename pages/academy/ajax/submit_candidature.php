<?php
/**
 * Endpoint AJAX optionnel pour soumettre une candidature sans rechargement.
 * (Le moteur _candidature_engine.php gère aussi le POST standard.)
 */
require_once __DIR__ . '/../../../config/config.php';
academy_require_public();

$fk = $_POST['faction'] ?? '';
if (!academy_faction_exists($fk)) academy_json(['success' => false, 'error' => 'Faction invalide'], 400);
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);

$uid = (int)$_SESSION['user_id'];
$db  = Database::getInstance()->getConnection();
$cfg = academy_candidature_config($fk);
if ((int)$cfg['is_active'] !== 1) academy_json(['success' => false, 'error' => 'Candidatures fermées'], 403);

try {
    $chk = $db->prepare("SELECT id, status FROM academy_candidatures WHERE user_id = ? AND faction_key = ? ORDER BY id DESC LIMIT 1");
    $chk->execute([$uid, $fk]);
    $ex = $chk->fetch(PDO::FETCH_ASSOC);
    if ($ex && $ex['status'] !== 'refuse') {
        academy_json(['success' => false, 'error' => 'Vous avez déjà une candidature active.'], 409);
    }
} catch (Exception $e) {}

$data = [];
foreach (($cfg['fields'] ?? []) as $f) {
    $k = $f['key'] ?? ''; if ($k === '') continue;
    $v = trim((string)($_POST['f_' . $k] ?? ''));
    if (!empty($f['required']) && $v === '') {
        academy_json(['success' => false, 'error' => 'Champ requis : ' . ($f['label'] ?? $k)], 400);
    }
    $data[$k] = $v;
}

try {
    $db->prepare('INSERT INTO academy_candidatures (user_id, faction_key, status, form_data) VALUES (?,?,?,?)')
       ->execute([$uid, $fk, 'en_attente', json_encode($data, JSON_UNESCAPED_UNICODE)]);
    academy_json(['success' => true]);
} catch (Exception $e) {
    academy_json(['success' => false, 'error' => $e->getMessage()], 500);
}
