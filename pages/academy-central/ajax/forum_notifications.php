<?php
/**
 * forum_notifications.php — récupère les notifications forum d'un user.
 *
 * GET ?action=list   → liste des dernières notifs (avec ?limit=10)
 * POST ?action=read  → marque comme lues (csrf, [thread])
 */
require_once __DIR__ . '/../../../config/config.php';
academy_require_forum_access();

$action = $_GET['action'] ?? 'list';
$uid    = (int)$_SESSION['user_id'];
$db     = Database::getInstance()->getConnection();

if ($action === 'read') {
    if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF invalide'], 403);
    $threadId = isset($_POST['thread']) ? (int)$_POST['thread'] : null;
    academy_forum_mark_read($uid, $threadId);
    academy_json(['success' => true]);
}

if ($action === 'list') {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
    try {
        $stmt = $db->prepare(
            "SELECT n.*, u.username AS from_username, u.avatar AS from_avatar,
                    t.title AS thread_title, c.name AS cat_name, c.color AS cat_color
             FROM academy_forum_notifications n
             LEFT JOIN users u ON u.id = n.from_user_id
             LEFT JOIN academy_forum_threads t ON t.id = n.thread_id
             LEFT JOIN academy_forum_categories c ON c.id = t.category_id
             WHERE n.user_id = ?
             ORDER BY n.created_at DESC
             LIMIT $limit"
        );
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread = academy_forum_unread_count($uid);
        academy_json(['success' => true, 'unread' => $unread, 'items' => $rows]);
    } catch (Exception $e) {
        academy_json(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

academy_json(['success' => false, 'error' => 'Action inconnue'], 400);
