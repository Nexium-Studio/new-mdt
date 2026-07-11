<?php
/**
 * _documents_engine.php — catégories + documents avec éditeur Quill.
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) { http_response_code(404); exit('Faction invalide'); }
$__fk = $__ACADEMY_FACTION;
$__meta = academy_faction_meta($__fk);
$__slug = $__meta['slug'];
academy_require_access('documents_' . $__slug);
$canEdit = academy_user_can((int)$_SESSION['user_id'], 'documents_' . $__slug, 'edit') || academy_is_staff_bypass();

$pageTitle  = 'Documents — ' . $__meta['name'];
$breadcrumb = [['label' => $__meta['name']], ['label' => 'Documents']];
$db = Database::getInstance()->getConnection();
$csrf = academy_csrf_token();

try {
    $cs = $db->prepare('SELECT * FROM academy_document_categories WHERE faction_key = ? ORDER BY sort_order, id');
    $cs->execute([$__fk]); $cats = $cs->fetchAll(PDO::FETCH_ASSOC);
    $ds = $db->prepare('SELECT d.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon, u.username AS author FROM academy_documents d LEFT JOIN academy_document_categories c ON c.id = d.category_id LEFT JOIN users u ON u.id = d.created_by WHERE d.faction_key = ? ORDER BY d.updated_at DESC');
    $ds->execute([$__fk]); $docs = $ds->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cats = []; $docs = []; }

$activeDocId = (int)($_GET['doc'] ?? 0);
$activeDoc   = null;
foreach ($docs as $d) if ((int)$d['id'] === $activeDocId) { $activeDoc = $d; break; }
if (!$activeDoc && !empty($docs)) $activeDoc = $docs[0];

$searchQ = trim((string)($_GET['q'] ?? ''));

[$ar,$ag,$ab] = sscanf($__meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$__meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";

include __DIR__ . '/header.php';
?>

<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<style>
<?php echo $facCss; ?>
.doc-layout{display:grid;grid-template-columns:300px 1fr;gap:16px;align-items:flex-start}
.doc-sidebar{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden;max-height:calc(100vh - 220px);overflow-y:auto;position:sticky;top:calc(var(--topbar-h) + 16px)}
.doc-side-head{padding:13px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.02)}
.doc-side-search{padding:10px 12px;border-bottom:1px solid var(--border)}
.doc-side-search input{height:32px;font-size:.8rem;padding-left:30px}
.doc-side-search .search-input i{font-size:12px;left:10px}
.doc-cat-head{padding:14px 16px 4px;font-size:.66rem;text-transform:uppercase;font-weight:700;letter-spacing:.1em;display:flex;align-items:center;gap:6px}
.doc-item{padding:9px 16px;font-size:.83rem;cursor:pointer;border-left:3px solid transparent;transition:all .15s;display:flex;align-items:center;gap:8px;color:var(--text-secondary)}
.doc-item:hover{background:var(--bg-hover);color:#fff}
.doc-item.active{background:var(--fac-c-15);color:#fff;border-left-color:var(--fac-c);font-weight:600}
.doc-item i{font-size:.7rem;flex-shrink:0;opacity:.65}
.doc-item .ttl{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.doc-main{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;min-height:560px;overflow:hidden}
.doc-main-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.doc-main-title{display:flex;align-items:center;gap:11px}
.doc-main-title .ic{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;flex-shrink:0;font-size:.95rem;background:var(--fac-c-15);color:var(--fac-c)}
.doc-main-title .ttl{font-family:'Syne',sans-serif;font-weight:700;font-size:1.05rem;color:#fff}
.doc-main-title .meta{font-size:.74rem;color:var(--text-secondary);margin-top:2px}
.doc-content{padding:26px 28px;line-height:1.75;color:#cbd5e1;font-size:.92rem}
.doc-content h1,.doc-content h2,.doc-content h3{color:#fff;margin:20px 0 12px;font-family:'Syne',sans-serif}
.doc-content h1{font-size:1.5rem}
.doc-content h2{font-size:1.2rem}
.doc-content h3{font-size:1.05rem}
.doc-content p{margin-bottom:12px}
.doc-content ul,.doc-content ol{margin:8px 0 14px 28px}
.doc-content li{margin-bottom:5px}
.doc-content blockquote{border-left:3px solid var(--purple);padding:8px 14px;color:var(--text-secondary);margin:14px 0;background:rgba(139,92,246,.05)}
.doc-content code{background:rgba(255,255,255,.06);padding:2px 6px;border-radius:4px;font-family:'DM Mono',monospace;font-size:.85em}
.doc-content pre{background:rgba(0,0,0,.3);padding:14px;border-radius:9px;border:1px solid var(--border);overflow-x:auto;margin:14px 0}
.doc-content a{color:var(--purple);text-decoration:underline}

#docEditor{min-height:340px;background:var(--bg-elevated)}
.ql-toolbar.ql-snow,.ql-container.ql-snow{border-color:var(--border)!important;color:var(--text-primary)!important}
.ql-toolbar.ql-snow{background:rgba(255,255,255,.02);border-radius:9px 9px 0 0}
.ql-container.ql-snow{border-radius:0 0 9px 9px;background:var(--bg-elevated)}
.ql-editor{color:var(--text-primary)!important;min-height:300px;font-family:'DM Sans',sans-serif}
.ql-snow .ql-stroke{stroke:var(--text-secondary)}
.ql-snow .ql-fill{fill:var(--text-secondary)}
.ql-snow .ql-picker{color:var(--text-secondary)}
.ql-snow .ql-picker-options{background:var(--bg-elevated);border-color:var(--border)}
@media(max-width:900px){.doc-layout{grid-template-columns:1fr}.doc-sidebar{position:static;max-height:none}}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-folder-open"></i></span> Documents</h1>
    <p>Bibliothèque documentaire de la faction (procédures, règlements, fiches…).</p>
  </div>
  <?php if ($canEdit): ?>
  <div class="page-header-right">
    <button class="btn btn-secondary" onclick="openDCatModal()"><i class="fa-solid fa-folder-plus"></i> Catégorie</button>
    <button class="btn btn-purple" onclick="openDocModal()"><i class="fa-solid fa-plus"></i> Document</button>
  </div>
  <?php endif; ?>
</div>

<div class="doc-layout">
  <aside class="doc-sidebar">
    <div class="doc-side-head"><strong style="font-family:'Syne',sans-serif;font-size:.92rem">Bibliothèque</strong><span class="badge badge-purple"><?php echo count($docs); ?></span></div>
    <div class="doc-side-search">
      <div class="search-input">
        <i class="fa-solid fa-search"></i>
        <input type="text" id="docSearch" class="form-control" placeholder="Rechercher un document…">
      </div>
    </div>
    <?php if (empty($docs)): ?>
    <div class="empty-state" style="padding:30px 20px">
      <i class="fa-solid fa-folder-open"></i>
      <div class="es-title" style="font-size:.9rem">Aucun document</div>
      <p style="font-size:.78rem">La bibliothèque est vide.</p>
    </div>
    <?php else:
      $docsByCat = [];
      foreach ($docs as $d) $docsByCat[(int)$d['category_id']][] = $d;
      $renderedCats = false;
      foreach ($cats as $c):
        $docsInCat = $docsByCat[(int)$c['id']] ?? [];
        if (empty($docsInCat)) continue;
        $renderedCats = true;
    ?>
      <div class="doc-cat-head" style="color:<?php echo $c['color']; ?>"><i class="fa-solid fa-<?php echo $c['icon']; ?>"></i> <?php echo htmlspecialchars($c['name']); ?></div>
      <?php foreach ($docsInCat as $d):
        $haystack = strtolower($d['title'] . ' ' . ($d['cat_name'] ?? ''));
      ?>
      <a href="?doc=<?php echo (int)$d['id']; ?>" class="doc-item <?php echo $activeDoc && (int)$activeDoc['id'] === (int)$d['id'] ? 'active' : ''; ?>" data-search="<?php echo htmlspecialchars($haystack); ?>">
        <i class="fa-solid fa-file-lines"></i><span class="ttl"><?php echo htmlspecialchars($d['title']); ?></span>
      </a>
      <?php endforeach; ?>
    <?php endforeach;
      $orphans = $docsByCat[0] ?? [];
      if (!empty($orphans)):
    ?>
      <div class="doc-cat-head" style="color:var(--text-secondary)"><i class="fa-solid fa-circle-question"></i> Sans catégorie</div>
      <?php foreach ($orphans as $d):
        $haystack = strtolower($d['title']);
      ?>
      <a href="?doc=<?php echo (int)$d['id']; ?>" class="doc-item <?php echo $activeDoc && (int)$activeDoc['id'] === (int)$d['id'] ? 'active' : ''; ?>" data-search="<?php echo htmlspecialchars($haystack); ?>">
        <i class="fa-solid fa-file-lines"></i><span class="ttl"><?php echo htmlspecialchars($d['title']); ?></span>
      </a>
      <?php endforeach; ?>
    <?php endif; endif; ?>

    <?php if ($canEdit && !empty($cats)): ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border);margin-top:8px">
      <div style="font-size:.66rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-secondary);font-weight:700;margin-bottom:8px">Catégories</div>
      <?php foreach ($cats as $c): ?>
      <div style="display:flex;align-items:center;gap:6px;padding:5px 0;font-size:.78rem;color:var(--text-secondary)">
        <i class="fa-solid fa-<?php echo $c['icon']; ?>" style="color:<?php echo $c['color']; ?>;font-size:.7rem"></i>
        <span style="flex:1"><?php echo htmlspecialchars($c['name']); ?></span>
        <button class="btn btn-secondary btn-sm btn-icon" onclick='openDCatModal(<?php echo json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>)' style="padding:3px"><i class="fa-solid fa-pen" style="font-size:.6rem"></i></button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </aside>

  <main class="doc-main">
    <?php if ($activeDoc): ?>
    <div class="doc-main-head">
      <div class="doc-main-title">
        <div class="ic" <?php if ($activeDoc['cat_color']): ?>style="background:<?php echo $activeDoc['cat_color']; ?>22;color:<?php echo $activeDoc['cat_color']; ?>"<?php endif; ?>><i class="fa-solid fa-<?php echo $activeDoc['cat_icon'] ?? 'file-lines'; ?>"></i></div>
        <div>
          <div class="ttl"><?php echo htmlspecialchars($activeDoc['title']); ?></div>
          <div class="meta">
            <?php if ($activeDoc['cat_name']): ?><span style="color:<?php echo $activeDoc['cat_color']; ?>"><?php echo htmlspecialchars($activeDoc['cat_name']); ?></span> · <?php endif; ?>
            Par <strong style="color:#cbd5e1"><?php echo htmlspecialchars($activeDoc['author'] ?? '—'); ?></strong> · Modifié le <?php echo date('d/m/Y H:i', strtotime($activeDoc['updated_at'])); ?>
          </div>
        </div>
      </div>
      <?php if ($canEdit): ?>
      <div style="display:flex;gap:6px">
        <button class="btn btn-secondary btn-sm" onclick='openDocModal(<?php echo json_encode(['id' => (int)$activeDoc['id'], 'title' => $activeDoc['title'], 'category_id' => $activeDoc['category_id'], 'content' => $activeDoc['content']], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>)'><i class="fa-solid fa-pen"></i> Éditer</button>
        <button class="btn btn-danger btn-sm" onclick="delDoc(<?php echo (int)$activeDoc['id']; ?>)"><i class="fa-solid fa-trash"></i></button>
      </div>
      <?php endif; ?>
    </div>
    <div class="doc-content"><?php echo $activeDoc['content']; ?></div>
    <?php else: ?>
    <div class="empty-state" style="padding:80px 20px">
      <i class="fa-solid fa-file-lines"></i>
      <div class="es-title">Aucun document sélectionné</div>
      <p>Choisissez un document dans la barre latérale ou créez-en un nouveau.</p>
      <?php if ($canEdit): ?>
      <button class="btn btn-purple" onclick="openDocModal()"><i class="fa-solid fa-plus"></i> Créer un document</button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </main>
</div>

<!-- ─────────────── MODALS ─────────────── -->
<div class="modal-overlay" id="dCatModal"><div class="modal">
  <div class="modal-header"><h3 id="dCatTitle"><i class="fa-solid fa-folder-plus"></i> Catégorie</h3><button class="modal-close" onclick="document.getElementById('dCatModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
  <input type="hidden" id="dCatId">
  <div class="form-group"><label class="form-label">Nom *</label><input type="text" id="dCatName" class="form-control"></div>
  <div class="grid-2">
    <div class="form-group"><label class="form-label">Couleur</label><input type="color" id="dCatColor" class="form-control" value="#64748b" style="height:40px;padding:3px"></div>
    <div class="form-group"><label class="form-label">Icône</label><input type="text" id="dCatIcon" class="form-control" value="folder"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" onclick="document.getElementById('dCatModal').classList.remove('open')">Annuler</button><button class="btn btn-purple" onclick="saveDCat()"><i class="fa-solid fa-check"></i> Enregistrer</button></div>
</div></div>

<div class="modal-overlay" id="docModal"><div class="modal" style="max-width:920px;width:92vw">
  <div class="modal-header"><h3 id="docTitle"><i class="fa-solid fa-plus"></i> Nouveau document</h3><button class="modal-close" onclick="document.getElementById('docModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
  <input type="hidden" id="docId">
  <div class="grid-2">
    <div class="form-group"><label class="form-label">Titre *</label><input type="text" id="docTitleIn" class="form-control" maxlength="255"></div>
    <div class="form-group"><label class="form-label">Catégorie</label>
      <select id="docCat" class="form-control">
        <option value="">— Aucune —</option>
        <?php foreach ($cats as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-group"><label class="form-label">Contenu</label>
    <div id="docEditor"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" onclick="document.getElementById('docModal').classList.remove('open')">Annuler</button><button class="btn btn-purple" onclick="saveDoc()"><i class="fa-solid fa-check"></i> Enregistrer</button></div>
</div></div>

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
const __CSRF = '<?php echo htmlspecialchars($csrf); ?>';
const __FACTION = '<?php echo $__fk; ?>';
let __quill = null;

function ensureQuill(){
  if (__quill) return;
  __quill = new Quill('#docEditor', {
    theme: 'snow',
    placeholder: 'Rédigez votre document…',
    modules: {
      toolbar: [
        [{ 'header': [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ 'color': [] }, { 'background': [] }],
        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
        ['blockquote', 'code-block', 'link'],
        ['clean']
      ]
    }
  });
}
function openDCatModal(c){
  document.getElementById('dCatTitle').innerHTML = c ? '<i class="fa-solid fa-pen"></i> Modifier la catégorie' : '<i class="fa-solid fa-folder-plus"></i> Nouvelle catégorie';
  document.getElementById('dCatId').value = c ? c.id : '';
  document.getElementById('dCatName').value = c ? c.name : '';
  document.getElementById('dCatColor').value = c ? c.color : '#64748b';
  document.getElementById('dCatIcon').value = c ? c.icon : 'folder';
  document.getElementById('dCatModal').classList.add('open');
}
async function fetchJSON(url, fd){
  try { const r = await fetch(url, { method: 'POST', body: fd }); return await r.json(); }
  catch(e) { return { success:false, error:'Erreur réseau' }; }
}
async function saveDCat(){
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('faction', __FACTION);
  fd.append('id', document.getElementById('dCatId').value);
  fd.append('name', document.getElementById('dCatName').value);
  fd.append('color', document.getElementById('dCatColor').value);
  fd.append('icon', document.getElementById('dCatIcon').value);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/document_category_crud.php?action=save', fd);
  if (j && j.success) { acToast('Catégorie enregistrée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
function openDocModal(d){
  ensureQuill();
  document.getElementById('docTitle').innerHTML = d ? '<i class="fa-solid fa-pen"></i> Modifier le document' : '<i class="fa-solid fa-plus"></i> Nouveau document';
  document.getElementById('docId').value = d ? d.id : '';
  document.getElementById('docTitleIn').value = d ? d.title : '';
  document.getElementById('docCat').value = d ? (d.category_id || '') : '';
  __quill.root.innerHTML = d ? (d.content || '') : '';
  document.getElementById('docModal').classList.add('open');
}
async function saveDoc(){
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('faction', __FACTION);
  fd.append('id', document.getElementById('docId').value);
  fd.append('title', document.getElementById('docTitleIn').value);
  fd.append('category_id', document.getElementById('docCat').value);
  fd.append('content', __quill ? __quill.root.innerHTML : '');
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/document_crud.php?action=save', fd);
  if (j && j.success) { acToast('Document enregistré'); setTimeout(() => location.href = '?doc=' + (j.id || ''), 500); }
  else acToast((j && j.error) || 'Erreur', true);
}
async function delDoc(id){
  if (!confirm('Supprimer définitivement ce document ?')) return;
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', id);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/document_crud.php?action=delete', fd);
  if (j && j.success) { acToast('Document supprimé'); setTimeout(() => location.href = '<?php echo SITE_URL; ?>/pages/academy-central/documents_<?php echo $__slug; ?>.php', 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
// Recherche live dans la sidebar
const docSearch = document.getElementById('docSearch');
if (docSearch) docSearch.addEventListener('input', () => {
  const q = docSearch.value.trim().toLowerCase();
  document.querySelectorAll('.doc-item').forEach(i => {
    const ok = !q || (i.dataset.search || '').includes(q);
    i.style.display = ok ? '' : 'none';
  });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
