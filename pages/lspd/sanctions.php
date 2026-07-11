<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Sanctions'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';
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
$canGive = hasRole(['*','Administrateur','Développeur','Staff']) || $__myLvl >= $__sgtThreshold;

if ($_SERVER['REQUEST_METHOD']==='POST'&&$canGive) {
    $action=$_POST['action']??'';
    if ($action==='create') {
        try {
            $db->prepare("INSERT INTO lspd_sanctions (officer_id,issued_by,sanction_type,reason,details,duration_days,date_issued,date_expires,status) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([intval($_POST['officer_id']),$uid,$_POST['sanction_type'],trim($_POST['reason']),trim($_POST['details']??''),intval($_POST['duration']??0)?:null,date('Y-m-d'),!empty($_POST['expires'])?$_POST['expires']:null,'active']);
            $msg='Sanction émise.';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='update') {
        $db->prepare("UPDATE lspd_sanctions SET status=? WHERE id=?")->execute([$_POST['status'],intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }
}

$fType=$_GET['type']??'all'; $fStatus=$_GET['s']??'all'; $search=trim($_GET['q']??'');
$q="SELECT ls.*,u1.username AS officier,u2.username AS emetteur FROM lspd_sanctions ls LEFT JOIN users u1 ON u1.id=ls.officer_id LEFT JOIN users u2 ON u2.id=ls.issued_by WHERE 1=1";
$params=[];
if ($fType!=='all'){$q.=" AND ls.sanction_type=?";$params[]=$fType;}
if ($fStatus!=='all'){$q.=" AND ls.status=?";$params[]=$fStatus;}
if ($search){$q.=" AND (u1.username LIKE ? OR ls.reason LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;}
$q.=" ORDER BY ls.created_at DESC LIMIT 100";
$stmt=$db->prepare($q);$stmt->execute($params);$sanctions=$stmt->fetchAll();

$stats=[];try{foreach($db->query("SELECT sanction_type,COUNT(*) as n FROM lspd_sanctions WHERE status='active' GROUP BY sanction_type")->fetchAll() as $r)$stats[$r['sanction_type']]=$r['n'];}catch(Exception $e){}
$allOfficers=[];try{$allOfficers=$db->query("SELECT id,username,faction_rank FROM users WHERE faction='LSPD' AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}

$STYPES=['verbal_warning'=>['Avertissement verbal','bg-gray'],'written_warning'=>['Avertissement écrit','bg-yellow'],'suspension'=>['Suspension','bg-orange'],'demotion'=>['Rétrogradation','bg-red'],'termination'=>['Renvoi','bg-red']];
include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>
<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
    <?php foreach($STYPES as $k=>[$l,$b]):?><div class="kpi"><div class="kpi-lbl" style="font-size:.6rem"><?php echo $l;?></div><div class="kpi-val <?php echo $b==='bg-red'?'red':($b==='bg-yellow'?'yellow':'');?>"><?php echo $stats[$k]??0;?></div></div><?php endforeach;?>
</div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;flex:1">
        <input type="text" name="q" class="form-input" placeholder="Officier, motif..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="type" class="form-select" style="width:175px" onchange="this.form.submit()"><option value="all">Tous types</option><?php foreach($STYPES as $k=>[$l,$b]):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
        <select name="s" class="form-select" style="width:110px" onchange="this.form.submit()"><option value="all">Statuts</option><option value="active" <?php echo $fStatus==='active'?'selected':'';?>>Actives</option><option value="completed" <?php echo $fStatus==='completed'?'selected':'';?>>Terminées</option><option value="cancelled" <?php echo $fStatus==='cancelled'?'selected':'';?>>Annulées</option></select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    </form>
    <?php if($canGive):?><button onclick="LSPD.openModal('newSancModal')" class="btn btn-warning btn-sm"><i class="fas fa-clipboard-list"></i> Émettre une sanction</button><?php endif;?>
</div>
<div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Type</th><th>Officier</th><th>Motif</th><th>Durée</th><th>Émetteur</th><th>Date</th><th>Expire</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php if(empty($sanctions)):?><tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune sanction</td></tr>
    <?php else:foreach($sanctions as $s):[$tl,$tb]=$STYPES[$s['sanction_type']]??[$s['sanction_type'],'bg-gray'];?>
    <tr>
        <td><span class="badge <?php echo $tb;?>"><?php echo $tl;?></span></td>
        <td style="font-weight:700"><?php echo htmlspecialchars($s['officier']??'—');?></td>
        <td style="font-size:.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($s['reason']);?></td>
        <td style="font-size:.78rem"><?php echo $s['duration_days']?$s['duration_days'].' jours':'—';?></td>
        <td style="font-size:.78rem"><?php echo htmlspecialchars($s['emetteur']??'—');?></td>
        <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y',strtotime($s['date_issued']));?></td>
        <td style="font-size:.72rem;color:var(--txt-m)"><?php echo $s['date_expires']?date('d/m/Y',strtotime($s['date_expires'])):'—';?></td>
        <td><?php if($canGive):?><form method="POST" style="margin:0"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?php echo $s['id'];?>"><select name="status" class="form-select" style="width:105px;font-size:.72rem;padding:.2rem .45rem" onchange="this.form.submit()"><option value="active" <?php echo $s['status']==='active'?'selected':'';?>>Active</option><option value="completed" <?php echo $s['status']==='completed'?'selected':'';?>>Terminée</option><option value="cancelled" <?php echo $s['status']==='cancelled'?'selected':'';?>>Annulée</option></select></form><?php else:?><span class="badge <?php echo $s['status']==='active'?'bg-red':($s['status']==='completed'?'bg-green':'bg-gray');?>"><?php echo $s['status'];?></span><?php endif;?></td>
        <td style="font-size:.75rem;color:var(--txt-m)"><?php if($s['details']):?><span title="<?php echo htmlspecialchars($s['details']);?>"><i class="fas fa-circle-info"></i></span><?php endif;?></td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
</table></div>
<?php if($canGive):?>
<div id="newSancModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-clipboard-list"></i> Nouvelle sanction</h2><button class="modal-cl" onclick="LSPD.closeModal('newSancModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Officier *</label><select name="officer_id" class="form-select" required><?php foreach($allOfficers as $o):?><option value="<?php echo $o['id'];?>"><?php echo htmlspecialchars($o['username'].' — '.$o['faction_rank']);?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Type de sanction *</label><select name="sanction_type" class="form-select" required><?php foreach($STYPES as $k=>[$l,$b]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Durée (jours)</label><input type="number" name="duration" class="form-input" min="0" placeholder="0 = illimitée"></div>
        <div class="form-group"><label class="form-label">Date d'expiration</label><input type="date" name="expires" class="form-input"></div>
        <div class="form-group form-full"><label class="form-label">Motif *</label><textarea name="reason" class="form-textarea" required></textarea></div>
        <div class="form-group form-full"><label class="form-label">Détails supplémentaires</label><textarea name="details" class="form-textarea"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('newSancModal')">Annuler</button><button type="submit" class="btn btn-warning"><i class="fas fa-check"></i> Émettre</button></div>
</form></div></div>
<?php endif;?>
<?php include __DIR__ . '/includes/footer.php';?>