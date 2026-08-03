<?php
/**
 * Gestion des Tags disponibles pour la création de rapports
 * pages/staff/content/report-tags.php
 */
defined('STAFF_PANEL') or define('STAFF_PANEL', true);
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

if (!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db  = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// Paramètre extensible des tags : « Alerte DOJ » (colonne ajoutée idempotemment).
try {
    $__rtCols = array_column($db->query("SHOW COLUMNS FROM report_tags")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('doj_alert', $__rtCols, true)) {
        $db->exec("ALTER TABLE report_tags ADD COLUMN doj_alert TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $e) {}

// ── AJAX reorder ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    header('Content-Type: application/json');
    $ids = array_map('intval', $_POST['ids'] ?? []);
    $s   = $db->prepare("UPDATE report_tags SET sort_order=? WHERE id=?");
    foreach ($ids as $pos => $id) $s->execute([$pos + 1, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Actions POST normales ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        $color = trim($_POST['color'] ?? '#94a3b8');
        $dojAlert = !empty($_POST['doj_alert']) ? 1 : 0;
        if (!$label) {
            $msg = 'Le nom du tag est obligatoire.'; $msgType = 'error';
        } else {
            try {
                $db->prepare("INSERT INTO report_tags (label, color, doj_alert, sort_order)
                              VALUES (?, ?, ?, (SELECT COALESCE(MAX(t2.sort_order),0)+1 FROM report_tags t2))")
                   ->execute([$label, $color, $dojAlert]);
                $msg = "Tag « ".htmlspecialchars($label)." » créé avec succès.";
                $msgType = 'success';
            } catch (Exception $e) {
                $msg = strpos($e->getMessage(),'Duplicate') !== false
                    ? "Un tag avec ce nom existe déjà." : "Erreur : ".$e->getMessage();
                $msgType = 'error';
            }
        }
    }

    if ($action === 'update') {
        $id    = intval($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $color = trim($_POST['color'] ?? '#94a3b8');
        $dojAlert = !empty($_POST['doj_alert']) ? 1 : 0;
        if (!$id || !$label) {
            $msg = 'Données invalides.'; $msgType = 'error';
        } else {
            try {
                $db->prepare("UPDATE report_tags SET label=?, color=?, doj_alert=? WHERE id=?")
                   ->execute([$label, $color, $dojAlert, $id]);
                $msg = "Tag mis à jour."; $msgType = 'success';
            } catch (Exception $e) {
                $msg = strpos($e->getMessage(),'Duplicate') !== false
                    ? "Ce nom est déjà utilisé par un autre tag." : "Erreur : ".$e->getMessage();
                $msgType = 'error';
            }
        }
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM report_tags WHERE id=?")->execute([$id]);
            $msg = 'Tag supprimé.'; $msgType = 'success';
        }
    }
}

// ── Chargement des tags ───────────────────────────────────────────────────────
$tags = $db->query("SELECT * FROM report_tags ORDER BY sort_order, id")->fetchAll();

$pageTitle  = 'Tags de Rapports';
$breadcrumb = [['label' => 'Contenu'], ['label' => 'Tags de Rapports']];
include __DIR__ . '/../includes/header.php';
?>
<style>
.rt-wrap { max-width: 760px; }

.rt-alert { display:flex; align-items:center; gap:.7rem; padding:.85rem 1.1rem;
  border-radius:10px; margin-bottom:1.5rem; font-size:.875rem; font-weight:500; }
.rt-alert.ok  { background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.2); color:#10b981; }
.rt-alert.err { background:rgba(239,68,68,.08);  border:1px solid rgba(239,68,68,.2);  color:#ef4444; }

/* ── CREATE ── */
.rt-create { background:var(--bg-card); border:1px solid var(--border);
  border-radius:14px; padding:1.4rem 1.5rem; margin-bottom:1.75rem; }
.rt-create h3 { font-family:'Syne',sans-serif; font-size:.9rem; font-weight:700;
  margin-bottom:1.1rem; display:flex; align-items:center; gap:.5rem; color:var(--text-primary); }
.rt-create h3 i { color:var(--blue); }
.rt-form-row { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; }
.rt-field { display:flex; flex-direction:column; gap:.4rem; flex:1; min-width:200px; }
.rt-field label { font-size:.7rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.08em; color:var(--text-secondary); }
.rt-field input[type=text] { padding:.65rem .9rem; background:rgba(255,255,255,.04);
  border:1px solid var(--border); border-radius:9px; color:var(--text-primary);
  font-size:.875rem; font-family:'DM Sans',sans-serif; outline:none; transition:border-color .2s; }
.rt-field input[type=text]:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.rt-field input[type=text]::placeholder { color:var(--text-muted); }
.rt-color-field { display:flex; flex-direction:column; gap:.4rem; flex-shrink:0; }
.rt-color-row { display:flex; align-items:center; gap:.6rem; }
.rt-color-row input[type=color] { width:44px; height:40px; padding:2px;
  border:1px solid var(--border); border-radius:9px; background:rgba(255,255,255,.04); cursor:pointer; }
.rt-color-hex { font-family:'DM Mono',monospace; font-size:.8rem; color:var(--text-secondary); min-width:64px; }
.rt-preview-row { margin-top:.9rem; display:flex; align-items:center; gap:.75rem; }
.rt-preview-row span.lbl { font-size:.72rem; color:var(--text-secondary); }
.tag-chip { display:inline-flex; align-items:center; gap:.38rem; padding:.3rem .75rem;
  border-radius:7px; font-size:.78rem; font-weight:700; border:1.5px solid; }
.tag-chip i { font-size:.58rem; opacity:.65; }

/* ── LIST ── */
.rt-list { background:var(--bg-card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.rt-list-head { padding:.95rem 1.25rem; border-bottom:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between; }
.rt-list-head h3 { font-family:'Syne',sans-serif; font-size:.9rem; font-weight:700;
  display:flex; align-items:center; gap:.5rem; margin:0; }
.rt-list-head h3 i { color:var(--blue); }
.rt-list-head small { font-size:.75rem; color:var(--text-secondary); }

.rt-row { display:flex; align-items:center; gap:.9rem; padding:.8rem 1.25rem;
  border-bottom:1px solid rgba(255,255,255,.04); transition:background .15s; }
.rt-row:last-child { border-bottom:none; }
.rt-row:hover { background:rgba(255,255,255,.03); }
.rt-row.dragging { opacity:.35; }
.rt-row.over { background:rgba(59,130,246,.07); }

.rt-grip { cursor:grab; color:var(--text-muted); font-size:.85rem; flex-shrink:0;
  opacity:.4; transition:opacity .2s; }
.rt-row:hover .rt-grip { opacity:.8; }
.rt-grip:active { cursor:grabbing; }

.rt-swatch { width:28px; height:28px; border-radius:7px; flex-shrink:0; border:2px solid; }

.rt-info { flex:1; }
.rt-info .rt-name { font-weight:600; font-size:.875rem; color:var(--text-primary); }
.rt-info .rt-hex  { font-family:'DM Mono',monospace; font-size:.7rem; color:var(--text-secondary); }

.rt-actions { display:flex; gap:.4rem; flex-shrink:0; }
.rt-btn { width:30px; height:30px; border-radius:7px; display:flex; align-items:center;
  justify-content:center; font-size:.73rem; cursor:pointer; transition:all .18s;
  border:1px solid var(--border); background:rgba(255,255,255,.03); color:var(--text-secondary); }
.rt-btn:hover { color:var(--text-primary); border-color:var(--border-hover); background:rgba(255,255,255,.06); }
.rt-btn.del:hover { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.3); color:#ef4444; }

.rt-empty { padding:3rem; text-align:center; color:var(--text-secondary); }
.rt-empty i { font-size:2.5rem; opacity:.2; display:block; margin-bottom:.75rem; }

/* ── MODAL ── */
.rt-modal-ov { position:fixed; inset:0; background:rgba(0,0,0,.72);
  backdrop-filter:blur(6px); z-index:500; display:none; align-items:center;
  justify-content:center; padding:1.5rem; }
.rt-modal-ov.open { display:flex; }
.rt-modal { background:var(--bg-elevated); border:1px solid var(--border);
  border-radius:16px; width:100%; max-width:420px; padding:1.6rem;
  box-shadow:0 20px 60px rgba(0,0,0,.5); animation:mIn .2s ease; }
@keyframes mIn { from{opacity:0;transform:scale(.95) translateY(8px)} to{opacity:1;transform:none} }
.rt-modal-hd { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.3rem; }
.rt-modal-hd h3 { font-family:'Syne',sans-serif; font-size:1rem; font-weight:700;
  display:flex; align-items:center; gap:.5rem; }
.rt-modal-hd h3 i { color:var(--blue); }
.rt-modal-cl { width:28px; height:28px; border-radius:7px; background:rgba(255,255,255,.04);
  border:1px solid var(--border); color:var(--text-secondary); cursor:pointer;
  display:flex; align-items:center; justify-content:center; font-size:.95rem; transition:all .18s; }
.rt-modal-cl:hover { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.28); color:#ef4444; }
.rt-modal-ft { display:flex; gap:.6rem; justify-content:flex-end; margin-top:1.3rem; }

/* ── Switch (paramètre de tag) ── */
.rt-switch-row { display:flex; align-items:flex-start; gap:.7rem; margin-top:1.1rem;
  padding:.8rem .95rem; background:rgba(139,92,246,.05); border:1px solid rgba(139,92,246,.18); border-radius:10px; }
.rt-switch { position:relative; display:inline-block; width:42px; height:23px; flex-shrink:0; }
.rt-switch input { opacity:0; width:0; height:0; }
.rt-switch .sl { position:absolute; inset:0; cursor:pointer; background:rgba(255,255,255,.12);
  border:1px solid var(--border); border-radius:23px; transition:.2s; }
.rt-switch .sl:before { content:''; position:absolute; width:17px; height:17px; left:2px; top:2px;
  background:#fff; border-radius:50%; transition:.2s; }
.rt-switch input:checked + .sl { background:#8b5cf6; border-color:#8b5cf6; }
.rt-switch input:checked + .sl:before { transform:translateX(19px); }
.rt-switch-row .txt { font-size:.84rem; font-weight:600; color:var(--text-primary); cursor:pointer; display:block; }
.rt-switch-row .hint { font-size:.72rem; color:var(--text-secondary); margin-top:.15rem; }
</style>

<div class="rt-wrap">

  <?php if ($msg): ?>
  <div class="rt-alert <?php echo $msgType === 'success' ? 'ok' : 'err'; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
  </div>
  <?php endif; ?>

  <!-- CRÉER UN TAG -->
  <div class="rt-create">
    <h3><i class="fas fa-plus-circle"></i> Nouveau tag</h3>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="rt-form-row">
        <div class="rt-field">
          <label>Nom du tag</label>
          <input type="text" name="label" placeholder="Ex: Remis DOJ, Confidentiel..." maxlength="100" required
            oninput="livePreview(this.value, document.getElementById('cColor').value, 'cPrev', 'cHex')">
        </div>
        <div class="rt-color-field">
          <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-secondary)">Couleur</label>
          <div class="rt-color-row">
            <input type="color" name="color" id="cColor" value="#00e5ff"
              oninput="livePreview(document.querySelector('.rt-create input[name=label]').value, this.value, 'cPrev', 'cHex')">
            <span class="rt-color-hex" id="cHex">#00e5ff</span>
          </div>
        </div>
        <button type="submit" class="btn btn-primary" style="flex-shrink:0">
          <i class="fas fa-plus"></i> Créer
        </button>
      </div>
      <div class="rt-switch-row">
        <label class="rt-switch"><input type="checkbox" name="doj_alert" id="cDojAlert" value="1"><span class="sl"></span></label>
        <div>
          <label for="cDojAlert" class="txt"><i class="fas fa-scale-balanced" style="color:#8b5cf6;margin-right:.35rem"></i>Alerte DOJ</label>
          <div class="hint">Les rapports LSPD portant ce tag seront automatiquement signalés au DOJ (rapport en attente de traitement).</div>
        </div>
      </div>
      <div class="rt-preview-row">
        <span class="lbl">Aperçu :</span>
        <span id="cPrev" class="tag-chip" style="color:#00e5ff;border-color:#00e5ff44;background:#00e5ff0d">
          <i class="fas fa-tag"></i> Aperçu
        </span>
      </div>
    </form>
  </div>

  <!-- LISTE -->
  <div class="rt-list">
    <div class="rt-list-head">
      <h3><i class="fas fa-tags"></i> Tags disponibles</h3>
      <small><?php echo count($tags); ?> tag<?php echo count($tags) !== 1 ? 's' : ''; ?> — glissez pour réordonner</small>
    </div>

    <?php if (empty($tags)): ?>
    <div class="rt-empty">
      <i class="fas fa-tags"></i>
      <p>Aucun tag pour l'instant. Créez-en un ci-dessus.</p>
    </div>
    <?php else: ?>
    <div id="rtList">
      <?php foreach ($tags as $t):
        $c   = htmlspecialchars($t['color']);
        $lbl = htmlspecialchars($t['label']);
        $rr  = hexdec(substr($t['color'],1,2));
        $gg  = hexdec(substr($t['color'],3,2));
        $bb  = hexdec(substr($t['color'],5,2));
      ?>
      <div class="rt-row" data-id="<?php echo $t['id']; ?>" draggable="true">
        <div class="rt-grip"><i class="fas fa-grip-vertical"></i></div>
        <div class="rt-swatch" style="background:rgba(<?php echo "$rr,$gg,$bb"?>,.14);border-color:rgba(<?php echo "$rr,$gg,$bb"?>,.4)"></div>
        <span class="tag-chip" style="color:<?php echo $c; ?>;border-color:rgba(<?php echo "$rr,$gg,$bb"?>,.35);background:rgba(<?php echo "$rr,$gg,$bb"?>,.1)">
          <i class="fas fa-tag"></i><?php echo $lbl; ?>
        </span>
        <div class="rt-info">
          <div class="rt-name"><?php echo $lbl; ?></div>
          <div class="rt-hex"><?php echo $c; ?></div>
        </div>
        <div class="rt-actions">
          <button type="button" class="rt-btn" title="Modifier"
            onclick="openEdit(<?php echo $t['id']; ?>,'<?php echo addslashes($t['label']); ?>','<?php echo $c; ?>',<?php echo (int)($t['doj_alert'] ?? 0); ?>)">
            <i class="fas fa-pen"></i>
          </button>
          <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer « <?php echo addslashes($t['label']); ?> » ?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
            <button type="submit" class="rt-btn del" title="Supprimer"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- MODAL MODIFIER -->
<div class="rt-modal-ov" id="editModal">
  <div class="rt-modal">
    <div class="rt-modal-hd">
      <h3><i class="fas fa-pen"></i> Modifier le tag</h3>
      <button class="rt-modal-cl" onclick="closeEdit()">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="eId">
      <div class="rt-field" style="margin-bottom:1rem">
        <label>Nom du tag</label>
        <input type="text" name="label" id="eLabel" maxlength="100" required
          oninput="livePreview(this.value, document.getElementById('eColor').value, 'ePrev', 'eHex')">
      </div>
      <div class="rt-color-field" style="margin-bottom:.5rem">
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-secondary);margin-bottom:.4rem;display:block">Couleur</label>
        <div class="rt-color-row">
          <input type="color" name="color" id="eColor"
            oninput="livePreview(document.getElementById('eLabel').value, this.value, 'ePrev', 'eHex')">
          <span class="rt-color-hex" id="eHex">#94a3b8</span>
        </div>
      </div>
      <div class="rt-switch-row">
        <label class="rt-switch"><input type="checkbox" name="doj_alert" id="eDojAlert" value="1"><span class="sl"></span></label>
        <div>
          <label for="eDojAlert" class="txt"><i class="fas fa-scale-balanced" style="color:#8b5cf6;margin-right:.35rem"></i>Alerte DOJ</label>
          <div class="hint">Signale automatiquement au DOJ les rapports portant ce tag.</div>
        </div>
      </div>
      <div class="rt-preview-row">
        <span class="lbl">Aperçu :</span>
        <span id="ePrev" class="tag-chip"><i class="fas fa-tag"></i> Aperçu</span>
      </div>
      <div class="rt-modal-ft">
        <button type="button" class="btn btn-secondary" onclick="closeEdit()">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Sauvegarder</button>
      </div>
    </form>
  </div>
</div>

<script>
function livePreview(label, hex, previewId, hexId) {
  const el = document.getElementById(previewId);
  const hx = document.getElementById(hexId);
  if (!el) return;
  if (hx) hx.textContent = hex;
  const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
  el.style.color       = hex;
  el.style.borderColor = `rgba(${r},${g},${b},.38)`;
  el.style.background  = `rgba(${r},${g},${b},.1)`;
  el.innerHTML = `<i class="fas fa-tag" style="font-size:.58rem;opacity:.65"></i> ${label || 'Aperçu'}`;
}

function openEdit(id, label, color, dojAlert) {
  document.getElementById('eId').value    = id;
  document.getElementById('eLabel').value = label;
  document.getElementById('eColor').value = color;
  document.getElementById('eDojAlert').checked = !!dojAlert;
  livePreview(label, color, 'ePrev', 'eHex');
  document.getElementById('editModal').classList.add('open');
  setTimeout(() => document.getElementById('eLabel').focus(), 100);
}
function closeEdit() { document.getElementById('editModal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEdit(); });
document.getElementById('editModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeEdit(); });

// Drag & drop pour réordonner
const list = document.getElementById('rtList');
if (list) {
  let dragged = null;
  list.querySelectorAll('.rt-row').forEach(row => {
    row.addEventListener('dragstart', e => {
      dragged = row;
      e.dataTransfer.effectAllowed = 'move';
      setTimeout(() => row.classList.add('dragging'), 0);
    });
    row.addEventListener('dragend', () => {
      row.classList.remove('dragging');
      list.querySelectorAll('.rt-row').forEach(r => r.classList.remove('over'));
      const ids = Array.from(list.querySelectorAll('.rt-row')).map(r => r.dataset.id);
      const fd = new FormData(); fd.append('action','reorder');
      ids.forEach(id => fd.append('ids[]', id));
      fetch(location.href, { method:'POST', body:fd });
    });
    row.addEventListener('dragover', e => {
      e.preventDefault();
      if (!dragged || dragged === row) return;
      list.querySelectorAll('.rt-row').forEach(r => r.classList.remove('over'));
      row.classList.add('over');
      const rect = row.getBoundingClientRect();
      if (e.clientY < rect.top + rect.height / 2) list.insertBefore(dragged, row);
      else row.after(dragged);
    });
    row.addEventListener('dragleave', () => row.classList.remove('over'));
    row.addEventListener('drop', e => e.preventDefault());
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>