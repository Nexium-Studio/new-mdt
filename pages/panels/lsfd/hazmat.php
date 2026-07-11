<?php
/**
 * §9 — Codes de danger & matières dangereuses (HazMat)
 * Base de données HazMat consultable en intervention : code ONU, risques,
 * procédure, EPI requis, zone à risque. Outil de référence + CRUD.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$selfUrl = SITE_URL . '/pages/panels/lsfd/hazmat.php';

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $fields = [trim($_POST['un_code']??''), trim($_POST['name']??''), trim($_POST['risks']??''), trim($_POST['procedure']??''), trim($_POST['epi_required']??''), trim($_POST['zone']??'')];
            if ($action === 'create') {
                $db->prepare("INSERT INTO lsfd_hazmat (un_code, name, risks, `procedure`, epi_required, zone) VALUES (?,?,?,?,?,?)")->execute($fields);
                lsfd_log('create','hazmat',(int)$db->lastInsertId(),'Fiche HazMat');
                $_SESSION['lsfd_flash']='Fiche ajoutée.';
            } else {
                $db->prepare("UPDATE lsfd_hazmat SET un_code=?, name=?, risks=?, `procedure`=?, epi_required=?, zone=? WHERE id=?")->execute(array_merge($fields,[(int)($_POST['id']??0)]));
                $_SESSION['lsfd_flash']='Fiche mise à jour.';
            }
            header("Location: $selfUrl"); exit;
        }
        if ($action === 'delete') { $db->prepare("DELETE FROM lsfd_hazmat WHERE id=?")->execute([(int)($_POST['id']??0)]); $_SESSION['lsfd_flash']='Fiche supprimée.'; header("Location: $selfUrl"); exit; }
    } catch (Exception $e) { $_SESSION['lsfd_flash']="Erreur (migration ?) : ".$e->getMessage(); header("Location: $selfUrl"); exit; }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash=$_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$rows = [];
try { $rows = $db->query("SELECT * FROM lsfd_hazmat ORDER BY name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$pageTitle = 'Codes HazMat';
include __DIR__ . '/includes/header.php';
?>
<?php if ($flash): ?><div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="lf-pagehead">
  <div><div class="lf-ph-title"><i class="fas fa-radiation"></i> Codes de danger HazMat</div><div class="lf-ph-sub">Base de référence — <?php echo count($rows); ?> fiche(s)</div></div>
  <button class="lf-btn lf-btn-primary" onclick="openHaz()"><i class="fas fa-plus"></i> Nouvelle fiche</button>
</div>

<div class="lf-card">
  <div class="lf-card-h"><h3><i class="fas fa-biohazard"></i> Fiches de sécurité</h3>
    <input type="text" class="lf-input" id="hazSearch" placeholder="Rechercher (nom, code ONU)…" style="max-width:260px" oninput="filterHaz(this.value)">
  </div>
  <?php if (empty($rows)): ?>
    <div class="lf-empty"><i class="fas fa-radiation"></i><p>Aucune fiche HazMat. Ajoutez les matières dangereuses de référence.</p></div>
  <?php else: ?>
  <div id="hazGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:.9rem">
    <?php foreach ($rows as $h): ?>
      <div class="haz-item" data-s="<?php echo htmlspecialchars(mb_strtolower(($h['name']??'').' '.($h['un_code']??''))); ?>" style="border:1px solid rgba(100,116,139,.14);border-radius:12px;padding:1rem;background:rgba(15,18,28,.4)">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.5rem">
          <span style="font-weight:700;color:#e8edf5"><?php echo htmlspecialchars($h['name']); ?></span>
          <?php if ($h['un_code']): ?><span class="lf-badge yellow">ONU <?php echo htmlspecialchars($h['un_code']); ?></span><?php endif; ?>
        </div>
        <?php if ($h['risks']): ?><div style="color:#94a3b8;font-size:.82rem;margin-bottom:.4rem"><strong style="color:#cbd5e1">Risques :</strong> <?php echo htmlspecialchars($h['risks']); ?></div><?php endif; ?>
        <?php if ($h['procedure']): ?><div style="color:#94a3b8;font-size:.82rem;margin-bottom:.4rem"><strong style="color:#cbd5e1">Procédure :</strong> <?php echo htmlspecialchars($h['procedure']); ?></div><?php endif; ?>
        <?php if ($h['epi_required']): ?><div style="color:#94a3b8;font-size:.82rem;margin-bottom:.4rem"><strong style="color:#cbd5e1">EPI :</strong> <?php echo htmlspecialchars($h['epi_required']); ?></div><?php endif; ?>
        <?php if ($h['zone']): ?><div style="font-size:.78rem;margin-top:.4rem"><span class="lf-badge red"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($h['zone']); ?></span></div><?php endif; ?>
        <div style="display:flex;gap:.4rem;margin-top:.7rem">
          <button class="lf-btn lf-btn-ghost lf-btn-sm" onclick='openHaz(<?php echo json_encode($h, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
          <form method="POST" style="margin:0" data-lsfd-confirm="Supprimer cette fiche ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $h['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="lf-modal-ov" id="hazModal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-radiation"></i> Fiche HazMat</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST"><input type="hidden" name="action" id="hazAction" value="create"><input type="hidden" name="id" id="hazId">
      <div class="lf-modal-b">
        <div class="lf-grid-2">
          <div class="lf-field"><label>Nom de la matière</label><input class="lf-input" name="name" id="hazName" required></div>
          <div class="lf-field"><label>Code ONU</label><input class="lf-input" name="un_code" id="hazUn" placeholder="Ex: 1203"></div>
        </div>
        <div class="lf-field"><label>Risques</label><textarea class="lf-textarea" name="risks" id="hazRisks"></textarea></div>
        <div class="lf-field"><label>Procédure d'intervention</label><textarea class="lf-textarea" name="procedure" id="hazProc"></textarea></div>
        <div class="lf-grid-2">
          <div class="lf-field"><label>EPI requis</label><input class="lf-input" name="epi_required" id="hazEpi"></div>
          <div class="lf-field"><label>Zone à risque</label><input class="lf-input" name="zone" id="hazZone" placeholder="Zone industrielle, dépôt…"></div>
        </div>
      </div>
      <div class="lf-modal-f"><button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button><button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
    </form>
  </div>
</div>

<script>
function filterHaz(q){ q=(q||'').toLowerCase().trim(); document.querySelectorAll('#hazGrid .haz-item').forEach(el=>el.style.display=!q||el.dataset.s.includes(q)?'':'none'); }
function openHaz(h){
  document.getElementById('hazAction').value = h ? 'update':'create';
  document.getElementById('hazId').value = h?h.id:'';
  document.getElementById('hazName').value = h?(h.name||''):'';
  document.getElementById('hazUn').value = h?(h.un_code||''):'';
  document.getElementById('hazRisks').value = h?(h.risks||''):'';
  document.getElementById('hazProc').value = h?(h.procedure||''):'';
  document.getElementById('hazEpi').value = h?(h.epi_required||''):'';
  document.getElementById('hazZone').value = h?(h.zone||''):'';
  document.getElementById('hazModal').classList.add('open');
}
</script>

<?php include __DIR__ . '/includes/footer.php';
