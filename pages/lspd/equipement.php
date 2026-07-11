<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Équipement'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

require_once __DIR__ . '/../../includes/faction_acl.php';
require_once __DIR__ . '/../../includes/lspd_cdn.php';
require_once __DIR__ . '/../../includes/matricule_helper.php';
$canEdit = faction_acl_can($db, $uid, 'LSPD', 'equipement.edit');

// ── AJAX champ image ──────────────────────────────────────────────────────────
$__imgAction = $_POST['img_action'] ?? $_GET['img_action'] ?? '';
if ($__imgAction !== '') {
    header('Content-Type: application/json');
    if ($__imgAction === 'upload') { echo json_encode(lspd_cdn_store_file_raw($_FILES['file'] ?? [])); exit; }
    if ($__imgAction === 'cdn_list') {
        echo json_encode(['success'=>true,'images'=>array_map(fn($r)=>['url'=>$r['public_url'],'title'=>$r['title']], lspd_cdn_all($db))]); exit;
    }
    echo json_encode(['success'=>false,'error'=>'Action inconnue']); exit;
}
try { $db->exec("ALTER TABLE lspd_equipment ADD COLUMN IF NOT EXISTS images TEXT NULL"); } catch(Exception $e){}
// Grade lu depuis la DB (faction_rank)
$__uid = (int)($_SESSION['user_id'] ?? 0);
try { $__grS=$db->prepare('SELECT faction_rank FROM users WHERE id=? LIMIT 1');$__grS->execute([$__uid]);$__grRow=$__grS->fetch();$__myRank=$__grRow['faction_rank']??''; } catch(Exception $e){ $__myRank=$_SESSION['user_faction_rank']??''; }
// Grades LSPD dynamiques
try { $__gS=$db->prepare("SELECT fg.name FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id WHERE f.faction_key='LSPD' ORDER BY fg.rank_order ASC");$__gS->execute();$__gRanks=array_column($__gS->fetchAll(),'name'); } catch(Exception $e){ $__gRanks=['Chief of Police','Assistant Chief','Deputy Chief','Captain','Lieutenant II','Lieutenant I','Sergeant II','Sergeant I','Police Officer III','Police Officer II','Police Officer I','Rookie']; }
$__gLevel=[];foreach($__gRanks as $__gi=>$__gn){$__gLevel[$__gn]=count($__gRanks)-$__gi;}
$__myLvl = $__gLevel[$__myRank] ?? 0;
$__maxLvl = count($__gRanks);
// Seuil Sergeant I = 40% de la hiérarchie
$__sgtThreshold = $__maxLvl > 0 ? (int)ceil($__maxLvl * 0.40) : 5;
$canManage = hasRole(['*','Administrateur','Développeur','Staff']) || $__myLvl >= $__sgtThreshold;

if ($_SERVER['REQUEST_METHOD']==='POST'&&$canManage){
    $a=$_POST['action']??'';
    if ($a==='assign'){
        try{
            $imgVal = lspd_equip_images_val($_POST['images'] ?? '');
            $db->prepare("INSERT INTO lspd_equipment (officer_id,equipment_type,serial_number,date_assigned,condition_status,notes,status,images) VALUES (?,?,?,?,?,?,?,?)")->execute([intval($_POST['officer_id']),$_POST['equip_type'],trim($_POST['serial']??''),date('Y-m-d'),$_POST['condition']??'excellent',trim($_POST['notes']??''),'assigned',$imgVal]);$msg='Équipement assigné.';$msgType='success';
        }catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($a==='return'){
        $db->prepare("UPDATE lspd_equipment SET status='returned',date_returned=CURDATE() WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Équipement retourné.';$msgType='success';
    }
}
// Édition d'une entrée — permission dédiée equipement.edit
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update' && $canEdit){
    try{
        $imgVal = lspd_equip_images_val($_POST['images'] ?? '');
        $db->prepare("UPDATE lspd_equipment SET equipment_type=?,serial_number=?,condition_status=?,notes=?,images=? WHERE id=?")
           ->execute([$_POST['equip_type'],trim($_POST['serial']??''),$_POST['condition']??'excellent',trim($_POST['notes']??''),$imgVal,intval($_POST['id'])]);
        $msg='Équipement mis à jour.';$msgType='success';
    }catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
}

/** Normalise le champ images (JSON array d'URLs) en valeur stockable ou null. */
function lspd_equip_images_val($raw): ?string {
    $arr = json_decode((string)$raw, true);
    return (is_array($arr) && $arr) ? json_encode(array_values($arr)) : null;
}

$fType=$_GET['type']??'all'; $search=trim($_GET['q']??'');
$q="SELECT le.*,u.username,u.faction_rank FROM lspd_equipment le LEFT JOIN users u ON u.id=le.officer_id WHERE 1=1";
$params=[];
if ($fType!=='all'){$q.=" AND le.equipment_type=?";$params[]=$fType;}
if ($search){$q.=" AND (u.username LIKE ? OR le.serial_number LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;}
$q.=" ORDER BY le.date_assigned DESC LIMIT 100";
$stmt=$db->prepare($q);$stmt->execute($params);$equipment=$stmt->fetchAll();
$equipMatricules = lspd_matricules_map($db, array_column($equipment,'officer_id'));

$stats=[];try{foreach($db->query("SELECT equipment_type,COUNT(*) as n FROM lspd_equipment WHERE status='assigned' GROUP BY equipment_type")->fetchAll() as $r)$stats[$r['equipment_type']]=$r['n'];}catch(Exception $e){}
$allOfficers=[];try{$allOfficers=$db->query("SELECT id,username,faction_rank FROM users WHERE faction='LSPD' AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}

$ETYPES=['glock'=>'Glock','taser'=>'Taser','baton'=>'Matraque','flashlight'=>'Lampe','radio'=>'Radio','bodycam'=>'Bodycam','vest'=>'Gilet pare-balles','badge'=>'Badge','handcuffs'=>'Menottes','pepper_spray'=>'Spray poivre'];
$COND=['excellent'=>'bg-green','good'=>'bg-blue','fair'=>'bg-yellow','poor'=>'bg-orange','damaged'=>'bg-red'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" class="form-input" placeholder="Officier, n° de série..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="type" class="form-select" style="width:160px" onchange="this.form.submit()"><option value="all">Tous types</option><?php foreach($ETYPES as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    </form>
    <?php if($canManage):?><button onclick="LSPD.openModal('assignModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Assigner équipement</button><?php endif;?>
</div>
<div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Image</th><th>Type</th><th>N° Série</th><th>Officier</th><th>Matricule</th><th>Date assignation</th><th>Condition</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php if(empty($equipment)):?><tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucun équipement</td></tr>
    <?php else:foreach($equipment as $e):$cb=$COND[$e['condition_status']??'excellent']??'bg-gray';
        $eImgs = json_decode($e['images']??'[]',true)?:[]; $eImg = $eImgs[0]??'';
        $eMat = $equipMatricules[(int)$e['officer_id']] ?? null;
    ?>
    <tr>
        <td style="width:48px">
            <?php if($eImg):?><img src="<?php echo htmlspecialchars($eImg);?>" alt="" onclick="window.open('<?php echo htmlspecialchars($eImg);?>','_blank')" style="width:40px;height:40px;border-radius:7px;object-fit:cover;border:1px solid rgba(37,99,235,.25);cursor:pointer">
            <?php else:?><div style="width:40px;height:40px;border-radius:7px;background:rgba(37,99,235,.05);display:grid;place-items:center;color:var(--txt-m)"><i class="fas fa-image" style="font-size:.8rem;opacity:.4"></i></div><?php endif;?>
        </td>
        <td style="font-weight:600"><?php echo $ETYPES[$e['equipment_type']]??$e['equipment_type'];?></td>
        <td class="mono" style="font-size:.75rem;color:var(--txt-m)"><?php echo htmlspecialchars($e['serial_number']??'—');?></td>
        <td><?php echo htmlspecialchars($e['username']??'—');?> <span style="color:var(--txt-m);font-size:.73rem"><?php echo htmlspecialchars($e['faction_rank']??'');?></span></td>
        <td class="mono" style="font-size:.75rem;color:var(--blue-xl)"><?php echo $eMat?htmlspecialchars($eMat):'<span style="color:var(--txt-m)">—</span>';?></td>
        <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y',strtotime($e['date_assigned']));?></td>
        <td><span class="badge <?php echo $cb;?>"><?php echo $e['condition_status'];?></span></td>
        <td><span class="badge <?php echo $e['status']==='assigned'?'bg-green':($e['status']==='lost'?'bg-red':'bg-gray');?>"><?php echo $e['status'];?></span></td>
        <td><div style="display:flex;gap:.3rem;justify-content:flex-end">
            <?php if($canEdit):?><button class="btn btn-ghost btn-xs" onclick='openEquipEdit(<?php echo json_encode(["id"=>(int)$e["id"],"type"=>$e["equipment_type"],"serial"=>$e["serial_number"],"cond"=>$e["condition_status"],"notes"=>$e["notes"],"images"=>$eImgs], JSON_HEX_APOS|JSON_HEX_QUOT);?>)'><i class="fas fa-pen"></i></button><?php endif;?>
            <?php if($canManage&&$e['status']==='assigned'):?><form method="POST" style="margin:0"><input type="hidden" name="action" value="return"><input type="hidden" name="id" value="<?php echo $e['id'];?>"><button type="submit" class="btn btn-ghost btn-xs">Retourner</button></form><?php endif;?>
        </div></td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
</table></div>
<?php if($canManage):?>
<div id="assignModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-gun"></i> Assigner un équipement</h2><button class="modal-cl" onclick="LSPD.closeModal('assignModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="assign">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Officier *</label><select name="officer_id" class="form-select" required><?php foreach($allOfficers as $o):?><option value="<?php echo $o['id'];?>"><?php echo htmlspecialchars($o['username'].' — '.$o['faction_rank']);?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Type d'équipement *</label><select name="equip_type" class="form-select" required><?php foreach($ETYPES as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">N° de série</label><input type="text" name="serial" class="form-input" placeholder="LSPD-2026-..."></div>
        <div class="form-group"><label class="form-label">État</label><select name="condition" class="form-select"><option value="excellent">Excellent</option><option value="good">Bon</option><option value="fair">Passable</option><option value="poor">Mauvais</option></select></div>
        <div class="form-group form-full"><label class="form-label">Image(s) de l'équipement</label><input type="hidden" name="images" id="assignImagesHidden" value="[]"><div id="assignImagesField"></div></div>
        <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" style="min-height:65px"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('assignModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Assigner</button></div>
</form></div></div>
<?php endif;?>

<?php if($canEdit):?>
<!-- ══ MODAL ÉDITION ÉQUIPEMENT ══ -->
<div id="equipEditModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Modifier l'équipement</h2><button class="modal-cl" onclick="LSPD.closeModal('equipEditModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="editEquipId">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Type *</label><select name="equip_type" id="editEquipType" class="form-select" required><?php foreach($ETYPES as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">N° de série</label><input type="text" name="serial" id="editEquipSerial" class="form-input"></div>
        <div class="form-group"><label class="form-label">État</label><select name="condition" id="editEquipCond" class="form-select"><option value="excellent">Excellent</option><option value="good">Bon</option><option value="fair">Passable</option><option value="poor">Mauvais</option><option value="damaged">Endommagé</option></select></div>
        <div class="form-group form-full"><label class="form-label">Image(s)</label><input type="hidden" name="images" id="editImagesHidden" value="[]"><div id="editImagesField"></div></div>
        <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" id="editEquipNotes" class="form-textarea" style="min-height:65px"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('equipEditModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>
<?php endif;?>

<script>
let assignImgApi=null, editImgApi=null;
(function(){
    function go(){
        if(!window.mdtImageField) return false;
        if(document.getElementById('assignImagesField') && !assignImgApi) assignImgApi = mdtImageField({container:'assignImagesField',hidden:'assignImagesHidden',uploadUrl:'equipement.php',listUrl:'equipement.php?img_action=cdn_list'});
        if(document.getElementById('editImagesField')   && !editImgApi)   editImgApi   = mdtImageField({container:'editImagesField',hidden:'editImagesHidden',uploadUrl:'equipement.php',listUrl:'equipement.php?img_action=cdn_list'});
        return true;
    }
    if(!go()){ var n=0,iv=setInterval(function(){ if(go()||++n>60) clearInterval(iv); },50); }
})();
function openEquipEdit(d){
    document.getElementById('editEquipId').value=d.id;
    document.getElementById('editEquipType').value=d.type||'';
    document.getElementById('editEquipSerial').value=d.serial||'';
    document.getElementById('editEquipCond').value=d.cond||'excellent';
    document.getElementById('editEquipNotes').value=d.notes||'';
    if(editImgApi) editImgApi.set(d.images||[]);
    LSPD.openModal('equipEditModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>