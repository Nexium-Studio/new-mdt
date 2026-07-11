<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Réhabilitation"; $__secLabel = "Justice & Service Public"; $__secColor = '#b45309'; $__secIcon = 'fas fa-person-circle-check';
$db = Database::getInstance()->getConnection(); $msg=''; $msgType='';
$db->exec("CREATE TABLE IF NOT EXISTS `gouv_rehabilitation` (
  `id` int AUTO_INCREMENT PRIMARY KEY, `reference` varchar(80) UNIQUE,
  `citoyen_nom` varchar(150) NOT NULL, `citoyen_faction` varchar(80),
  `type` enum('casier_judiciaire','amende','interdiction','emploi','autre') DEFAULT 'casier_judiciaire',
  `description` text, `justification` text,
  `statut` enum('demande','etude','acceptee','refusee') DEFAULT 'demande',
  `instruit_par` varchar(150), `decision` text, `date_decision` date,
  `conditions` text, `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='create_rehab') {
        $year=date('Y'); $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_rehabilitation WHERE YEAR(created_at)=$year")->fetchColumn();
        $ref=sprintf("REHAB-%d-%04d",$year,$seq);
        $db->prepare("INSERT INTO gouv_rehabilitation (reference,citoyen_nom,citoyen_faction,type,description,justification,created_by) VALUES (?,?,?,?,?,?,?)")
           ->execute([$ref,trim($_POST['citoyen_nom']),trim($_POST['citoyen_faction']??''),$_POST['type'],trim($_POST['description']??''),trim($_POST['justification']??''),$_SESSION['user_id']]);
        $msg="Demande $ref créée."; $msgType='ok';
    }
    if($action==='update_rehab') {
        $db->prepare("UPDATE gouv_rehabilitation SET statut=?,instruit_par=?,decision=?,date_decision=?,conditions=? WHERE id=?")
           ->execute([$_POST['statut'],trim($_POST['instruit_par']??''),trim($_POST['decision']??''),$_POST['date_decision']?:null,trim($_POST['conditions']??''),(int)$_POST['rehab_id']]);
        $msg='Mise à jour effectuée.'; $msgType='ok';
    }
    if($msg){header('Location: sec_rehabilitation.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}
$rehabs=[];try{$rehabs=$db->query("SELECT * FROM gouv_rehabilitation ORDER BY FIELD(statut,'demande','etude','acceptee','refusee'),created_at DESC LIMIT 50")->fetchAll();}catch(Exception $e){}
$statsR=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_rehabilitation GROUP BY statut")->fetchAll() as $r)$statsR[$r['statut']]=$r['n'];}catch(Exception $e){}
$statCfg=['demande'=>['bg-blue','Demande'],'etude'=>['bg-yellow','Étude'],'acceptee'=>['bg-green','Acceptée'],'refusee'=>['bg-red','Refusée']];
$typeCfg=['casier_judiciaire'=>'Casier judiciaire','amende'=>'Amende','interdiction'=>'Interdiction','emploi'=>'Emploi','autre'=>'Autre'];
include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>
<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(180,83,9,.12);color:#fde68a"><i class="fas fa-person-circle-check"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val amber"><?php echo array_sum($statsR);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-clock"></i></div><div class="kpi-lbl">En cours</div><div class="kpi-val gold"><?php echo ($statsR['demande']??0)+($statsR['etude']??0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-check"></i></div><div class="kpi-lbl">Acceptées</div><div class="kpi-val green"><?php echo $statsR['acceptee']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-times"></i></div><div class="kpi-lbl">Refusées</div><div class="kpi-val red"><?php echo $statsR['refusee']??0;?></div></div>
</div>
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem"><button onclick="Gov.openModal('newRehabModal')" class="btn btn-warning btn-sm"><i class="fas fa-plus"></i> Nouvelle Demande</button></div>
<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Référence</th><th>Citoyen</th><th>Type</th><th>Instruit par</th><th>Date décision</th><th>Statut</th><th></th></tr></thead>
<tbody>
<?php if(empty($rehabs)):?><tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-person-circle-check" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucune demande</td></tr>
<?php else: foreach($rehabs as $r):[$sb,$sl]=$statCfg[$r['statut']]??['bg-gray','—'];?>
<tr>
  <td><span class="mono" style="font-size:.73rem;color:var(--gov-l)"><?php echo $r['reference'];?></span></td>
  <td><div style="font-weight:600;font-size:.83rem"><?php echo htmlspecialchars($r['citoyen_nom']);?></div><?php if($r['citoyen_faction']):?><div style="font-size:.68rem;color:var(--txt-m)"><?php echo $r['citoyen_faction'];?></div><?php endif;?></td>
  <td><span class="badge bg-amber" style="font-size:.58rem"><?php echo $typeCfg[$r['type']]??'—';?></span></td>
  <td style="font-size:.8rem"><?php echo htmlspecialchars($r['instruit_par']??'—');?></td>
  <td class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo $r['date_decision']?date('d/m/Y',strtotime($r['date_decision'])):'—';?></td>
  <td><span class="badge <?php echo $sb;?>"><?php echo $sl;?></span></td>
  <td><button class="btn btn-ghost btn-xs" onclick='openRehabEdit(<?php echo json_encode($r,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE);?>)'><i class="fas fa-pen"></i></button></td>
</tr>
<?php endforeach; endif;?>
</tbody></table></div>

<div id="newRehabModal" class="modal-ov hidden"><div class="modal"><div class="modal-hd"><h2><i class="fas fa-person-circle-check"></i> Nouvelle Demande</h2><button class="modal-cl" onclick="Gov.closeModal('newRehabModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_rehab">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Citoyen *</label><input type="text" name="citoyen_nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Faction</label><input type="text" name="citoyen_faction" class="form-input"></div>
    <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-select"><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
    <div class="form-group form-full"><label class="form-label">Description de la situation</label><textarea name="description" class="form-textarea"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Justification de la demande</label><textarea name="justification" class="form-textarea" style="min-height:90px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newRehabModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Soumettre</button></div>
</form></div></div>

<div id="editRehabModal" class="modal-ov hidden"><div class="modal"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Traiter la Demande</h2><button class="modal-cl" onclick="Gov.closeModal('editRehabModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update_rehab"><input type="hidden" name="rehab_id" id="erh-id">
  <div id="erh-cit" style="font-weight:600;font-size:.85rem;margin-bottom:.85rem"></div>
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Statut</label><select name="statut" id="erh-stat" class="form-select"><?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Instruit par</label><input type="text" name="instruit_par" id="erh-inst" class="form-input"></div>
    <div class="form-group"><label class="form-label">Date de décision</label><input type="date" name="date_decision" id="erh-dd" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Décision</label><textarea name="decision" id="erh-dec" class="form-textarea" style="min-height:80px"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Conditions</label><textarea name="conditions" id="erh-cond" class="form-textarea" style="min-height:65px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('editRehabModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<script>
function openRehabEdit(r){document.getElementById('erh-id').value=r.id;document.getElementById('erh-cit').textContent=r.citoyen_nom+' — '+r.reference;document.getElementById('erh-stat').value=r.statut;document.getElementById('erh-inst').value=r.instruit_par||'';document.getElementById('erh-dd').value=r.date_decision||'';document.getElementById('erh-dec').value=r.decision||'';document.getElementById('erh-cond').value=r.conditions||'';Gov.openModal('editRehabModal');}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>