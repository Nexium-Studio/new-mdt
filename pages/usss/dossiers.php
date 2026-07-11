<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$companyFaction = 'USSS';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff'])) redirect(SITE_URL . '/pages/dashboard.php');
$pageTitle = 'Dossiers';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

function genRefDossier($db){ $y=date('Y'); $s=$db->query("SELECT COUNT(*)+1 FROM usss_dossiers WHERE YEAR(created_at)=$y")->fetchColumn(); return sprintf("USSS-%d-%05d",$y,$s); }

// ── ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_dossier') {
        try {
            $db->beginTransaction();
            $ref = genRefDossier($db);
            $aa  = !empty($_POST['agents_assignes']) ? json_encode(array_map('intval',$_POST['agents_assignes'])) : null;
            $db->prepare("INSERT INTO usss_dossiers (reference,titre,type,priorite,niveau_classification,division_responsable,agent_responsable,agents_assignes,description,date_ouverture,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$ref,trim($_POST['titre']),$_POST['type'],$_POST['priorite']??'MOYENNE',$_POST['niveau_classification']??'CONFIDENTIEL',!empty($_POST['division_responsable'])?(int)$_POST['division_responsable']:null,(int)$_POST['agent_responsable'],$aa,trim($_POST['description']),$_POST['date_ouverture']??date('Y-m-d'),$_SESSION['user_id']]);
            $did=$db->lastInsertId();
            try{$db->prepare("INSERT INTO usss_dossiers_timeline (dossier_id,date_evenement,type_evenement,description,agent_id) VALUES (?,NOW(),'CREATION',?,?)")->execute([$did,'Dossier créé',$_SESSION['user_id']]);}catch(Exception $e){}
            $db->commit();
            $msg="Dossier $ref créé."; $msgType='success';
        } catch(Exception $e){if($db->inTransaction())$db->rollBack();$msg=$e->getMessage();$msgType='error';}
    }

    if ($action === 'update_statut') {
        try {
            $extra=[]; $vals=[$_POST['statut']];
            if($_POST['statut']==='RESOLU'){$extra[]="date_cloture=CURDATE()";}
            $db->prepare("UPDATE usss_dossiers SET statut=?".($extra?','.implode(',',$extra):'')." WHERE id=?")->execute(array_merge($vals,[(int)$_POST['dossier_id']]));
            $msg='Statut mis à jour.';$msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    if ($msg) { header('Location: dossiers.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ─────────────────────────────────────────────────────────────────
$search=$_GET['q']??''; $fType=$_GET['type']??''; $fPrio=$_GET['prio']??''; $fStat=$_GET['stat']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;

$where=['1=1']; $params=[];
if($search){$where[]="(d.titre LIKE ? OR d.reference LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($fType){$where[]="d.type=?";$params[]=$fType;}
if($fPrio){$where[]="d.priorite=?";$params[]=$fPrio;}
if($fStat){$where[]="d.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);

$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM usss_dossiers d $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));

$dossiers=[];
try{$s=$db->prepare("SELECT d.*,a.nom_rp,a.prenom_rp,a.badge_number,div.nom as div_nom FROM usss_dossiers d JOIN usss_agents a ON d.agent_responsable=a.id LEFT JOIN usss_divisions div ON d.division_responsable=div.id $wStr ORDER BY FIELD(d.priorite,'CRITIQUE','HAUTE','MOYENNE','FAIBLE'),d.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$dossiers=$s->fetchAll();}catch(Exception $e){}

$statsD=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM usss_dossiers GROUP BY statut")->fetchAll() as $r)$statsD[$r['statut']]=$r['n'];}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,nom_rp,prenom_rp,badge_number FROM usss_agents WHERE statut='EN_SERVICE' ORDER BY nom_rp")->fetchAll();}catch(Exception $e){}
$divisions=[];try{$divisions=$db->query("SELECT id,nom,code FROM usss_divisions WHERE actif=1 ORDER BY ordre")->fetchAll();}catch(Exception $e){}

$prioC=['FAIBLE'=>'b-slate','MOYENNE'=>'b-blue','HAUTE'=>'b-amber','CRITIQUE'=>'b-red'];
$statC=['OUVERT'=>'b-blue','EN_COURS'=>'b-amber','RESOLU'=>'b-green','CLASSE'=>'b-slate','TRANSMIS_DOJ'=>'b-purple'];
$habCls=['CONFIDENTIEL'=>'cls-conf','SECRET'=>'cls-secret','TOP_SECRET'=>'cls-ts'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
  <?php foreach([['Ouverts',$statsD['OUVERT']??0,'b-blue','fa-folder-open'],['En Cours',$statsD['EN_COURS']??0,'b-amber','fa-spinner'],['Résolus',$statsD['RESOLU']??0,'b-green','fa-folder-closed'],['Classés',$statsD['CLASSE']??0,'b-slate','fa-archive'],['DOJ',$statsD['TRANSMIS_DOJ']??0,'b-purple','fa-gavel']] as [$l,$v,$b,$i]):?>
  <div class="kpi" style="--kpi-color:<?php echo $b==='b-blue'?'var(--blue-xl)':($b==='b-amber'?'var(--amber-l)':($b==='b-green'?'var(--green-l)':($b==='b-purple'?'#c4b5fd':'var(--txt-m)'))); ?>">
    <div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas <?php echo $i;?>"></i></div>
    <div class="kpi-lbl" style="margin-top:2.1rem"><?php echo $l;?></div>
    <div class="kpi-val"><?php echo (int)$v;?></div>
  </div>
  <?php endforeach;?>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Titre, référence…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:160px">
    <select name="prio" class="form-select" style="width:130px" onchange="this.form.submit()">
      <option value="">Priorité</option>
      <?php foreach(['FAIBLE','MOYENNE','HAUTE','CRITIQUE'] as $p):?><option value="<?php echo $p;?>" <?php echo $fPrio===$p?'selected':'';?>><?php echo $p;?></option><?php endforeach;?>
    </select>
    <select name="stat" class="form-select" style="width:130px" onchange="this.form.submit()">
      <option value="">Statut</option>
      <?php foreach(array_keys($statC) as $s):?><option value="<?php echo $s;?>" <?php echo $fStat===$s?'selected':'';?>><?php echo str_replace('_',' ',$s);?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fPrio||$fStat||$fType):?><a href="dossiers.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="USSS.openModal('newDossierModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouveau Dossier</button>
</div>

<div class="tbl-wrap">
<table class="tbl">
<thead><tr><th>Référence</th><th>Titre</th><th>Type</th><th>Classification</th><th>Priorité</th><th>Agent</th><th>Division</th><th>Statut</th><th>Ouverture</th><th></th></tr></thead>
<tbody>
<?php if(empty($dossiers)):?>
<tr><td colspan="10" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-folder-open" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun dossier</td></tr>
<?php else: foreach($dossiers as $d):[$pc]=$prioC[$d['priorite']]??['b-slate'];[$sc]=$statC[$d['statut']]??['b-slate'];$hc=$habCls[$d['niveau_classification']??'CONFIDENTIEL']??'cls-conf';?>
<tr>
  <td><span class="ref"><?php echo htmlspecialchars($d['reference']);?></span></td>
  <td style="max-width:220px">
    <div style="font-weight:600;font-size:.84rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($d['titre']);?></div>
  </td>
  <td><span class="badge b-slate" style="font-size:.6rem"><?php echo str_replace('_',' ',$d['type']);?></span></td>
  <td><span class="cls-stamp <?php echo $hc;?>" style="font-size:.58rem"><?php echo $d['niveau_classification']??'—';?></span></td>
  <td><span class="badge <?php echo $pc;?>" style="font-size:.6rem"><?php echo $d['priorite'];?></span></td>
  <td style="font-size:.78rem">
    <div><?php echo htmlspecialchars($d['nom_rp'].' '.$d['prenom_rp']);?></div>
    <div class="ref" style="font-size:.65rem"><?php echo $d['badge_number'];?></div>
  </td>
  <td style="font-size:.75rem;color:var(--txt-s)"><?php echo $d['div_nom']?htmlspecialchars($d['div_nom']):'—';?></td>
  <td>
    <form method="POST" style="margin:0">
      <input type="hidden" name="action" value="update_statut"><input type="hidden" name="dossier_id" value="<?php echo $d['id'];?>">
      <select name="statut" class="form-select" style="padding:.28rem .5rem;font-size:.7rem;width:115px" onchange="this.form.submit()">
        <?php foreach(array_keys($statC) as $s):?><option value="<?php echo $s;?>" <?php echo $d['statut']===$s?'selected':'';?>><?php echo str_replace('_',' ',$s);?></option><?php endforeach;?>
      </select>
    </form>
  </td>
  <td class="mono" style="font-size:.68rem;color:var(--txt-m)"><?php echo $d['date_ouverture']?date('d.m.Y',strtotime($d['date_ouverture'])):'—';?></td>
  <td>
    <button class="btn btn-ghost btn-xs" onclick='showDossier(<?php echo json_encode($d,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE);?>)' title="Détail"><i class="fas fa-eye"></i></button>
  </td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table>
</div>

<?php if($totalPages>1):?><div class="pag">
  <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>&prio=<?php echo $fPrio;?>">← Préc.</a><?php endif;?>
  <span class="pag-cur">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
  <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>&prio=<?php echo $fPrio;?>">Suiv. →</a><?php endif;?>
</div><?php endif;?>

<!-- MODAL NOUVEAU DOSSIER -->
<div id="newDossierModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-folder-plus"></i> Nouveau Dossier</h2><button class="modal-cl" onclick="USSS.closeModal('newDossierModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_dossier">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required placeholder="Dossier d'enquête sur…"></div>
    <div class="form-group"><label class="form-label">Type *</label>
      <select name="type" class="form-select" required>
        <option value="PROTECTION_PERSONNALITE">Protection Personnalité</option>
        <option value="ENQUETE_FINANCIERE">Enquête Financière</option>
        <option value="CONTRE_TERRORISME">Contre-Terrorisme</option>
        <option value="ESPIONNAGE">Espionnage</option>
        <option value="CRIME_ORGANISE">Crime Organisé</option>
        <option value="AUTRE">Autre</option>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Priorité</label>
      <select name="priorite" class="form-select">
        <?php foreach(['FAIBLE','MOYENNE','HAUTE','CRITIQUE'] as $p):?><option value="<?php echo $p;?>" <?php echo $p==='MOYENNE'?'selected':'';?>><?php echo $p;?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Classification</label>
      <select name="niveau_classification" class="form-select">
        <option value="CONFIDENTIEL">CONFIDENTIEL</option><option value="SECRET">SECRET</option><option value="TOP_SECRET">TOP SECRET</option>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Agent responsable *</label>
      <select name="agent_responsable" class="form-select" required>
        <option value="">Sélectionner…</option>
        <?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>"><?php echo htmlspecialchars($a['nom_rp'].' '.$a['prenom_rp'].' ('.$a['badge_number'].')');?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Division</label>
      <select name="division_responsable" class="form-select"><option value="">Aucune</option><?php foreach($divisions as $dv):?><option value="<?php echo $dv['id'];?>"><?php echo htmlspecialchars($dv['nom']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Date d'ouverture</label><input type="date" name="date_ouverture" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newDossierModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>

<!-- MODAL DÉTAIL DOSSIER -->
<div id="viewDossierModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-folder-open"></i> <span id="vd-ref"></span></h2><button class="modal-cl" onclick="USSS.closeModal('viewDossierModal')"><i class="fas fa-times"></i></button></div>
<div class="modal-bd" id="vd-body"></div>
<div class="modal-ft"><button class="btn btn-ghost" onclick="USSS.closeModal('viewDossierModal')">Fermer</button></div>
</div></div>

<script>
function showDossier(d) {
  document.getElementById('vd-ref').textContent = d.reference;
  const clsMap = {CONFIDENTIEL:'cls-conf',SECRET:'cls-secret',TOP_SECRET:'cls-ts'};
  document.getElementById('vd-body').innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;margin-bottom:1rem">
      <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;padding:.65rem .85rem"><div class="form-label">Priorité</div><div style="margin-top:.2rem;font-weight:600">${d.priorite||'—'}</div></div>
      <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;padding:.65rem .85rem"><div class="form-label">Type</div><div style="margin-top:.2rem;font-size:.8rem">${(d.type||'').replace(/_/g,' ')}</div></div>
      <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;padding:.65rem .85rem"><div class="form-label">Classification</div><div style="margin-top:.2rem"><span class="cls-stamp ${clsMap[d.niveau_classification]||'cls-conf'}" style="font-size:.62rem">${d.niveau_classification||'—'}</span></div></div>
    </div>
    <div style="margin-bottom:.85rem"><div class="form-label" style="margin-bottom:.35rem">Titre</div><div style="font-size:.95rem;font-weight:600">${d.titre||'—'}</div></div>
    <div style="margin-bottom:.85rem"><div class="form-label" style="margin-bottom:.35rem">Agent responsable</div><div style="font-size:.82rem">${d.nom_rp||''} ${d.prenom_rp||''} · <span class="ref" style="font-size:.72rem">${d.badge_number||''}</span></div></div>
    ${d.description?`<div><div class="form-label" style="margin-bottom:.35rem">Description</div><div style="font-size:.82rem;line-height:1.65;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;padding:.85rem">${d.description.replace(/\n/g,'<br>')}</div></div>`:''}
  `;
  USSS.openModal('viewDossierModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>