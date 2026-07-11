<?php
/**
 * Gestion des soins fréquents SAMS (utilisés par la facturation)
 * pages/staff/content/soins-sams.php
 *
 * CRUD complet : ajouter, modifier, supprimer, activer/désactiver, prix par défaut.
 * Table : `sams_soins`.
 */
defined('STAFF_PANEL') or define('STAFF_PANEL', true);
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

if (!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db  = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// ── AJAX reorder ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    header('Content-Type: application/json');
    $ids = array_map('intval', $_POST['ids'] ?? []);
    $s   = $db->prepare("UPDATE sams_soins SET sort_order=? WHERE id=?");
    foreach ($ids as $pos => $id) $s->execute([$pos + 1, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Actions POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        $id    = intval($_POST['id'] ?? 0);
        $nom   = trim($_POST['nom'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $prix  = (float)str_replace(',', '.', $_POST['prix_defaut'] ?? '0');
        if ($prix < 0) $prix = 0;

        if (!$nom) {
            $msg = 'Le nom du soin est obligatoire.'; $msgType = 'error';
        } else {
            try {
                if ($action === 'create') {
                    $db->prepare("INSERT INTO sams_soins (nom, description, prix_defaut, actif, sort_order, created_by)
                                  VALUES (?, ?, ?, 1, (SELECT COALESCE(MAX(s2.sort_order),0)+1 FROM sams_soins s2), ?)")
                       ->execute([$nom, $desc ?: null, $prix, $uid ?: null]);
                    $msg = "Soin « ".htmlspecialchars($nom)." » ajouté."; $msgType = 'success';
                } else {
                    if (!$id) { $msg = 'Données invalides.'; $msgType = 'error'; }
                    else {
                        $db->prepare("UPDATE sams_soins SET nom=?, description=?, prix_defaut=? WHERE id=?")
                           ->execute([$nom, $desc ?: null, $prix, $id]);
                        $msg = "Soin mis à jour."; $msgType = 'success';
                    }
                }
            } catch (Exception $e) {
                $msg = strpos($e->getMessage(),'Duplicate') !== false
                    ? "Un soin avec ce nom existe déjà." : "Erreur : ".$e->getMessage();
                $msgType = 'error';
            }
        }
    }

    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE sams_soins SET actif = 1 - actif WHERE id=?")->execute([$id]);
            $msg = 'Statut du soin mis à jour.'; $msgType = 'success';
        }
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM sams_soins WHERE id=?")->execute([$id]);
            $msg = 'Soin supprimé.'; $msgType = 'success';
        }
    }
}

// ── Chargement ──────────────────────────────────────────────────────────────────
$soins = [];
try {
    $soins = $db->query("SELECT * FROM sams_soins ORDER BY sort_order, id")->fetchAll();
} catch (Exception $e) {
    $msg = "La table des soins SAMS n'existe pas encore. Appliquez la migration database/migration_sams_refonte.sql.";
    $msgType = 'error';
}

$pageTitle  = 'Soins Fréquents SAMS';
$breadcrumb = [['label' => 'Contenu'], ['label' => 'SAMS'], ['label' => 'Soins SAMS']];
include __DIR__ . '/../includes/header.php';
?>
<style>
.so-wrap { max-width: 820px; }
.so-alert { display:flex; align-items:center; gap:.7rem; padding:.85rem 1.1rem;
  border-radius:10px; margin-bottom:1.5rem; font-size:.875rem; font-weight:500; }
.so-alert.ok  { background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.2); color:#10b981; }
.so-alert.err { background:rgba(239,68,68,.08);  border:1px solid rgba(239,68,68,.2);  color:#ef4444; }

.so-create { background:var(--bg-card); border:1px solid var(--border);
  border-radius:14px; padding:1.4rem 1.5rem; margin-bottom:1.75rem; }
.so-create h3 { font-family:'Syne',sans-serif; font-size:.9rem; font-weight:700;
  margin-bottom:1.1rem; display:flex; align-items:center; gap:.5rem; }
.so-create h3 i { color:var(--red); }
.so-grid { display:grid; grid-template-columns:1.4fr .8fr; gap:.9rem; }
.so-field { display:flex; flex-direction:column; gap:.4rem; }
.so-field.full { grid-column:1/-1; }
.so-field label { font-size:.7rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.08em; color:var(--text-secondary); }
.so-field input, .so-field textarea { padding:.65rem .9rem; background:rgba(255,255,255,.04);
  border:1px solid var(--border); border-radius:9px; color:var(--text-primary);
  font-size:.875rem; font-family:'DM Sans',sans-serif; outline:none; transition:border-color .2s; }
.so-field textarea { resize:vertical; min-height:64px; }
.so-field input:focus, .so-field textarea:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(225,29,72,.12); }
.so-price-wrap { position:relative; }
.so-price-wrap span.cur { position:absolute; left:.7rem; top:50%; transform:translateY(-50%);
  color:var(--text-secondary); font-family:'DM Mono',monospace; pointer-events:none; }
.so-price-wrap input { padding-left:1.5rem; }

.so-list { background:var(--bg-card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.so-list-head { padding:.95rem 1.25rem; border-bottom:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between; }
.so-list-head h3 { font-family:'Syne',sans-serif; font-size:.9rem; font-weight:700; display:flex; align-items:center; gap:.5rem; margin:0; }
.so-list-head h3 i { color:var(--red); }
.so-list-head small { font-size:.75rem; color:var(--text-secondary); }

.so-row { display:flex; align-items:center; gap:.9rem; padding:.85rem 1.25rem;
  border-bottom:1px solid rgba(255,255,255,.04); transition:background .15s; }
.so-row:last-child { border-bottom:none; }
.so-row:hover { background:rgba(255,255,255,.03); }
.so-row.dragging { opacity:.35; }
.so-row.over { background:rgba(225,29,72,.07); }
.so-row.inactive { opacity:.5; }
.so-grip { cursor:grab; color:var(--text-muted); opacity:.4; flex-shrink:0; }
.so-row:hover .so-grip { opacity:.8; }
.so-ico { width:34px; height:34px; border-radius:9px; flex-shrink:0; display:grid; place-items:center;
  background:rgba(225,29,72,.12); color:var(--red); font-size:.85rem; }
.so-info { flex:1; min-width:0; }
.so-name { font-weight:700; font-size:.9rem; }
.so-desc { font-size:.75rem; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:380px; }
.so-price { font-family:'DM Mono',monospace; font-weight:700; color:var(--green); font-size:.95rem; flex-shrink:0; }
.so-actions { display:flex; gap:.4rem; align-items:center; flex-shrink:0; }
.so-btn { width:30px; height:30px; border-radius:7px; display:grid; place-items:center;
  font-size:.73rem; cursor:pointer; border:1px solid var(--border); background:rgba(255,255,255,.03); color:var(--text-secondary); transition:all .18s; }
.so-btn:hover { color:var(--text-primary); border-color:var(--border-hover); background:rgba(255,255,255,.06); }
.so-btn.del:hover { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.3); color:#ef4444; }
.so-toggle { position:relative; width:40px; height:22px; flex-shrink:0; border:none;
  background:var(--text-muted); border-radius:22px; cursor:pointer; padding:0; transition:background .2s; }
.so-toggle::before { content:''; position:absolute; left:3px; top:3px; width:16px; height:16px;
  background:#fff; border-radius:50%; transition:transform .2s; }
.so-toggle.on { background:var(--green); }
.so-toggle.on::before { transform:translateX(18px); }
.so-empty { padding:3rem; text-align:center; color:var(--text-secondary); }
.so-empty i { font-size:2.5rem; opacity:.2; display:block; margin-bottom:.75rem; }

.so-modal-ov { position:fixed; inset:0; background:rgba(0,0,0,.72); backdrop-filter:blur(6px);
  z-index:500; display:none; align-items:center; justify-content:center; padding:1.5rem; }
.so-modal-ov.open { display:flex; }
.so-modal { background:var(--bg-elevated); border:1px solid var(--border); border-radius:16px;
  width:100%; max-width:460px; padding:1.6rem; box-shadow:0 20px 60px rgba(0,0,0,.5); animation:mIn .2s ease; }
@keyframes mIn { from{opacity:0;transform:scale(.95) translateY(8px)} to{opacity:1;transform:none} }
.so-modal-hd { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.3rem; }
.so-modal-hd h3 { font-family:'Syne',sans-serif; font-size:1rem; font-weight:700; display:flex; align-items:center; gap:.5rem; }
.so-modal-hd h3 i { color:var(--red); }
.so-modal-cl { width:28px; height:28px; border-radius:7px; background:rgba(255,255,255,.04);
  border:1px solid var(--border); color:var(--text-secondary); cursor:pointer; display:grid; place-items:center; transition:all .18s; }
.so-modal-cl:hover { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.28); color:#ef4444; }
.so-modal-ft { display:flex; gap:.6rem; justify-content:flex-end; margin-top:1.3rem; }
@media(max-width:680px){ .so-grid{grid-template-columns:1fr} }
</style>

<div class="so-wrap">

  <?php if ($msg): ?>
  <div class="so-alert <?php echo $msgType === 'success' ? 'ok' : 'err'; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
  </div>
  <?php endif; ?>

  <!-- CRÉER -->
  <div class="so-create">
    <h3><i class="fas fa-kit-medical"></i> Nouveau soin fréquent</h3>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="so-grid">
        <div class="so-field">
          <label>Nom du soin</label>
          <input type="text" name="nom" placeholder="Ex: Consultation, Réanimation..." maxlength="150" required>
        </div>
        <div class="so-field">
          <label>Prix par défaut</label>
          <div class="so-price-wrap">
            <span class="cur">$</span>
            <input type="number" name="prix_defaut" min="0" step="1" value="0">
          </div>
        </div>
        <div class="so-field full">
          <label>Description (optionnel)</label>
          <textarea name="description" maxlength="500" placeholder="Détail du soin..."></textarea>
        </div>
      </div>
      <div style="margin-top:1rem;text-align:right">
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter le soin</button>
      </div>
    </form>
  </div>

  <!-- LISTE -->
  <div class="so-list">
    <div class="so-list-head">
      <h3><i class="fas fa-list-check"></i> Soins configurés</h3>
      <small><?php echo count($soins); ?> soin<?php echo count($soins) !== 1 ? 's' : ''; ?> — glissez pour réordonner</small>
    </div>

    <?php if (empty($soins)): ?>
    <div class="so-empty">
      <i class="fas fa-kit-medical"></i>
      <p>Aucun soin configuré. Ajoutez-en un ci-dessus.</p>
    </div>
    <?php else: ?>
    <div id="soList">
      <?php foreach ($soins as $s):
        $actif = (int)($s['actif'] ?? 1) === 1;
      ?>
      <div class="so-row <?php echo $actif ? '' : 'inactive'; ?>" data-id="<?php echo $s['id']; ?>" draggable="true">
        <div class="so-grip"><i class="fas fa-grip-vertical"></i></div>
        <div class="so-ico"><i class="fas fa-kit-medical"></i></div>
        <div class="so-info">
          <div class="so-name"><?php echo htmlspecialchars($s['nom']); ?></div>
          <?php if (!empty($s['description'])): ?>
          <div class="so-desc"><?php echo htmlspecialchars($s['description']); ?></div>
          <?php endif; ?>
        </div>
        <div class="so-price">$<?php echo number_format((float)$s['prix_defaut'], 0, ',', ' '); ?></div>
        <div class="so-actions">
          <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
            <button type="submit" class="so-toggle <?php echo $actif ? 'on' : ''; ?>"
              title="<?php echo $actif ? 'Désactiver' : 'Activer'; ?>"></button>
          </form>
          <button type="button" class="so-btn" title="Modifier"
            onclick='openEdit(<?php echo json_encode([
                "id" => (int)$s["id"],
                "nom" => $s["nom"],
                "description" => $s["description"] ?? "",
                "prix" => (float)$s["prix_defaut"],
            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>
            <i class="fas fa-pen"></i>
          </button>
          <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer « <?php echo addslashes($s['nom']); ?> » ?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
            <button type="submit" class="so-btn del" title="Supprimer"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- MODAL MODIFIER -->
<div class="so-modal-ov" id="editModal">
  <div class="so-modal">
    <div class="so-modal-hd">
      <h3><i class="fas fa-pen"></i> Modifier le soin</h3>
      <button class="so-modal-cl" onclick="closeEdit()">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="eId">
      <div class="so-field" style="margin-bottom:1rem">
        <label>Nom du soin</label>
        <input type="text" name="nom" id="eNom" maxlength="150" required>
      </div>
      <div class="so-field" style="margin-bottom:1rem">
        <label>Prix par défaut</label>
        <div class="so-price-wrap">
          <span class="cur">$</span>
          <input type="number" name="prix_defaut" id="ePrix" min="0" step="1" value="0">
        </div>
      </div>
      <div class="so-field">
        <label>Description (optionnel)</label>
        <textarea name="description" id="eDesc" maxlength="500"></textarea>
      </div>
      <div class="so-modal-ft">
        <button type="button" class="btn btn-secondary" onclick="closeEdit()">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Sauvegarder</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(data) {
  document.getElementById('eId').value   = data.id;
  document.getElementById('eNom').value  = data.nom;
  document.getElementById('eDesc').value = data.description || '';
  document.getElementById('ePrix').value = data.prix;
  document.getElementById('editModal').classList.add('open');
  setTimeout(() => document.getElementById('eNom').focus(), 100);
}
function closeEdit() { document.getElementById('editModal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEdit(); });
document.getElementById('editModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeEdit(); });

// Drag & drop réordonnancement
const list = document.getElementById('soList');
if (list) {
  let dragged = null;
  list.querySelectorAll('.so-row').forEach(row => {
    row.addEventListener('dragstart', e => {
      dragged = row; e.dataTransfer.effectAllowed = 'move';
      setTimeout(() => row.classList.add('dragging'), 0);
    });
    row.addEventListener('dragend', () => {
      row.classList.remove('dragging');
      list.querySelectorAll('.so-row').forEach(r => r.classList.remove('over'));
      const ids = Array.from(list.querySelectorAll('.so-row')).map(r => r.dataset.id);
      const fd = new FormData(); fd.append('action','reorder');
      ids.forEach(id => fd.append('ids[]', id));
      fetch(location.href, { method:'POST', body:fd });
    });
    row.addEventListener('dragover', e => {
      e.preventDefault();
      if (!dragged || dragged === row) return;
      list.querySelectorAll('.so-row').forEach(r => r.classList.remove('over'));
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
