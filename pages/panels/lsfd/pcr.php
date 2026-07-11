<?php
/**
 * §8 — Dossiers médicaux préhospitaliers (PCR — Patient Care Report)
 * Partie préhospitalière gérée par les pompiers-paramedics. Transmission au SAMS
 * à la livraison hôpital (flag). Patient lié au profil citoyen partagé (profiles).
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$selfUrl = SITE_URL . '/pages/panels/lsfd/pcr.php';

$citizens = [];
try { $citizens = $db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname, firstname LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
$incidents = [];
try { $incidents = $db->query("SELECT id, ref, type FROM lsfd_incidents ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $outcome = in_array($_POST['outcome']??'',['stabilise','decede','refus_transport'],true)?$_POST['outcome']:'stabilise';
            $db->prepare("INSERT INTO lsfd_pcr (incident_id, patient_id, arrival_state, initial_assessment, actions, medications, evolution, outcome, transmitted_sams, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([(int)($_POST['incident_id']??0)?:null, (int)($_POST['patient_id']??0)?:null,
                trim($_POST['arrival_state']??''), trim($_POST['initial_assessment']??''), trim($_POST['actions']??''),
                trim($_POST['medications']??''), trim($_POST['evolution']??''), $outcome, (int)!empty($_POST['transmit']), $_SESSION['user_id']??null]);
            $pid=(int)$db->lastInsertId();
            lsfd_log('create','pcr',$pid,"PCR ($outcome)");
            $_SESSION['lsfd_flash']='PCR enregistré.' . (!empty($_POST['transmit']) ? ' Transmis au SAMS (dossier pré-rempli).' : '');
            header("Location: $selfUrl"); exit;
        }
        if ($action === 'transmit') { $db->prepare("UPDATE lsfd_pcr SET transmitted_sams=1 WHERE id=?")->execute([(int)($_POST['id']??0)]); lsfd_log('transmit','pcr',(int)($_POST['id']??0),'Transmis SAMS'); $_SESSION['lsfd_flash']='PCR transmis au SAMS.'; header("Location: $selfUrl"); exit; }
        if ($action === 'delete') { $db->prepare("DELETE FROM lsfd_pcr WHERE id=?")->execute([(int)($_POST['id']??0)]); $_SESSION['lsfd_flash']='PCR supprimé.'; header("Location: $selfUrl"); exit; }
    } catch (Exception $e) { $_SESSION['lsfd_flash']="Erreur (migration ?) : ".$e->getMessage(); header("Location: $selfUrl"); exit; }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash=$_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$rows = [];
try { $rows = $db->query("SELECT pcr.*, i.ref AS inc_ref,
    NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS patient_name
    FROM lsfd_pcr pcr LEFT JOIN lsfd_incidents i ON i.id=pcr.incident_id LEFT JOIN profiles p ON p.id=pcr.patient_id
    ORDER BY pcr.created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$outcomeLabels = ['stabilise'=>['Stabilisé','green'],'decede'=>['Décédé','red'],'refus_transport'=>['Refus transport','gray']];

$pageTitle = 'Dossiers PCR';
include __DIR__ . '/includes/header.php';
?>
<?php if ($flash): ?><div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="lf-pagehead">
  <div><div class="lf-ph-title"><i class="fas fa-notes-medical"></i> Dossiers PCR</div><div class="lf-ph-sub">Préhospitalier — <?php echo count($rows); ?> dossier(s)</div></div>
  <button class="lf-btn lf-btn-primary" onclick="document.getElementById('newPcrModal').classList.add('open')"><i class="fas fa-plus"></i> Nouveau PCR</button>
</div>

<div class="lf-card">
  <table class="lf-table">
    <thead><tr><th>Patient</th><th>Incident</th><th>Issue</th><th>SAMS</th><th>Date</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr class="lf-empty-row"><td colspan="6"><i class="fas fa-notes-medical"></i> Aucun PCR</td></tr>
      <?php else: foreach ($rows as $r):
        $oc=$outcomeLabels[$r['outcome']]??[ucfirst($r['outcome']),'gray']; ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($r['patient_name'] ?: 'Profil non renseigné'); ?></strong></td>
          <td><?php echo htmlspecialchars($r['inc_ref'] ?: '—'); ?></td>
          <td><span class="lf-badge <?php echo $oc[1]; ?>"><?php echo $oc[0]; ?></span></td>
          <td><?php echo $r['transmitted_sams'] ? '<span class="lf-badge green"><i class="fas fa-check"></i> Transmis</span>' : '<span class="lf-badge gray">Non transmis</span>'; ?></td>
          <td style="color:#64748b;font-size:.78rem"><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if (!$r['transmitted_sams']): ?><form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Transmettre ce PCR au SAMS ?" data-lsfd-confirm-danger="false"><input type="hidden" name="action" value="transmit"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><button class="lf-btn lf-btn-ghost lf-btn-sm" title="Transmettre SAMS"><i class="fas fa-share"></i></button></form><?php endif; ?>
            <form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Supprimer ce PCR ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="lf-modal-ov" id="newPcrModal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-notes-medical"></i> Nouveau PCR</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="create"><input type="hidden" name="patient_id" id="pcrPatient">
      <div class="lf-modal-b">
        <div class="lf-grid-2">
          <div class="lf-field"><label>Patient</label>
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
              <button type="button" class="lf-btn lf-btn-ghost lf-btn-sm" id="pcrPatientBtn"><i class="fas fa-user-plus"></i> Sélectionner</button>
              <span id="pcrPatientChip"></span>
            </div>
          </div>
          <div class="lf-field"><label>Incident lié</label>
            <select name="incident_id" data-search="true"><option value="">— Aucun —</option><?php foreach ($incidents as $i): ?><option value="<?php echo $i['id']; ?>"><?php echo htmlspecialchars(($i['ref']?:'#'.$i['id']).' · '.$i['type']); ?></option><?php endforeach; ?></select>
          </div>
        </div>
        <div class="lf-field"><label>État à l'arrivée</label><textarea class="lf-textarea" name="arrival_state"></textarea></div>
        <div class="lf-grid-2">
          <div class="lf-field"><label>Bilan initial</label><textarea class="lf-textarea" name="initial_assessment"></textarea></div>
          <div class="lf-field"><label>Gestes effectués</label><textarea class="lf-textarea" name="actions"></textarea></div>
        </div>
        <div class="lf-grid-2">
          <div class="lf-field"><label>Médicaments administrés</label><textarea class="lf-textarea" name="medications"></textarea></div>
          <div class="lf-field"><label>Évolution</label><textarea class="lf-textarea" name="evolution"></textarea></div>
        </div>
        <div class="lf-field"><label>Issue</label><select name="outcome"><option value="stabilise">Stabilisé</option><option value="decede">Décédé</option><option value="refus_transport">Refus de transport</option></select></div>
        <input type="hidden" name="transmit" id="pcrTransmit">
        <span class="lsfd-switch" data-target="pcrTransmit"><span class="sw-track"></span><span class="sw-lbl">Patient livré à l'hôpital — transmettre au SAMS</span></span>
      </div>
      <div class="lf-modal-f"><button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button><button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
    </form>
  </div>
</div>

<script>
(function(){
  const CIT = <?php echo json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>trim(($c['firstname']??'').' '.($c['lastname']??'')) ?: ('Profil #'.$c['id']),'avatar'=>$c['avatar']??'']; }, $citizens), JSON_UNESCAPED_UNICODE); ?>;
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  const btn=document.getElementById('pcrPatientBtn');
  if(btn) btn.addEventListener('click',()=>LsfdPicker.open({title:'Patient',items:CIT,onConfirm:(it)=>{
    if(!it) return; document.getElementById('pcrPatient').value=it.id;
    document.getElementById('pcrPatientChip').innerHTML='<span class="lp-field-chip">'+esc(it.name)+'</span>';
  }}));
})();
</script>

<?php include __DIR__ . '/includes/footer.php';
