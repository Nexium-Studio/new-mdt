<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$companyFaction = 'USSS';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff'])) redirect(SITE_URL . '/pages/dashboard.php');
$pageTitle = 'Mandats'; $db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';
$isAdmin = hasRole(['*','Administrateur','Développeur','Staff']);

function genRefMandat($db,$type){
    $y=date('Y');
    $p=match($type){'ARRET'=>'MW','PERQUISITION'=>'SW','SAISIE'=>'SZ','ECOUTE_TELEPHONIQUE'=>'WTP','SURVEILLANCE'=>'SRV',default=>'MW'};
    $s=$db->query("SELECT COUNT(*)+1 FROM usss_mandats WHERE YEAR(created_at)=$y AND type=".$db->quote($type))->fetchColumn();
    return sprintf("%s-%d-%05d",$p,$y,$s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_mandat') {
        try {
            $db->beginTransaction();
            $type = $_POST['type'];
            $ref  = genRefMandat($db, $type);
            $agentId = $db->query("SELECT id FROM usss_agents WHERE user_id=".$_SESSION['user_id'])->fetchColumn();
            if (!$agentId) throw new Exception('Vous devez être un agent USSS.');
            $db->prepare("INSERT INTO usss_mandats (reference,dossier_id,type,cible_nom,cible_details,lieu,motif,agent_demandeur,date_emission,date_expiration) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$ref,!empty($_POST['dossier_id'])?(int)$_POST['dossier_id']:null,$type,trim($_POST['cible_nom']),trim($_POST['cible_details']??''),trim($_POST['lieu']??''),trim($_POST['motif']),$agentId,$_POST['date_emission']??date('Y-m-d'),!empty($_POST['date_expiration'])?$_POST['date_expiration']:null]);
            $db->commit(); $msg="Mandat $ref créé — en attente de validation."; $msgType='success';
        } catch(Exception $e){if($db->inTransaction())$db->rollBack();$msg=$e->getMessage();$msgType='error';}
    }

    if ($action === 'valider_mandat' && $isAdmin) {
        try {
            $db->prepare("UPDATE usss_mandats SET statut='ACTIF',juge_valideur=? WHERE id=?")
               ->execute([trim($_POST['juge_valideur']),(int)$_POST['mandat_id']]);
            $msg='Mandat validé et activé.'; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    if ($action === 'executer_mandat') {
        try {
            $db->prepare("UPDATE usss_mandats SET statut='EXECUTE',date_execution=CURDATE(),rapport_execution=? WHERE id=?")
               ->execute([trim($_POST['rapport']),(int)$_POST['mandat_id']]);
            $msg='Mandat marqué comme exécuté.'; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    if ($action === 'annuler_mandat') {
        $db->prepare("UPDATE usss_mandats SET statut='ANNULE' WHERE id=?")->execute([(int)$_POST['mandat_id']]);
        $msg='Mandat annulé.'; $msgType='success';
    }

    if ($msg) { header('Location: mandats.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ─────────────────────────────────────────────────────────────────
$fStat=$_GET['stat']??''; $fType=$_GET['type_f']??''; $search=$_GET['q']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=25; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(m.reference LIKE ? OR m.cible_nom LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($fStat){$where[]="m.statut=?";$params[]=$fStat;}
if($fType){$where[]="m.type=?";$params[]=$fType;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM usss_mandats m $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$mandats=[];
try{$s=$db->prepare("SELECT m.*,a.nom_rp,a.prenom_rp,a.badge_number FROM usss_mandats m JOIN usss_agents a ON m.agent_demandeur=a.id $wStr ORDER BY FIELD(m.statut,'EN_ATTENTE_VALIDATION','ACTIF','EXECUTE','EXPIRE','ANNULE'),m.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$mandats=$s->fetchAll();}catch(Exception $e){}
$statsM=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM usss_mandats GROUP BY statut")->fetchAll() as $r)$statsM[$r['statut']]=$r['n'];}catch(Exception $e){}
$dossiers=[];try{$dossiers=$db->query("SELECT id,reference,titre FROM usss_dossiers WHERE statut IN ('OUVERT','EN_COURS') ORDER BY created_at DESC LIMIT 100")->fetchAll();}catch(Exception $e){}

$statCfg=['EN_ATTENTE_VALIDATION'=>['b-amber','Attente Validation'],'ACTIF'=>['b-green','Actif'],'EXECUTE'=>['b-blue','Exécuté'],'EXPIRE'=>['b-slate','Expiré'],'ANNULE'=>['b-red','Annulé']];
$typeCfg=['ARRET'=>'Arrestation','PERQUISITION'=>'Perquisition','SAISIE'=>'Saisie','ECOUTE_TELEPHONIQUE'=>'Écoute téléph.','SURVEILLANCE'=>'Surveillance'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
<?php foreach([['Attente',$statsM['EN_ATTENTE_VALIDATION']??0,'var(--amber-l)'],['Actifs',$statsM['ACTIF']??0,'var(--green-l)'],['Exécutés',$statsM['EXECUTE']??0,'var(--blue-xl)'],['Expirés',$statsM['EXPIRE']??0,'var(--txt-m)'],['Annulés',$statsM['ANNULE']??0,'var(--red-l)']] as [$l,$v,$c]):?>
<div class="kpi" style="--kpi-color:<?php echo $c;?>"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-gavel"></i></div><div class="kpi-lbl" style="margin-top:2.1rem"><?php echo $l;?></div><div class="kpi-val"><?php echo (int)$v;?></div></div>
<?php endforeach;?>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Référence, cible…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="type_f" class="form-select" style="width:150px" onchange="this.form.submit()">
      <option value="">Tous types</option>
      <?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?>
    </select>
    <select name="stat" class="form-select" style="width:145px" onchange="this.form.submit()">
      <option value="">Tous statuts</option>
      <?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fStat||$fType):?><a href="mandats.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="USSS.openModal('newMandatModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouveau Mandat</button>
</div>

<div class="tbl-wrap">
<table class="tbl">
<thead><tr><th>Référence</th><th>Type</th><th>Cible</th><th>Lieu</th><th>Agent</th><th>Émission</th><th>Expiration</th><th>Statut</th><th>Actions</th></tr></thead>
<tbody>
<?php if(empty($mandats)):?>
<tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-gavel" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun mandat</td></tr>
<?php else: foreach($mandats as $m):[$sb,$sl]=$statCfg[$m['statut']]??['b-slate',$m['statut']];$expired=$m['date_expiration']&&strtotime($m['date_expiration'])<time()&&$m['statut']==='ACTIF';?>
<tr <?php echo $expired?'style="opacity:.65"':'';?>>
  <td><span class="ref"><?php echo htmlspecialchars($m['reference']);?></span></td>
  <td><span class="badge b-slate" style="font-size:.6rem"><?php echo $typeCfg[$m['type']]??$m['type'];?></span></td>
  <td style="font-weight:600;font-size:.83rem"><?php echo htmlspecialchars($m['cible_nom']);?></td>
  <td style="font-size:.75rem;color:var(--txt-s)"><?php echo $m['lieu']?htmlspecialchars(mb_substr($m['lieu'],0,30)):'—';?></td>
  <td style="font-size:.75rem"><?php echo htmlspecialchars($m['nom_rp'].' '.$m['prenom_rp']);?><div class="ref" style="font-size:.62rem"><?php echo $m['badge_number'];?></div></td>
  <td class="mono" style="font-size:.68rem;color:var(--txt-m)"><?php echo $m['date_emission']?date('d.m.Y',strtotime($m['date_emission'])):'—';?></td>
  <td class="mono" style="font-size:.68rem;color:<?php echo $expired?'var(--red-l)':'var(--txt-m)';?>"><?php echo $m['date_expiration']?date('d.m.Y',strtotime($m['date_expiration'])):'—';?><?php if($expired):?> <span class="badge b-red" style="font-size:.5rem">EXPIRÉ</span><?php endif;?></td>
  <td><span class="badge <?php echo $sb;?>" style="font-size:.6rem"><?php echo $sl;?></span></td>
  <td>
    <div style="display:flex;gap:.3rem">
      <?php if($m['statut']==='EN_ATTENTE_VALIDATION'&&$isAdmin):?>
      <button class="btn btn-success btn-xs" onclick="openValider(<?php echo $m['id'];?>,'<?php echo addslashes($m['reference']);?>')" title="Valider"><i class="fas fa-check"></i></button>
      <?php endif;?>
      <?php if($m['statut']==='ACTIF'):?>
      <button class="btn btn-ghost btn-xs" onclick="openExecuter(<?php echo $m['id'];?>,'<?php echo addslashes($m['reference']);?>')" title="Exécuté"><i class="fas fa-check-double"></i></button>
      <?php endif;?>
      <?php if(in_array($m['statut'],['EN_ATTENTE_VALIDATION','ACTIF'])&&$isAdmin):?>
      <form method="POST" style="margin:0;display:inline" data-confirm data-confirm-title="Annuler le mandat" data-confirm-target="<?php echo htmlspecialchars($m['reference']);?>" data-confirm-ok="Annuler">
        <input type="hidden" name="action" value="annuler_mandat"><input type="hidden" name="mandat_id" value="<?php echo $m['id'];?>">
        <button type="submit" class="btn btn-danger btn-xs" title="Annuler"><i class="fas fa-ban"></i></button>
      </form>
      <?php endif;?>
    </div>
  </td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table>
</div>

<?php if($totalPages>1):?><div class="pag">
  <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>">← Préc.</a><?php endif;?>
  <span class="pag-cur">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
  <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>">Suiv. →</a><?php endif;?>
</div><?php endif;?>

<!-- MODAL CRÉER MANDAT -->
<div id="newMandatModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-gavel"></i> Nouveau Mandat</h2><button class="modal-cl" onclick="USSS.closeModal('newMandatModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_mandat">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type *</label>
      <select name="type" class="form-select" required>
        <?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Dossier lié</label>
      <select name="dossier_id" class="form-select"><option value="">— Aucun —</option><?php foreach($dossiers as $d):?><option value="<?php echo $d['id'];?>"><?php echo htmlspecialchars($d['reference'].' — '.$d['titre']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Nom de la cible *</label><input type="text" name="cible_nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input" placeholder="Adresse, lieu…"></div>
    <div class="form-group"><label class="form-label">Date d'émission</label><input type="date" name="date_emission" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group"><label class="form-label">Date d'expiration</label><input type="date" name="date_expiration" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Détails sur la cible</label><textarea name="cible_details" class="form-textarea" style="min-height:65px" placeholder="Signalement, antécédents…"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Motif *</label><textarea name="motif" class="form-textarea" required style="min-height:80px" placeholder="Fondements juridiques et motifs de la demande…"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newMandatModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Soumettre</button></div>
</form></div></div>

<!-- MODAL VALIDER -->
<div id="validerModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-check"></i> Valider le Mandat</h2><button class="modal-cl" onclick="USSS.closeModal('validerModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="valider_mandat"><input type="hidden" name="mandat_id" id="val-id">
  <div style="font-size:.82rem;color:var(--txt-s);margin-bottom:.85rem">Mandat : <strong id="val-ref" style="color:var(--txt)"></strong></div>
  <div class="form-group"><label class="form-label">Juge valideur *</label><input type="text" name="juge_valideur" class="form-input" required placeholder="Nom du juge…"></div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('validerModal')">Annuler</button><button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Valider</button></div>
</form></div></div>

<!-- MODAL EXÉCUTER -->
<div id="executerModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-check-double"></i> Rapport d'Exécution</h2><button class="modal-cl" onclick="USSS.closeModal('executerModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="executer_mandat"><input type="hidden" name="mandat_id" id="exec-id">
  <div style="font-size:.82rem;color:var(--txt-s);margin-bottom:.85rem">Mandat : <strong id="exec-ref" style="color:var(--txt)"></strong></div>
  <div class="form-group"><label class="form-label">Rapport d'exécution *</label><textarea name="rapport" class="form-textarea" required style="min-height:90px" placeholder="Déroulement de l'exécution…"></textarea></div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('executerModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check-double"></i> Confirmer</button></div>
</form></div></div>

<script>
function openValider(id,ref){document.getElementById('val-id').value=id;document.getElementById('val-ref').textContent=ref;USSS.openModal('validerModal');}
function openExecuter(id,ref){document.getElementById('exec-id').value=id;document.getElementById('exec-ref').textContent=ref;USSS.openModal('executerModal');}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>