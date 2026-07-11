<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$companyFaction = 'USSS';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff'])) redirect(SITE_URL . '/pages/dashboard.php');
$pageTitle = 'Opérations'; $db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_operation') {
        try {
            $db->beginTransaction();
            $participants = !empty($_POST['participants']) ? json_encode(array_map('intval',$_POST['participants'])) : null;
            $agentId = $db->query("SELECT id FROM usss_agents WHERE user_id=".$_SESSION['user_id'])->fetchColumn();
            $db->prepare("INSERT INTO usss_operations (nom_code,description,niveau_classification,division_responsable,chef_operation,participants,objectif,date_debut,date_fin,planning,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([strtoupper(trim($_POST['nom_code'])),trim($_POST['description']),$_POST['niveau_classification']??'SECRET',!empty($_POST['division_responsable'])?(int)$_POST['division_responsable']:null,(int)$_POST['chef_operation'],$participants,trim($_POST['objectif']),$_POST['date_debut'],!empty($_POST['date_fin'])?$_POST['date_fin']:null,trim($_POST['planning']??''),$_SESSION['user_id']]);
            $db->commit(); $msg='Opération créée.'; $msgType='success';
        } catch(Exception $e){if($db->inTransaction())$db->rollBack();$msg=$e->getMessage();$msgType='error';}
    }
    if ($action === 'change_statut') {
        try {
            $params=[$_POST['nouveau_statut']]; $sql="UPDATE usss_operations SET statut=?";
            if($_POST['nouveau_statut']==='TERMINEE'){$sql.=",date_fin=CURDATE(),resultat=?";$params[]=$_POST['resultat']??'SUCCES';}
            $sql.=" WHERE id=?"; $params[]=(int)$_POST['operation_id'];
            $db->prepare($sql)->execute($params); $msg='Statut mis à jour.'; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }
    if ($msg) { header('Location: operations.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

$fStat=$_GET['stat']??''; $search=$_GET['q']??'';
$where=['1=1']; $params=[];
if($search){$where[]="o.nom_code LIKE ?";$params[]="%$search%";}
if($fStat){$where[]="o.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$ops=[];try{$s=$db->prepare("SELECT o.*,a.nom_rp,a.prenom_rp,a.badge_number,d.nom as div_nom FROM usss_operations o JOIN usss_agents a ON o.chef_operation=a.id LEFT JOIN usss_divisions d ON o.division_responsable=d.id $wStr ORDER BY FIELD(o.statut,'EN_COURS','PLANIFIEE','TERMINEE','ABANDONNEE'),o.date_debut DESC");$s->execute($params);$ops=$s->fetchAll();}catch(Exception $e){}

$statsO=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM usss_operations GROUP BY statut")->fetchAll() as $r)$statsO[$r['statut']]=$r['n'];}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,nom_rp,prenom_rp,badge_number FROM usss_agents WHERE statut='EN_SERVICE' ORDER BY nom_rp")->fetchAll();}catch(Exception $e){}
$divisions=[];try{$divisions=$db->query("SELECT id,nom FROM usss_divisions WHERE actif=1 ORDER BY ordre")->fetchAll();}catch(Exception $e){}

$statCfg=['PLANIFIEE'=>['b-blue','fa-clock'],'EN_COURS'=>['b-amber','fa-crosshairs'],'TERMINEE'=>['b-green','fa-check'],'SUSPENDUE'=>['b-amber','fa-pause'],'ABANDONNEE'=>['b-red','fa-times']];
$habCls=['TOP_SECRET'=>'cls-ts','SECRET'=>'cls-secret','CONFIDENTIEL'=>'cls-conf'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
  <?php foreach([['Planifiées',$statsO['PLANIFIEE']??0,'var(--blue-xl)'],['En Cours',$statsO['EN_COURS']??0,'var(--amber-l)'],['Terminées',$statsO['TERMINEE']??0,'var(--green-l)'],['Abandonnées',$statsO['ABANDONNEE']??0,'var(--red-l)']] as [$l,$v,$c]):?>
  <div class="kpi" style="--kpi-color:<?php echo $c;?>"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-crosshairs"></i></div><div class="kpi-lbl" style="margin-top:2.1rem"><?php echo $l;?></div><div class="kpi-val"><?php echo (int)$v;?></div></div>
  <?php endforeach;?>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Code opération…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="stat" class="form-select" style="width:140px" onchange="this.form.submit()">
      <option value="">Tous statuts</option>
      <?php foreach(array_keys($statCfg) as $s):?><option value="<?php echo $s;?>" <?php echo $fStat===$s?'selected':'';?>><?php echo str_replace('_',' ',$s);?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fStat):?><a href="operations.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="USSS.openModal('newOpModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle Opération</button>
</div>

<div style="display:flex;flex-direction:column;gap:.65rem">
<?php if(empty($ops)):?>
<div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-m)"><i class="fas fa-crosshairs" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucune opération</div>
<?php else: foreach($ops as $op):[$sb,$sic]=$statCfg[$op['statut']]??['b-slate','fa-circle'];$hc=$habCls[$op['niveau_classification']??'']??'cls-conf';?>
<div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:10px;overflow:hidden;transition:border-color .18s" onmouseover="this.style.borderColor='var(--bdr-b)'" onmouseout="this.style.borderColor='var(--bdr)'">
  <!-- Header opération -->
  <div style="display:flex;align-items:center;gap:.85rem;padding:1rem 1.25rem;border-bottom:1px solid var(--bdr)">
    <div style="width:40px;height:40px;border-radius:9px;background:<?php echo $op['statut']==='EN_COURS'?'rgba(180,83,9,.15)':'rgba(26,86,219,.1)';?>;border:1px solid <?php echo $op['statut']==='EN_COURS'?'rgba(180,83,9,.35)':'rgba(26,86,219,.25)';?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="fas <?php echo $sic;?>" style="color:<?php echo $op['statut']==='EN_COURS'?'var(--amber-l)':'var(--blue-l)';?>;font-size:.9rem"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;margin-bottom:.2rem">
        <span style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:1rem;letter-spacing:.08em">OP-<?php echo htmlspecialchars($op['nom_code']);?></span>
        <span class="badge <?php echo $sb;?>" style="font-size:.6rem"><?php echo str_replace('_',' ',$op['statut']);?></span>
        <span class="cls-stamp <?php echo $hc;?>" style="font-size:.55rem"><?php echo $op['niveau_classification'];?></span>
      </div>
      <div style="font-size:.75rem;color:var(--txt-s)">Chef : <?php echo htmlspecialchars($op['nom_rp'].' '.$op['prenom_rp']);?> · <?php echo $op['badge_number'];?>
        <?php if($op['div_nom']):?> · <?php echo htmlspecialchars($op['div_nom']);?><?php endif;?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0">
      <form method="POST" style="margin:0;display:flex;gap:.3rem;align-items:center">
        <input type="hidden" name="action" value="change_statut"><input type="hidden" name="operation_id" value="<?php echo $op['id'];?>">
        <select name="nouveau_statut" class="form-select" style="padding:.28rem .5rem;font-size:.7rem;width:130px" onchange="this.form.submit()">
          <?php foreach(array_keys($statCfg) as $s):?><option value="<?php echo $s;?>" <?php echo $op['statut']===$s?'selected':'';?>><?php echo str_replace('_',' ',$s);?></option><?php endforeach;?>
        </select>
      </form>
    </div>
  </div>
  <!-- Body -->
  <div style="padding:.85rem 1.25rem;display:grid;grid-template-columns:1fr 1fr 1fr;gap:.85rem;font-size:.78rem">
    <div><div class="form-label">Objectif</div><div style="margin-top:.2rem;color:var(--txt)"><?php echo htmlspecialchars(mb_substr($op['objectif']??'—',0,80));?></div></div>
    <div><div class="form-label">Dates</div><div style="margin-top:.2rem;font-family:'IBM Plex Mono',monospace;font-size:.72rem"><?php echo $op['date_debut']?date('d.m.Y',strtotime($op['date_debut'])):'—';?> → <?php echo $op['date_fin']?date('d.m.Y',strtotime($op['date_fin'])):'En cours';?></div></div>
    <div><div class="form-label">Résultat</div><div style="margin-top:.2rem"><?php echo $op['resultat']?htmlspecialchars($op['resultat']):'<span style="color:var(--txt-m)">—</span>';?></div></div>
  </div>
</div>
<?php endforeach; endif;?>
</div>

<!-- MODAL NOUVELLE OPÉRATION -->
<div id="newOpModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-crosshairs"></i> Nouvelle Opération</h2><button class="modal-cl" onclick="USSS.closeModal('newOpModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_operation">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Code Opération *</label><input type="text" name="nom_code" class="form-input" required placeholder="EAGLE, SHADOW…" style="text-transform:uppercase"></div>
    <div class="form-group"><label class="form-label">Classification</label>
      <select name="niveau_classification" class="form-select"><option value="CONFIDENTIEL">CONFIDENTIEL</option><option value="SECRET" selected>SECRET</option><option value="TOP_SECRET">TOP SECRET</option></select>
    </div>
    <div class="form-group"><label class="form-label">Chef d'opération *</label>
      <select name="chef_operation" class="form-select" required><option value="">Sélectionner…</option><?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>"><?php echo htmlspecialchars($a['nom_rp'].' '.$a['prenom_rp'].' ('.$a['badge_number'].')');?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Division</label>
      <select name="division_responsable" class="form-select"><option value="">Aucune</option><?php foreach($divisions as $dv):?><option value="<?php echo $dv['id'];?>"><?php echo htmlspecialchars($dv['nom']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Date début *</label><input type="date" name="date_debut" class="form-input" required value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group"><label class="form-label">Date fin prévue</label><input type="date" name="date_fin" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Objectif *</label><textarea name="objectif" class="form-textarea" required style="min-height:70px"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Planning</label><textarea name="planning" class="form-textarea" style="min-height:65px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newOpModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>