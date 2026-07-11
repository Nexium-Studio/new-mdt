<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Interventions';
$db = Database::getInstance()->getConnection();
$msg='';$msgType='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='create') {
        try {
            $year=date('Y');
            $seq=(int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM sams_interventions")->fetchColumn();
            $num='INT-'.$year.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO sams_interventions (numero,type,urgence,statut,patient_id,lieu,description,medecins,notes_terrain,heure_appel,created_by) VALUES (?,?,?,'en_attente',?,?,?,?,?,NOW(),?)")
               ->execute([$num,$_POST['type'],$_POST['urgence'],intval($_POST['patient_id'])?:null,trim($_POST['lieu']),trim($_POST['description']??''),json_encode([]),trim($_POST['notes_terrain']??''),$_SESSION['user_id']]);
            $msg="Intervention $num créée.";$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='update_statut') {
        $extra=''; $params=[$_POST['statut']];
        if($_POST['statut']==='en_cours'){$extra=",heure_depart=NOW()";}
        elseif($_POST['statut']==='termine'){$extra=",heure_fin=NOW()";}
        $db->prepare("UPDATE sams_interventions SET statut=?{$extra} WHERE id=?")->execute([...$params,intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }
    if ($action==='add_notes') {
        $db->prepare("UPDATE sams_interventions SET notes_terrain=? WHERE id=?")->execute([trim($_POST['notes']),intval($_POST['id'])]);
        $msg='Notes enregistrées.';$msgType='success';
    }
    if ($action==='assign') {
        $ids=array_values(array_unique(array_map('intval',$_POST['medecins']??[])));
        $db->prepare("UPDATE sams_interventions SET medecins=? WHERE id=?")->execute([json_encode($ids),intval($_POST['id'])]);
        $msg='Médecins assignés.';$msgType='success';
    }
}

$filterStatut=$_GET['s']??'all';$filterUrgence=$_GET['u']??'all';$search=trim($_GET['q']??'');
// Créateur affiché en Nom + Prénom (profil), jamais le pseudo (règle SAMS §5)
$q="SELECT si.*,p.firstname,p.lastname,cp.firstname AS creator_fn,cp.lastname AS creator_ln
    FROM sams_interventions si
    LEFT JOIN profiles p ON p.id=si.patient_id
    LEFT JOIN users u ON u.id=si.created_by
    LEFT JOIN profiles cp ON cp.id=u.profile_id
    WHERE 1=1";
$params=[];
if($filterStatut!=='all'){$q.=" AND si.statut=?";$params[]=$filterStatut;}
if($filterUrgence!=='all'){$q.=" AND si.urgence=?";$params[]=$filterUrgence;}
if($search){$q.=" AND (si.lieu LIKE ? OR si.numero LIKE ? OR p.lastname LIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
$q.=" ORDER BY FIELD(si.urgence,'critique','elevee','moderee','faible'), si.created_at DESC LIMIT 100";
$stmt=$db->prepare($q);$stmt->execute($params);$interventions=$stmt->fetchAll();

// Patients pour le sélecteur (SamsPicker)
$allPatients=[];try{$allPatients=$db->query("SELECT id,firstname,lastname,phone,avatar FROM profiles ORDER BY lastname LIMIT 500")->fetchAll();}catch(Exception $e){}
$patientsPicker=[];
foreach($allPatients as $p){
    $patientsPicker[]=['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname']),'sub'=>$p['phone']??'','avatar'=>$p['avatar']??null];
}
// Membres SAMS pour l'assignation (Nom + Prénom, jamais le pseudo)
$samsStaff=[];try{$samsStaff=$db->query("SELECT u.id,u.faction_grade,u.avatar,p.firstname,p.lastname FROM users u LEFT JOIN profiles p ON p.id=u.profile_id WHERE u.faction='SAMS' AND u.is_active=1")->fetchAll();}catch(Exception $e){}
$staffMap=[]; $staffPicker=[];
foreach($samsStaff as $s){
    $nm=mdt_rp_display_name($s['firstname']??null,$s['lastname']??null,'');
    if($nm===''||$nm==='—') $nm='Profil non renseigné';
    $staffMap[(int)$s['id']]=['name'=>$nm,'grade'=>$s['faction_grade']??''];
    $staffPicker[]=['id'=>(int)$s['id'],'name'=>$nm,'sub'=>$s['faction_grade']??'','avatar'=>$s['avatar']??null];
}

$statsCounts=[];
try{$r=$db->query("SELECT statut,COUNT(*) as n FROM sams_interventions GROUP BY statut");foreach($r->fetchAll() as $row)$statsCounts[$row['statut']]=$row['n'];}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
$TYPES=['traumatisme'=>'Traumatisme','maladie'=>'Maladie','accident'=>'Accident','overdose'=>'Overdose','noyade'=>'Noyade','incendie'=>'Incendie','violence'=>'Violence','autre'=>'Autre'];
$URGENCES=['critique'=>['Critique','badge-red'],'elevee'=>['Élevée','badge-orange'],'moderee'=>['Modérée','badge-blue'],'faible'=>['Faible','badge-green']];
$STATUTS=['en_attente'=>['En attente','badge-yellow'],'en_cours'=>['En cours','badge-blue'],'termine'=>['Terminée','badge-green'],'annule'=>['Annulée','badge-gray']];
?>
<style>
.int-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.1rem;margin-bottom:.85rem;transition:all .2s;cursor:pointer}
.int-card:hover{border-color:var(--bdr-h)}
.int-card.urgence-critique{border-left:4px solid #dc2626}
.int-card.urgence-elevee{border-left:4px solid #f97316}
.int-card.urgence-moderee{border-left:4px solid #3b82f6}
.int-card.urgence-faible{border-left:4px solid #10b981}
.int-detail{display:none;padding-top:.85rem;margin-top:.85rem;border-top:1px solid rgba(255,255,255,.05)}
.int-detail.open{display:block}
.med-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.28rem .7rem;border-radius:20px;font-size:.72rem;font-weight:600;
  background:rgba(225,29,72,.1);border:1px solid rgba(225,29,72,.28);color:var(--red-l)}
.med-chip i{font-size:.62rem;opacity:.7}
/* chip patient choisi dans le formulaire */
.chosen-chip{display:flex;align-items:center;gap:.7rem;padding:.6rem .8rem;background:rgba(225,29,72,.07);
  border:1px solid rgba(225,29,72,.25);border-radius:10px;margin-top:.5rem}
.chosen-chip.hidden{display:none}
.chosen-chip .cc-av{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,rgba(225,29,72,.25),rgba(159,18,57,.12));
  border:1px solid rgba(225,29,72,.25);display:grid;place-items:center;font-weight:700;color:var(--red-l);font-size:.8rem;overflow:hidden;flex-shrink:0}
.chosen-chip .cc-av img{width:100%;height:100%;object-fit:cover}
.chosen-chip .cc-rm{margin-left:auto;width:26px;height:26px;border-radius:7px;border:1px solid var(--bdr);background:rgba(255,255,255,.04);
  color:var(--txt-s);cursor:pointer;display:grid;place-items:center;transition:all .15s}
.chosen-chip .cc-rm:hover{background:rgba(225,29,72,.18);color:var(--red-l)}
.pick-btn{width:100%;justify-content:center;border-style:dashed!important}
</style>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
    <?php foreach(['en_attente'=>['En attente','yellow','fa-hourglass-half'],'en_cours'=>['En cours','blue','fa-truck-medical'],'termine'=>['Terminées','green','fa-circle-check'],'annule'=>['Annulées','gray','fa-ban']] as $s=>[$l,$c,$ic]):?>
    <div class="kpi" data-color="<?php echo $c;?>"><div class="kpi-ico"><i class="fas <?php echo $ic;?>"></i></div><div class="kpi-lbl"><?php echo $l;?></div><div class="kpi-val"><?php echo $statsCounts[$s]??0;?></div></div>
    <?php endforeach;?>
</div>

<div class="doc-toolbar">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;flex:1">
        <div class="search-wrap"><i class="fas fa-search"></i><input type="text" name="q" placeholder="Lieu, numéro, patient..." value="<?php echo htmlspecialchars($search);?>"></div>
        <select name="s" class="form-select" style="width:140px" onchange="this.form.submit()">
            <option value="all" <?php echo $filterStatut==='all'?'selected':'';?>>Tous statuts</option>
            <?php foreach($STATUTS as $k=>[$l,$c]):?><option value="<?php echo $k;?>" <?php echo $filterStatut===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <select name="u" class="form-select" style="width:130px" onchange="this.form.submit()">
            <option value="all" <?php echo $filterUrgence==='all'?'selected':'';?>>Toutes urgences</option>
            <?php foreach($URGENCES as $k=>[$l,$c]):?><option value="<?php echo $k;?>" <?php echo $filterUrgence===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <?php if($search||$filterStatut!=='all'||$filterUrgence!=='all'):?><a href="interventions.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <button onclick="Sams.openModal('newIntModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle intervention</button>
</div>

<?php if(empty($interventions)):?>
<div class="empty-state"><i class="fas fa-ambulance empty-icon"></i><p>Aucune intervention.</p></div>
<?php else: foreach($interventions as $iv):
    [$urgLabel,$urgBadge]=$URGENCES[$iv['urgence']]??['—','badge-gray'];
    [$statLabel,$statBadge]=$STATUTS[$iv['statut']]??['—','badge-gray'];
    $nom=!empty($iv['firstname'])?trim($iv['firstname'].' '.$iv['lastname']):'Patient inconnu';
    $creator=mdt_rp_display_name($iv['creator_fn']??null,$iv['creator_ln']??null,'');
    if($creator===''||$creator==='—') $creator='—';
    $meds=json_decode($iv['medecins']??'[]',true)?:[];
    $cid='iv-'.$iv['id'];
?>
<div class="int-card urgence-<?php echo $iv['urgence'];?>" onclick="toggleDetail('<?php echo $cid;?>')">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem">
        <div style="flex:1">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.3rem;flex-wrap:wrap">
                <span class="mono" style="font-size:.7rem;color:var(--txt-m)"><?php echo htmlspecialchars($iv['numero']??'—');?></span>
                <span class="badge <?php echo $urgBadge;?>"><?php echo $urgLabel;?></span>
                <span class="badge <?php echo $statBadge;?>"><?php echo $statLabel;?></span>
            </div>
            <div style="font-weight:700;font-size:.9rem;margin-bottom:.2rem"><i class="fas fa-map-marker-alt" style="color:var(--red-l);margin-right:.4rem"></i><?php echo htmlspecialchars($iv['lieu']);?></div>
            <div style="font-size:.75rem;color:var(--txt-s)"><?php echo $TYPES[$iv['type']]??$iv['type'];?> · Patient : <strong><?php echo htmlspecialchars($nom);?></strong> · Par <?php echo htmlspecialchars($creator);?> · <?php echo date('d/m/Y H:i',strtotime($iv['created_at']));?></div>
        </div>
        <i class="fas fa-chevron-down" style="color:var(--txt-m);font-size:.72rem;transition:transform .2s;flex-shrink:0;margin-top:.25rem" id="chev-<?php echo $cid;?>"></i>
    </div>
    <div class="int-detail" id="<?php echo $cid;?>" onclick="event.stopPropagation()">
        <?php if($iv['description']):?>
        <div style="background:var(--bg-ov);border-radius:8px;padding:.75rem;font-size:.82rem;color:var(--txt-s);margin-bottom:.75rem"><?php echo nl2br(htmlspecialchars($iv['description']));?></div>
        <?php endif;?>
        <!-- Médecins assignés -->
        <div style="margin-bottom:.85rem">
            <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.4rem">Médecins assignés</div>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <?php $shown=false; foreach($meds as $mid){ if(isset($staffMap[(int)$mid])){ $shown=true; echo '<span class="med-chip"><i class="fas fa-user-doctor"></i>'.htmlspecialchars($staffMap[(int)$mid]['name']).'</span>'; } }
                if(!$shown):?><span style="font-size:.75rem;color:var(--txt-m)">Aucun médecin assigné</span><?php endif;?>
            </div>
        </div>
        <!-- Timeline -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;margin-bottom:.85rem">
            <?php foreach([['Appel',$iv['heure_appel']],['Départ',$iv['heure_depart']],['Arrivée',$iv['heure_arrivee']],['Fin',$iv['heure_fin']]] as [$l,$v]):?>
            <div style="text-align:center;padding:.55rem;background:var(--bg-ov);border-radius:8px">
                <div style="font-size:.65rem;color:var(--txt-m);margin-bottom:.2rem"><?php echo $l;?></div>
                <div class="mono" style="font-size:.82rem;font-weight:700;color:<?php echo $v?'#f9fafb':'#374151';?>"><?php echo $v?date('H:i:s',strtotime($v)):'—';?></div>
            </div>
            <?php endforeach;?>
        </div>
        <?php if($iv['notes_terrain']):?>
        <div style="background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:.75rem;font-size:.8rem;color:#fde68a;margin-bottom:.75rem"><i class="fas fa-clipboard" style="margin-right:.4rem"></i><?php echo nl2br(htmlspecialchars($iv['notes_terrain']));?></div>
        <?php endif;?>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <?php if($iv['statut']==='en_attente'):?>
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $iv['id'];?>"><input type="hidden" name="statut" value="en_cours"><button type="submit" class="btn btn-success btn-sm"><i class="fas fa-play"></i> Démarrer</button></form>
            <button type="button" class="btn btn-danger btn-sm" onclick="cancelInt(<?php echo $iv['id'];?>,'<?php echo addslashes($iv['numero']??'');?>')"><i class="fas fa-ban"></i> Annuler</button>
            <?php elseif($iv['statut']==='en_cours'):?>
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $iv['id'];?>"><input type="hidden" name="statut" value="termine"><button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Terminer</button></form>
            <button type="button" class="btn btn-danger btn-sm" onclick="cancelInt(<?php echo $iv['id'];?>,'<?php echo addslashes($iv['numero']??'');?>')"><i class="fas fa-ban"></i> Annuler</button>
            <?php endif;?>
            <button type="button" onclick="openNotesModal(<?php echo $iv['id'];?>,<?php echo htmlspecialchars(json_encode($iv['notes_terrain']??'',JSON_HEX_APOS|JSON_HEX_QUOT),ENT_QUOTES);?>)" class="btn btn-secondary btn-sm"><i class="fas fa-clipboard"></i> Notes</button>
            <button type="button" onclick="assignMedecins(<?php echo $iv['id'];?>,<?php echo htmlspecialchars(json_encode(array_map('intval',$meds)),ENT_QUOTES);?>)" class="btn btn-secondary btn-sm"><i class="fas fa-user-plus"></i> Assigner</button>
        </div>
    </div>
</div>
<?php endforeach;endif;?>

<!-- MODAL NOUVELLE INTERVENTION -->
<div id="newIntModal" class="modal-ov hidden">
    <div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-ambulance"></i> Nouvelle intervention</h2><button class="modal-close" onclick="Sams.closeModal('newIntModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create"><input type="hidden" name="patient_id" id="patientIdInput">
        <div class="form-grid">
            <div class="form-group"><label class="form-label required">Type</label><select name="type" class="form-select" required><?php foreach($TYPES as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label required">Niveau d'urgence</label><select name="urgence" class="form-select" required><?php foreach($URGENCES as $k=>[$l,$c]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
            <div class="form-group form-full"><label class="form-label required">Lieu d'intervention</label><input type="text" name="lieu" class="form-input" required placeholder="Adresse, quartier, lieu-dit..."></div>
            <div class="form-group form-full">
                <label class="form-label">Patient</label>
                <button type="button" class="btn btn-secondary btn-sm pick-btn" onclick="pickPatient()"><i class="fas fa-user-plus"></i> Ajouter un patient</button>
                <div class="chosen-chip hidden" id="chosenPatient"></div>
            </div>
            <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" placeholder="Détails de l'appel, état du patient..."></textarea></div>
            <div class="form-group form-full"><label class="form-label">Notes terrain</label><textarea name="notes_terrain" class="form-textarea" placeholder="Observations sur place..."></textarea></div>
        </div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('newIntModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-ambulance"></i> Créer l'intervention</button></div>
    </form></div>
</div>

<!-- MODAL NOTES TERRAIN -->
<div id="notesModal" class="modal-ov hidden">
    <div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-clipboard"></i> Notes terrain</h2><button class="modal-close" onclick="Sams.closeModal('notesModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_notes"><input type="hidden" name="id" id="notesIntId">
        <div class="form-group"><label class="form-label required">Notes</label><textarea name="notes" id="notesText" class="form-textarea" style="min-height:150px" required></textarea></div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('notesModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
    </form></div>
</div>

<?php include __DIR__ . '/includes/components.php'; ?>
<script>
const PATIENTS = <?php echo json_encode($patientsPicker, JSON_UNESCAPED_UNICODE); ?>;
const STAFF    = <?php echo json_encode($staffPicker, JSON_UNESCAPED_UNICODE); ?>;

function toggleDetail(id){const d=document.getElementById(id),c=document.getElementById('chev-'+id);const o=d.classList.toggle('open');if(c)c.style.transform=o?'rotate(180deg)':'';}
function openNotesModal(id,notes){document.getElementById('notesIntId').value=id;document.getElementById('notesText').value=notes||'';Sams.openModal('notesModal');}

function pickPatient(){
    SamsPicker.open({title:'Sélection du patient', items:PATIENTS, onConfirm:function(it){
        if(!it) return;
        document.getElementById('patientIdInput').value=it.id;
        const c=document.getElementById('chosenPatient');
        const ava=it.avatar?('<img src="'+it.avatar+'" alt="">'):((it.name||'?').charAt(0).toUpperCase());
        c.innerHTML='<div class="cc-av">'+ava+'</div><div><div style="font-weight:700;font-size:.85rem">'+escapeHtmlInt(it.name)+'</div>'+(it.sub?'<div style="font-size:.72rem;color:var(--txt-m)">'+escapeHtmlInt(it.sub)+'</div>':'')+'</div><button type="button" class="cc-rm" onclick="clearPatient()"><i class="fas fa-times"></i></button>';
        c.classList.remove('hidden');
    }});
}
function clearPatient(){document.getElementById('patientIdInput').value='';const c=document.getElementById('chosenPatient');c.classList.add('hidden');c.innerHTML='';}
function escapeHtmlInt(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}

function assignMedecins(intId,current){
    SamsPicker.open({title:'Assigner des médecins SAMS', items:STAFF, multiple:true, selected:(current||[]).map(String), onConfirm:function(arr){
        const f=document.createElement('form'); f.method='POST'; f.style.display='none';
        f.innerHTML='<input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="'+intId+'">';
        arr.forEach(function(it){const i=document.createElement('input');i.type='hidden';i.name='medecins[]';i.value=it.id;f.appendChild(i);});
        document.body.appendChild(f); f.submit();
    }});
}

function cancelInt(id,num){
    SamsConfirm({title:"Annuler l'intervention", target:num, message:"Cette intervention sera marquée comme annulée.", okText:"Annuler l'intervention", onConfirm:function(){
        const f=document.createElement('form'); f.method='POST'; f.style.display='none';
        f.innerHTML='<input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="'+id+'"><input type="hidden" name="statut" value="annule">';
        document.body.appendChild(f); f.submit();
    }});
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>
