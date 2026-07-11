<?php
/**
 * forum_thread_tags.php — assigne/remplace les tags d'un thread.
 * POST: csrf, thread, tags (csv ids)
 */
require_once __DIR__ . '/../../../config/config.php';
academy_require_forum_access();

if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF invalide'], 403);

$tid     = (int)($_POST['thread'] ?? 0);
$tagsCsv = (string)($_POST['tags'] ?? '');
$uid     = (int)$_SESSION['user_id'];
$db      = Database::getInstance()->getConnection();

if ($tid <= 0) academy_json(['success' => false, 'error' => 'Thread invalide'], 400);

try {
    $st = $db->prepare(
        'SELECT t.id, t.author_id, c.faction_key FROM academy_forum_threads t
         JOIN academy_forum_categories c ON c.id = t.category_id WHERE t.id = ?'
    );
    $st->execute([$tid]);
    $th = $st->fetch(PDO::FETCH_ASSOC);
    if (!$th) academy_json(['success' => false, 'error' => 'Thread introuvable'], 404);

    $canModerate = academy_forum_can_moderate($uid, $th['faction_key']);
    $isAuthor    = ((int)$th['author_id'] === $uid);
    if (!$canModerate && !$isAuthor) academy_json(['success' => false, 'error' => 'Permission refusée'], 403);

    $ids = array_unique(array_filter(array_map('intval', explode(',', $tagsCsv))));

    $db->beginTransaction();
    $db->prepare('DELETE FROM academy_forum_thread_tags WHERE thread_id = ?')->execute([$tid]);

    if (!empty($ids)) {
        // Filtre les tags valides pour la faction (ou globaux)
        $valid = $db->prepare(
            'SELECT id FROM academy_forum_tags
             WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             AND (faction_key IS NULL OR faction_key = ?)'
        );
        $params = $ids; $params[] = $th['faction_key'];
        $valid->execute($params);
        $validIds = $valid->fetchAll(PDO::FETCH_COLUMN);
        $ins = $db->prepare('INSERT IGNORE INTO academy_forum_thread_tags (thread_id, tag_id) VALUES (?, ?)');
        foreach ($validIds as $tagId) $ins->execute([$tid, (int)$tagId]);
    }

    $db->commit();
    academy_json(['success' => true]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    academy_json(['success' => false, 'error' => 'Erreur SQL : ' . $e->getMessage()], 500);
}
