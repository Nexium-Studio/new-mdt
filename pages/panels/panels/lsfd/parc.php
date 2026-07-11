<?php
/**
 * §7 — Parc auto / appareils LSFD
 * Inventaire des appareils, prise/libération de véhicule en service, déclaration
 * et suivi de maintenance.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$selfUrl = SITE_URL . '/pages/panels/lsfd/parc.php';
$me = $_SESSION['user_id'] ?? null;

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $type = in_array($_POST['type']??'',['pompe','echelle','ambulance','hazmat','commandement'],true)?$_POST['type']:'pompe';
            $db->prepare("INSERT INTO lsfd_apparatus (plate, name, type, station, status, mileage) VALUES (?,?,?,?, 'service', ?)")
               ->execute([trim($_POST['plate']??''), trim($_POST['name']??''), $type, trim($_POST['station']??''), (int)($_POST['mileage']??0) ?: null]);
            lsfd_log('create','apparatus',(int)$db->lastInsertId(),'Appareil ajouté');
            $_SESSION['lsfd_flash']='Appareil ajouté.'; header("Location: $selfUrl"); exit;
        }
        if ($action === 'take') { $db->prepare("UPDATE lsfd_apparatus SET assigned_to=? WHERE id=?")->execute([$me,(int)($_POST['id']??0)]); lsfd_log('take','apparatus',(int)($_POST['id']??0),'Prise de véhicule'); $_SESSION['lsfd_flash']='Véhicule pris en service.'; header("Location: $selfUrl"); exit; }
        if ($action === 'release') { $db->prepare("UPDATE lsfd_apparatus SET assigned_to=NULL WHERE id=?")->execute([(int)($_POST['id']??0)]); $_SESSION['lsfd_flash']='Véhicule libéré.'; header("Location: $selfUrl"); exit; }
        if ($action === 'astatus') { $s=in_array($_POST['status']??'',['service','maintenance','hors_service'],true)?$_POST['status']:'service'; $db->prepare("UPDATE lsfd_apparatus SET status=? WHERE id=?")->execute([$s,(int)($_POST['id']??0)]); header("Location: $selfUrl"); exit; }
        if ($action === 'maint') {
            $aid=(int)($_POST['apparatus_id']??0);
            $kind=in_array($_POST['kind']??'',['panne','maintenance','controle'],true)?$_POST['kind']:'panne';
            $db->prepare("INSERT INTO lsfd_apparatus_maintenance (apparatus_id, kind, description, status, created_by) VALUES (?,?,?, 'ouvert', ?)")
               ->execute([$aid,$kind,trim($_POST['description']??''),$me]);
            if ($kind==='panne') $db->prepare("UPDATE lsfd_apparatus SET status='maintenance' WHERE id=?")->execute([$aid]);
            lsfd_log('maint','apparatus',$aid,"Maintenance: $kind");
            $_SESSION['lsfd_flash']='Déclaration enregistrée.'; header("Location: $selfUrl"); exit;
        }
        if ($action === 'maint_done') { $db->prepare("UPDATE lsfd_apparatus_maintenance SET status='termine' WHERE id=?")->execute([(int)($_POST['mid']??0)]); $_SESSION['lsfd_flash']='Maintenance clôturée.'; header("Location: $selfUrl"); exit; }
        if ($action === 'delete') { $id=(int)($_POST['id']??0); $db->prepare("DELETE FROM lsfd_apparatus WHERE id=?")->execute([$id]); $db->prepare("DELETE FROM lsfd_apparatus_maintenance WHERE apparatus_id=?")->execute([$id]); $_SESSION['lsfd_flash']='Appareil supprimé.'; header("Location: $selfUrl"); exit; }
    } catch (Exception $e) { $_SESSION['lsfd_flash']="Erreur (migration ?) : ".$e->getMessage(); header("Location: $selfUrl"); exit; }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash=$_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$apparatus = [];
try { $apparatus = $db->query("SELECT a.*, NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS holder
    FROM lsfd_apparatus a LEFT JOIN users u ON u.id=a.assigned_to LEFT JOIN profiles p ON p.id=u.profile ORDER BY a.station, a.name")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
$maint = [];
try { $maint = $db->query("SELECT m.*, a.name AS app_name FROM lsfd_apparatus_maintenance m LEFT JOIN lsfd_apparatus a ON a.id=m.apparatus_id WHERE m.status!='termine' ORDER BY m.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$typeLabels = ['pompe'=>'Pompe','echelle'=>'Échelle','ambulance'=>'Ambulance','hazmat'=>'HazMat','commandement'=>'Commandement'];
$statusLabels = ['service'=>['En service','green'],'maintenance'=>['Maintenance','yellow'],'hors_service'=>['Hors service','red']];

$pageTitle = 'Parc auto';
include __DIR__ . '/includes/header.php';
?>
<?php if ($flash): ?><div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="lf-pagehead">
  <div><div class="lf-ph-title"><i class="fas fa-truck-medical"></i> Parc auto</div><div class="lf-ph-sub"><?php echo count($apparatus); ?> appareil(s)</div></div>
  <button class="lf-btn lf-btn-primary" onclick="document.getElementById('newAppModal').classList.add('open')"><i class="fas fa-plus"></i> Ajouter un appareil</button>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;align-items:start">
  <div class="lf-card">
    <div class="lf-card-h"><h3><i class="fas fa-truck"></i> Appareils</h3></div>
    <table class="lf-table">
      <thead><tr><th>Appareil</th><th>Type</th><th>Caserne</th><th>Statut</th><th>Affecté à</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($apparatus)): ?>
          <tr class="lf-empty-row"><td colspan="6"><i class="fas fa-truck"></i> Aucun appareil</td></tr>
        <?php else: foreach ($apparatus as $a):
          $stt=$statusLabels[$a['status']]??[ucfirst($a['status']),'gray']; ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($a['name'] ?: '—'); ?></strong><br><span style="color:#64748b;font-size:.75rem"><?php echo htmlspecialchars($a['plate'] ?: ''); ?></span></td>
            <td><?php echo htmlspecialchars($typeLabels[$a['type']] ?? $a['type']); ?></td>
            <td><?php echo htmlspecialchars($a['station'] ?: '—'); ?></td>
            <td>
              <form method="POST" style="margin:0"><input type="hidden" name="action" value="astatus"><input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                <select name="status" onchange="this.form.submit()" style="max-width:150px">
                  <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $a['status']===$k?'selected':''; ?>><?php echo $v[0]; ?></option><?php endforeach; ?>
                </select>
              </form>
            </td>
            <td><?php echo $a['holder'] ? htmlspecialchars($a['holder']) : '<span style="color:#64748b">Libre</span>'; ?></td>
            <td style="text-align:right;white-space:nowrap">
              <?php if ($a['assigned_to']): ?>
                <form method="POST" style="display:inline;margin:0"><input type="hidden" name="action" value="release"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button class="lf-btn lf-btn-ghost lf-btn-sm" title="Libérer"><i class="fas fa-arrow-right-from-bracket"></i></button></form>
              <?php else: ?>
                <form method="POST" style="display:inline;margin:0"><input type="hidden" name="action" value="take"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button class="lf-btn lf-btn-ghost lf-btn-sm" title="Prendre"><i class="fas fa-hand"></i></button></form>
              <?php endif; ?>
              <button class="lf-btn lf-btn-ghost lf-btn-sm" title="Maintenance" onclick="openMaint(<?php echo $a['id']; ?>,'<?php echo addslashes($a['name']); ?>')"><i class="fas fa-screwdriver-wrench"></i></button>
              <form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Supprimer cet appareil ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="lf-card">
    <div class="lf-card-h"><h3><i class="fas fa-screwdriver-wrench"></i> Maintenance en cours</h3></div>
    <?php if (empty($maint)): ?><div class="lf-empty" style="padding:1.5rem"><i class="fas fa-check-circle"></i><p>Aucune maintenance</p></div>
    <?php else: foreach ($maint as $m): ?>
      <div style="padding:.7rem 0;border-bottom:1px solid rgba(100,116,139,.08)">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-weight:600;color:#e2e8f0"><?php echo htmlspecialchars($m['app_name'] ?: '—'); ?></span>
          <span class="lf-badge <?php echo $m['kind']==='panne'?'red':'yellow'; ?>"><?php echo htmlspecialchars(ucfirst($m['kind'])); ?></span>
        </div>
        <div style="color:#94a3b8;font-size:.82rem;margin:.25rem 0"><?php echo htmlspecialchars($m['description'] ?: ''); ?></div>
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="maint_done"><input type="hidden" name="mid" value="<?php echo $m['id']; ?>"><button class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-check"></i> Clôturer</button></form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Modal ajout appareil -->
<div class="lf-modal-ov" id="newAppModal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-truck"></i> Nouvel appareil</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="create">
      <div class="lf-modal-b">
        <div class="lf-grid-2">
          <div class="lf-field"><label>Nom</label><input class="lf-input" name="name" required placeholder="Ex: Pompe 1"></div>
          <div class="lf-field"><label>Immatriculation</label><input class="lf-input" name="plate"></div>
        </div>
        <div class="lf-grid-2">
          <div class="lf-field"><label>Type</label><select name="type"><option value="pompe">Pompe</option><option value="echelle">Échelle</option><option value="ambulance">Ambulance</option><option value="hazmat">HazMat</option><option value="commandement">Commandement</option></select></div>
          <div class="lf-field"><label>Caserne</label><input class="lf-input" name="station" placeholder="Station 1 / 2 / 3"></div>
        </div>
        <div class="lf-field"><label>Kilométrage</label><input class="lf-input" type="number" name="mileage" min="0"></div>
      </div>
      <div class="lf-modal-f"><button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button><button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Ajouter</button></div>
    </form>
  </div>
</div>

<!-- Modal maintenance -->
<div class="lf-modal-ov" id="maintModal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-screwdriver-wrench"></i> Maintenance — <span id="maintAppName"></span></h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="maint"><input type="hidden" name="apparatus_id" id="maintAppId">
      <div class="lf-modal-b">
        <div class="lf-field"><label>Type</label><select name="kind"><option value="panne">Panne (met hors service)</option><option value="maintenance">Maintenance planifiée</option><option value="controle">Contrôle</option></select></div>
        <div class="lf-field"><label>Description</label><textarea class="lf-textarea" name="description" required></textarea></div>
      </div>
      <div class="lf-modal-f"><button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button><button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Déclarer</button></div>
    </form>
  </div>
</div>

<script>
function openMaint(id,name){ document.getElementById('maintAppId').value=id; document.getElementById('maintAppName').textContent=name; document.getElementById('maintModal').classList.add('open'); }
</script>

<?php include __DIR__ . '/includes/footer.php';
