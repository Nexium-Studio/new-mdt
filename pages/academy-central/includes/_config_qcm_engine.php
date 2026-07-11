<?php
/**
 * _config_qcm_engine.php — CRUD catégories + questions QCM.
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) { http_response_code(404); exit('Faction invalide'); }
$__fk = $__ACADEMY_FACTION;
$__meta = academy_faction_meta($__fk);
$__slug = $__meta['slug'];
academy_require_access('config_qcm_' . $__slug, 'view');
$canEdit = academy_user_can((int)$_SESSION['user_id'], 'config_qcm_' . $__slug, 'edit') || academy_is_staff_bypass();

$pageTitle  = 'Config QCM — ' . $__meta['name'];
$breadcrumb = [['label' => $__meta['name']], ['label' => 'Config QCM']];
$db = Database::getInstance()->getConnection();
$csrf = academy_csrf_token();

try {
    $cs = $db->prepare('SELECT * FROM academy_qcm_categories WHERE faction_key = ? ORDER BY sort_order, id');
    $cs->execute([$__fk]);
    $cats = $cs->fetchAll(PDO::FETCH_ASSOC);
    $qs = $db->prepare('SELECT * FROM academy_qcm_questions WHERE faction_key = ? ORDER BY category_id, sort_order, id');
    $qs->execute([$__fk]);
    $questions = $qs->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cats = []; $questions = []; }

$questionsByCat = [];
foreach ($questions as $q) $questionsByCat[(int)$q['category_id']][] = $q;
$totalQ = count($questions);
$activeQ = 0; foreach ($questions as $q) if ($q['is_active']) $activeQ++;

// QCM config (seuil etc)
$qcmCfg = academy_qcm_config($__fk);

[$ar,$ag,$ab] = sscanf($__meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$__meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";

include __DIR__ . '/header.php';
?>

<style>
<?php echo $facCss; ?>
.qcat-card{margin-bottom:14px}
.qcat-head{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;user-select:none}
.qcat-head .ic{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;flex-shrink:0;font-size:.9rem}
.qcat-head .nm{font-family:'Syne',sans-serif;font-weight:700;color:#fff}
.qcat-body.collapse{display:none}
.qrow{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:10px;background:rgba(255,255,255,.02);border:1px solid var(--border);margin-bottom:8px;transition:border-color .15s}
.qrow:hover{border-color:var(--border-hover)}
.qrow.off{opacity:.55}
.qrow .qnum{width:26px;height:26px;border-radius:7px;background:rgba(139,92,246,.14);color:#c4b5fd;display:grid;place-items:center;font-size:.7rem;flex-shrink:0;font-family:'DM Mono',monospace;font-weight:700}
.qrow .qbody{flex:1;min-width:0}
.qrow .qtxt{font-weight:600;font-size:.9rem;margin-bottom:7px;color:#fff;line-height:1.4}
.qrow .qopts{display:flex;flex-wrap:wrap;gap:6px}
.qopt{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;font-size:.74rem;font-weight:500;background:rgba(255,255,255,.04);color:var(--text-secondary);border:1px solid var(--border);max-width:100%}
.qopt.ok{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.3);color:#86efac;font-weight:600}
.qopt .ltr{font-family:'DM Mono',monospace;font-weight:700;color:#fff;flex-shrink:0}
.qopt.ok .ltr{color:#10b981}
.qopt .txt{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px}
.qrow .qact{display:flex;gap:4px;flex-shrink:0}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-circle-question"></i></span> Configuration du QCM</h1>
    <p>Organisez les catégories et la banque de questions de cette faction.</p>
  </div>
  <?php if ($canEdit): ?>
  <div class="page-header-right">
    <button class="btn btn-secondary" onclick="openCatModal()"><i class="fa-solid fa-folder-plus"></i> Catégorie</button>
    <button class="btn btn-purple" onclick="openQModal()" <?php echo empty($cats) ? 'disabled title="Créez d\'abord une catégorie"' : ''; ?>><i class="fa-solid fa-plus"></i> Question</button>
  </div>
  <?php endif; ?>
</div>

<div class="stats-grid">
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-folder-open"></i></div><div class="stat-info">
    <div class="stat-label">Catégories</div><div class="stat-value"><?php echo count($cats); ?></div>
    <div class="stat-meta">organisation</div>
  </div></div>
  <div class="stat-card stat-cyan"><div class="stat-ic"><i class="fa-solid fa-list-check"></i></div><div class="stat-info">
    <div class="stat-label">Questions</div><div class="stat-value"><?php echo $totalQ; ?></div>
    <div class="stat-meta"><?php echo $activeQ; ?> actives</div>
  </div></div>
  <div class="stat-card stat-orange"><div class="stat-ic"><i class="fa-solid fa-percent"></i></div><div class="stat-info">
    <div class="stat-label">Seuil de réussite</div><div class="stat-value"><?php echo (int)($qcmCfg['passing_score'] ?? 60); ?>%</div>
    <div class="stat-meta">configurable côté staff</div>
  </div></div>
  <div class="stat-card stat-purple" style="--stat-c:#c4b5fd;--stat-bg:rgba(139,92,246,.14)"><div class="stat-ic"><i class="fa-solid fa-shuffle"></i></div><div class="stat-info">
    <div class="stat-label">Questions par tentative</div><div class="stat-value"><?php echo (int)($qcmCfg['num_questions'] ?? 0) ?: $totalQ; ?></div>
    <div class="stat-meta"><?php echo !empty($qcmCfg['randomize']) ? 'tirées au hasard' : 'ordre fixe'; ?></div>
  </div></div>
</div>

<?php if (empty($cats)): ?>
<div class="card"><div class="card-body">
  <div class="empty-state">
    <i class="fa-solid fa-folder-plus"></i>
    <div class="es-title">Aucune catégorie créée</div>
    <p>Créez une première catégorie pour commencer à organiser vos questions QCM.</p>
    <?php if ($canEdit): ?>
    <button class="btn btn-purple" onclick="openCatModal()"><i class="fa-solid fa-folder-plus"></i> Créer une catégorie</button>
    <?php endif; ?>
  </div>
</div></div>
<?php else: ?>
<?php foreach ($cats as $c): $qByC = $questionsByCat[(int)$c['id']] ?? []; ?>
<div class="card qcat-card">
  <div class="card-header qcat-head" onclick="this.nextElementSibling.classList.toggle('collapse'); this.querySelector('.tg').classList.toggle('fa-chevron-up')">
    <div class="card-title">
      <div class="ic" style="background:<?php echo $c['color']; ?>22;color:<?php echo $c['color']; ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($c['icon']); ?>"></i></div>
      <div class="nm"><?php echo htmlspecialchars($c['name']); ?></div>
      <span class="badge" style="background:<?php echo $c['color']; ?>22;color:<?php echo $c['color']; ?>;margin-left:6px"><?php echo count($qByC); ?> question<?php echo count($qByC) > 1 ? 's' : ''; ?></span>
      <?php if ($c['description']): ?><span style="font-size:.78rem;color:var(--text-secondary);font-weight:400;margin-left:6px"><?php echo htmlspecialchars(mb_substr($c['description'], 0, 90)); ?></span><?php endif; ?>
    </div>
    <div style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
      <?php if ($canEdit): ?>
      <button class="btn btn-secondary btn-sm" onclick='openCatModal(<?php echo json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>)' title="Modifier"><i class="fa-solid fa-pen"></i></button>
      <button class="btn btn-purple btn-sm" onclick='openQModal(null, <?php echo (int)$c['id']; ?>)' title="Ajouter une question"><i class="fa-solid fa-plus"></i></button>
      <button class="btn btn-danger btn-sm" onclick="delCat(<?php echo (int)$c['id']; ?>)" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
      <?php endif; ?>
      <i class="fa-solid fa-chevron-down tg" style="color:var(--text-secondary);margin-left:6px;font-size:.75rem;cursor:pointer"></i>
    </div>
  </div>
  <div class="card-body qcat-body">
    <?php if (empty($qByC)): ?>
    <div class="empty-state" style="padding:26px 16px">
      <i class="fa-solid fa-circle-question"></i>
      <div class="es-title" style="font-size:.9rem">Aucune question dans cette catégorie</div>
      <?php if ($canEdit): ?>
      <button class="btn btn-purple btn-sm" onclick='openQModal(null, <?php echo (int)$c['id']; ?>)'><i class="fa-solid fa-plus"></i> Ajouter</button>
      <?php endif; ?>
    </div>
    <?php else: foreach ($qByC as $q): ?>
    <div class="qrow <?php echo $q['is_active'] ? '' : 'off'; ?>">
      <div class="qnum"><?php echo (int)$q['sort_order']; ?></div>
      <div class="qbody">
        <div class="qtxt"><?php echo htmlspecialchars($q['description']); ?></div>
        <div class="qopts">
          <?php foreach (['A','B','C','D'] as $L):
            $opt = $q['option_' . strtolower($L)] ?? '';
            if ($opt === '' || $opt === null) continue;
            $ok = $q['correct'] === $L;
          ?>
          <span class="qopt <?php echo $ok ? 'ok' : ''; ?>"><span class="ltr"><?php echo $L; ?>.</span><span class="txt"><?php echo htmlspecialchars($opt); ?></span><?php if ($ok) echo ' <i class="fa-solid fa-check" style="color:#10b981;font-size:.65rem"></i>'; ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if (!$q['is_active']): ?><span class="badge badge-slate" style="align-self:center">Désactivée</span><?php endif; ?>
      <?php if ($canEdit): ?>
      <div class="qact">
        <button class="btn btn-secondary btn-sm btn-icon" onclick="toggleQ(<?php echo (int)$q['id']; ?>)" title="<?php echo $q['is_active'] ? 'Désactiver' : 'Activer'; ?>"><i class="fa-solid fa-power-off"></i></button>
        <button class="btn btn-secondary btn-sm btn-icon" onclick='openQModal(<?php echo json_encode($q, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>)' title="Modifier"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-danger btn-sm btn-icon" onclick="delQ(<?php echo (int)$q['id']; ?>)" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ─────────────── MODALS ─────────────── -->
<div class="modal-overlay" id="catModal">
  <div class="modal">
    <div class="modal-header"><h3 id="catMTitle"><i class="fa-solid fa-folder-plus"></i> Nouvelle catégorie</h3><button class="modal-close" onclick="document.getElementById('catModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
    <input type="hidden" id="catId" value="">
    <div class="form-group"><label class="form-label">Nom *</label><input type="text" id="catName" class="form-control" maxlength="128" placeholder="Ex: Procédures policières"></div>
    <div class="form-group"><label class="form-label">Description</label><textarea id="catDesc" class="form-control" rows="2" placeholder="Décrivez le périmètre couvert (optionnel)"></textarea></div>
    <div class="grid-2">
      <div class="form-group"><label class="form-label">Couleur</label><input type="color" id="catColor" class="form-control" value="#3b82f6" style="height:40px;padding:3px"></div>
      <div class="form-group"><label class="form-label">Icône (FontAwesome)</label><input type="text" id="catIcon" class="form-control" value="folder" placeholder="ex: book"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="document.getElementById('catModal').classList.remove('open')">Annuler</button>
      <button class="btn btn-purple" onclick="saveCat()"><i class="fa-solid fa-check"></i> Enregistrer</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="qModal">
  <div class="modal" style="max-width:680px">
    <div class="modal-header"><h3 id="qMTitle"><i class="fa-solid fa-plus"></i> Nouvelle question</h3><button class="modal-close" onclick="document.getElementById('qModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
    <input type="hidden" id="qId" value="">
    <div class="grid-2">
      <div class="form-group"><label class="form-label">Catégorie *</label>
        <select id="qCat" class="form-control">
          <?php foreach ($cats as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Ordre</label><input type="number" id="qOrder" class="form-control" value="0" min="0"></div>
    </div>
    <div class="form-group"><label class="form-label">Énoncé *</label><textarea id="qDesc" class="form-control" rows="3" placeholder="Posez la question complète"></textarea></div>
    <div class="grid-2">
      <div class="form-group"><label class="form-label">Option A *</label><input type="text" id="qA" class="form-control" maxlength="512"></div>
      <div class="form-group"><label class="form-label">Option B *</label><input type="text" id="qB" class="form-control" maxlength="512"></div>
      <div class="form-group"><label class="form-label">Option C</label><input type="text" id="qC" class="form-control" maxlength="512"></div>
      <div class="form-group"><label class="form-label">Option D</label><input type="text" id="qD" class="form-control" maxlength="512"></div>
    </div>
    <div class="grid-2">
      <div class="form-group"><label class="form-label"><i class="fa-solid fa-circle-check" style="color:#10b981"></i> Bonne réponse</label>
        <select id="qCorrect" class="form-control">
          <option value="A">Option A</option><option value="B">Option B</option><option value="C">Option C</option><option value="D">Option D</option>
        </select>
      </div>
      <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:11px">
        <label style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="checkbox" id="qActive" checked> <span style="font-size:.85rem">Question active</span></label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="document.getElementById('qModal').classList.remove('open')">Annuler</button>
      <button class="btn btn-purple" onclick="saveQ()"><i class="fa-solid fa-check"></i> Enregistrer</button>
    </div>
  </div>
</div>

<script>
const __FACTION = '<?php echo $__fk; ?>';
const __CSRF    = '<?php echo htmlspecialchars($csrf); ?>';

function openCatModal(c){
  document.getElementById('catMTitle').innerHTML = c ? '<i class="fa-solid fa-pen"></i> Modifier la catégorie' : '<i class="fa-solid fa-folder-plus"></i> Nouvelle catégorie';
  document.getElementById('catId').value    = c ? c.id : '';
  document.getElementById('catName').value  = c ? c.name : '';
  document.getElementById('catDesc').value  = c ? (c.description || '') : '';
  document.getElementById('catColor').value = c ? c.color : '#3b82f6';
  document.getElementById('catIcon').value  = c ? c.icon : 'folder';
  document.getElementById('catModal').classList.add('open');
}
function openQModal(q, defaultCat){
  document.getElementById('qMTitle').innerHTML = q ? '<i class="fa-solid fa-pen"></i> Modifier la question' : '<i class="fa-solid fa-plus"></i> Nouvelle question';
  document.getElementById('qId').value = q ? q.id : '';
  document.getElementById('qCat').value = q ? q.category_id : (defaultCat || '');
  document.getElementById('qDesc').value = q ? q.description : '';
  document.getElementById('qA').value = q ? (q.option_a || '') : '';
  document.getElementById('qB').value = q ? (q.option_b || '') : '';
  document.getElementById('qC').value = q ? (q.option_c || '') : '';
  document.getElementById('qD').value = q ? (q.option_d || '') : '';
  document.getElementById('qCorrect').value = q ? q.correct : 'A';
  document.getElementById('qOrder').value = q ? q.sort_order : 0;
  document.getElementById('qActive').checked = q ? !!+q.is_active : true;
  document.getElementById('qModal').classList.add('open');
}
async function fetchJSON(url, fd){
  try { const r = await fetch(url, { method: 'POST', body: fd }); return await r.json(); }
  catch(e) { return { success:false, error:'Erreur réseau' }; }
}
async function saveCat(){
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('faction', __FACTION);
  fd.append('id', document.getElementById('catId').value);
  fd.append('name', document.getElementById('catName').value);
  fd.append('description', document.getElementById('catDesc').value);
  fd.append('color', document.getElementById('catColor').value);
  fd.append('icon', document.getElementById('catIcon').value);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/qcm_category_crud.php?action=save', fd);
  if (j && j.success) { acToast('Catégorie enregistrée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
async function delCat(id){
  if (!confirm('Supprimer la catégorie et toutes ses questions ?')) return;
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', id);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/qcm_category_crud.php?action=delete', fd);
  if (j && j.success) { acToast('Catégorie supprimée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
async function saveQ(){
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('faction', __FACTION);
  fd.append('id', document.getElementById('qId').value);
  fd.append('category_id', document.getElementById('qCat').value);
  fd.append('description', document.getElementById('qDesc').value);
  fd.append('option_a', document.getElementById('qA').value);
  fd.append('option_b', document.getElementById('qB').value);
  fd.append('option_c', document.getElementById('qC').value);
  fd.append('option_d', document.getElementById('qD').value);
  fd.append('correct',  document.getElementById('qCorrect').value);
  fd.append('sort_order', document.getElementById('qOrder').value);
  fd.append('is_active', document.getElementById('qActive').checked ? '1' : '0');
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/qcm_question_crud.php?action=save', fd);
  if (j && j.success) { acToast('Question enregistrée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
async function delQ(id){
  if (!confirm('Supprimer cette question ?')) return;
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', id);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/qcm_question_crud.php?action=delete', fd);
  if (j && j.success) { acToast('Question supprimée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
async function toggleQ(id){
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', id);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/qcm_question_toggle.php', fd);
  if (j && j.success) location.reload();
  else acToast((j && j.error) || 'Erreur', true);
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
