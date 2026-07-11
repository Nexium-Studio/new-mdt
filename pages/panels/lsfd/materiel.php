<?php
/**
 * §7 — Matériel, stock & EPI LSFD
 * Stock par station (consommables, équipements), EPI nominatifs, alertes de stock
 * bas et demandes de réapprovisionnement (transmises au Gouvernement).
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$selfUrl = SITE_URL . '/pages/panels/lsfd/materiel.php';

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $catg = in_array($_POST['category']??'',['consommable','epi','equipement'],true)?$_POST['category']:'consommable';
            $db->prepare("INSERT INTO lsfd_inventory (name, category, station, quantity, threshold, assigned_to) VALUES (?,?,?,?,?,?)")
               ->execute([trim($_POST['name']??''), $catg, trim($_POST['station']??''), (int)($_POST['quantity']??0), (int)($_POST['threshold']??0), (int)($_POST['assigned_to']??0)?:null]);
            lsfd_log('create','inventory',(int)$db->lastInsertId(),'Article ajouté');
            $_SESSION['lsfd_flash']='Article ajouté.'; header("Location: $selfUrl"); exit;
        }
        if ($action === 'qty') {
            $id=(int)($_POST['id']??0); $q=max(0,(int)($_POST['quantity']??0));
            $db->prepare("UPDATE lsfd_inventory SET quantity=? WHERE id=?")->execute([$q,$id]);
            header("Location: $selfUrl"); exit;
        }
        if ($action === 'delete') { $db->prepare("DELETE FROM lsfd_inventory WHERE id=?")->execute([(int)($_POST['id']??0)]); $_SESSION['lsfd_flash']='Article supprimé.'; header("Location: $selfUrl"); exit; }
    } catch (Exception $e) { $_SESSION['lsfd_flash']="Erreur (migration ?) : ".$e->getMessage(); header("Location: $selfUrl"); exit; }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash=$_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$items = [];
try { $items = $db->query("SELECT i.*, NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS holder
    FROM lsfd_inventory i LEFT JOIN users u ON u.id=i.assigned_to LEFT JOIN profiles p ON p.id=u.profile ORDER BY i.station, i.category, i.name")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
$agents = [];
try { $agents = $db->query("SELECT u.id, NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS rp_name FROM users u LEFT JOIN profiles p ON p.id=u.profile WHERE u.faction='LSFD'")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$low = array_filter($items, fn($i) => (int)$i['threshold'] > 0 && (int)$i['quantity'] <= (int)$i['threshold']);
$catLabels = ['consommable'=>['Consommable','blue'],'epi'=>['EPI','purple'],'equipement'=>['Équipement','gray']];

$pageTitle = 'Matériel & stock';
include __DIR__ . '/includes/header.php';
?>
<?php if ($flash): ?><div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="lf-pagehead">
  <div><div class="lf-ph-title"><i class="fas fa-boxes-stacked"></i> Matériel & stock</div><div class="lf-ph-sub"><?php echo count($items); ?> article(s) — <?php echo count($low); ?> en alerte</div></div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="<?php echo SITE_URL; ?>/pages/gouv" class="lf-btn lf-btn-ghost"><i class="fas fa-landmark"></i> Demande de réappro (Gouv.)</a>
    <button class="lf-btn lf-btn-primary" onclick="document.getElementById('newItemModal').classList.add('open')"><i class="fas fa-plus"></i> Ajouter</button>
  </div>
</div>

<?php if ($low): ?>
<div class="lf-card" style="border-color:rgba(249,115,22,.3)">
  <div class="lf-card-h"><h3 style="color:#fdba74"><i class="fas fa-triangle-exclamation" style="color:#f97316"></i> Alertes de stock bas</h3></div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php foreach ($low as $i): ?><span class="lf-badge orange"><?php echo htmlspecialchars($i['name']); ?> — <?php echo (int)$i['quantity']; ?>/<?php echo (int)$i['threshold']; ?> (<?php echo htmlspecialchars($i['station']?:'—'); ?>)</span><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="lf-card">
  <table class="lf-table">
    <thead><tr><th>Article</th><th>Catégorie</th><th>Caserne</th><th>Quantité</th><th>Seuil</th><th>EPI affecté</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($items)): ?>
        <tr class="lf-empty-row"><td colspan="7"><i class="fas fa-boxes-stacked"></i> Aucun article</td></tr>
      <?php else: foreach ($items as $i):
        $cl=$catLabels[$i['category']]??[ucfirst($i['category']),'gray'];
        $isLow=(int)$i['threshold']>0 && (int)$i['quantity']<=(int)$i['threshold']; ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($i['name']); ?></strong></td>
          <td><span class="lf-badge <?php echo $cl[1]; ?>"><?php echo $cl[0]; ?></span></td>
          <td><?php echo htmlspecialchars($i['station'] ?: '—'); ?></td>
          <td>
            <form method="POST" style="margin:0;display:flex;gap:.3rem;align-items:center"><input type="hidden" name="action" value="qty"><input type="hidden" name="id" value="<?php echo $i['id']; ?>">
              <input class="lf-input" type="number" name="quantity" value="<?php echo (int)$i['quantity']; ?>" min="0" style="width:80px;<?php echo $isLow?'border-color:rgba(249,115,22,.5)':''; ?>" onchange="this.form.submit()">
            </form>
          </td>
          <td><?php echo (int)$i['threshold']; ?></td>
          <td><?php echo $i['holder'] ? htmlspecialchars($i['holder']) : '—'; ?></td>
          <td style="text-align:right"><form method="POST" style="margin:0" data-lsfd-confirm="Supprimer cet article ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $i['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="lf-modal-ov" id="newItemModal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-box"></i> Nouvel article</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="create">
      <div class="lf-modal-b">
        <div class="lf-grid-2">
          <div class="lf-field"><label>Nom</label><input class="lf-input" name="name" required placeholder="Tuyaux, SCBA, combinaison HazMat…"></div>
          <div class="lf-field"><label>Catégorie</label><select name="category" id="itemCat"><option value="consommable">Consommable</option><option value="epi">EPI (nominatif)</option><option value="equipement">Équipement</option></select></div>
        </div>
        <div class="lf-grid-2">
          <div class="lf-field"><label>Caserne</label><input class="lf-input" name="station" placeholder="Station 1 / 2 / 3"></div>
          <div class="lf-field" id="epiAgentField" style="display:none"><label>Agent (EPI)</label>
            <select name="assigned_to" data-search="true"><option value="">— Aucun —</option><?php foreach ($agents as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['rp_name'] ?: 'Profil non renseigné'); ?></option><?php endforeach; ?></select>
          </div>
        </div>
        <div class="lf-grid-2">
          <div class="lf-field"><label>Quantité</label><input class="lf-input" type="number" name="quantity" value="0" min="0"></div>
          <div class="lf-field"><label>Seuil d'alerte</label><input class="lf-input" type="number" name="threshold" value="0" min="0"></div>
        </div>
      </div>
      <div class="lf-modal-f"><button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button><button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Ajouter</button></div>
    </form>
  </div>
</div>

<script>
(function(){
  const cat=document.getElementById('itemCat'); const f=document.getElementById('epiAgentField');
  if(!cat||!f) return;
  function sync(){ f.style.display = cat.value==='epi' ? '' : 'none'; }
  cat.addEventListener('change', sync); sync();
})();
</script>

<?php include __DIR__ . '/includes/footer.php';
