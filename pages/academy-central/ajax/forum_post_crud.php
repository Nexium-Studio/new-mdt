<?php
/**
 * forum_post_crud.php — édition / suppression d'un post.
 * GET ?action=edit | delete
 * POST: csrf, id, [content]
 */
require_once __DIR__ . '/../../../config/config.php';
academy_require_forum_access();

if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF invalide'], 403);

$action = $_GET['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);
$uid    = (int)$_SESSION['user_id'];
$db     = Database::getInstance()->getConnection();

if ($id <= 0) academy_json(['success' => false, 'error' => 'Post invalide'], 400);

try {
    $st = $db->prepare(
        'SELECT p.*, t.id AS tid, c.faction_key
         FROM academy_forum_posts p
         JOIN academy_forum_threads t ON t.id = p.thread_id
         JOIN academy_forum_categories c ON c.id = t.category_id
         WHERE p.id = ?'
    );
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) academy_json(['success' => false, 'error' => 'Post introuvable'], 404);

    $isAuthor      = ((int)$p['author_id'] === $uid);
    $canModerate   = academy_forum_can_moderate($uid, $p['faction_key']);
    if (!$isAuthor && !$canModerate) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);

    if ($action === 'edit') {
        $content = academy_sanitize_html(trim((string)($_POST['content'] ?? '')));
        if (trim(strip_tags($content)) === '') academy_json(['success' => false, 'error' => 'Contenu vide'], 400);
        $db->prepare('UPDATE academy_forum_posts SET content = ?, edited_at = NOW(), edited_by = ? WHERE id = ?')
           ->execute([$content, $uid, $id]);
        academy_json(['success' => true]);
    }

    if ($action === 'delete') {
        // Soft delete pour préserver l'historique. Si c'est le post d'ouverture, on supprime le thread entier.
        if (!empty($p['is_opening'])) {
            $db->prepare('DELETE FROM academy_forum_threads WHERE id = ?')->execute([(int)$p['tid']]);
        } else {
            $db->beginTransaction();
            $db->prepare('UPDATE academy_forum_posts SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
            $db->prepare('UPDATE academy_forum_threads SET reply_count = GREATEST(reply_count - 1, 0) WHERE id = ?')->execute([(int)$p['tid']]);
            $db->commit();
        }
        academy_json(['success' => true]);
    }

    academy_json(['success' => false, 'error' => 'Action inconnue'], 400);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    academy_json(['success' => false, 'error' => 'Erreur SQL : ' . $e->getMessage()], 500);
}
