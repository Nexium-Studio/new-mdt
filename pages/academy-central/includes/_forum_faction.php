<?php
/**
 * _forum_faction.php — vue d'un forum de faction.
 * Affiche le bandeau identitaire + KPIs + cards de catégories + derniers sujets.
 *
 * Variables : $db, $uid, $csrf, $facKey
 */
if (!isset($db) || $facKey === '' || !academy_faction_exists($facKey)) {
    header('Location: ' . SITE_URL . '/pages/academy-central/forums.php'); exit;
}
require_once __DIR__ . '/_forum_ui.php';

$meta = academy_faction_meta($facKey);
$slug = $meta['slug'];

// Sécurité d'accès : faut être de la faction OU avoir un grade académie sur cette faction OU staff
$canView = academy_is_staff_bypass()
        || ($_SESSION['user_faction'] ?? '') === $facKey
        || in_array($facKey, academy_user_accessible_factions($uid), true);
if (!$canView) {
    header('Location: ' . SITE_URL . '/pages/academy-central/forums.php'); exit;
}

$pageTitle  = 'Forum ' . $meta['name'];
$breadcrumb = [
    ['label' => 'Forums', 'url' => SITE_URL . '/pages/academy-central/forums.php'],
    ['label' => $meta['name']],
];

// Catégories de la faction
$categories = academy_forum_categories($uid, $facKey);

// KPIs
$nbCats    = count($categories);
$nbThreads = array_sum(array_map(fn($c) => (int)($c['nb_threads'] ?? 0), $categories));
$nbPosts   = array_sum(array_map(fn($c) => (int)($c['nb_posts']   ?? 0), $categories));

// Derniers threads de la faction
$latestThreads = [];
try {
    $stmt = $db->prepare(
        'SELECT t.id, t.title, t.is_pinned, t.is_locked, t.reply_count, t.view_count, t.last_post_at,
                c.id AS cat_id, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
                u.username, u.avatar
         FROM academy_forum_threads t
         JOIN academy_forum_categories c ON c.id = t.category_id
         LEFT JOIN users u ON u.id = t.author_id
         WHERE c.faction_key = ?
         ORDER BY t.is_pinned DESC, t.last_post_at DESC
         LIMIT 8'
    );
    $stmt->execute([$facKey]);
    $latestThreads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Top contributeurs (sur la faction)
$topContributors = [];
try {
    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.avatar, COUNT(p.id) AS nb
         FROM academy_forum_posts p
         JOIN academy_forum_threads t ON t.id = p.thread_id
         JOIN academy_forum_categories c ON c.id = t.category_id
         LEFT JOIN users u ON u.id = p.author_id
         WHERE c.faction_key = ? AND p.deleted_at IS NULL
         GROUP BY u.id
         ORDER BY nb DESC
         LIMIT 5'
    );
    $stmt->execute([$facKey]);
    $topContributors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

[$ar,$ag,$ab] = sscanf($meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";

include __DIR__ . '/header.php';
include __DIR__ . '/_forum_styles.php';
?>

<style><?php echo $facCss; ?></style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-comments"></i> Forum</div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-<?php echo $meta['icon']; ?>"></i></span> <?php echo htmlspecialchars($meta['name']); ?></h1>
    <p>Espace de discussion réservé aux membres et formateurs de la faction.</p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/forums.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Tous les forums</a>
  </div>
</div>

<div class="fac-strip">
  <div class="fs-ic"><i class="fa-solid fa-<?php echo $meta['icon']; ?>"></i></div>
  <div class="fs-info">
    <div class="fs-name"><?php echo htmlspecialchars($meta['name']); ?></div>
    <div class="fs-sub"><?php echo htmlspecialchars($meta['subtitle'] ?? ''); ?></div>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-folder-open"></i></div><div class="stat-info">
    <div class="stat-label">Sections</div><div class="stat-value"><?php echo $nbCats; ?></div>
    <div class="stat-meta">organisation</div>
  </div></div>
  <div class="stat-card stat-cyan"><div class="stat-ic"><i class="fa-solid fa-comments"></i></div><div class="stat-info">
    <div class="stat-label">Sujets</div><div class="stat-value"><?php echo $nbThreads; ?></div>
    <div class="stat-meta">discussions</div>
  </div></div>
  <div class="stat-card stat-orange"><div class="stat-ic"><i class="fa-solid fa-message"></i></div><div class="stat-info">
    <div class="stat-label">Messages</div><div class="stat-value"><?php echo $nbPosts; ?></div>
    <div class="stat-meta">échangés</div>
  </div></div>
  <div class="stat-card stat-green"><div class="stat-ic"><i class="fa-solid fa-users"></i></div><div class="stat-info">
    <div class="stat-label">Contributeurs</div><div class="stat-value"><?php echo count($topContributors); ?></div>
    <div class="stat-meta">actifs récents</div>
  </div></div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-folder-tree"></i> Sections du forum</div>
    <span class="badge badge-purple"><?php echo $nbCats; ?></span>
  </div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
    <?php if (empty($categories)): ?>
    <div class="empty-state" style="padding:36px 18px">
      <i class="fa-solid fa-folder-open"></i>
      <div class="es-title">Aucune section disponible</div>
      <p>Le staff doit créer au moins une section depuis le panel de configuration.</p>
      <?php if (academy_is_staff_bypass()): ?>
      <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/forum.php" class="btn btn-purple"><i class="fa-solid fa-sliders"></i> Configurer</a>
      <?php endif; ?>
    </div>
    <?php else: foreach ($categories as $c): ?>
    <a href="?view=category&cat=<?php echo (int)$c['id']; ?>" class="f-cat-card">
      <div class="fcc-ic" style="background:<?php echo $c['color']; ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($c['icon']); ?>"></i></div>
      <div class="fcc-info">
        <div class="fcc-name">
          <?php echo htmlspecialchars($c['name']); ?>
          <?php if (!empty($c['is_locked'])): ?><span class="badge badge-slate"><i class="fa-solid fa-lock"></i> Verrouillée</span><?php endif; ?>
          <?php if (!empty($c['required_grade_id'])): ?><span class="badge badge-purple"><i class="fa-solid fa-shield-halved"></i> Restreinte</span><?php endif; ?>
        </div>
        <div class="fcc-desc"><?php echo htmlspecialchars($c['description'] ?? ''); ?></div>
      </div>
      <div class="fcc-stats">
        <div class="num"><?php echo (int)$c['nb_threads']; ?></div>
        <div>sujet<?php echo (int)$c['nb_threads'] > 1 ? 's' : ''; ?></div>
        <div style="font-size:.66rem;margin-top:5px"><?php echo $c['last_activity'] ? ac_forum_time_ago($c['last_activity']) : 'Vide'; ?></div>
      </div>
    </a>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="grid-2" style="gap:18px;align-items:flex-start">
  <!-- Derniers sujets -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-fire"></i> Sujets récents</div>
      <span class="badge badge-purple"><?php echo count($latestThreads); ?></span>
    </div>
    <div class="card-body">
      <?php if (empty($latestThreads)): ?>
      <div class="empty-state" style="padding:32px 16px">
        <i class="fa-solid fa-comments"></i>
        <div class="es-title">Aucun sujet pour le moment</div>
        <p>Lancez la première discussion depuis une section.</p>
      </div>
      <?php else:
        $tagsByThread = academy_forum_threads_tags(array_column($latestThreads, 'id'));
        foreach ($latestThreads as $t):
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
            <span style="color:<?php echo $t['cat_color']; ?>"><i class="fa-solid fa-<?php echo $t['cat_icon']; ?>"></i> <?php echo htmlspecialchars($t['cat_name']); ?></span>
            <span class="sep">·</span>
            <span>par <strong style="color:#fff"><?php echo htmlspecialchars($t['username'] ?? '—'); ?></strong></span>
          </div>
        </div>
        <div class="ftr-stats">
          <span class="num"><?php echo (int)$t['reply_count']; ?></span>
          <span>réponses</span>
        </div>
      </a>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Top contributeurs -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-trophy"></i> Top contributeurs</div>
    </div>
    <div class="card-body">
      <?php if (empty($topContributors)): ?>
      <div class="empty-state" style="padding:32px 16px">
        <i class="fa-solid fa-users"></i>
        <p>Aucun contributeur pour le moment.</p>
      </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:9px">
      <?php foreach ($topContributors as $i => $c):
        $rank = $i + 1;
        $rankBg = $rank === 1 ? '#f59e0b' : ($rank === 2 ? '#94a3b8' : ($rank === 3 ? '#b45309' : 'rgba(139,92,246,.18)'));
        $rankCl = $rank <= 3 ? '#fff' : '#c4b5fd';
      ?>
      <div style="display:flex;align-items:center;gap:11px;padding:10px 12px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px">
        <div style="width:28px;height:28px;border-radius:8px;background:<?php echo $rankBg; ?>;color:<?php echo $rankCl; ?>;display:grid;place-items:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.78rem;flex-shrink:0">#<?php echo $rank; ?></div>
        <?php echo ac_forum_avatar($c['username'], $c['avatar'], 30); ?>
        <div style="flex:1;min-width:0">
          <div class="row-strong"><?php echo htmlspecialchars($c['username'] ?? '—'); ?></div>
          <div class="row-muted"><?php echo (int)$c['nb']; ?> message<?php echo (int)$c['nb'] > 1 ? 's' : ''; ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
