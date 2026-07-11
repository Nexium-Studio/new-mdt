<?php
/**
 * forum_thread_create.php — création d'un nouveau thread (sujet) dans une catégorie.
 * POST: csrf, cat, title, content, tags (CSV optional)
 */
require_once __DIR__ . '/../../../config/config.php';
academy_require_forum_access();

if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF invalide'], 403);

$catId   = (int)($_POST['cat']   ?? 0);
$title   = trim((string)($_POST['title'] ?? ''));
$content = academy_sanitize_html(trim((string)($_POST['content'] ?? '')));
$tagsCsv = (string)($_POST['tags'] ?? '');
$uid     = (int)$_SESSION['user_id'];

if ($catId <= 0)             academy_json(['success' => false, 'error' => 'Catégorie manquante'], 400);
if ($title === '')           academy_json(['success' => false, 'error' => 'Titre requis'], 400);
if (mb_strlen($title) > 255) academy_json(['success' => false, 'error' => 'Titre trop long (max 255)'], 400);
if (trim(strip_tags($content)) === '') academy_json(['success' => false, 'error' => 'Contenu requis'], 400);

$db = Database::getInstance()->getConnection();

try {
    // Validation catégorie + permissions
    $cs = $db->prepare('SELECT * FROM academy_forum_categories WHERE id = ? AND is_active = 1');
    $cs->execute([$catId]);
    $cat = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$cat) academy_json(['success' => false, 'error' => 'Catégorie introuvable'], 404);

    if (!academy_forum_can_view_category($uid, $cat)) {
        academy_json(['success' => false, 'error' => 'Accès refusé'], 403);
    }
    if (!empty($cat['is_locked']) && !academy_forum_can_moderate($uid, $cat['faction_key'])) {
        academy_json(['success' => false, 'error' => 'Catégorie verrouillée'], 403);
    }

    $db->beginTransaction();

    // Crée le thread
    $db->prepare(
        'INSERT INTO academy_forum_threads (category_id, author_id, title, last_post_at, last_post_user_id) VALUES (?, ?, ?, NOW(), ?)'
    )->execute([$catId, $uid, $title, $uid]);
    $tid = (int)$db->lastInsertId();

    // Crée le premier post (opening)
    $db->prepare(
        'INSERT INTO academy_forum_posts (thread_id, author_id, content, is_opening) VALUES (?, ?, ?, 1)'
    )->execute([$tid, $uid, $content]);
    $openingPostId = (int)$db->lastInsertId();

    // Tags
    if ($tagsCsv !== '') {
        $ids = array_filter(array_map('intval', explode(',', $tagsCsv)));
        if (!empty($ids)) {
            $valid = $db->prepare(
                'SELECT id FROM academy_forum_tags WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                 AND (faction_key IS NULL OR faction_key = ?)'
            );
            $params = $ids; $params[] = $cat['faction_key'];
            $valid->execute($params);
            $validIds = $valid->fetchAll(PDO::FETCH_COLUMN);
            $stmtT = $db->prepare('INSERT IGNORE INTO academy_forum_thread_tags (thread_id, tag_id) VALUES (?, ?)');
            foreach ($validIds as $tagId) $stmtT->execute([$tid, (int)$tagId]);
        }
    }

    $db->commit();

    // Mentions dans le 1er message
    academy_forum_process_mentions($content, $openingPostId, $tid, $uid);

    academy_json(['success' => true, 'thread_id' => $tid]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    academy_json(['success' => false, 'error' => 'Erreur SQL : ' . $e->getMessage()], 500);
}
