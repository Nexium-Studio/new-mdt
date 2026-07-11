<?php
/**
 * _forum_thread.php — vue d'un thread (sujet) : posts paginés, répondre, éditer, modérer.
 *
 * Variables : $db, $uid, $csrf, $threadId, $page
 */
if (!isset($db) || $threadId <= 0) { header('Location: ' . SITE_URL . '/pages/academy-central/forums.php'); exit; }
require_once __DIR__ . '/_forum_ui.php';

// ── Charge le thread ──────────────────────────────────────────────────
try {
    $stmt = $db->prepare(
        'SELECT t.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon, c.faction_key AS cat_faction_key,
                c.required_grade_id, c.is_locked AS cat_locked,
                u.username AS author_name, u.avatar AS author_avatar
         FROM academy_forum_threads t
         JOIN academy_forum_categories c ON c.id = t.category_id
         LEFT JOIN users u ON u.id = t.author_id
         WHERE t.id = ?'
    );
    $stmt->execute([$threadId]);
    $th = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $th = null; }

if (!$th) {
    include __DIR__ . '/header.php';
    echo '<div class="empty-state"><i class="fa-solid fa-file-circle-xmark"></i><div class="es-title">Sujet introuvable</div><p>Ce thread n\'existe plus ou a été supprimé.</p><a href="' . SITE_URL . '/pages/academy-central/forums.php" class="btn btn-purple">Retour aux forums</a></div>';
    include __DIR__ . '/footer.php';
    exit;
}
// Permission : doit pouvoir voir la catégorie
if (!academy_forum_can_view_category($uid, ['required_grade_id' => $th['required_grade_id']])) {
    include __DIR__ . '/header.php';
    echo '<div class="empty-state"><i class="fa-solid fa-lock"></i><div class="es-title">Accès restreint</div></div>';
    include __DIR__ . '/footer.php';
    exit;
}

$facKey      = $th['cat_faction_key'];
$facMeta     = $facKey ? academy_faction_meta($facKey) : null;
$canModerate = academy_forum_can_moderate($uid, $facKey);
$canReply    = empty($th['is_locked']) && empty($th['cat_locked']);

// Compte de vue (1×/jour)
academy_forum_register_view($threadId, $uid);
// Marque les notifs liées à ce thread comme lues
academy_forum_mark_read($uid, $threadId);

// ── Pagination des posts ─────────────────────────────────────────────
$perPage = 15;
$offset  = ($page - 1) * $perPage;

try {
    $cnt = $db->prepare('SELECT COUNT(*) FROM academy_forum_posts WHERE thread_id = ? AND deleted_at IS NULL');
    $cnt->execute([$threadId]);
    $totalPosts = (int)$cnt->fetchColumn();

    $stmt = $db->prepare(
        'SELECT p.*, u.username, u.avatar, u.role,
                eu.username AS edited_by_name,
                rp.id AS reply_to_pid, rpu.username AS reply_to_user, rp.content AS reply_to_content,
                (SELECT COUNT(*) FROM academy_forum_likes WHERE post_id = p.id) AS likes,
                (SELECT 1 FROM academy_forum_likes WHERE post_id = p.id AND user_id = ? LIMIT 1) AS liked
         FROM academy_forum_posts p
         LEFT JOIN users u ON u.id = p.author_id
         LEFT JOIN users eu ON eu.id = p.edited_by
         LEFT JOIN academy_forum_posts rp ON rp.id = p.reply_to_id
         LEFT JOIN users rpu ON rpu.id = rp.author_id
         WHERE p.thread_id = ? AND p.deleted_at IS NULL
         ORDER BY p.id ASC
         LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $threadId, PDO::PARAM_INT);
    $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $posts = []; $totalPosts = 0; }

$nbPages = max(1, (int)ceil($totalPosts / $perPage));

// Tags du thread
$threadTags = academy_forum_thread_tags($threadId);
// Tags disponibles (pour modal d'édition)
$availableTags = academy_forum_tags($facKey);

$pageTitle  = $th['title'];
$breadcrumb = [
    ['label' => 'Forums', 'url' => SITE_URL . '/pages/academy-central/forums.php'],
];
if ($facMeta) $breadcrumb[] = ['label' => $facMeta['name'], 'url' => SITE_URL . '/pages/academy-central/forums.php?view=faction&faction=' . $facKey];
$breadcrumb[] = ['label' => $th['cat_name'], 'url' => SITE_URL . '/pages/academy-central/forums.php?view=category&cat=' . (int)$th['category_id']];
$breadcrumb[] = ['label' => mb_strimwidth($th['title'], 0, 36, '…')];

if ($facMeta) {
    [$ar,$ag,$ab] = sscanf($facMeta['color'], "#%02x%02x%02x");
    $facCss = "--fac-c:{$facMeta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";
} else {
    $facCss = '--fac-c:#8b5cf6;--fac-c-15:rgba(139,92,246,.10);--fac-c-25:rgba(139,92,246,.25);';
}

include __DIR__ . '/header.php';
include __DIR__ . '/_forum_styles.php';
?>

<style><?php echo $facCss; ?></style>
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">

<div class="page-header" style="margin-bottom:14px">
  <div class="page-header-left">
    <div class="ph-eyebrow">
      <i class="fa-solid fa-<?php echo $th['cat_icon']; ?>" style="color:<?php echo $th['cat_color']; ?>"></i>
      <a href="?view=category&cat=<?php echo (int)$th['category_id']; ?>" style="color:inherit;text-decoration:none"><?php echo htmlspecialchars($th['cat_name']); ?></a>
    </div>
    <h1 style="font-size:1.45rem"><span class="ph-ic"><i class="fa-solid fa-comments"></i></span> <?php echo htmlspecialchars($th['title']); ?></h1>
  </div>
  <div class="page-header-right">
    <a href="?view=category&cat=<?php echo (int)$th['category_id']; ?>" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Retour</a>
    <?php if ($canModerate): ?>
    <button class="btn btn-secondary" onclick="thrAction('pin', <?php echo $threadId; ?>)"><i class="fa-solid fa-thumbtack"></i> <?php echo !empty($th['is_pinned']) ? 'Désépingler' : 'Épingler'; ?></button>
    <button class="btn btn-secondary" onclick="thrAction('lock', <?php echo $threadId; ?>)"><i class="fa-solid fa-<?php echo !empty($th['is_locked']) ? 'unlock' : 'lock'; ?>"></i> <?php echo !empty($th['is_locked']) ? 'Déverrouiller' : 'Verrouiller'; ?></button>
    <button class="btn btn-secondary" onclick="openTagsModal()"><i class="fa-solid fa-tag"></i> Tags</button>
    <button class="btn btn-danger" onclick="thrDelete(<?php echo $threadId; ?>)"><i class="fa-solid fa-trash"></i></button>
    <?php endif; ?>
  </div>
</div>

<div class="f-thread-head">
  <h2>
    <?php echo htmlspecialchars($th['title']); ?>
    <?php if (!empty($th['is_pinned'])): ?><span class="badge badge-yellow"><i class="fa-solid fa-thumbtack"></i> Épinglé</span><?php endif; ?>
    <?php if (!empty($th['is_locked'])): ?><span class="badge badge-slate"><i class="fa-solid fa-lock"></i> Verrouillé</span><?php endif; ?>
    <?php foreach ($threadTags as $tg) echo ac_forum_tag_chip($tg, false); ?>
  </h2>
  <div class="ft-meta">
    <span class="ft-author"><?php echo ac_forum_avatar($th['author_name'], $th['author_avatar'], 22); ?> <strong><?php echo htmlspecialchars($th['author_name'] ?? '—'); ?></strong></span>
    <span><i class="fa-solid fa-calendar-plus"></i> Créé <?php echo ac_forum_time_ago($th['created_at']); ?></span>
    <span><i class="fa-solid fa-comments"></i> <strong><?php echo (int)$th['reply_count']; ?></strong> réponses</span>
    <span><i class="fa-solid fa-eye"></i> <strong><?php echo (int)$th['view_count']; ?></strong> vues</span>
    <span><i class="fa-solid fa-clock"></i> Dernier message <?php echo ac_forum_time_ago($th['last_post_at']); ?></span>
  </div>
</div>

<!-- Pagination top -->
<?php if ($nbPages > 1):
  $base = "?view=thread&thread={$threadId}";
?>
<div class="fpagi" style="margin-top:0;margin-bottom:12px">
  <?php if ($page > 1): ?><a href="<?php echo $base; ?>&page=<?php echo $page - 1; ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
  <?php
  $start = max(1, $page - 2); $end = min($nbPages, $page + 2);
  if ($start > 1) echo '<a href="' . $base . '&page=1">1</a>' . ($start > 2 ? '<span class="ellipsis">…</span>' : '');
  for ($i = $start; $i <= $end; $i++) echo $i === $page ? '<span class="on">' . $i . '</span>' : '<a href="' . $base . '&page=' . $i . '">' . $i . '</a>';
  if ($end < $nbPages) echo ($end < $nbPages - 1 ? '<span class="ellipsis">…</span>' : '') . '<a href="' . $base . '&page=' . $nbPages . '">' . $nbPages . '</a>';
  ?>
  <?php if ($page < $nbPages): ?><a href="<?php echo $base; ?>&page=<?php echo $page + 1; ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
</div>
<?php endif; ?>

<!-- Posts -->
<?php if (empty($posts)): ?>
<div class="empty-state" style="padding:36px 18px">
  <i class="fa-solid fa-message"></i>
  <div class="es-title">Aucun message</div>
  <p>Soyez le premier à répondre.</p>
</div>
<?php else: foreach ($posts as $i => $p):
  $idx = $offset + $i + 1;
  $canEditPost = ($p['author_id'] == $uid) || $canModerate;
  $rawContent = (string)($p['content'] ?? '');
?>
<div class="f-post <?php echo !empty($p['is_opening']) ? 'opening' : ''; ?>" id="post-<?php echo (int)$p['id']; ?>">
  <div class="fp-side">
    <?php echo ac_forum_avatar($p['username'], $p['avatar'], 48); ?>
    <div class="fp-author"><?php echo htmlspecialchars($p['username'] ?? '—'); ?></div>
    <?php if (!empty($p['role'])): ?><div class="fp-rank"><?php echo htmlspecialchars($p['role']); ?></div><?php endif; ?>
  </div>
  <div class="fp-body">
    <div class="fp-head">
      <?php if (!empty($p['is_opening'])): ?><span class="badge badge-purple"><i class="fa-solid fa-flag"></i> Sujet</span><?php endif; ?>
      <span><i class="fa-solid fa-clock" style="font-size:.65rem"></i> <?php echo ac_forum_time_ago($p['created_at']); ?></span>
      <span class="fp-num">#<?php echo $idx; ?></span>
    </div>

    <?php if (!empty($p['reply_to_pid'])): ?>
    <a href="#post-<?php echo (int)$p['reply_to_pid']; ?>" class="fp-quote-box" style="text-decoration:none;display:block">
      <div class="qa"><i class="fa-solid fa-reply"></i> Réponse à <?php echo htmlspecialchars($p['reply_to_user'] ?? '—'); ?></div>
      <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars(mb_strimwidth(strip_tags($p['reply_to_content'] ?? ''), 0, 140, '…')); ?></div>
    </a>
    <?php endif; ?>

    <div class="fp-content" id="post-content-<?php echo (int)$p['id']; ?>"><?php echo academy_forum_render_content($rawContent); ?></div>

    <div class="fp-foot">
      <button class="fbtn-react like <?php echo $p['liked'] ? 'on' : ''; ?>" data-post="<?php echo (int)$p['id']; ?>" onclick="toggleLike(this)">
        <i class="fa-solid fa-heart"></i> <span class="like-cnt"><?php echo (int)$p['likes']; ?></span>
      </button>
      <?php if ($canReply): ?>
      <button class="fbtn-react" onclick="quoteReply(<?php echo (int)$p['id']; ?>, <?php echo htmlspecialchars(json_encode($p['username'] ?? ''), ENT_QUOTES); ?>)"><i class="fa-solid fa-quote-right"></i> Citer</button>
      <?php endif; ?>
      <?php if ($canEditPost): ?>
      <button class="fbtn-react" onclick="editPost(<?php echo (int)$p['id']; ?>, <?php echo htmlspecialchars(json_encode($rawContent), ENT_QUOTES); ?>)"><i class="fa-solid fa-pen"></i> Éditer</button>
      <button class="fbtn-react" onclick="delPost(<?php echo (int)$p['id']; ?>)" style="color:#fca5a5;border-color:rgba(239,68,68,.25)"><i class="fa-solid fa-trash"></i></button>
      <?php endif; ?>
      <?php if (!empty($p['edited_at'])): ?>
      <span class="fp-edited">Édité <?php echo ac_forum_time_ago($p['edited_at']); ?> par <?php echo htmlspecialchars($p['edited_by_name'] ?? '—'); ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<!-- Pagination bottom -->
<?php if ($nbPages > 1): ?>
<div class="fpagi">
  <?php if ($page > 1): ?><a href="<?php echo $base; ?>&page=<?php echo $page - 1; ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
  <?php
  $start = max(1, $page - 2); $end = min($nbPages, $page + 2);
  if ($start > 1) echo '<a href="' . $base . '&page=1">1</a>' . ($start > 2 ? '<span class="ellipsis">…</span>' : '');
  for ($i = $start; $i <= $end; $i++) echo $i === $page ? '<span class="on">' . $i . '</span>' : '<a href="' . $base . '&page=' . $i . '">' . $i . '</a>';
  if ($end < $nbPages) echo ($end < $nbPages - 1 ? '<span class="ellipsis">…</span>' : '') . '<a href="' . $base . '&page=' . $nbPages . '">' . $nbPages . '</a>';
  ?>
  <?php if ($page < $nbPages): ?><a href="<?php echo $base; ?>&page=<?php echo $page + 1; ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
</div>
<?php endif; ?>

<!-- ──────────────── Reply box ──────────────── -->
<?php if ($canReply): ?>
<div class="f-reply-box" id="replyBox">
  <div class="f-reply-box-head">
    <i class="fa-solid fa-reply"></i> Répondre au sujet
    <span id="replyToHint" style="margin-left:auto;font-size:.78rem;font-weight:500;color:var(--text-secondary);display:none"></span>
  </div>
  <div class="f-reply-box-body">
    <input type="hidden" id="replyToId" value="0">
    <div id="replyEditor"></div>
    <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div style="font-size:.74rem;color:var(--text-secondary)"><i class="fa-solid fa-info-circle"></i> Tip : <code style="color:#c4b5fd">@username</code> pour mentionner un membre.</div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-secondary" id="cancelReplyBtn" onclick="cancelReply()" style="display:none">Annuler citation</button>
        <button class="btn btn-purple" onclick="submitReply()"><i class="fa-solid fa-paper-plane"></i> Publier</button>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-info" style="margin-top:14px"><i class="fa-solid fa-lock"></i> Ce sujet est verrouillé : aucune nouvelle réponse n'est possible.</div>
<?php endif; ?>

<!-- ──────────────── MODAL : Éditer post ──────────────── -->
<div class="modal-overlay" id="editPostModal">
  <div class="modal" style="max-width:760px">
    <div class="modal-header"><h3><i class="fa-solid fa-pen"></i> Éditer le message</h3><button class="modal-close" onclick="document.getElementById('editPostModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
    <input type="hidden" id="editPostId">
    <div class="form-group"><label class="form-label">Contenu</label><div id="editEditor"></div></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="document.getElementById('editPostModal').classList.remove('open')">Annuler</button>
      <button class="btn btn-purple" onclick="submitEdit()"><i class="fa-solid fa-check"></i> Enregistrer</button>
    </div>
  </div>
</div>

<!-- ──────────────── MODAL : Tags du thread ──────────────── -->
<?php if ($canModerate && !empty($availableTags)): ?>
<div class="modal-overlay" id="tagsModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><h3><i class="fa-solid fa-tag"></i> Tags du sujet</h3><button class="modal-close" onclick="document.getElementById('tagsModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="form-group">
      <label class="form-label">Cliquez pour ajouter/retirer</label>
      <div id="tmList" style="display:flex;flex-wrap:wrap;gap:6px">
        <?php $currentTagIds = array_column($threadTags, 'id'); foreach ($availableTags as $tg):
          [$tr,$tg2,$tb] = sscanf($tg['color'], "#%02x%02x%02x");
          $isOn = in_array((int)$tg['id'], array_map('intval', $currentTagIds), true);
        ?>
        <span class="ftag ftag-pickable <?php echo $isOn ? 'on' : ''; ?>" data-tagid="<?php echo (int)$tg['id']; ?>" style="background:rgba(<?php echo "$tr,$tg2,$tb"; ?>,.10);color:<?php echo $tg['color']; ?>;border-color:rgba(<?php echo "$tr,$tg2,$tb"; ?>,.30)"><?php echo htmlspecialchars($tg['label']); ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="document.getElementById('tagsModal').classList.remove('open')">Fermer</button>
      <button class="btn btn-purple" onclick="saveTags()"><i class="fa-solid fa-check"></i> Enregistrer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
const __CSRF   = '<?php echo htmlspecialchars($csrf); ?>';
const __THID   = <?php echo (int)$threadId; ?>;
const __SITE   = '<?php echo SITE_URL; ?>';
let __replyQuill = null;
let __editQuill  = null;
let __tagSet = new Set();

const QUILL_OPTS = { theme:'snow', placeholder:'Rédigez votre réponse…', modules:{ toolbar:[
  [{'header':[1,2,3,false]}],
  ['bold','italic','underline','strike'],
  [{'color':[]},{'background':[]}],
  [{'list':'ordered'},{'list':'bullet'}],
  ['blockquote','code-block','link'],
  ['clean']
]}};

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('replyEditor')) {
    __replyQuill = new Quill('#replyEditor', QUILL_OPTS);
  }
});

function quoteReply(pid, author){
  if (!__replyQuill) return;
  document.getElementById('replyToId').value = pid;
  const hint = document.getElementById('replyToHint');
  hint.innerHTML = '<i class="fa-solid fa-reply"></i> Réponse à <strong style="color:#fff">' + author + '</strong>';
  hint.style.display = 'block';
  document.getElementById('cancelReplyBtn').style.display = '';
  document.getElementById('replyBox').scrollIntoView({behavior:'smooth', block:'center'});
  __replyQuill.focus();
}
function cancelReply(){
  document.getElementById('replyToId').value = 0;
  document.getElementById('replyToHint').style.display = 'none';
  document.getElementById('cancelReplyBtn').style.display = 'none';
}

async function fetchJSON(url, fd){
  try { const r = await fetch(url, { method:'POST', body:fd }); return await r.json(); }
  catch(e) { return { success:false, error:'Erreur réseau' }; }
}

async function submitReply(){
  if (!__replyQuill) return;
  const content = __replyQuill.root.innerHTML;
  if (!content || content === '<p><br></p>') { acToast('Le message est vide', true); return; }
  const fd = new FormData();
  fd.append('csrf', __CSRF);
  fd.append('thread', __THID);
  fd.append('content', content);
  fd.append('reply_to', document.getElementById('replyToId').value);
  const j = await fetchJSON(__SITE + '/pages/academy-central/ajax/forum_post_reply.php', fd);
  if (j && j.success) { acToast('Réponse publiée'); setTimeout(() => location.href = j.redirect || ('?view=thread&thread=' + __THID + '&page=' + (j.page || 1) + '#post-' + j.post_id), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}

function editPost(pid, content){
  document.getElementById('editPostId').value = pid;
  document.getElementById('editPostModal').classList.add('open');
  if (!__editQuill) __editQuill = new Quill('#editEditor', { ...QUILL_OPTS, placeholder:'Modifiez le message…' });
  __editQuill.root.innerHTML = content;
}
async function submitEdit(){
  const pid = document.getElementById('editPostId').value;
  const content = __editQuill ? __editQuill.root.innerHTML : '';
  if (!content || content === '<p><br></p>') { acToast('Contenu vide', true); return; }
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('id', pid); fd.append('content', content);
  const j = await fetchJSON(__SITE + '/pages/academy-central/ajax/forum_post_crud.php?action=edit', fd);
  if (j && j.success) { acToast('Message modifié'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}

async function delPost(pid){
  if (!confirm('Supprimer ce message ?')) return;
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', pid);
  const j = await fetchJSON(__SITE + '/pages/academy-central/ajax/forum_post_crud.php?action=delete', fd);
  if (j && j.success) { acToast('Message supprimé'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}

async function toggleLike(btn){
  const pid = btn.dataset.post;
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('post_id', pid);
  const j = await fetchJSON(__SITE + '/pages/academy-central/ajax/forum_post_like.php', fd);
  if (j && j.success) { btn.classList.toggle('on', j.liked); btn.querySelector('.like-cnt').textContent = j.count; }
}

async function thrAction(act, tid){
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', tid);
  const j = await fetchJSON(__SITE + '/pages/academy-central/ajax/forum_thread_crud.php?action=' + act, fd);
  if (j && j.success) location.reload();
  else acToast((j && j.error) || 'Erreur', true);
}
async function thrDelete(tid){
  if (!confirm('Supprimer définitivement ce sujet et tous ses messages ?')) return;
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', tid);
  const j = await fetchJSON(__SITE + '/pages/academy-central/ajax/forum_thread_crud.php?action=delete', fd);
  if (j && j.success) { acToast('Sujet supprimé'); setTimeout(() => location.href = '?view=category&cat=<?php echo (int)$th['category_id']; ?>', 500); }
  else acToast((j && j.error) || 'Erreur', true);
}

<?php if ($canModerate && !empty($availableTags)): ?>
function openTagsModal(){
  __tagSet.clear();
  document.querySelectorAll('#tmList .ftag-pickable').forEach(el => {
    if (el.classList.contains('on')) __tagSet.add(parseInt(el.dataset.tagid));
    el.onclick = () => {
      const id = parseInt(el.dataset.tagid);
      if (__tagSet.has(id)) { __tagSet.delete(id); el.classList.remove('on'); }
      else { __tagSet.add(id); el.classList.add('on'); }
    };
  });
  document.getElementById('tagsModal').classList.add('open');
}
async function saveTags(){
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('thread', __THID);
  fd.append('tags', [...__tagSet].join(','));
  const j = await fetchJSON(__SITE + '/pages/academy-central/ajax/forum_thread_tags.php', fd);
  if (j && j.success) { acToast('Tags mis à jour'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
<?php endif; ?>
</script>

<?php include __DIR__ . '/footer.php'; ?>
