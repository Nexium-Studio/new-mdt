<?php
/**
 * _forum_index.php — page d'accueil du forum.
 * Affiche : KPIs globaux, tuiles "Forum par faction", catégories globales (académie),
 * derniers threads actifs, derniers posts.
 *
 * Variables fournies par forums.php : $db, $uid, $csrf
 */
if (!isset($db)) { http_response_code(500); exit; }
require_once __DIR__ . '/_forum_ui.php';

$pageTitle  = 'Forums Académie';
$breadcrumb = [['label' => 'Forums']];

// Factions accessibles (où le user a au moins un grade académie OU est de la faction)
$factionsAccessible = academy_user_accessible_factions($uid);
// On enrichit avec les factions où le user appartient (faction RP) → pour qu'un membre LSPD voit son forum LSPD
$userFaction = $_SESSION['user_faction'] ?? '';
if ($userFaction !== '' && !in_array($userFaction, $factionsAccessible, true) && academy_faction_exists($userFaction)) {
    $factionsAccessible[] = $userFaction;
}
if (academy_is_staff_bypass()) {
    // Staff voit toutes les factions
    $factionsAccessible = array_keys(academy_factions());
}

// Stats par faction (nb threads, nb posts, dernière activité)
$factionStats = [];
try {
    if (!empty($factionsAccessible)) {
        $ph = implode(',', array_fill(0, count($factionsAccessible), '?'));
        $stmt = $db->prepare(
            "SELECT c.faction_key,
                    COUNT(DISTINCT t.id) AS nb_threads,
                    SUM(t.reply_count + 1) AS nb_posts,
                    MAX(t.last_post_at) AS last_activity
             FROM academy_forum_categories c
             LEFT JOIN academy_forum_threads t ON t.category_id = c.id
             WHERE c.faction_key IN ($ph) AND c.is_active = 1
             GROUP BY c.faction_key"
        );
        $stmt->execute($factionsAccessible);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $factionStats[$r['faction_key']] = $r;
    }
} catch (Exception $e) {}

// KPIs globaux (sur les factions accessibles)
$gThreads = 0; $gPosts = 0; $gLast = null;
foreach ($factionStats as $s) {
    $gThreads += (int)$s['nb_threads'];
    $gPosts   += (int)$s['nb_posts'];
    if ($s['last_activity'] && (!$gLast || $s['last_activity'] > $gLast)) $gLast = $s['last_activity'];
}

// Catégories Académie (globales, faction_key NULL)
$globalCategories = academy_forum_categories($uid, null);

// Derniers threads actifs (sur factions accessibles + global)
$recentThreads = [];
try {
    $factionFilter = $factionsAccessible;
    $ph = implode(',', array_fill(0, count($factionFilter), '?'));
    $where = empty($factionFilter) ? 'c.faction_key IS NULL' : "(c.faction_key IS NULL OR c.faction_key IN ($ph))";
    $stmt = $db->prepare(
        "SELECT t.id, t.title, t.is_pinned, t.is_locked, t.reply_count, t.view_count, t.last_post_at, t.created_at,
                c.id AS cat_id, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon, c.faction_key,
                u.username, u.avatar
         FROM academy_forum_threads t
         JOIN academy_forum_categories c ON c.id = t.category_id
         LEFT JOIN users u ON u.id = t.author_id
         WHERE $where
         ORDER BY t.last_post_at DESC
         LIMIT 8"
    );
    $stmt->execute($factionFilter);
    $recentThreads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Mes notifications non lues
$unread = academy_forum_unread_count($uid);

include __DIR__ . '/header.php';
include __DIR__ . '/_forum_styles.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-comments"></i> Forum Académie</div>
    <h1><span class="ph-ic"><i class="fa-solid fa-comments"></i></span> Espace de discussion</h1>
    <p>Communiquez, partagez et organisez vos opérations entre factions. Chaque service public dispose de son propre espace.</p>
  </div>
  <div class="page-header-right">
    <?php if ($unread > 0): ?>
    <a href="?view=notifications" class="btn btn-secondary"><i class="fa-solid fa-bell"></i> Notifications <span class="badge badge-red" style="margin-left:4px"><?php echo $unread; ?></span></a>
    <?php endif; ?>
    <?php if (academy_is_staff_bypass()): ?>
    <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/forum.php" class="btn btn-secondary"><i class="fa-solid fa-sliders"></i> Configurer</a>
    <?php endif; ?>
  </div>
</div>

<!-- KPIs globaux -->
<div class="stats-grid">
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-building-columns"></i></div><div class="stat-info">
    <div class="stat-label">Factions</div>
    <div class="stat-value"><?php echo count($factionsAccessible); ?></div>
    <div class="stat-meta">accessibles</div>
  </div></div>
  <div class="stat-card stat-cyan"><div class="stat-ic"><i class="fa-solid fa-comments"></i></div><div class="stat-info">
    <div class="stat-label">Sujets</div>
    <div class="stat-value"><?php echo $gThreads; ?></div>
    <div class="stat-meta">tous forums</div>
  </div></div>
  <div class="stat-card stat-orange"><div class="stat-ic"><i class="fa-solid fa-message"></i></div><div class="stat-info">
    <div class="stat-label">Messages</div>
    <div class="stat-value"><?php echo $gPosts; ?></div>
    <div class="stat-meta">échangés</div>
  </div></div>
  <div class="stat-card stat-green"><div class="stat-ic"><i class="fa-solid fa-bolt"></i></div><div class="stat-info">
    <div class="stat-label">Activité</div>
    <div class="stat-value" style="font-size:.95rem;font-family:'DM Sans',sans-serif"><?php echo $gLast ? ac_forum_time_ago($gLast) : '—'; ?></div>
    <div class="stat-meta">dernier message</div>
  </div></div>
</div>

<!-- Forum par faction -->
<?php if (!empty($factionsAccessible)): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-building-columns"></i> Forums par faction</div>
    <span class="badge badge-purple"><?php echo count($factionsAccessible); ?></span>
  </div>
  <div class="card-body">
    <div class="f-faction-grid">
    <?php foreach ($factionsAccessible as $fk):
      $m  = academy_faction_meta($fk);
      $st = $factionStats[$fk] ?? ['nb_threads' => 0, 'nb_posts' => 0, 'last_activity' => null];
      [$r,$g,$b] = sscanf($m['color'], "#%02x%02x%02x");
      $style = "--ftc:{$m['color']};--ftc15:rgba($r,$g,$b,.10)";
    ?>
    <a href="?view=faction&faction=<?php echo $fk; ?>" class="f-faction-tile" style="<?php echo $style; ?>">
      <div class="ftt-head">
        <div class="ftt-ic"><i class="fa-solid fa-<?php echo $m['icon']; ?>"></i></div>
        <div class="ftt-name"><?php echo htmlspecialchars($m['name']); ?></div>
        <i class="fa-solid fa-arrow-right ftt-arrow"></i>
      </div>
      <div class="ftt-stats">
        <span><i class="fa-solid fa-comments" style="opacity:.6"></i> <strong><?php echo (int)$st['nb_threads']; ?></strong> sujet<?php echo (int)$st['nb_threads'] > 1 ? 's' : ''; ?></span>
        <span><i class="fa-solid fa-message" style="opacity:.6"></i> <strong><?php echo (int)$st['nb_posts']; ?></strong> message<?php echo (int)$st['nb_posts'] > 1 ? 's' : ''; ?></span>
      </div>
      <div class="ftt-foot">
        <i class="fa-solid fa-clock" style="opacity:.6"></i>
        <?php echo $st['last_activity'] ? 'Activité ' . ac_forum_time_ago($st['last_activity']) : 'Aucune activité'; ?>
      </div>
    </a>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:18px"><div class="card-body">
  <div class="empty-state">
    <i class="fa-solid fa-lock"></i>
    <div class="es-title">Aucun forum accessible</div>
    <p>Vous n'êtes membre d'aucune faction publique et n'avez pas de grade académie.</p>
  </div>
</div></div>
<?php endif; ?>

<!-- Catégories globales académie -->
<?php if (!empty($globalCategories)): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-graduation-cap"></i> Académie inter-factions</div>
    <span class="badge badge-purple"><?php echo count($globalCategories); ?></span>
  </div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($globalCategories as $c): ?>
    <a href="?view=category&cat=<?php echo (int)$c['id']; ?>" class="f-cat-card">
      <div class="fcc-ic" style="background:<?php echo $c['color']; ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($c['icon']); ?>"></i></div>
      <div class="fcc-info">
        <div class="fcc-name">
          <?php echo htmlspecialchars($c['name']); ?>
          <?php if (!empty($c['is_locked'])): ?><span class="badge badge-slate"><i class="fa-solid fa-lock"></i></span><?php endif; ?>
        </div>
        <div class="fcc-desc"><?php echo htmlspecialchars($c['description'] ?? ''); ?></div>
      </div>
      <div class="fcc-stats">
        <div class="num"><?php echo (int)$c['nb_threads']; ?></div>
        <div>sujets</div>
        <div style="font-size:.66rem;margin-top:5px"><?php echo $c['last_activity'] ? ac_forum_time_ago($c['last_activity']) : '—'; ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Derniers sujets actifs -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-fire"></i> Sujets récemment actifs</div>
    <span class="badge badge-purple"><?php echo count($recentThreads); ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($recentThreads)): ?>
    <div class="empty-state" style="padding:36px 18px">
      <i class="fa-solid fa-comments"></i>
      <div class="es-title">Aucun sujet pour le moment</div>
      <p>Soyez le premier à lancer une discussion dans un forum de faction.</p>
    </div>
    <?php else:
      $tagsByThread = academy_forum_threads_tags(array_column($recentThreads, 'id'));
    ?>
    <?php foreach ($recentThreads as $t):
      $facMeta = $t['faction_key'] ? academy_faction_meta($t['faction_key']) : null;
      $cls = ($t['is_pinned'] ? 'pinned ' : '') . ($t['is_locked'] ? 'locked' : '');
      $tags = $tagsByThread[(int)$t['id']] ?? [];
    ?>
    <a class="f-thread-row <?php echo trim($cls); ?>" href="?view=thread&thread=<?php echo (int)$t['id']; ?>">
      <div class="ftr-ic"><i class="fa-solid fa-<?php echo !empty($t['is_pinned']) ? 'thumbtack' : (!empty($t['is_locked']) ? 'lock' : 'message'); ?>"></i></div>
      <div class="ftr-mid">
        <div class="ftr-title">
          <?php echo htmlspecialchars($t['title']); ?>
          <?php foreach ($tags as $tg) echo ac_forum_tag_chip($tg); ?>
        </div>
        <div class="ftr-meta">
          <?php if ($facMeta): ?>
          <span class="badge" style="background:<?php echo $facMeta['color']; ?>22;color:<?php echo $facMeta['color']; ?>;font-size:.65rem"><i class="fa-solid fa-<?php echo $facMeta['icon']; ?>"></i> <?php echo htmlspecialchars($facMeta['name']); ?></span>
          <?php else: ?>
          <span class="badge badge-purple" style="font-size:.65rem"><i class="fa-solid fa-graduation-cap"></i> Académie</span>
          <?php endif; ?>
          <span class="sep">·</span>
          <span style="color:<?php echo $t['cat_color']; ?>"><i class="fa-solid fa-<?php echo $t['cat_icon']; ?>"></i> <?php echo htmlspecialchars($t['cat_name']); ?></span>
          <span class="sep">·</span>
          <span>par <strong style="color:#fff"><?php echo htmlspecialchars($t['username'] ?? '—'); ?></strong></span>
        </div>
      </div>
      <div class="ftr-stats">
        <span class="num"><?php echo (int)$t['reply_count']; ?></span>
        <span>réponses</span>
      </div>
      <div class="ftr-last">
        <?php echo ac_forum_avatar($t['username'], $t['avatar'], 28); ?>
        <div class="last-info">
          <div class="last-when"><?php echo ac_forum_time_ago($t['last_post_at']); ?></div>
        </div>
      </div>
    </a>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
