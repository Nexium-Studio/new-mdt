<?php
/**
 * §6 — Enquêtes incendie (Fire Marshal)
 * Dossiers d'enquête liés à un rapport d'intervention. Causes possibles, preuves,
 * suspects (profiles). Si criminel → transmission LSPD (criminal_records/reports) +
 * possibilité de procédure DOJ. Réservé aux agents certifiés Fire Investigator.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$selfUrl = SITE_URL . '/pages/panels/lsfd/enquetes.php';

// Rapports liables
$reports = [];
try { $reports = $db->query("SELECT id, title, incident_id FROM lsfd_reports ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
// Citoyens (suspects)
$citizens = [];
try { $citizens = $db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname, firstname LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $suspects = array_values(array_filter(array_map('intval', explode(',', $_POST['suspects'] ?? ''))));
            $cause = in_array($_POST['cause'] ?? '', ['accidentelle','negligence','criminel','indeterminee'], true) ? $_POST['cause'] : 'indeterminee';
            $st = $db->prepare("INSERT INTO lsfd_fire_investigations (report_id, incident_id, cause, findings, evidence, suspects, status, created_by) VALUES (?,?,?,?,?,?, 'en_cours', ?)");
            $st->execute([(int)($_POST['report_id']??0)?:null, (int)($_POST['incident_id']??0)?:null, $cause,
                trim($_POST['findings']??''), trim($_POST['evidence']??''), json_encode($suspects), $_SESSION['user_id']??null]);
            $iid = (int)$db->lastInsertId();
            lsfd_log('create','investigation',$iid,"Enquête ($cause)");
            $_SESSION['lsfd_flash'] = 'Dossier d\'enquête créé.' . ($cause==='criminel' ? ' Transmission LSPD recommandée.' : '');
            header("Location: $selfUrl"); exit;
        }
        if ($action === 'status') {
            $id=(int)($_POST['id']??0); $s=in_array($_POST['status']??'',['en_cours','ferme','transmis'],true)?$_POST['status']:'en_cours';
            $db->prepare("UPDATE lsfd_fire_investigations SET status=? WHERE id=?")->execute([$s,$id]);
            lsfd_log('status','investigation',$id,"Statut → $s");
            $_SESSION['lsfd_flash']='Statut mis à jour.'; header("Location: $selfUrl"); exit;
        }
        if ($action === 'transmit') {
            $id=(int)($_POST['id']??0);
            $db->prepare("UPDATE lsfd_fire_investigations SET transmitted_lspd=1, status='transmis' WHERE id=?")->execute([$id]);
            lsfd_log('transmit','investigation',$id,'Transmis au LSPD');
            $_SESSION['lsfd_flash']='Dossier transmis au LSPD (périmètre judiciaire).'; header("Location: $selfUrl"); exit;
        }
        if ($action === 'delete') {
            $id=(int)($_POST['id']??0); $db->prepare("DELETE FROM lsfd_fire_investigations WHERE id=?")->execute([$id]);
            lsfd_log('delete','investigation',$id,'Enquête supprimée');
            $_SESSION['lsfd_flash']='Dossier supprimé.'; header("Location: $selfUrl"); exit;
        }
    } catch (Exception $e) { $_SESSION['lsfd_flash']="Erreur (migration ?) : ".$e->getMessage(); header("Location: $selfUrl"); exit; }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash=$_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$rows = [];
try { $rows = $db->query("SELECT fi.*, r.title AS report_title FROM lsfd_fire_investigations fi LEFT JOIN lsfd_reports r ON r.id=fi.report_id ORDER BY fi.created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$causeLabels = ['accidentelle'=>['Accidentelle','blue'],'negligence'=>['Négligence','yellow'],'criminel'=>['Criminel','red'],'indeterminee'=>['Indéterminée','gray']];
$statusLabels = ['en_cours'=>['En cours','orange'],'ferme'=>['Fermé','green'],'transmis'=>['Transmis','purple']];
$citName = function($id) use ($citizens){ foreach($citizens as $c) if((int)$c['id']===(int)$id) return trim(($c['firstname']??'').' '.($c['lastname']??'')); return 'Profil #'.$id; };

$pageTitle = 'Enquêtes incendie';
include __DIR__ . '/includes/header.php';
?>
<?php if ($flash): ?><div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="lf-pagehead">
  <div>
    <div class="lf-ph-title"><i class="fas fa-magnifying-glass-chart"></i> Enquêtes incendie</div>
    <div class="lf-ph-sub">Fire Marshal — réservé aux agents certifiés <em>Fire Investigator</em></div>
  </div>
  <button class="lf-btn lf-btn-primary" onclick="document.getElementById('newInvModal').classList.add('open')"><i class="fas fa-folder-plus"></i> Nouveau dossier</button>
</div>

<div class="lf-card">
  <table class="lf-table">
    <thead><tr><th>Rapport lié</th><th>Cause</th><th>Suspects</th><th>Statut</th><th>Date</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr class="lf-empty-row"><td colspan="6"><i class="fas fa-folder-open"></i> Aucun dossier d'enquête</td></tr>
      <?php else: foreach ($rows as $r):
        $cz = $causeLabels[$r['cause']] ?? [ucfirst($r['cause']),'gray'];
        $stt = $statusLabels[$r['status']] ?? [ucfirst($r['status']),'gray'];
        $susp = json_decode($r['suspects'] ?? '[]', true) ?: []; ?>
        <tr>
          <td><?php echo htmlspecialchars($r['report_title'] ?: ($r['incident_id'] ? 'Incident #'.$r['incident_id'] : '—')); ?></td>
          <td><span class="lf-badge <?php echo $cz[1]; ?>"><?php echo htmlspecialchars($cz[0]); ?></span></td>
          <td><?php echo $susp ? count($susp).' suspect(s)' : '—'; ?></td>
          <td><span class="lf-badge <?php echo $stt[1]; ?>"><?php echo htmlspecialchars($stt[0]); ?></span><?php echo $r['transmitted_lspd'] ? ' <span class="lf-badge purple" style="font-size:.6rem">LSPD</span>' : ''; ?></td>
          <td style="color:#64748b;font-size:.78rem"><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if ($r['cause']==='criminel' && !$r['transmitted_lspd']): ?>
              <form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Transmettre ce dossier au LSPD ?" data-lsfd-confirm-danger="false"><input type="hidden" name="action" value="transmit"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><button class="lf-btn lf-btn-ghost lf-btn-sm" title="Transmettre LSPD"><i class="fas fa-share"></i></button></form>
            <?php endif; ?>
            <form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Supprimer ce dossier ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="lf-modal-ov" id="newInvModal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-magnifying-glass-chart"></i> Nouveau dossier d'enquête</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="create"><input type="hidden" name="suspects" id="invSuspects">
      <div class="lf-modal-b">
        <div class="lf-grid-2">
          <div class="lf-field"><label>Rapport d'intervention lié</label>
            <select name="report_id" data-search="true"><option value="">— Aucun —</option>
              <?php foreach ($reports as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['title'] ?: '#'.$r['id']); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="lf-field"><label>Cause probable</label>
            <select name="cause"><option value="indeterminee">Indéterminée</option><option value="accidentelle">Accidentelle</option><option value="negligence">Négligence</option><option value="criminel">Incendie volontaire (criminel)</option></select>
          </div>
        </div>
        <div class="lf-field"><label>Constatations</label><textarea class="lf-textarea" name="findings"></textarea></div>
        <div class="lf-field"><label>Preuves (descriptions, photos, témoignages)</label><textarea class="lf-textarea" name="evidence"></textarea></div>
        <div class="lf-field"><label>Suspects identifiés</label>
          <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
            <button type="button" class="lf-btn lf-btn-ghost lf-btn-sm" id="invSuspBtn"><i class="fas fa-user-plus"></i> Ajouter</button>
            <span id="invSuspChips" style="display:flex;gap:.4rem;flex-wrap:wrap"></span>
          </div>
        </div>
      </div>
      <div class="lf-modal-f">
        <button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button>
        <button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Créer le dossier</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const CIT = <?php echo json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>trim(($c['firstname']??'').' '.($c['lastname']??'')) ?: ('Profil #'.$c['id']),'avatar'=>$c['avatar']??'']; }, $citizens), JSON_UNESCAPED_UNICODE); ?>;
  let selected = [];
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  function render(){
    document.getElementById('invSuspects').value = selected.map(x=>x.id).join(',');
    document.getElementById('invSuspChips').innerHTML = selected.map(x=>`<span class="lp-field-chip">${esc(x.name)}<span class="x" data-id="${x.id}"><i class="fas fa-times"></i></span></span>`).join('');
    document.querySelectorAll('#invSuspChips .x').forEach(el=>el.onclick=()=>{selected=selected.filter(s=>String(s.id)!==el.dataset.id);render();});
  }
  const btn=document.getElementById('invSuspBtn');
  if(btn) btn.addEventListener('click',()=>LsfdPicker.open({title:'Suspects',multiple:true,selected:selected.map(s=>s.id),items:CIT,onConfirm:(ch)=>{selected=ch.map(c=>({id:c.id,name:c.name}));render();}}));
})();
</script>

<?php include __DIR__ . '/includes/footer.php';
