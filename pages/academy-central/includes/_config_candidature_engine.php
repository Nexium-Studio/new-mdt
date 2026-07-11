<?php
/**
 * _config_candidature_engine.php — édition split : méta (gauche) / champs dynamiques (droite).
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) { http_response_code(404); exit('Faction invalide'); }
$__fk = $__ACADEMY_FACTION;
$__meta = academy_faction_meta($__fk);
$__slug = $__meta['slug'];
academy_require_access('config_candidature_' . $__slug, 'view');
$canEdit = academy_user_can((int)$_SESSION['user_id'], 'config_candidature_' . $__slug, 'edit') || academy_is_staff_bypass();

$pageTitle  = 'Config candidature — ' . $__meta['name'];
$breadcrumb = [['label' => $__meta['name']], ['label' => 'Config candidature']];
$db = Database::getInstance()->getConnection();
$csrf = academy_csrf_token();

$cfg   = academy_candidature_config($__fk);
$types = academy_candidature_field_types();

[$ar,$ag,$ab] = sscanf($__meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$__meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";

include __DIR__ . '/header.php';
?>

<style>
<?php echo $facCss; ?>
.field-row{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:11px;padding:12px;transition:border-color .15s}
.field-row:hover{border-color:var(--border-hover)}
.field-row.dragging{opacity:.5;border-style:dashed}
.field-row .row-top{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.field-row .row-bot{display:flex;gap:8px;flex-wrap:wrap}
.field-row .num{width:28px;height:28px;border-radius:7px;background:var(--fac-c-15);color:var(--fac-c);display:grid;place-items:center;font-size:.78rem;flex-shrink:0;font-weight:700;cursor:grab}
.field-row .num:active{cursor:grabbing}
.cf-toggle{position:relative;display:inline-block;width:42px;height:22px}
.cf-toggle input{opacity:0;width:0;height:0}
.cf-toggle .sl{position:absolute;cursor:pointer;inset:0;background:rgba(255,255,255,.08);border-radius:22px;transition:.2s}
.cf-toggle .sl::before{content:"";position:absolute;height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.cf-toggle input:checked + .sl{background:var(--purple)}
.cf-toggle input:checked + .sl::before{transform:translateX(20px)}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-sliders"></i></span> Configuration candidature</h1>
    <p>Éditez le formulaire qui sera affiché aux candidats sur la page publique de cette faction.</p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo SITE_URL; ?>/pages/academy/candidature_<?php echo $__slug; ?>.php" target="_blank" class="btn btn-secondary"><i class="fa-solid fa-eye"></i> Aperçu public</a>
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/log_candidature_<?php echo $__slug; ?>.php" class="btn btn-secondary"><i class="fa-solid fa-file-signature"></i> Voir candidatures</a>
  </div>
</div>

<div class="grid-2" style="align-items:flex-start;gap:18px">

  <!-- GAUCHE : méta -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-circle-info"></i> Paramètres généraux</div></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Titre de la candidature</label>
        <input type="text" id="cfgName" class="form-control" maxlength="128" value="<?php echo htmlspecialchars($cfg['name'] ?? ''); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> placeholder="Ex: Recrutement officier">
      </div>
      <div class="form-group">
        <label class="form-label">Description / Préambule</label>
        <textarea id="cfgDesc" class="form-control" rows="5" <?php echo $canEdit ? '' : 'disabled'; ?> placeholder="Texte affiché en haut du formulaire public"><?php echo htmlspecialchars($cfg['description'] ?? ''); ?></textarea>
      </div>
      <div class="form-group" style="background:rgba(255,255,255,.02);border:1px solid var(--border);padding:12px;border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:14px">
        <div>
          <div style="font-weight:600;font-size:.85rem;margin-bottom:2px">Candidatures ouvertes</div>
          <div style="font-size:.74rem;color:var(--text-secondary)">Visible côté espace public</div>
        </div>
        <label class="cf-toggle"><input type="checkbox" id="cfgActive" <?php echo (int)($cfg['is_active'] ?? 1) === 1 ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>><span class="sl"></span></label>
      </div>
      <?php if ($canEdit): ?>
      <button class="btn btn-purple" onclick="saveCfg()" style="width:100%;justify-content:center;padding:11px;font-size:.85rem"><i class="fa-solid fa-floppy-disk"></i> Sauvegarder la configuration</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- DROITE : champs dynamiques -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <i class="fa-solid fa-list-check"></i> Champs du formulaire
        <span class="badge badge-purple" id="fieldsCount">0</span>
      </div>
      <?php if ($canEdit): ?>
      <button class="btn btn-purple btn-sm" onclick="addField()"><i class="fa-solid fa-plus"></i> Ajouter</button>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div id="fieldsList" style="display:flex;flex-direction:column;gap:10px"></div>
      <div id="fieldsEmpty" style="display:none">
        <div class="empty-state" style="padding:30px 16px">
          <i class="fa-solid fa-list"></i>
          <div class="es-title" style="font-size:.92rem">Aucun champ configuré</div>
          <p style="font-size:.8rem">Ajoutez des champs pour construire le formulaire (motivation, expérience…).</p>
          <?php if ($canEdit): ?>
          <button class="btn btn-purple btn-sm" onclick="addField()"><i class="fa-solid fa-plus"></i> Ajouter un premier champ</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const __FACTION = '<?php echo $__fk; ?>';
const __CSRF    = '<?php echo htmlspecialchars($csrf); ?>';
const __TYPES   = <?php echo json_encode($types); ?>;
let __fields    = <?php echo json_encode($cfg['fields'] ?? []); ?>;
const __canEdit = <?php echo $canEdit ? 'true' : 'false'; ?>;

function renderFields(){
  const list  = document.getElementById('fieldsList');
  const empty = document.getElementById('fieldsEmpty');
  const cnt   = document.getElementById('fieldsCount');
  list.innerHTML = '';
  cnt.textContent = (__fields || []).length;
  if (!__fields || __fields.length === 0) { empty.style.display = 'block'; return; }
  empty.style.display = 'none';
  __fields.sort((a,b) => (a.order || 0) - (b.order || 0));
  __fields.forEach((f, idx) => {
    const row = document.createElement('div');
    row.className = 'field-row';
    row.innerHTML = `
      <div class="row-top">
        <div class="num" title="N°${idx+1}">${idx+1}</div>
        <input type="text" class="form-control" placeholder="Clé technique (ex: motivation)" value="${escHtml(f.key || '')}" onchange="updF(${idx}, 'key', this.value)" ${__canEdit ? '' : 'disabled'} style="flex:1;font-family:'DM Mono',monospace;font-size:.82rem">
        <select class="form-control" onchange="updF(${idx}, 'type', this.value)" ${__canEdit ? '' : 'disabled'} style="max-width:170px">
          ${Object.entries(__TYPES).map(([k,v]) => `<option value="${k}" ${(f.type||'texte_court')===k?'selected':''}>${v}</option>`).join('')}
        </select>
        ${__canEdit ? `
          <button class="btn btn-secondary btn-sm btn-icon" onclick="moveF(${idx}, -1)" title="Monter"><i class="fa-solid fa-arrow-up"></i></button>
          <button class="btn btn-secondary btn-sm btn-icon" onclick="moveF(${idx}, 1)" title="Descendre"><i class="fa-solid fa-arrow-down"></i></button>
          <button class="btn btn-danger btn-sm btn-icon" onclick="delF(${idx})" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
        ` : ''}
      </div>
      <div class="row-bot">
        <input type="text" class="form-control" placeholder="Libellé affiché" value="${escHtml(f.label || '')}" onchange="updF(${idx}, 'label', this.value)" ${__canEdit ? '' : 'disabled'} style="flex:2;min-width:200px">
        <input type="text" class="form-control" placeholder="Placeholder (optionnel)" value="${escHtml(f.placeholder || '')}" onchange="updF(${idx}, 'placeholder', this.value)" ${__canEdit ? '' : 'disabled'} style="flex:2;min-width:200px">
        <label style="display:flex;align-items:center;gap:7px;font-size:.78rem;white-space:nowrap;padding:0 8px;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid var(--border)"><input type="checkbox" ${f.required?'checked':''} onchange="updF(${idx}, 'required', this.checked)" ${__canEdit ? '' : 'disabled'}> Obligatoire</label>
      </div>
    `;
    list.appendChild(row);
  });
}
function updF(i, k, v){ __fields[i][k] = v; }
function delF(i){
  if (!confirm('Supprimer ce champ ?')) return;
  __fields.splice(i, 1);
  __fields.forEach((f, idx) => f.order = idx + 1);
  renderFields();
}
function moveF(i, delta){
  const j = i + delta;
  if (j < 0 || j >= __fields.length) return;
  [__fields[i], __fields[j]] = [__fields[j], __fields[i]];
  __fields.forEach((f, idx) => f.order = idx + 1);
  renderFields();
}
function addField(){
  __fields.push({ key: 'champ_' + (__fields.length + 1), label: 'Nouveau champ', type: 'texte_court', required: false, order: __fields.length + 1, placeholder: '' });
  renderFields();
}
async function saveCfg(){
  const fd = new FormData();
  fd.append('csrf', __CSRF);
  fd.append('faction', __FACTION);
  fd.append('name', document.getElementById('cfgName').value);
  fd.append('description', document.getElementById('cfgDesc').value);
  fd.append('is_active', document.getElementById('cfgActive').checked ? '1' : '0');
  fd.append('fields', JSON.stringify(__fields));
  try {
    const r = await fetch('<?php echo SITE_URL; ?>/pages/academy-central/ajax/candidature_config_save.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (j && j.success) acToast('Configuration sauvegardée');
    else acToast((j && j.error) || 'Erreur', true);
  } catch(e) { acToast('Erreur réseau', true); }
}
function escHtml(s){ return String(s).replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
renderFields();
</script>

<?php include __DIR__ . '/footer.php'; ?>
