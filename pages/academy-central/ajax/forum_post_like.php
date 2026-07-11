<?php
require_once __DIR__ . '/../../../config/config.php';
academy_require_forum_access();
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);
$pid = (int)($_POST['post_id'] ?? 0);
if ($pid <= 0) academy_json(['success' => false, 'error' => 'Post invalide'], 400);
$uid = (int)$_SESSION['user_id'];
$db = Database::getInstance()->getConnection();

try {
    $s = $db->prepare('SELECT id FROM academy_forum_likes WHERE post_id = ? AND user_id = ? LIMIT 1');
    $s->execute([$pid, $uid]);
    $exists = $s->fetchColumn();
    if ($exists) {
        $db->prepare('DELETE FROM academy_forum_likes WHERE id = ?')->execute([$exists]);
        $liked = false;
    } else {
        $db->prepare('INSERT IGNORE INTO academy_forum_likes (post_id, user_id) VALUES (?, ?)')->execute([$pid, $uid]);
        $liked = true;
    }
    $count = (int)$db->query('SELECT COUNT(*) FROM academy_forum_likes WHERE post_id = ' . $pid)->fetchColumn();
    academy_json(['success' => true, 'liked' => $liked, 'count' => $count]);
} catch (Exception $e) {
    academy_json(['success' => false, 'error' => $e->getMessage()], 500);
}
