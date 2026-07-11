<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Recours & Appels"; $__secLabel = "Secrétariat à la Justice"; $__secColor = '#b45309'; $__secIcon = 'fas fa-balance-scale';
$db = Database::getInstance()->getConnection(); $msg=''; $msgType='';

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_recours` (
  `id` int AUTO_INCREMENT PRIMARY KEY, `reference` varchar(80) UNIQUE,
  `type` enum('amende','licences','decision_administrative','licenciement','autre') DEFAULT 'autre',
  `demandeur` varchar(150) NOT NULL, `demandeur_faction` varchar(80),
  `objet` varchar(250) NOT NULL, `motifs` text, `pieces_jointes` text,
  `statut` enum('depose','en_instruction','audience','accepte','rejete','partiellement_accepte') DEFAULT 'depose',
  `instruction_par` varchar(150), `decision` text, `date_decision` date,
  `montant_initial` decimal(12,2) DEFAULT 0, `montant_final` decimal(12,2) DEFAULT 0,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='create_recours') {
        try {
            $year=date('Y'); $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_recours WHERE YEAR(created_at)=$year")->fetchColumn();
            $ref=sprintf("REC-%d-%04d",$year,$seq);
            $db->prepare("INSERT INTO gouv_recours (reference,type,demandeur,demandeur_faction,objet,motifs,montant_initial,created_by) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$ref,$_POST['type'],trim($_POST['demandeur']),trim($_POST['demandeur_faction']??''),trim($_POST['objet']),trim($_POST['motifs']??''),(float)($_POST['montant_initial']??0),$_SESSION['user_id']]);
            $msg="Recours $ref enregistré."; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }
    if($action==='update_recours') {
        $id=(int)$_POST['recours_id'];
        try {
            $db->prepare("UPDATE gouv_recours SET statut=?,instruction_par=?,decision=?,date_decision=?,montant_final=? WHERE id=?")
               ->execute([$_POST['statut'],trim($_POST['instruction_par']??''),trim($_POST['decision']??''),$_POST['date_decision']?:null,(float)($_POST['montant_final']??0),$id]);
            $msg='Recours mis à jour.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }
    if($msg){header('Location: sec_recours.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}

$fStat=$_GET['stat']??''; $search=$_GET['q']??'';
$where=['1=1'];$params=[];
if($search){$where[]="(r.reference LIKE ? OR r.demandeur LIKE ? OR r.objet LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;$params[]=$s;}
if($fStat){$where[]="r.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$recours=[];try{$s=$db->prepare("SELECT * FROM gouv_recours r $wStr ORDER BY r.created_at DESC");$s->execute($params);$recours=$s->fetchAll();}catch(Exception $e){}
$statsR=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_recours GROUP BY statut")->fetchAll() as $r)$statsR[$r['statut']]=$r['n'];}catch(Exception $e){}

$statCfg=['depose'=>['bg-blue','Déposé'],'en_instruction'=>['bg-yellow','Instruction'],'audience'=>['bg-yellow','Audience'],'accepte'=>['bg-green','Accepté'],'rejete'=>['bg-red','Rejeté'],'partiellement_accepte'=>['bg-yellow','Partial']];
$typeCfg=['amende'=>'Amende','licences'=>'Licence','decision_administrative'=>'Déc. administrative','licenciement'=>'Licenciement','autre'=>'Autre'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>
<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(180,83,9,.12);color:#fde68a"><i class="fas fa-reply"></i></div><div class="kpi-lbl">Total Recours</div><div class="kpi-val amber"><?php echo array_sum($statsR);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-clock"></i></div><div class="kpi-lbl">En Cours</div><div class="kpi-val gold"><?php echo ($statsR['depose']??0)+($statsR['en_instruction']??0)+($statsR['audience']??0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Acceptés</div><div class="kpi-val green"><?php echo ($statsR['accepte']??0)+($statsR['partiellement_accepte']??0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-ban"></i></div><div class="kpi-lbl">Rejetés</div><div class="kpi-val red"><?php echo $statsR['rejete']??0;?></div></div>
</div>
<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Référence, demandeur…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="stat" class="form-select" style="width:140px" onchange="this.form.submit()"><option value="">Tous statuts</option><?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fStat):?><a href="sec_recours.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="Gov.openModal('newRecoursModal')" class="btn btn-warning btn-sm"><i class="fas fa-plus"></i> Nouveau Recours</button>
</div>
<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Référence</th><th>Type</th><th>Demandeur</th><th>Objet</th><th>Montant initial</th><th>Montant final</th><th>Statut</th><th>Actions</th></tr></thead>
<tbody>
<?php if(empty($recours)):?><tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-reply" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun recours</td></tr>
<?php else: foreach($recours as $r):[$sb,$sl]=$statCfg[$r['statut']]??['bg-gray','—'];?>
<tr>
  <td><span class="mono" style="font-size:.73rem;color:var(--gov-l)"><?php echo $r['reference'];?></span></td>
  <td><span class="badge bg-amber" style="font-size:.58rem"><?php echo $typeCfg[$r['type']]??'—';?></span></td>
  <td><div style="font-weight:600;font-size:.83rem"><?php echo htmlspecialchars($r['demandeur']);?></div><?php if($r['demandeur_faction']):?><div style="font-size:.68rem;color:var(--txt-m)"><?php echo $r['demandeur_faction'];?></div><?php endif;?></td>
  <td style="font-size:.8rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($r['objet']);?></td>
  <td class="mono" style="font-size:.78rem">$<?php echo number_format($r['montant_initial'],0);?></td>
  <td class="mono" style="font-size:.78rem;color:<?php echo $r['montant_final']>0?'#4ade80':'var(--txt-m)';?>">$<?php echo number_format($r['montant_final'],0);?></td>
  <td><span class="badge <?php echo $sb;?>"><?php echo $sl;?></span></td>
  <td><button class="btn btn-ghost btn-xs" onclick='openRecoursEdit(<?php echo json_encode($r,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE);?>)'><i class="fas fa-pen"></i></button></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>

<!-- MODAL CRÉER -->
<div id="newRecoursModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-reply"></i> Nouveau Recours</h2><button class="modal-cl" onclick="Gov.closeModal('newRecoursModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_recours">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-select"><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Demandeur *</label><input type="text" name="demandeur" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Faction</label><input type="text" name="demandeur_faction" class="form-input"></div>
    <div class="form-group"><label class="form-label">Montant initial ($)</label><input type="number" name="montant_initial" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group form-full"><label class="form-label">Objet du recours *</label><input type="text" name="objet" class="form-input" required></div>
    <div class="form-group form-full"><label class="form-label">Motifs</label><textarea name="motifs" class="form-textarea" style="min-height:90px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newRecoursModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
</form></div></div>

<!-- MODAL EDIT -->
<div id="editRecoursModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Mettre à Jour</h2><button class="modal-cl" onclick="Gov.closeModal('editRecoursModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update_recours"><input type="hidden" name="recours_id" id="er-id">
  <div id="er-ref" style="font-family:'IBM Plex Mono',monospace;font-size:.82rem;color:var(--gov-l);margin-bottom:.85rem"></div>
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Statut</label><select name="statut" id="er-stat" class="form-select"><?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Instruit par</label><input type="text" name="instruction_par" id="er-inst" class="form-input"></div>
    <div class="form-group"><label class="form-label">Montant final ($)</label><input type="number" name="montant_final" id="er-mf" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group"><label class="form-label">Date de décision</label><input type="date" name="date_decision" id="er-dd" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Décision</label><textarea name="decision" id="er-dec" class="form-textarea" style="min-height:90px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('editRecoursModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<script>
function openRecoursEdit(r){
  document.getElementById('er-id').value=r.id;
  document.getElementById('er-ref').textContent=r.reference+' — '+r.objet.substring(0,50);
  document.getElementById('er-stat').value=r.statut;
  document.getElementById('er-inst').value=r.instruction_par||'';
  document.getElementById('er-mf').value=r.montant_final||'0';
  document.getElementById('er-dd').value=r.date_decision||'';
  document.getElementById('er-dec').value=r.decision||'';
  Gov.openModal('editRecoursModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>