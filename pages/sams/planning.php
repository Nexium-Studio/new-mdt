<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Planning des Gardes';
$db = Database::getInstance()->getConnection();
$msg='';$msgType='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='add') {
        try {
            $db->prepare("INSERT INTO sams_planning (user_id,date_garde,heure_debut,heure_fin,type_garde,poste,statut,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([intval($_POST['user_id']),$_POST['date_garde'],$_POST['heure_debut'],$_POST['heure_fin'],$_POST['type_garde'],trim($_POST['poste']??''),$_POST['statut']??'planifie',trim($_POST['notes']??''),$_SESSION['user_id']]);
            $msg='Garde planifiée.';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='update_statut') {
        $db->prepare("UPDATE sams_planning SET statut=? WHERE id=?")->execute([$_POST['statut'],intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }
    if ($action==='delete') {
        $db->prepare("DELETE FROM sams_planning WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Supprimé.';$msgType='success';
    }
}

$weekStart=$_GET['week']??date('Y-m-d',strtotime('monday this week'));
$weekEnd=date('Y-m-d',strtotime($weekStart.' +6 days'));

$gardes=[];
try {
    $stmt=$db->prepare("SELECT sp.*,NULLIF(TRIM(CONCAT(COALESCE(pp.firstname,''),' ',COALESCE(pp.lastname,''))),'') AS username,u.faction_rank FROM sams_planning sp JOIN users u ON u.id=sp.user_id LEFT JOIN profiles pp ON pp.id=u.profile_id WHERE sp.date_garde BETWEEN ? AND ? ORDER BY sp.date_garde,sp.heure_debut");
    $stmt->execute([$weekStart,$weekEnd]);
    $rawGardes=$stmt->fetchAll();
    foreach($rawGardes as $g) $gardes[$g['date_garde']][]=$g;
} catch(Exception $e){}

$samsStaff=[];try{$samsStaff=$db->query("SELECT u.id,NULLIF(TRIM(CONCAT(COALESCE(pp.firstname,''),' ',COALESCE(pp.lastname,''))),'') AS username,u.faction_rank FROM users u LEFT JOIN profiles pp ON pp.id=u.profile_id WHERE u.faction='SAMS' AND u.is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}

$prevWeek=date('Y-m-d',strtotime($weekStart.' -7 days'));
$nextWeek=date('Y-m-d',strtotime($weekStart.' +7 days'));

$TYPES=['jour'=>['Jour','bg-blue'],'nuit'=>['Nuit','bg-purple'],'astreinte'=>['Astreinte','bg-yellow'],'formation'=>['Formation','bg-green']];
$POSTES=['Urgences','Hélicoptère','Unité mobile','Pharmacie','Bloc opératoire','Accueil','Autre'];
$STATUTS=['planifie'=>'bg-gray','confirme'=>'bg-blue','effectue'=>'bg-green','absent'=>'bg-red'];

include __DIR__ . '/includes/header.php';
?>
<style>
.week-grid{display:grid;grid-template-columns:120px repeat(7,1fr);gap:1px;background:var(--bdr);border-radius:12px;overflow:hidden;border:1px solid var(--bdr)}
.week-cell{background:var(--bg-c);padding:.75rem;min-height:80px;vertical-align:top}
.week-header{background:var(--bg-e);padding:.65rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-s)}
.week-header.today{background:rgba(220,38,38,.1);color:var(--red-xl)}
.garde-chip{padding:.35rem .6rem;border-radius:7px;margin-bottom:.35rem;font-size:.72rem;cursor:pointer;transition:all .15s}
.garde-chip:hover{opacity:.85}
.time-slot{color:var(--txt-m);font-size:.65rem;margin-bottom:.12rem}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;align-items:center;gap:.65rem">
        <a href="?week=<?php echo $prevWeek;?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-left"></i></a>
        <span style="font-weight:700;font-size:.9rem">
            Semaine du <?php echo date('d',strtotime($weekStart));?> au <?php echo date('d/m/Y',strtotime($weekEnd));?>
        </span>
        <a href="?week=<?php echo $nextWeek;?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-right"></i></a>
        <a href="?week=<?php echo date('Y-m-d',strtotime('monday this week'));?>" class="btn btn-secondary btn-sm">Aujourd'hui</a>
    </div>
    <button onclick="Sams.openModal('addGardeModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Planifier une garde</button>
</div>

<!-- Grille semaine -->
<div class="week-grid" style="margin-bottom:1.5rem">
    <div class="week-header">Médecin</div>
    <?php $days=['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
    for($i=0;$i<7;$i++):
        $d=date('Y-m-d',strtotime($weekStart." +{$i} days"));
        $isToday=$d===date('Y-m-d');
    ?>
    <div class="week-header <?php echo $isToday?'today':'';?>"><?php echo $days[$i];?> <?php echo date('d',strtotime($d));?></div>
    <?php endfor;?>
    <!-- Lignes par staff -->
    <?php foreach($samsStaff as $s):?>
    <div class="week-cell" style="background:var(--bg-ov);display:flex;align-items:center;gap:.5rem">
        <div style="width:26px;height:26px;border-radius:7px;background:rgba(220,38,38,.15);color:var(--red-l);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;flex-shrink:0"><?php echo strtoupper(substr($s['username'],0,1));?></div>
        <div><div style="font-size:.78rem;font-weight:600"><?php echo htmlspecialchars($s['username']);?></div><div style="font-size:.62rem;color:var(--txt-m)"><?php echo htmlspecialchars($s['faction_rank']??'—');?></div></div>
    </div>
    <?php for($i=0;$i<7;$i++):
        $d=date('Y-m-d',strtotime($weekStart." +{$i} days"));
        $dayGardes=array_filter($gardes[$d]??[],fn($g)=>$g['user_id']==$s['id']);
    ?>
    <div class="week-cell">
        <?php foreach($dayGardes as $g):
            [$tLabel,$tBadge]=$TYPES[$g['type_garde']]??['—','bg-gray'];
            $sBadge=$STATUTS[$g['statut']]??'bg-gray';
        ?>
        <div class="garde-chip <?php echo $tBadge;?>" onclick="Sams.openModal('editGardeModal')" style="position:relative">
            <div class="time-slot"><?php echo substr($g['heure_debut'],0,5);?>–<?php echo substr($g['heure_fin'],0,5);?></div>
            <div style="font-weight:700;font-size:.73rem"><?php echo $tLabel;?></div>
            <?php if($g['poste']):?><div style="font-size:.65rem;opacity:.8"><?php echo htmlspecialchars($g['poste']);?></div><?php endif;?>
            <?php if($g['statut']==='absent'):?><div style="font-size:.65rem;color:#fca5a5">ABSENT</div><?php endif;?>
        </div>
        <?php endforeach;?>
    </div>
    <?php endfor;endforeach;?>
</div>

<!-- Liste des gardes de la semaine -->
<div class="card">
    <div class="card-title"><i class="fas fa-list"></i> Détail des gardes de la semaine</div>
    <?php $allWeekGardes=array_merge(...array_values($gardes));
    if(empty($allWeekGardes)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucune garde planifiée cette semaine</div>
    <?php else:?>
    <table class="tbl">
        <thead><tr><th>Agent</th><th>Date</th><th>Horaires</th><th>Type</th><th>Poste</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($allWeekGardes as $g):[$tLabel,$tBadge]=$TYPES[$g['type_garde']]??['—','bg-gray'];$sBadge=$STATUTS[$g['statut']]??'bg-gray';$sLabel=['planifie'=>'Planifié','confirme'=>'Confirmé','effectue'=>'Effectué','absent'=>'Absent'][$g['statut']]??$g['statut'];?>
        <tr>
            <td style="font-weight:700"><?php echo htmlspecialchars($g['username']);?></td>
            <td><?php echo date('l d/m',strtotime($g['date_garde']));?></td>
            <td class="mono" style="font-size:.8rem"><?php echo substr($g['heure_debut'],0,5);?>–<?php echo substr($g['heure_fin'],0,5);?></td>
            <td><span class="badge <?php echo $tBadge;?>"><?php echo $tLabel;?></span></td>
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($g['poste']??'—');?></td>
            <td>
                <form method="POST" style="margin:0;display:inline-flex;gap:.3rem">
                    <input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $g['id'];?>">
                    <select name="statut" class="form-select" style="width:120px;font-size:.75rem" onchange="this.form.submit()">
                        <?php foreach(['planifie'=>'Planifié','confirme'=>'Confirmé','effectue'=>'Effectué','absent'=>'Absent'] as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $g['statut']===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?>
                    </select>
                </form>
            </td>
            <td><button type="button" class="btn btn-ghost btn-xs" onclick="delGarde(<?php echo $g['id'];?>,<?php echo htmlspecialchars(json_encode(($g['username']??'').' — '.date('d/m/Y',strtotime($g['date_garde']))),ENT_QUOTES);?>)"><i class="fas fa-trash"></i></button></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table>
    <?php endif;?>
</div>

<!-- MODAL AJOUT GARDE -->
<div id="addGardeModal" class="modal-ov hidden">
    <div class="modal"><div class="modal-hd"><h2><i class="fas fa-calendar-plus"></i> Planifier une garde</h2><button class="modal-cl" onclick="Sams.closeModal('addGardeModal')">×</button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Agent *</label><select name="user_id" class="form-select" required><?php foreach($samsStaff as $s):?><option value="<?php echo $s['id'];?>"><?php echo htmlspecialchars($s['username'].' — '.$s['faction_rank']);?></option><?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Type de garde *</label><select name="type_garde" class="form-select" required><?php foreach($TYPES as $k=>[$l,$c]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Date *</label><input type="date" name="date_garde" class="form-input" value="<?php echo date('Y-m-d');?>" required></div>
            <div class="form-group"><label class="form-label">Poste</label><select name="poste" class="form-select"><option value="">—</option><?php foreach($POSTES as $p):?><option><?php echo $p;?></option><?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Heure début *</label><input type="time" name="heure_debut" class="form-input" value="08:00" required></div>
            <div class="form-group"><label class="form-label">Heure fin *</label><input type="time" name="heure_fin" class="form-input" value="20:00" required></div>
            <div class="form-group form-full"><label class="form-label">Notes</label><input type="text" name="notes" class="form-input" placeholder="Remarques particulières..."></div>
        </div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addGardeModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Planifier</button></div>
    </form></div>
</div>
<?php include __DIR__ . '/includes/components.php'; ?>
<script>
function delGarde(id,label){
    SamsConfirm({title:'Supprimer la garde',target:label,message:'Cette garde planifiée sera supprimée.',okText:'Supprimer',onConfirm:function(){
        const f=document.createElement('form');f.method='POST';f.style.display='none';
        f.innerHTML='<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'+id+'">';
        document.body.appendChild(f);f.submit();
    }});
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>