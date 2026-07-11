<?php
/**
 * _forum_category.php — vue d'une catégorie : liste des threads + filtres + tags + nouveau sujet.
 *
 * Variables : $db, $uid, $csrf, $catId, $page
 */
if (!isset($db) || $catId <= 0) { header('Location: ' . SITE_URL . '/pages/academy-central/forums.php'); exit; }
require_once __DIR__ . '/_forum_ui.php';

// ── Charge la catégorie ───────────────────────────────────────────────
try {
    $stmt = $db->prepare('SELECT * FROM academy_forum_categories WHERE id = ? AND is_active = 1');
    $stmt->execute([$catId]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cat = null; }

if (!$cat) {
    include __DIR__ . '/header.php';
    echo '<div class="empty-state"><i class="fa-solid fa-folder-xmark"></i><div class="es-title">Catégorie introuvable</div><p>Cette section n\'existe plus ou est désactivée.</p><a href="' . SITE_URL . '/pages/academy-central/forums.php" class="btn btn-purple">Retour aux forums</a></div>';
    include __DIR__ . '/footer.php';
    exit;
}

// Permission de voir la catégorie
if (!academy_forum_can_view_category($uid, $cat)) {
    include __DIR__ . '/header.php';
    echo '<div class="empty-state"><i class="fa-solid fa-lock"></i><div class="es-title">Accès restreint</div><p>Cette section est réservée à un grade académie spécifique.</p></div>';
    include __DIR__ . '/footer.php';
    exit;
}

$facKey  = $cat['faction_key'];
$facMeta = $facKey ? academy_faction_meta($facKey) : null;

$canModerate    = academy_forum_can_moderate($uid, $facKey);
$canCreate      = empty($cat['is_locked']) || $canModerate;

// ── Tags disponibles + filtre actif ───────────────────────────────────
$availableTags = academy_forum_tags($facKey);
$tagFilter     = isset($_GET['tag']) ? (int)$_GET['tag'] : 0;
$searchQ       = trim((string)($_GET['q'] ?? ''));
$sortBy        = $_GET['sort'] ?? 'recent'; // recent | popular | views | new
$sortMap = [
    'recent'  => 't.is_pinned DESC, t.last_post_at DESC',
    'popular' => 't.is_pinned DESC, t.reply_count DESC, t.last_post_at DESC',
    'views'   => 't.is_pinned DESC, t.view_count DESC',
    'new'     => 't.is_pinned DESC, t.created_at DESC',
];
$orderBy = $sortMap[$sortBy] ?? $sortMap['recent'];

// ── Pagination ────────────────────────────────────────────────────────
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// ── Compte total + récupère les threads ───────────────────────────────
$where  = 't.category_id = :cat';
$params = [':cat' => $catId];
$joins  = '';
if ($searchQ !== '') {
    $where .= ' AND t.title LIKE :q';
    $params[':q'] = '%' . $searchQ . '%';
}
if ($tagFilter > 0) {
    $joins .= ' JOIN academy_forum_thread_tags tt ON tt.thread_id = t.id AND tt.tag_id = :tag';
    $params[':tag'] = $tagFilter;
}
try {
    $cnt = $db->prepare("SELECT COUNT(DISTINCT t.id) FROM academy_forum_threads t $joins WHERE $where");
    foreach ($params as $k => $v) $cnt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $cnt->execute();
    $total = (int)$cnt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT t.*, u.username, u.avatar,
                lu.username AS last_username, lu.avatar AS last_avatar
         FROM academy_forum_threads t $joins
         LEFT JOIN users u  ON u.id  = t.author_id
         LEFT JOIN users lu ON lu.id = t.last_post_user_id
         WHERE $where
         ORDER BY $orderBy
         LIMIT $perPage OFFSET $offset"
    );
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->execute();
    $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $threads = []; $total = 0; }

$nbPages = max(1, (int)ceil($total / $perPage));
$tagsByThread = academy_forum_threads_tags(array_column($threads, 'id'));

$pageTitle  = $cat['name'] . ' — Forum';
$breadcrumb = [
    ['label' => 'Forums', 'url' => SITE_URL . '/pages/academy-central/forums.php'],
];
if ($facMeta) {
    $breadcrumb[] = ['label' => $facMeta['name'], 'url' => SITE_URL . '/pages/academy-central/forums.php?view=faction&faction=' . $facKey];
} else {
    $breadcrumb[] = ['label' => 'Académie'];
}
$breadcrumb[] = ['label' => $cat['name']];

[$cr,$cg,$cb] = sscanf($cat['color'], "#%02x%02x%02x");
$catCss = "--catc:{$cat['color']};--catc-15:rgba($cr,$cg,$cb,.10);--catc-25:rgba($cr,$cg,$cb,.25);";
if ($facMeta) {
    [$ar,$ag,$ab] = sscanf($facMeta['color'], "#%02x%02x%02x");
    $catCss .= "--fac-c:{$facMeta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";
}

include __DIR__ . '/header.php';
include __DIR__ . '/_forum_styles.php';
?>

<style><?php echo $catCss; ?></style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow">
      <i class="fa-solid fa-<?php echo $facMeta ? $facMeta['icon'] : 'graduation-cap'; ?>"></i>
      <?php echo htmlspecialchars($facMeta['name'] ?? 'Académie'); ?>
    </div>
    <h1>
      <span class="ph-ic" style="background:<?php echo $cat['color']; ?>22;color:<?php echo $cat['color']; ?>"><i class="fa-solid fa-<?php echo $cat['icon']; ?>"></i></span>
      <?php echo htmlspecialchars($cat['name']); ?>
      <?php if (!empty($cat['is_locked'])): ?><span class="badge badge-slate" style="font-size:.6rem"><i class="fa-solid fa-lock"></i> Verrouillée</span><?php endif; ?>
    </h1>
    <p><?php echo htmlspecialchars($cat['description'] ?? ''); ?></p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo $facKey ? '?view=faction&faction=' . $facKey : SITE_URL . '/pages/academy-central/forums.php'; ?>" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Retour</a>
    <?php if ($canCreate): ?>
    <button class="btn btn-purple" onclick="openNewThread()"><i class="fa-solid fa-plus"></i> Nouveau sujet</button>
    <?php endif; ?>
  </div>
</div>

<!-- Filtres / recherche -->
<form method="GET" class="toolbar">
  <input type="hidden" name="view" value="category">
  <input type="hidden" name="cat" value="<?php echo (int)$catId; ?>">
  <div class="grow search-input">
    <i class="fa-solid fa-search"></i>
    <input type="text" name="q" value="<?php echo htmlspecialchars($searchQ); ?>" class="form-control" placeholder="Rechercher un sujet…">
  </div>
  <select name="sort" class="form-control" style="max-width:170px;height:36px" onchange="this.form.submit()">
    <option value="recent"  <?php echo $sortBy === 'recent'  ? 'selected' : ''; ?>>Récents</option>
    <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>Plus populaires</option>
    <option value="views"   <?php echo $sortBy === 'views'   ? 'selected' : ''; ?>>Plus vus</option>
    <option value="new"     <?php echo $sortBy === 'new'     ? 'selected' : ''; ?>>Plus récents (création)</option>
  </select>
  <button class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Filtrer</button>
  <?php if ($searchQ || $tagFilter || $sortBy !== 'recent'): ?>
  <a href="?view=category&cat=<?php echo $catId; ?>" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Réinit.</a>
  <?php endif; ?>
</form>

<!-- Tags filtres -->
<?php if (!empty($availableTags)): ?>
<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:14px">
  <span style="font-size:.74rem;color:var(--text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-right:6px">Filtrer par tag :</span>
  <a href="?view=category&cat=<?php echo $catId; ?>&q=<?php echo urlencode($searchQ); ?>" class="ftag" style="<?php echo $tagFilter === 0 ? 'background:rgba(139,92,246,.18);color:#c4b5fd;border-color:rgba(139,92,246,.4)' : 'background:rgba(255,255,255,.04);color:var(--text-secondary);border-color:var(--border)'; ?>;text-decoration:none">Tous</a>
  <?php foreach ($availableTags as $tg):
    $isOn = $tagFilter === (int)$tg['id'];
    [$tr,$tg2,$tb] = sscanf($tg['color'], "#%02x%02x%02x");
    $bg = $isOn ? "rgba($tr,$tg2,$tb,.25)" : "rgba($tr,$tg2,$tb,.10)";
    $br = "rgba($tr,$tg2,$tb,.40)";
  ?>
  <a href="?view=category&cat=<?php echo $catId; ?>&tag=<?php echo (int)$tg['id']; ?>&q=<?php echo urlencode($searchQ); ?>" class="ftag" style="background:<?php echo $bg; ?>;color:<?php echo $tg['color']; ?>;border-color:<?php echo $br; ?>;text-decoration:none"><?php echo htmlspecialchars($tg['label']); ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Liste des threads -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-list-ul"></i> Sujets</div>
    <span class="badge badge-purple"><?php echo $total; ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($threads)): ?>
    <div class="empty-state" style="padding:42px 20px">
      <i class="fa-solid fa-comments"></i>
      <div class="es-title"><?php echo $searchQ || $tagFilter ? 'Aucun sujet trouvé' : 'Aucun sujet pour le moment'; ?></div>
      <p><?php echo $searchQ || $tagFilter ? 'Essayez d\'autres filtres ou réinitialisez la recherche.' : 'Soyez le premier à lancer une discussion !'; ?></p>
      <?php if ($canCreate && !$searchQ && !$tagFilter): ?>
      <button class="btn btn-purple" onclick="openNewThread()"><i class="fa-solid fa-plus"></i> Créer le premier sujet</button>
      <?php endif; ?>
    </div>
    <?php else: foreach ($threads as $t):
      $cls = ($t['is_pinned'] ? 'pinned ' : '') . ($t['is_locked'] ? 'locked' : '');
      $tags = $tagsByThread[(int)$t['id']] ?? [];
    ?>
    <div class="f-thread-row <?php echo trim($cls); ?>" style="position:relative">
      <a href="?view=thread&thread=<?php echo (int)$t['id']; ?>" class="ftr-ic" style="text-decoration:none;color:inherit"><i class="fa-solid fa-<?php echo !empty($t['is_pinned']) ? 'thumbtack' : (!empty($t['is_locked']) ? 'lock' : 'message'); ?>"></i></a>
      <div class="ftr-mid">
        <div class="ftr-title">
          <a href="?view=thread&thread=<?php echo (int)$t['id']; ?>" style="text-decoration:none;color:inherit"><?php echo htmlspecialchars($t['title']); ?></a>
          <?php foreach ($tags as $tg) echo ac_forum_tag_chip($tg); ?>
          <?php if (!empty($t['is_pinned'])): ?><span class="badge badge-yellow" style="font-size:.62rem"><i class="fa-solid fa-thumbtack"></i> Épinglé</span><?php endif; ?>
          <?php if (!empty($t['is_locked'])): ?><span class="badge badge-slate" style="font-size:.62rem"><i class="fa-solid fa-lock"></i> Fermé</span><?php endif; ?>
        </div>
        <div class="ftr-meta">
          <span>par <strong style="color:#fff"><?php echo htmlspecialchars($t['username'] ?? '—'); ?></strong></span>
          <span class="sep">·</span>
          <span>créé <?php echo ac_forum_time_ago($t['created_at']); ?></span>
          <span class="sep">·</span>
          <span><i class="fa-solid fa-eye" style="opacity:.6"></i> <?php echo (int)$t['view_count']; ?> vue<?php echo (int)$t['view_count'] > 1 ? 's' : ''; ?></span>
        </div>
      </div>
      <a href="?view=thread&thread=<?php echo (int)$t['id']; ?>" class="ftr-stats" style="text-decoration:none;color:inherit">
        <span class="num"><?php echo (int)$t['reply_count']; ?></span>
        <span>réponses</span>
      </a>
      <a href="?view=thread&thread=<?php echo (int)$t['id']; ?>" class="ftr-last" style="text-decoration:none;color:inherit">
        <?php echo ac_forum_avatar($t['last_username'] ?? $t['username'], $t['last_avatar'] ?? $t['avatar'], 30); ?>
        <div class="last-info">
          <div class="last-author"><?php echo htmlspecialchars($t['last_username'] ?? $t['username'] ?? '—'); ?></div>
          <div class="last-when"><?php echo ac_forum_time_ago($t['last_post_at']); ?></div>
        </div>
      </a>
    </div>
    <?php endforeach; endif; ?>

    <!-- Pagination -->
    <?php if ($nbPages > 1):
      $base = "?view=category&cat={$catId}&sort={$sortBy}" . ($searchQ ? '&q=' . urlencode($searchQ) : '') . ($tagFilter ? '&tag=' . $tagFilter : '');
    ?>
    <div class="fpagi">
      <?php if ($page > 1): ?>
        <a href="<?php echo $base; ?>&page=<?php echo $page - 1; ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <?php endif; ?>
      <?php
      $start = max(1, $page - 2);
      $end   = min($nbPages, $page + 2);
      if ($start > 1)        echo '<a href="' . $base . '&page=1">1</a>' . ($start > 2 ? '<span class="ellipsis">…</span>' : '');
      for ($i = $start; $i <= $end; $i++) echo $i === $page ? '<span class="on">' . $i . '</span>' : '<a href="' . $base . '&page=' . $i . '">' . $i . '</a>';
      if ($end < $nbPages)   echo ($end < $nbPages - 1 ? '<span class="ellipsis">…</span>' : '') . '<a href="' . $base . '&page=' . $nbPages . '">' . $nbPages . '</a>';
      ?>
      <?php if ($page < $nbPages): ?>
        <a href="<?php echo $base; ?>&page=<?php echo $page + 1; ?>"><i class="fa-solid fa-chevron-right"></i></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ─────────────── MODAL : Nouveau sujet ─────────────── -->
<?php if ($canCreate): ?>
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<div class="modal-overlay" id="newThreadModal">
  <div class="modal" style="max-width:760px">
    <div class="modal-header">
      <h3><i class="fa-solid fa-plus"></i> Nouveau sujet — <?php echo htmlspecialchars($cat['name']); ?></h3>
      <button class="modal-close" onclick="document.getElementById('newThreadModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="form-group">
      <label class="form-label">Titre du sujet *</label>
      <input type="text" id="ntTitle" class="form-control" maxlength="255" placeholder="Soyez clair et explicite">
    </div>
    <?php if (!empty($availableTags)): ?>
    <div class="form-group">
      <label class="form-label">Tags (optionnel — cliquer pour ajouter/retirer)</label>
      <div id="ntTagsList" style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($availableTags as $tg):
          [$tr,$tg2,$tb] = sscanf($tg['color'], "#%02x%02x%02x");
        ?>
        <span class="ftag ftag-pickable" data-tagid="<?php echo (int)$tg['id']; ?>" style="background:rgba(<?php echo "$tr,$tg2,$tb"; ?>,.10);color:<?php echo $tg['color']; ?>;border-color:rgba(<?php echo "$tr,$tg2,$tb"; ?>,.30)"><?php echo htmlspecialchars($tg['label']); ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="form-group">
      <label class="form-label">Contenu *</label>
      <div id="ntEditor"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="document.getElementById('newThreadModal').classList.remove('open')">Annuler</button>
      <button class="btn btn-purple" onclick="submitNewThread()"><i class="fa-solid fa-paper-plane"></i> Publier</button>
    </div>
  </div>
</div>

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
const __CSRF = '<?php echo htmlspecialchars($csrf); ?>';
const __CAT  = <?php echo (int)$catId; ?>;
let __ntQuill = null;
let __ntTags  = new Set();

function openNewThread(){
  document.getElementById('newThreadModal').classList.add('open');
  if (!__ntQuill) {
    __ntQuill = new Quill('#ntEditor', {
      theme: 'snow',
      placeholder: 'Rédigez votre message…',
      modules: { toolbar: [
        [{ 'header': [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ 'color': [] }, { 'background': [] }],
        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
        ['blockquote', 'code-block', 'link'],
        ['clean']
      ]}
    });
  }
  // Bind tags
  document.querySelectorAll('#ntTagsList .ftag-pickable').forEach(el => {
    el.classList.remove('on'); __ntTags.clear();
    el.onclick = () => {
      const id = parseInt(el.dataset.tagid);
      if (__ntTags.has(id)) { __ntTags.delete(id); el.classList.remove('on'); }
      else { __ntTags.add(id); el.classList.add('on'); }
    };
  });
}
async function submitNewThread(){
  const title = document.getElementById('ntTitle').value.trim();
  const content = __ntQuill ? __ntQuill.root.innerHTML : '';
  if (!title) { acToast('Le titre est requis', true); return; }
  if (!content || content === '<p><br></p>') { acToast('Le contenu est requis', true); return; }
  const fd = new FormData();
  fd.append('csrf', __CSRF);
  fd.append('cat', __CAT);
  fd.append('title', title);
  fd.append('content', content);
  fd.append('tags', [...__ntTags].join(','));
  try {
    const r = await fetch('<?php echo SITE_URL; ?>/pages/academy-central/ajax/forum_thread_create.php', { method:'POST', body:fd });
    const j = await r.json();
    if (j && j.success && j.thread_id) {
      acToast('Sujet créé');
      setTimeout(() => location.href = '?view=thread&thread=' + j.thread_id, 400);
    } else acToast((j && j.error) || 'Erreur', true);
  } catch(e) { acToast('Erreur réseau', true); }
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
