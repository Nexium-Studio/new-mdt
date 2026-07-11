<?php
/**
 * forum_post_reply.php — réponse à un thread (avec citation optionnelle).
 * POST: csrf, thread, content, reply_to (post_id ou 0)
 */
require_once __DIR__ . '/../../../config/config.php';
academy_require_forum_access();

if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF invalide'], 403);

$tid     = (int)($_POST['thread']  ?? 0);
$content = academy_sanitize_html(trim((string)($_POST['content'] ?? '')));
$replyTo = (int)($_POST['reply_to'] ?? 0);
$uid     = (int)$_SESSION['user_id'];

if ($tid <= 0)                            academy_json(['success' => false, 'error' => 'Thread manquant'], 400);
if (trim(strip_tags($content)) === '')    academy_json(['success' => false, 'error' => 'Message vide'], 400);

$db = Database::getInstance()->getConnection();

try {
    // Vérifie le thread + permissions catégorie
    $st = $db->prepare(
        'SELECT t.*, c.faction_key, c.is_locked AS cat_locked, c.required_grade_id, c.is_active
         FROM academy_forum_threads t JOIN academy_forum_categories c ON c.id = t.category_id
         WHERE t.id = ?'
    );
    $st->execute([$tid]);
    $th = $st->fetch(PDO::FETCH_ASSOC);
    if (!$th)                              academy_json(['success' => false, 'error' => 'Thread introuvable'], 404);
    if (empty($th['is_active']))           academy_json(['success' => false, 'error' => 'Catégorie inactive'], 403);
    if (!empty($th['is_locked']))          academy_json(['success' => false, 'error' => 'Sujet verrouillé'], 403);
    if (!empty($th['cat_locked']) && !academy_forum_can_moderate($uid, $th['faction_key'])) {
        academy_json(['success' => false, 'error' => 'Catégorie verrouillée'], 403);
    }
    if (!academy_forum_can_view_category($uid, ['required_grade_id' => $th['required_grade_id']])) {
        academy_json(['success' => false, 'error' => 'Accès refusé'], 403);
    }

    // Vérif reply_to (doit appartenir au même thread)
    if ($replyTo > 0) {
        $rs = $db->prepare('SELECT id FROM academy_forum_posts WHERE id = ? AND thread_id = ? AND deleted_at IS NULL');
        $rs->execute([$replyTo, $tid]);
        if (!$rs->fetchColumn()) $replyTo = 0;
    }

    $db->beginTransaction();

    $db->prepare(
        'INSERT INTO academy_forum_posts (thread_id, author_id, content, reply_to_id) VALUES (?, ?, ?, ?)'
    )->execute([$tid, $uid, $content, $replyTo > 0 ? $replyTo : null]);
    $postId = (int)$db->lastInsertId();

    $db->prepare(
        'UPDATE academy_forum_threads SET reply_count = reply_count + 1, last_post_at = NOW(), last_post_user_id = ? WHERE id = ?'
    )->execute([$uid, $tid]);

    $db->commit();

    // Notifie l'auteur du thread (si différent)
    if ((int)$th['author_id'] !== $uid) {
        academy_forum_notify((int)$th['author_id'], 'reply', $tid, $postId, $uid, mb_substr(strip_tags($content), 0, 200));
    }

    // Notifie l'auteur du post cité
    if ($replyTo > 0) {
        $rs = $db->prepare('SELECT author_id FROM academy_forum_posts WHERE id = ?');
        $rs->execute([$replyTo]);
        $rauth = (int)$rs->fetchColumn();
        if ($rauth > 0 && $rauth !== $uid && $rauth !== (int)$th['author_id']) {
            academy_forum_notify($rauth, 'quote', $tid, $postId, $uid, mb_substr(strip_tags($content), 0, 200));
        }
    }

    // Mentions
    academy_forum_process_mentions($content, $postId, $tid, $uid);

    // Calcule la page où le post se trouve (15 par page)
    $cs = $db->prepare('SELECT COUNT(*) FROM academy_forum_posts WHERE thread_id = ? AND deleted_at IS NULL AND id <= ?');
    $cs->execute([$tid, $postId]);
    $pos = (int)$cs->fetchColumn();
    $pageNo = max(1, (int)ceil($pos / 15));

    academy_json([
        'success'  => true,
        'post_id'  => $postId,
        'page'     => $pageNo,
        'redirect' => '?view=thread&thread=' . $tid . '&page=' . $pageNo . '#post-' . $postId,
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    academy_json(['success' => false, 'error' => 'Erreur SQL : ' . $e->getMessage()], 500);
}
