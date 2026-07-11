<?php
/**
 * forums.php — routeur principal du système de forum Académie v2.
 * Dispatch vers les sous-vues : index / faction / category / thread.
 *
 * Paramètres GET :
 *  view    = index | faction | category | thread (défaut : index)
 *  faction = faction_key (LSPD, LSSD, …)        — pour view=faction|category
 *  cat     = category_id                        — pour view=category|thread (création)
 *  thread  = thread_id                          — pour view=thread
 *  page    = numéro de page                     — pour view=category|thread
 */
require_once __DIR__ . '/../../config/config.php';
academy_require_forum_access();

$db   = Database::getInstance()->getConnection();
$uid  = (int)$_SESSION['user_id'];
$csrf = academy_csrf_token();

$view     = $_GET['view']    ?? 'index';
$facKey   = isset($_GET['faction']) ? (string)$_GET['faction'] : '';
$catId    = (int)($_GET['cat']    ?? 0);
$threadId = (int)($_GET['thread'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));

if ($facKey !== '' && !academy_faction_exists($facKey)) { $facKey = ''; }

// Validation : si une vue spécifique est demandée mais sans le paramètre requis,
// on retombe sur l'index.
if (in_array($view, ['faction', 'category', 'thread'], true)) {
    if ($view === 'faction' && $facKey === '') $view = 'index';
    if ($view === 'category' && $catId <= 0)   $view = 'index';
    if ($view === 'thread'   && $threadId <= 0) $view = 'index';
}

// Routage vers la sous-vue
switch ($view) {
    case 'thread':   require __DIR__ . '/includes/_forum_thread.php';   break;
    case 'category': require __DIR__ . '/includes/_forum_category.php'; break;
    case 'faction':  require __DIR__ . '/includes/_forum_faction.php';  break;
    default:         require __DIR__ . '/includes/_forum_index.php';    break;
}
