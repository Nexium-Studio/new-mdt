<?php
/**
 * _formations_engine.php — catégories + formations + statut des membres.
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) { http_response_code(404); exit('Faction invalide'); }
$__fk = $__ACADEMY_FACTION;
$__meta = academy_faction_meta($__fk);
$__slug = $__meta['slug'];
academy_require_access('formations_' . $__slug);
$canEdit = academy_user_can((int)$_SESSION['user_id'], 'formations_' . $__slug, 'edit') || academy_is_staff_bypass();

$pageTitle  = 'Formations — ' . $__meta['name'];
$breadcrumb = [['label' => $__meta['name']], ['label' => 'Formations']];
$db = Database::getInstance()->getConnection();
$csrf = academy_csrf_token();

try {
    $cs = $db->prepare('SELECT * FROM academy_formation_categories WHERE faction_key = ? ORDER BY sort_order, id');
    $cs->execute([$__fk]); $cats = $cs->fetchAll(PDO::FETCH_ASSOC);
    $fs = $db->prepare('SELECT f.*, c.name AS cat_name, c.color AS cat_color FROM academy_formations f LEFT JOIN academy_formation_categories c ON c.id = f.category_id WHERE f.faction_key = ? ORDER BY f.sort_order, f.id');
    $fs->execute([$__fk]); $formations = $fs->fetchAll(PDO::FETCH_ASSOC);
    $us = $db->prepare('SELECT uf.*, u.username, u.avatar, f.name AS formation_name, f.color AS formation_color, f.icon AS formation_icon FROM academy_user_formations uf JOIN academy_formations f ON f.id = uf.formation_id LEFT JOIN users u ON u.id = uf.user_id WHERE f.faction_key = ? ORDER BY uf.updated_at DESC LIMIT 200');
    $us->execute([$__fk]); $userFormations = $us->fetchAll(PDO::FETCH_ASSOC);
    $members = $db->query('SELECT id, username FROM users WHERE faction = ' . $db->quote($__fk) . ' ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cats = []; $formations = []; $userFormations = []; $members = []; }

$statuses = academy_formation_statuses();
$totalForm = count($formations);
$activeForm = count(array_filter($formations, fn($f) => !empty($f['is_active'])));
$nbValide = count(array_filter($userFormations, fn($x) => ($x['status'] ?? '') === 'valide'));
$nbEncours = count(array_filter($userFormations, fn($x) => ($x['status'] ?? '') === 'en_cours'));

[$ar,$ag,$ab] = sscanf($__meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$__meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";

include __DIR__ . '/header.php';
?>

<style>
<?php echo $facCss; ?>
.frow{display:flex;align-items:center;gap:11px;padding:11px 13px;border-radius:10px;background:rgba(255,255,255,.02);border:1px solid var(--border);transition:border-color .15s;margin-bottom:7px}
.frow:hover{border-color:var(--border-hover)}
.frow .ic{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;flex-shrink:0;font-size:.95rem}
.frow .nm{font-weight:600;font-size:.88rem;color:#fff}
.frow .desc{font-size:.74rem;color:var(--text-secondary);margin-top:2px;line-height:1.3}
.frow.off{opacity:.55}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-chalkboard-user"></i></span> Formations</h1>
    <p>Catalogue des formations et suivi des stagiaires.</p>
  </div>
  <?php if ($canEdit): ?>
  <div class="page-header-right">
    <button class="btn btn-secondary" onclick="openCatFModal()"><i class="fa-solid fa-folder-plus"></i> Catégorie</button>
    <button class="btn btn-purple" onclick="openFModal()"<?php echo empty($cats) ? ' disabled title="Créez d\'abord une catégorie"' : ''; ?>><i class="fa-solid fa-plus"></i> Formation</button>
  </div>
  <?php endif; ?>
</div>

<div class="stats-grid">
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-book"></i></div><div class="stat-info">
    <div class="stat-label">Formations</div><div class="stat-value"><?php echo $totalForm; ?></div>
    <div class="stat-meta"><?php echo $activeForm; ?> actives</div>
  </div></div>
  <div class="stat-card stat-cyan"><div class="stat-ic"><i class="fa-solid fa-folder-open"></i></div><div class="stat-info">
    <div class="stat-label">Catégories</div><div class="stat-value"><?php echo count($cats); ?></div>
    <div class="stat-meta">organisation</div>
  </div></div>
  <div class="stat-card stat-orange"><div class="stat-ic"><i class="fa-solid fa-spinner"></i></div><div class="stat-info">
    <div class="stat-label">En cours</div><div class="stat-value"><?php echo $nbEncours; ?></div>
    <div class="stat-meta">stagiaires actifs</div>
  </div></div>
  <div class="stat-card stat-green"><div class="stat-ic"><i class="fa-solid fa-circle-check"></i></div><div class="stat-info">
    <div class="stat-label">Validées</div><div class="stat-value"><?php echo $nbValide; ?></div>
    <div class="stat-meta">total réussites</div>
  </div></div>
</div>

<div class="grid-2" style="align-items:flex-start;gap:18px">

  <!-- COL 1 : Catalogue -->
  <div style="display:flex;flex-direction:column;gap:14px">
    <?php if (empty($cats) && empty($formations)): ?>
    <div class="card"><div class="card-body">
      <div class="empty-state">
        <i class="fa-solid fa-chalkboard"></i>
        <div class="es-title">Aucune formation configurée</div>
        <p>Créez une catégorie puis ajoutez vos formations pour démarrer le catalogue.</p>
        <?php if ($canEdit): ?>
        <button class="btn btn-purple" onclick="openCatFModal()"><i class="fa-solid fa-folder-plus"></i> Créer une catégorie</button>
        <?php endif; ?>
      </div>
    </div></div>
    <?php else: foreach ($cats as $c):
      $fsInCat = array_filter($formations, fn($f) => (int)$f['category_id'] === (int)$c['id']);
    ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <div style="width:30px;height:30px;border-radius:8px;background:<?php echo $c['color']; ?>22;color:<?php echo $c['color']; ?>;display:grid;place-items:center"><i class="fa-solid fa-<?php echo htmlspecialchars($c['icon']); ?>"></i></div>
          <?php echo htmlspecialchars($c['name']); ?>
          <span class="badge" style="background:<?php echo $c['color']; ?>22;color:<?php echo $c['color']; ?>"><?php echo count($fsInCat); ?> formation<?php echo count($fsInCat) > 1 ? 's' : ''; ?></span>
        </div>
        <?php if ($canEdit): ?>
        <div style="display:flex;gap:6px">
          <button class="btn btn-purple btn-sm" onclick='openFModal(null, <?php echo (int)$c['id']; ?>)' title="Ajouter"><i class="fa-solid fa-plus"></i></button>
          <button class="btn btn-secondary btn-sm" onclick='openCatFModal(<?php echo json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>)' title="Modifier"><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-danger btn-sm" onclick="delCatF(<?php echo (int)$c['id']; ?>)" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
        </div>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (empty($fsInCat)): ?>
        <div style="text-align:center;color:var(--text-secondary);font-size:.82rem;padding:22px 12px">Aucune formation dans cette catégorie</div>
        <?php else: foreach ($fsInCat as $f): ?>
        <div class="frow <?php echo $f['is_active'] ? '' : 'off'; ?>">
          <div class="ic" style="background:<?php echo $f['color']; ?>22;color:<?php echo $f['color']; ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($f['icon']); ?>"></i></div>
          <div style="flex:1;min-width:0">
            <div class="nm"><?php echo htmlspecialchars($f['name']); ?></div>
            <?php if (!empty($f['description'])): ?><div class="desc"><?php echo htmlspecialchars(mb_strimwidth($f['description'], 0, 100, '…')); ?></div><?php endif; ?>
          </div>
          <?php if (!$f['is_active']): ?><span class="badge badge-slate">Inactive</span><?php endif; ?>
          <?php if ($canEdit): ?>
          <div style="display:flex;gap:4px">
            <button class="btn btn-secondary btn-sm btn-icon" onclick='openFModal(<?php echo json_encode($f, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>)'><i class="fa-solid fa-pen"></i></button>
            <button class="btn btn-danger btn-sm btn-icon" onclick="delF(<?php echo (int)$f['id']; ?>)"><i class="fa-solid fa-trash"></i></button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- COL 2 : Suivi -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-user-graduate"></i> Suivi des stagiaires <span class="badge badge-purple"><?php echo count($userFormations); ?></span></div>
      <?php if ($canEdit && !empty($formations) && !empty($members)): ?>
      <button class="btn btn-purple btn-sm" onclick="openUFModal()"><i class="fa-solid fa-plus"></i> Assigner</button>
      <?php endif; ?>
    </div>
    <div class="table-wrapper">
      <?php if (empty($userFormations)): ?>
      <div class="empty-state">
        <i class="fa-solid fa-user-graduate"></i>
        <div class="es-title">Aucun stagiaire</div>
        <p>Assignez une formation à un membre pour démarrer son suivi.</p>
      </div>
      <?php else: ?>
      <table>
        <thead><tr><th>Membre</th><th>Formation</th><th>Statut</th><?php if ($canEdit) echo '<th></th>'; ?></tr></thead>
        <tbody>
        <?php foreach ($userFormations as $uf):
          $st = $statuses[$uf['status']] ?? null;
          $initial = strtoupper(mb_substr($uf['username'] ?? 'U', 0, 1));
        ?>
        <tr>
          <td>
            <div class="usr">
              <div class="usr-ava">
                <?php if (!empty($uf['avatar'])): ?>
                  <img src="<?php echo htmlspecialchars($uf['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:7px">
                <?php else: echo $initial; endif; ?>
              </div>
              <div class="row-strong"><?php echo htmlspecialchars($uf['username'] ?? '—'); ?></div>
            </div>
          </td>
          <td>
            <span class="assign-badge" style="--gr-c:<?php echo $uf['formation_color']; ?>;--gr-bg:<?php echo $uf['formation_color']; ?>22">
              <i class="fa-solid fa-<?php echo $uf['formation_icon']; ?>"></i> <?php echo htmlspecialchars($uf['formation_name']); ?>
            </span>
          </td>
          <td><?php if ($st): ?><span class="badge" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>"><i class="fa-solid fa-<?php echo $st['icon']; ?>"></i> <?php echo $st['label']; ?></span><?php endif; ?></td>
          <?php if ($canEdit): ?>
          <td>
            <select class="form-control" style="padding:5px 10px;font-size:.75rem;height:auto" onchange="updateUF(<?php echo (int)$uf['id']; ?>, this.value)">
              <?php foreach ($statuses as $sk => $sm): ?>
              <option value="<?php echo $sk; ?>" <?php echo $uf['status'] === $sk ? 'selected' : ''; ?>><?php echo $sm['label']; ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ─────────────── MODALS ─────────────── -->
<div class="modal-overlay" id="catFModal"><div class="modal">
  <div class="modal-header"><h3 id="catFTitle"><i class="fa-solid fa-folder-plus"></i> Catégorie</h3><button class="modal-close" onclick="document.getElementById('catFModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
  <input type="hidden" id="catFId">
  <div class="form-group"><label class="form-label">Nom *</label><input type="text" id="catFName" class="form-control" maxlength="128"></div>
  <div class="form-group"><label class="form-label">Description</label><textarea id="catFDesc" class="form-control" rows="2"></textarea></div>
  <div class="grid-2">
    <div class="form-group"><label class="form-label">Couleur</label><input type="color" id="catFColor" class="form-control" value="#10b981" style="height:40px;padding:3px"></div>
    <div class="form-group"><label class="form-label">Icône</label><input type="text" id="catFIcon" class="form-control" value="folder"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" onclick="document.getElementById('catFModal').classList.remove('open')">Annuler</button><button class="btn btn-purple" onclick="saveCatF()"><i class="fa-solid fa-check"></i> Enregistrer</button></div>
</div></div>

<div class="modal-overlay" id="fModal"><div class="modal">
  <div class="modal-header"><h3 id="fTitle"><i class="fa-solid fa-plus"></i> Formation</h3><button class="modal-close" onclick="document.getElementById('fModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
  <input type="hidden" id="fId">
  <div class="form-group"><label class="form-label">Catégorie</label>
    <select id="fCat" class="form-control">
      <option value="">— Aucune —</option>
      <?php foreach ($cats as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label class="form-label">Nom *</label><input type="text" id="fName" class="form-control" maxlength="128"></div>
  <div class="form-group"><label class="form-label">Description</label><textarea id="fDesc" class="form-control" rows="3"></textarea></div>
  <div class="grid-2">
    <div class="form-group"><label class="form-label">Couleur</label><input type="color" id="fColor" class="form-control" value="#10b981" style="height:40px;padding:3px"></div>
    <div class="form-group"><label class="form-label">Icône</label><input type="text" id="fIcon" class="form-control" value="chalkboard-user"></div>
  </div>
  <div class="form-group"><label style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="checkbox" id="fActive" checked> Formation active</label></div>
  <div class="modal-footer"><button class="btn btn-secondary" onclick="document.getElementById('fModal').classList.remove('open')">Annuler</button><button class="btn btn-purple" onclick="saveF()"><i class="fa-solid fa-check"></i> Enregistrer</button></div>
</div></div>

<div class="modal-overlay" id="ufModal"><div class="modal">
  <div class="modal-header"><h3><i class="fa-solid fa-user-plus"></i> Assigner une formation</h3><button class="modal-close" onclick="document.getElementById('ufModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
  <div class="form-group"><label class="form-label"><i class="fa-solid fa-user"></i> Membre</label>
    <select id="ufUser" class="form-control">
      <?php foreach ($members as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label class="form-label"><i class="fa-solid fa-graduation-cap"></i> Formation</label>
    <select id="ufFormation" class="form-control">
      <?php foreach ($formations as $f): ?><option value="<?php echo (int)$f['id']; ?>"><?php echo htmlspecialchars($f['name']); ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label class="form-label">Statut initial</label>
    <select id="ufStatus" class="form-control">
      <?php foreach ($statuses as $sk => $sm): ?><option value="<?php echo $sk; ?>"><?php echo $sm['label']; ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" onclick="document.getElementById('ufModal').classList.remove('open')">Annuler</button><button class="btn btn-purple" onclick="saveUF()"><i class="fa-solid fa-check"></i> Assigner</button></div>
</div></div>

<script>
const __CSRF = '<?php echo htmlspecialchars($csrf); ?>';
const __FACTION = '<?php echo $__fk; ?>';

async function fetchJSON(url, fd){
  try { const r = await fetch(url, { method: 'POST', body: fd }); return await r.json(); }
  catch(e) { return { success:false, error:'Erreur réseau' }; }
}
function openCatFModal(c){
  document.getElementById('catFTitle').innerHTML = c ? '<i class="fa-solid fa-pen"></i> Modifier la catégorie' : '<i class="fa-solid fa-folder-plus"></i> Nouvelle catégorie';
  document.getElementById('catFId').value = c ? c.id : '';
  document.getElementById('catFName').value = c ? c.name : '';
  document.getElementById('catFDesc').value = c ? (c.description || '') : '';
  document.getElementById('catFColor').value = c ? c.color : '#10b981';
  document.getElementById('catFIcon').value = c ? c.icon : 'folder';
  document.getElementById('catFModal').classList.add('open');
}
async function saveCatF(){
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('faction', __FACTION);
  fd.append('id', document.getElementById('catFId').value);
  fd.append('name', document.getElementById('catFName').value);
  fd.append('description', document.getElementById('catFDesc').value);
  fd.append('color', document.getElementById('catFColor').value);
  fd.append('icon', document.getElementById('catFIcon').value);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/formation_category_crud.php?action=save', fd);
  if (j && j.success) { acToast('Catégorie enregistrée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
async function delCatF(id){
  if (!confirm('Supprimer la catégorie ?')) return;
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', id);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/formation_category_crud.php?action=delete', fd);
  if (j && j.success) { acToast('Catégorie supprimée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
function openFModal(f, defaultCat){
  document.getElementById('fTitle').innerHTML = f ? '<i class="fa-solid fa-pen"></i> Modifier la formation' : '<i class="fa-solid fa-plus"></i> Nouvelle formation';
  document.getElementById('fId').value = f ? f.id : '';
  document.getElementById('fCat').value = f ? (f.category_id || '') : (defaultCat || '');
  document.getElementById('fName').value = f ? f.name : '';
  document.getElementById('fDesc').value = f ? (f.description || '') : '';
  document.getElementById('fColor').value = f ? f.color : '#10b981';
  document.getElementById('fIcon').value = f ? f.icon : 'chalkboard-user';
  document.getElementById('fActive').checked = f ? !!+f.is_active : true;
  document.getElementById('fModal').classList.add('open');
}
async function saveF(){
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('faction', __FACTION);
  fd.append('id', document.getElementById('fId').value);
  fd.append('category_id', document.getElementById('fCat').value);
  fd.append('name', document.getElementById('fName').value);
  fd.append('description', document.getElementById('fDesc').value);
  fd.append('color', document.getElementById('fColor').value);
  fd.append('icon', document.getElementById('fIcon').value);
  fd.append('is_active', document.getElementById('fActive').checked ? '1' : '0');
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/formation_crud.php?action=save', fd);
  if (j && j.success) { acToast('Formation enregistrée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
async function delF(id){
  if (!confirm('Supprimer la formation ? Tous les suivis associés seront supprimés.')) return;
  const fd = new FormData(); fd.append('csrf', __CSRF); fd.append('id', id);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/formation_crud.php?action=delete', fd);
  if (j && j.success) { acToast('Formation supprimée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
function openUFModal(){ document.getElementById('ufModal').classList.add('open'); }
async function saveUF(){
  const fd = new FormData();
  fd.append('csrf', __CSRF);
  fd.append('user_id', document.getElementById('ufUser').value);
  fd.append('formation_id', document.getElementById('ufFormation').value);
  fd.append('status', document.getElementById('ufStatus').value);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/formation_status_update.php?action=assign', fd);
  if (j && j.success) { acToast('Formation assignée'); setTimeout(() => location.reload(), 400); }
  else acToast((j && j.error) || 'Erreur', true);
}
async function updateUF(id, status){
  const fd = new FormData();
  fd.append('csrf', __CSRF); fd.append('id', id); fd.append('status', status);
  const j = await fetchJSON('<?php echo SITE_URL; ?>/pages/academy-central/ajax/formation_status_update.php?action=update', fd);
  if (j && j.success) acToast('Statut mis à jour');
  else acToast((j && j.error) || 'Erreur', true);
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
