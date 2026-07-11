<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Audits & Contrôles d'Entreprises";
$__secLabel = "Secrétariat aux Entreprises"; $__secColor = '#0891b2'; $__secIcon = 'fas fa-building';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_audits` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `entreprise_id` int DEFAULT NULL,
  `faction_key` varchar(80),
  `nom_entreprise` varchar(200),
  `type_audit` enum('fiscal','social','hygiene','securite','comptable','general') DEFAULT 'general',
  `motif` enum('routine','plainte','alerte_fiscale','controle_aleatoire','demande') DEFAULT 'routine',
  `statut` enum('planifie','en_cours','termine','suspendu') DEFAULT 'planifie',
  `date_planifiee` date,
  `date_debut` date,
  `date_fin` date,
  `auditeur` varchar(150),
  `conclusion` enum('conforme','irregularites_mineures','irregularites_majeures','fraude','non_determine') DEFAULT 'non_determine',
  `amende_proposee` decimal(12,2) DEFAULT 0,
  `rapport` text,
  `notes_internes` text,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='create_audit') {
        try {
            $db->prepare("INSERT INTO gouv_audits (entreprise_id,faction_key,nom_entreprise,type_audit,motif,date_planifiee,auditeur,notes_internes,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([!empty($_POST['entreprise_id'])?(int)$_POST['entreprise_id']:null,trim($_POST['faction_key']??''),trim($_POST['nom_entreprise']),
                   $_POST['type_audit'],$_POST['motif'],$_POST['date_planifiee']?:null,trim($_POST['auditeur']??''),trim($_POST['notes_internes']??''),$_SESSION['user_id']]);
            $msg='Audit planifié.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }
    if($action==='update_audit') {
        try {
            $db->prepare("UPDATE gouv_audits SET statut=?,date_debut=?,date_fin=?,conclusion=?,amende_proposee=?,rapport=?,notes_internes=? WHERE id=?")
               ->execute([$_POST['statut'],$_POST['date_debut']?:null,$_POST['date_fin']?:null,$_POST['conclusion'],(float)$_POST['amende_proposee'],
                   trim($_POST['rapport']??''),trim($_POST['notes_internes']??''),(int)$_POST['audit_id']]);
            $msg='Audit mis à jour.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }
    if($msg){header('Location: sec_audits.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}

$fStat=$_GET['stat']??''; $fType=$_GET['type_f']??''; $search=$_GET['q']??'';
$where=['1=1'];$params=[];
if($search){$where[]="(a.nom_entreprise LIKE ? OR a.faction_key LIKE ? OR a.auditeur LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;$params[]=$s;}
if($fStat){$where[]="a.statut=?";$params[]=$fStat;}
if($fType){$where[]="a.type_audit=?";$params[]=$fType;}
$wStr='WHERE '.implode(' AND ',$where);
$audits=[];try{$s=$db->prepare("SELECT * FROM gouv_audits a $wStr ORDER BY a.created_at DESC");$s->execute($params);$audits=$s->fetchAll();}catch(Exception $e){}
$statsA=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_audits GROUP BY statut")->fetchAll() as $r)$statsA[$r['statut']]=$r['n'];}catch(Exception $e){}
$totalAmendes=0;try{$totalAmendes=(float)$db->query("SELECT SUM(amende_proposee) FROM gouv_audits WHERE conclusion IN ('irregularites_majeures','fraude')")->fetchColumn();}catch(Exception $e){}
$entreprises=[];try{$entreprises=$db->query("SELECT id,nom,faction_key FROM gouv_registre_entreprises WHERE statut='active' ORDER BY nom LIMIT 100")->fetchAll();}catch(Exception $e){}

$statCfg=['planifie'=>['bg-blue','Planifié'],'en_cours'=>['bg-yellow','En cours'],'termine'=>['bg-green','Terminé'],'suspendu'=>['bg-gray','Suspendu']];
$concCfg=['conforme'=>['bg-green','Conforme'],'irregularites_mineures'=>['bg-yellow','Irr. mineures'],'irregularites_majeures'=>['bg-red','Irr. majeures'],'fraude'=>['bg-red','Fraude'],'non_determine'=>['bg-gray','N/D']];
$typeCfg=['fiscal'=>'Fiscal','social'=>'Social','hygiene'=>'Hygiène','securite'=>'Sécurité','comptable'=>'Comptable','general'=>'Général'];
$motifCfg=['routine'=>'Routine','plainte'=>'Plainte','alerte_fiscale'=>'Alerte fiscale','controle_aleatoire'=>'Contrôle aléatoire','demande'=>'Demande'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(8,145,178,.12);color:#67e8f9"><i class="fas fa-magnifying-glass-chart"></i></div><div class="kpi-lbl">Total Audits</div><div class="kpi-val cyan"><?php echo array_sum($statsA);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-spinner"></i></div><div class="kpi-lbl">En Cours</div><div class="kpi-val gold"><?php echo ($statsA['planifie']??0)+($statsA['en_cours']??0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-triangle-exclamation"></i></div><div class="kpi-lbl">Irrégularités</div><div class="kpi-val red"><?php
    $irr=0;try{$irr=(int)$db->query("SELECT COUNT(*) FROM gouv_audits WHERE conclusion IN ('irregularites_mineures','irregularites_majeures','fraude')")->fetchColumn();}catch(Exception $e){}echo $irr;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-coins"></i></div><div class="kpi-lbl">Amendes Proposées</div><div class="kpi-val red">$<?php echo number_format($totalAmendes,0);?></div></div>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Entreprise, auditeur…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="type_f" class="form-select" style="width:135px" onchange="this.form.submit()"><option value="">Tous types</option><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select>
    <select name="stat" class="form-select" style="width:130px" onchange="this.form.submit()"><option value="">Tous statuts</option><?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fStat||$fType):?><a href="sec_audits.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="Gov.openModal('newAuditModal')" class="btn btn-cyan btn-sm"><i class="fas fa-plus"></i> Planifier Audit</button>
</div>

<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Entreprise</th><th>Type</th><th>Motif</th><th>Auditeur</th><th>Date planifiée</th><th>Statut</th><th>Conclusion</th><th>Amende proposée</th><th>Actions</th></tr></thead>
<tbody>
<?php if(empty($audits)):?><tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-magnifying-glass-chart" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun audit</td></tr>
<?php else: foreach($audits as $a):[$sb,$sl]=$statCfg[$a['statut']]??['bg-gray','—'];[$cb,$cl]=$concCfg[$a['conclusion']]??['bg-gray','N/D'];?>
<tr>
  <td><div style="font-weight:600;font-size:.83rem"><?php echo htmlspecialchars($a['nom_entreprise']??'—');?></div><?php if($a['faction_key']):?><div style="font-size:.68rem;color:var(--txt-m)"><?php echo $a['faction_key'];?></div><?php endif;?></td>
  <td><span class="badge bg-cyan" style="font-size:.58rem"><?php echo $typeCfg[$a['type_audit']]??'—';?></span></td>
  <td style="font-size:.79rem;color:var(--txt-s)"><?php echo $motifCfg[$a['motif']]??'—';?></td>
  <td style="font-size:.8rem"><?php echo htmlspecialchars($a['auditeur']??'—');?></td>
  <td class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo $a['date_planifiee']?date('d/m/Y',strtotime($a['date_planifiee'])):'—';?></td>
  <td><span class="badge <?php echo $sb;?>"><?php echo $sl;?></span></td>
  <td><span class="badge <?php echo $cb;?>"><?php echo $cl;?></span></td>
  <td class="mono" style="font-size:.8rem;<?php echo $a['amende_proposee']>0?'color:#f87171':'color:var(--txt-m)';?>">$<?php echo number_format($a['amende_proposee'],0);?></td>
  <td><button class="btn btn-ghost btn-xs" onclick='openAuditEdit(<?php echo json_encode($a,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE);?>)'><i class="fas fa-pen"></i></button></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>

<!-- MODAL CRÉER AUDIT -->
<div id="newAuditModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-magnifying-glass-chart"></i> Planifier un Audit</h2><button class="modal-cl" onclick="Gov.closeModal('newAuditModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_audit">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Entreprise *</label>
      <select name="entreprise_id" class="form-select" onchange="fillAuditEnt(this)">
        <option value="">— Saisie manuelle —</option>
        <?php foreach($entreprises as $e):?><option value="<?php echo $e['id'];?>" data-nom="<?php echo htmlspecialchars($e['nom']);?>" data-fk="<?php echo htmlspecialchars($e['faction_key']??'');?>"><?php echo htmlspecialchars($e['nom']);?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Nom entreprise (si manuelle)</label><input type="text" name="nom_entreprise" id="audit-nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Faction (clé)</label><input type="text" name="faction_key" id="audit-fk" class="form-input"></div>
    <div class="form-group"><label class="form-label">Type d'audit</label>
      <select name="type_audit" class="form-select"><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Motif</label>
      <select name="motif" class="form-select"><?php foreach($motifCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Auditeur</label><input type="text" name="auditeur" class="form-input"></div>
    <div class="form-group"><label class="form-label">Date planifiée</label><input type="date" name="date_planifiee" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes_internes" class="form-textarea" style="min-height:70px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newAuditModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Planifier</button></div>
</form></div></div>

<!-- MODAL EDIT AUDIT -->
<div id="editAuditModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Mettre à Jour l'Audit</h2><button class="modal-cl" onclick="Gov.closeModal('editAuditModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update_audit"><input type="hidden" name="audit_id" id="ea-id">
  <div id="ea-ent" style="font-size:.82rem;font-weight:600;margin-bottom:.85rem;color:#67e8f9"></div>
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Statut</label>
      <select name="statut" id="ea-stat" class="form-select"><?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Conclusion</label>
      <select name="conclusion" id="ea-conc" class="form-select"><?php foreach($concCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Date de début</label><input type="date" name="date_debut" id="ea-dd" class="form-input"></div>
    <div class="form-group"><label class="form-label">Date de fin</label><input type="date" name="date_fin" id="ea-df" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Amende proposée ($)</label><input type="number" name="amende_proposee" id="ea-am" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group form-full"><label class="form-label">Rapport d'audit</label><textarea name="rapport" id="ea-rap" class="form-textarea" style="min-height:90px"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes_internes" id="ea-notes" class="form-textarea" style="min-height:65px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('editAuditModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<script>
function fillAuditEnt(sel) {
  const opt=sel.options[sel.selectedIndex];
  document.getElementById('audit-nom').value=opt.dataset.nom||'';
  document.getElementById('audit-fk').value=opt.dataset.fk||'';
}
function openAuditEdit(a) {
  document.getElementById('ea-id').value=a.id;
  document.getElementById('ea-ent').textContent=a.nom_entreprise+(a.faction_key?' ('+a.faction_key+')':'');
  document.getElementById('ea-stat').value=a.statut;
  document.getElementById('ea-conc').value=a.conclusion||'non_determine';
  document.getElementById('ea-dd').value=a.date_debut||'';
  document.getElementById('ea-df').value=a.date_fin||'';
  document.getElementById('ea-am').value=a.amende_proposee||'0';
  document.getElementById('ea-rap').value=a.rapport||'';
  document.getElementById('ea-notes').value=a.notes_internes||'';
  Gov.openModal('editAuditModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>