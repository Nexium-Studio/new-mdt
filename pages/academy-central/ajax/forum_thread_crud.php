<?php
/**
 * forum_thread_crud.php — pin / lock / delete d'un thread.
 * Réservé au modérateur (faction) ou staff.
 */
require_once __DIR__ . '/../../../config/config.php';
academy_require_forum_access();

if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF invalide'], 403);

$action = $_GET['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);
$uid    = (int)$_SESSION['user_id'];
$db     = Database::getInstance()->getConnection();

if ($id <= 0) academy_json(['success' => false, 'error' => 'Thread invalide'], 400);

try {
    $st = $db->prepare(
        'SELECT t.*, c.faction_key FROM academy_forum_threads t
         JOIN academy_forum_categories c ON c.id = t.category_id
         WHERE t.id = ?'
    );
    $st->execute([$id]);
    $th = $st->fetch(PDO::FETCH_ASSOC);
    if (!$th) academy_json(['success' => false, 'error' => 'Thread introuvable'], 404);

    $canModerate = academy_forum_can_moderate($uid, $th['faction_key']);

    if ($action === 'pin' || $action === 'lock') {
        if (!$canModerate) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
        $col = $action === 'lock' ? 'is_locked' : 'is_pinned';
        $db->prepare("UPDATE academy_forum_threads SET $col = NOT $col WHERE id = ?")->execute([$id]);
        academy_json(['success' => true]);
    }

    if ($action === 'delete') {
        $isAuthor = ((int)$th['author_id'] === $uid);
        if (!$isAuthor && !$canModerate) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);
        $db->prepare('DELETE FROM academy_forum_threads WHERE id = ?')->execute([$id]);
        academy_json(['success' => true]);
    }

    academy_json(['success' => false, 'error' => 'Action inconnue'], 400);
} catch (Exception $e) {
    academy_json(['success' => false, 'error' => 'Erreur SQL : ' . $e->getMessage()], 500);
}
