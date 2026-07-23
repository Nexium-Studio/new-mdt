<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Avis de Recherche'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

// Sergeant II minimum pour émettre des avis de recherche
$RLEVEL_W=['Deputy Sheriff Trainee'=>1,'Deputy Sheriff'=>2,'Sergeant'=>3,'Lieutenant'=>4,'Captain'=>5,'Area Commander'=>6,'Division Chief'=>7,'Assistant Sheriff'=>8,'Undersheriff'=>9,'Sheriff'=>10];
$__wLevel=$RLEVEL_W[$_SESSION['user_faction_rank']??'']??0;
$canEmit=hasRole(['*','Administrateur','Développeur','Staff'])||$__wLevel>=5;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='create' && $canEmit) {
        try {
            $db->prepare("INSERT INTO arrest_warrants (citizen_id,warrant_type,offense_code,offense_title,reason_rich,severity,bail_amount,issued_by,issued_date,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([intval($_POST['citizen_id']),$_POST['warrant_type'],trim($_POST['offense_code']),trim($_POST['offense_title']),trim($_POST['reason']??''),$_POST['severity'],floatval($_POST['bail']??0),$uid,date('Y-m-d H:i:s'),'active']);
            $msg='Avis de recherche émis.';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='update') {
        $extra=($_POST['status']==='executed')?',execution_date=NOW()':'';
        $db->prepare("UPDATE arrest_warrants SET status=?{$extra} WHERE id=?")->execute([$_POST['status'],intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }
}

$fStatus=$_GET['s']??'active'; $fSev=$_GET['sev']??'all'; $search=trim($_GET['q']??''); $cid=intval($_GET['cid']??0);
$q="SELECT aw.*,CONCAT(p.firstname,' ',p.lastname) AS citoyen,p.phone,u.username AS officier FROM arrest_warrants aw LEFT JOIN profiles p ON p.id=aw.citizen_id LEFT JOIN users u ON u.id=aw.issued_by WHERE 1=1";
$params=[];
if ($fStatus!=='all'){$q.=" AND aw.status=?";$params[]=$fStatus;}
if ($fSev!=='all'){$q.=" AND aw.severity=?";$params[]=$fSev;}
if ($cid){$q.=" AND aw.citizen_id=?";$params[]=$cid;}
if ($search){$q.=" AND (p.firstname LIKE ? OR p.lastname LIKE ? OR aw.offense_title LIKE ?)";$s="%$search%";$params=array_merge($params,[$s,$s,$s]);}
$q.=" ORDER BY FIELD(aw.severity,'critical','high','medium','low'),aw.issued_date DESC LIMIT 100";
$stmt=$db->prepare($q);$stmt->execute($params);$warrants=$stmt->fetchAll();

$statsCounts=[];
try{foreach($db->query("SELECT status,COUNT(*) as n FROM arrest_warrants GROUP BY status")->fetchAll() as $r)$statsCounts[$r['status']]=$r['n'];}catch(Exception $e){}
$sevCounts=[];
try{foreach($db->query("SELECT severity,COUNT(*) as n FROM arrest_warrants WHERE status='active' GROUP BY severity")->fetchAll() as $r)$sevCounts[$r['severity']]=$r['n'];}catch(Exception $e){}

$allCitizens=[];try{$allCitizens=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname LIMIT 200")->fetchAll();}catch(Exception $e){}
$allOffenses=[];try{$allOffenses=$db->query("SELECT code,title FROM penal_code WHERE active=1 ORDER BY code LIMIT 300")->fetchAll();}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
$SEV=['critical'=>['Critique','bg-red'],'high'=>['Élevé','bg-orange'],'medium'=>['Modéré','bg-blue'],'low'=>['Faible','bg-green']];
$WTYPE=['arrest'=>'Arrestation','search'=>'Perquisition','seizure'=>'Saisie'];
?>
<style>
.wanted-big{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.15rem;border-left:5px solid var(--blue);transition:all .2s}
.wanted-big:hover{border-color:var(--bdr-h)}
.wanted-big.critical{border-left-color:#ef4444}.wanted-big.high{border-left-color:#f59e0b}.wanted-big.medium{border-left-color:#10b981}.wanted-big.low{border-left-color:#10b981}
</style>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
    <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.15);color:#fca5a5"><i class="fas fa-crosshairs"></i></div><div class="kpi-lbl">Actifs</div><div class="kpi-val red"><?php echo $statsCounts['active']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Exécutés</div><div class="kpi-val green"><?php echo $statsCounts['executed']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Critiques actifs</div><div class="kpi-val red"><?php echo $sevCounts['critical']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Élevés actifs</div><div class="kpi-val yellow"><?php echo $sevCounts['high']??0;?></div></div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;flex:1">
        <input type="text" name="q" class="form-input" placeholder="Nom, infraction..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="s" class="form-select" style="width:130px" onchange="this.form.submit()">
            <option value="all">Tous</option>
            <option value="active" <?php echo $fStatus==='active'?'selected':'';?>>Actifs</option>
            <option value="executed" <?php echo $fStatus==='executed'?'selected':'';?>>Exécutés</option>
            <option value="cancelled" <?php echo $fStatus==='cancelled'?'selected':'';?>>Annulés</option>
            <option value="expired" <?php echo $fStatus==='expired'?'selected':'';?>>Expirés</option>
        </select>
        <select name="sev" class="form-select" style="width:130px" onchange="this.form.submit()">
            <option value="all">Sévérité</option>
            <?php foreach($SEV as $k=>[$l,$c]):?><option value="<?php echo $k;?>" <?php echo $fSev===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <a href="recherches.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
    </form>
    <?php if($canEmit??false):?><button onclick="LSSD.openModal('newWarrantModal')" class="btn btn-danger btn-sm"><i class="fas fa-crosshairs"></i> Nouvel avis de recherche</button><?php else:?><span class="badge bg-gray" style="font-size:.75rem"><i class="fas fa-lock"></i> Sergent II requis pour émettre</span><?php endif;?>
</div>

<?php if(empty($warrants)):?>
<div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)"><i class="fas fa-crosshairs" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun avis de recherche</div>
<?php else:foreach($warrants as $w):[$sl,$sb]=$SEV[$w['severity']]??[$w['severity'],'bg-gray'];?>
<div class="wanted-big <?php echo $w['severity'];?>" style="margin-bottom:.85rem">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:.65rem">
        <div style="flex:1">
            <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.3rem">
                <span class="badge <?php echo $sb;?>" style="font-family:'IBM Plex Mono',monospace"><?php echo $sl;?></span>
                <span class="badge bg-gray"><?php echo $WTYPE[$w['warrant_type']]??$w['warrant_type'];?></span>
                <?php if($w['bail_amount']>0):?><span class="badge bg-yellow" style="font-size:.63rem">Caution $<?php echo number_format($w['bail_amount'],0);?></span><?php endif;?>
            </div>
            <div style="font-family:'Syne',serif;font-size:1rem;font-weight:800;color:<?php echo in_array($w['severity'],['critical','high'])?'#fca5a5':'#f9fafb';?>"><?php echo htmlspecialchars($w['citoyen']);?></div>
            <div style="font-size:.82rem;color:var(--txt-s);margin-top:.15rem"><?php echo htmlspecialchars($w['offense_title']);?> <span class="mono" style="color:var(--txt-m);font-size:.72rem"><?php echo htmlspecialchars($w['offense_code']);?></span></div>
        </div>
        <div style="text-align:right;flex-shrink:0">
            <?php $sc=['active'=>'bg-red','executed'=>'bg-green','cancelled'=>'bg-gray','expired'=>'bg-gray'][$w['status']]??'bg-gray';?>
            <span class="badge <?php echo $sc;?>" style="display:block;margin-bottom:.35rem"><?php echo strtoupper($w['status']);?></span>
            <div style="font-size:.7rem;color:var(--txt-m)"><?php echo date('d/m/Y',strtotime($w['issued_date']));?></div>
            <div style="font-size:.7rem;color:var(--txt-m)">Par <?php echo htmlspecialchars($w['officier']??'—');?></div>
        </div>
    </div>
    <?php if($w['reason_rich']):?><div style="background:rgba(0,0,0,.2);border-radius:7px;padding:.65rem .85rem;font-size:.78rem;color:var(--txt-s);margin-bottom:.65rem;line-height:1.6"><?php echo nl2br(htmlspecialchars(mb_substr($w['reason_rich'],0,200)));?></div><?php endif;?>
    <?php if($w['phone']):?><div style="font-size:.72rem;color:var(--txt-m);margin-bottom:.65rem"><i class="fas fa-phone" style="margin-right:.35rem"></i><?php echo htmlspecialchars($w['phone']);?></div><?php endif;?>
    <?php if($w['status']==='active'):?>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?php echo $w['id'];?>"><input type="hidden" name="status" value="executed"><button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Marquer exécuté</button></form>
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?php echo $w['id'];?>"><input type="hidden" name="status" value="cancelled"><button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-ban"></i> Annuler</button></form>
        <a href="profils.php?id=<?php echo $w['citizen_id'];?>" class="btn btn-secondary btn-sm"><i class="fas fa-user"></i> Dossier citoyen</a>
    </div>
    <?php endif;?>
</div>
<?php endforeach;endif;?>

<?php if($canEmit??false):?>
<!-- MODAL NOUVEL AVIS -->
<div id="newWarrantModal" class="modal-ov <?php echo (isset($_GET['new']))?'':'hidden';?>">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-crosshairs"></i> Nouvel avis de recherche</h2><button class="modal-cl" onclick="LSSD.closeModal('newWarrantModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label">Citoyen recherché *</label>
            <select name="citizen_id" class="form-select" required>
                <option value="">Sélectionner...</option>
                <?php foreach($allCitizens as $p):?><option value="<?php echo $p['id'];?>" <?php echo $cid==$p['id']?'selected':'';?>><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Type de mandat *</label>
            <select name="warrant_type" class="form-select" required>
                <option value="arrest">Mandat d'arrestation</option>
                <option value="search">Mandat de perquisition</option>
                <option value="seizure">Mandat de saisie</option>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Sévérité *</label>
            <select name="severity" class="form-select" required>
                <?php foreach($SEV as $k=>[$l,$c]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Infraction</label>
            <select class="form-select" onchange="document.querySelector('[name=offense_code]').value=this.value.split('|')[0];document.querySelector('[name=offense_title]').value=this.value.split('|')[1]||''">
                <option value="">— Code pénal —</option>
                <?php foreach($allOffenses as $o):?><option value="<?php echo $o['code'].'|'.htmlspecialchars($o['title']);?>"><?php echo htmlspecialchars($o['code'].' — '.$o['title']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Code pénal *</label><input type="text" name="offense_code" class="form-input" required placeholder="PC 187..."></div>
        <div class="form-group"><label class="form-label">Intitulé *</label><input type="text" name="offense_title" class="form-input" required placeholder="Meurtre..."></div>
        <div class="form-group"><label class="form-label">Montant caution ($)</label><input type="number" name="bail" class="form-input" value="0" min="0" step="500"></div>
        <div class="form-group form-full"><label class="form-label">Motif détaillé *</label><textarea name="reason" class="form-textarea" required placeholder="Circonstances, preuves, témoignages..."></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('newWarrantModal')">Annuler</button><button type="submit" class="btn btn-danger"><i class="fas fa-crosshairs"></i> Émettre l'avis</button></div>
</form></div></div>
<?php endif;?>
<?php include __DIR__ . '/includes/footer.php';?>