<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Bracelets Électroniques'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

try{$db->exec("CREATE TABLE IF NOT EXISTS `lspd_bracelets` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `numero_bracelet` varchar(40) NOT NULL UNIQUE,
    `citoyen_id` int(11) NOT NULL COMMENT 'FK → profiles.id',
    `rapport_id` int(11) NOT NULL COMMENT 'FK → reports.id',
    `agent_id` int(11) DEFAULT NULL,
    `date_pose` date NOT NULL,
    `date_fin_prevue` date NOT NULL,
    `date_retrait` date DEFAULT NULL,
    `zone_autorisee` varchar(400) DEFAULT NULL COMMENT 'Zones autorisées',
    `horaires_autorisees` varchar(300) DEFAULT NULL,
    `conditions_speciales` text DEFAULT NULL,
    `statut` enum('actif','retire','violation','expire','suspendu') DEFAULT 'actif',
    `notes` text DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(`citoyen_id`), INDEX(`rapport_id`), INDEX(`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}

// ── ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    if ($action==='create') {
        try {
            $cid=intval($_POST['citoyen_id']);
            $rid=intval($_POST['rapport_id']);
            if (!$cid) throw new Exception('Veuillez sélectionner un citoyen.');
            if (!$rid) throw new Exception('Veuillez sélectionner un rapport.');
            // Vérif rapport LSPD
            $rchk=$db->prepare("SELECT id FROM reports WHERE id=? AND faction='LSPD'");$rchk->execute([$rid]);
            if(!$rchk->fetchColumn()) throw new Exception('Ce rapport n\'existe pas ou n\'appartient pas à la LSPD.');
            $num='BR-'.date('Y').'-'.str_pad((int)$db->query("SELECT COUNT(*)+1 FROM lspd_bracelets")->fetchColumn(),4,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO lspd_bracelets (numero_bracelet,citoyen_id,rapport_id,agent_id,date_pose,date_fin_prevue,zone_autorisee,horaires_autorisees,conditions_speciales,statut,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$num,$cid,$rid,intval($_POST['agent_id'])?:$uid,$_POST['date_pose'],$_POST['date_fin_prevue'],trim($_POST['zone_autorisee']??''),trim($_POST['horaires_autorisees']??''),trim($_POST['conditions_speciales']??''),$_POST['statut']??'actif',trim($_POST['notes']??''),$uid]);
            $msg="Bracelet $num posé.";$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }

    if ($action==='update') {
        $bid=intval($_POST['id']);
        $retire=$_POST['statut']==='retire'?($_POST['date_retrait']?:date('Y-m-d')):null;
        $db->prepare("UPDATE lspd_bracelets SET statut=?,date_retrait=?,notes=? WHERE id=?")->execute([$_POST['statut'],$retire,trim($_POST['notes']??''),$bid]);
        $msg='Bracelet mis à jour.';$msgType='success';
    }

    if ($action==='delete') {
        $db->prepare("DELETE FROM lspd_bracelets WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Bracelet supprimé.';$msgType='success';
    }

    if ($msg) { header('Location: bracelets-electronique.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ───────────────────────────────────────────────────────────────────
$fStat=$_GET['stat']??'all'; $search=$_GET['q']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(b.numero_bracelet LIKE ? OR CONCAT(p.firstname,' ',p.lastname) LIKE ? OR r.title LIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
if($fStat!=='all'){$where[]="b.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM lspd_bracelets b LEFT JOIN profiles p ON p.id=b.citoyen_id LEFT JOIN reports r ON r.id=b.rapport_id $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$bracelets=[];
try{$s=$db->prepare("SELECT b.*,CONCAT(p.firstname,' ',p.lastname) AS citoyen,r.title AS rapport_title,ua.username AS agent_name FROM lspd_bracelets b LEFT JOIN profiles p ON p.id=b.citoyen_id LEFT JOIN reports r ON r.id=b.rapport_id LEFT JOIN users ua ON ua.id=b.agent_id $wStr ORDER BY FIELD(b.statut,'actif','suspendu','violation','expire','retire'),b.date_fin_prevue ASC LIMIT $perPage OFFSET $offset");$s->execute($params);$bracelets=$s->fetchAll();}catch(Exception $e){}

$statsB=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM lspd_bracelets GROUP BY statut")->fetchAll() as $r)$statsB[$r['statut']]=$r['n'];}catch(Exception $e){}

$profils=[];try{$profils=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname LIMIT 300")->fetchAll();}catch(Exception $e){}
$rapports=[];try{$rapports=$db->query("SELECT r.id,r.title,CONCAT(p.firstname,' ',p.lastname) AS sujet FROM reports r LEFT JOIN profiles p ON p.id=JSON_UNQUOTE(JSON_EXTRACT(r.suspects,'$[0]')) WHERE r.faction='LSPD' ORDER BY r.created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e){
    try{$rapports=$db->query("SELECT id,title FROM reports WHERE faction='LSPD' ORDER BY created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e2){}
}
$agents=[];try{$agents=$db->query("SELECT id,username FROM users WHERE faction IN ('LSPD','LSSD') AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}

$STATUTS=['actif'=>['Actif','bg-green'],'retire'=>['Retiré','bg-gray'],'violation'=>['Violation','bg-red'],'expire'=>['Expiré','bg-gray'],'suspendu'=>['Suspendu','bg-yellow']];

include __DIR__ . '/includes/header.php';
?>
<style>
.bracelet-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:13px;padding:1.1rem 1.2rem;margin-bottom:.85rem;transition:all .18s;position:relative;overflow:hidden}
.bracelet-card.actif{border-left:4px solid var(--green)}
.bracelet-card.violation{border-left:4px solid var(--red)}
.bracelet-card.suspendu{border-left:4px solid var(--yellow)}
.bracelet-card:hover{border-color:var(--bdr-h)}
.bc-num{font-family:'IBM Plex Mono',monospace;font-size:.82rem;font-weight:700;color:var(--blue-l)}
.progress-bar{height:6px;border-radius:4px;background:var(--bg-ov);overflow:hidden;margin-top:.5rem}
.progress-bar div{height:100%;border-radius:4px;transition:width .3s}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
    <div class="kpi"><div class="kpi-lbl">Total</div><div class="kpi-val blue"><?php echo array_sum($statsB);?></div></div>
    <div class="kpi"><div class="kpi-lbl">Actifs</div><div class="kpi-val green"><?php echo $statsB['actif']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Violations</div><div class="kpi-val red"><?php echo $statsB['violation']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Suspendus</div><div class="kpi-val yellow"><?php echo $statsB['suspendu']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Retirés</div><div class="kpi-val"><?php echo $statsB['retire']??0;?></div></div>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1.1rem;flex-wrap:wrap;align-items:center">
    <form method="GET" style="display:flex;gap:.4rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" class="form-input" placeholder="N° bracelet, citoyen, rapport..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="stat" class="form-select" style="width:120px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search||$fStat!=='all'):?><a href="bracelets-electronique.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <button onclick="LSPD.openModal('newBraceletModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Poser un bracelet</button>
</div>

<!-- CARTES BRACELETS -->
<div>
<?php if(empty($bracelets)):?>
<div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-m)">
    <i class="fas fa-circle-dot" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
    Aucun bracelet enregistré
</div>
<?php else:foreach($bracelets as $b):[$sl,$sb]=$STATUTS[$b['statut']]??[$b['statut'],'bg-gray'];
    $pose=strtotime($b['date_pose']);
    $fin=strtotime($b['date_fin_prevue']);
    $now=time();
    $duration=max(1,$fin-$pose);
    $elapsed=min($duration,max(0,$now-$pose));
    $pct=round($elapsed/$duration*100);
    $expired=$fin<$now && $b['statut']==='actif';
?>
<div class="bracelet-card <?php echo $b['statut'];?>">
    <div style="display:flex;align-items:flex-start;gap:.85rem">
        <!-- Icône -->
        <div style="width:46px;height:46px;border-radius:11px;background:<?php echo $b['statut']==='actif'?'rgba(16,185,129,.12)':($b['statut']==='violation'?'rgba(239,68,68,.12)':'rgba(37,99,235,.08)');?>;color:<?php echo $b['statut']==='actif'?'#6ee7b7':($b['statut']==='violation'?'#fca5a5':'var(--txt-m)');?>;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
            <i class="fas fa-circle-dot"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;margin-bottom:.3rem">
                <span class="bc-num"><?php echo htmlspecialchars($b['numero_bracelet']);?></span>
                <span class="badge <?php echo $sb;?>" style="font-size:.62rem"><?php echo $sl;?></span>
                <?php if($expired):?><span class="badge bg-red" style="font-size:.6rem"><i class="fas fa-clock"></i> Expiré</span><?php endif;?>
            </div>
            <div style="font-weight:700;font-size:.9rem;margin-bottom:.2rem"><?php echo htmlspecialchars($b['citoyen']??'—');?></div>
            <div style="font-size:.73rem;color:var(--txt-s);margin-bottom:.4rem;display:flex;gap:.8rem;flex-wrap:wrap">
                <span><i class="fas fa-file-alt" style="opacity:.5;margin-right:.25rem"></i><?php echo htmlspecialchars(mb_substr($b['rapport_title']??'Rapport inconnu',0,40));?></span>
                <span><i class="fas fa-user-shield" style="opacity:.5;margin-right:.25rem"></i><?php echo htmlspecialchars($b['agent_name']??'—');?></span>
            </div>
            <!-- Dates + barre de progression -->
            <div style="display:flex;gap:1.5rem;font-size:.7rem;color:var(--txt-m);margin-bottom:.3rem;flex-wrap:wrap">
                <span><i class="fas fa-calendar-plus" style="margin-right:.25rem;opacity:.5"></i>Posé : <?php echo date('d/m/Y',strtotime($b['date_pose']));?></span>
                <span><i class="fas fa-calendar-xmark" style="margin-right:.25rem;opacity:.5"></i>Fin prévue : <?php echo date('d/m/Y',strtotime($b['date_fin_prevue']));?></span>
                <?php if($b['date_retrait']):?><span><i class="fas fa-calendar-check" style="margin-right:.25rem;opacity:.5"></i>Retiré : <?php echo date('d/m/Y',strtotime($b['date_retrait']));?></span><?php endif;?>
            </div>
            <?php if($b['statut']==='actif'||$b['statut']==='suspendu'):?>
            <div class="progress-bar">
                <div style="width:<?php echo $pct;?>%;background:<?php echo $pct>=90?'var(--red)':($pct>=70?'var(--yellow)':'var(--green)');?>"></div>
            </div>
            <div style="font-size:.65rem;color:var(--txt-m);margin-top:.2rem;text-align:right"><?php echo $pct;?>% du temps écoulé</div>
            <?php endif;?>
            <!-- Conditions -->
            <?php if($b['zone_autorisee']):?>
            <div style="margin-top:.45rem;font-size:.72rem;color:var(--txt-s)"><i class="fas fa-map-marker-alt" style="color:var(--blue-l);margin-right:.3rem"></i><?php echo htmlspecialchars($b['zone_autorisee']);?></div>
            <?php endif;?>
            <?php if($b['horaires_autorisees']):?>
            <div style="font-size:.72rem;color:var(--txt-s)"><i class="fas fa-clock" style="color:var(--blue-l);margin-right:.3rem"></i><?php echo htmlspecialchars($b['horaires_autorisees']);?></div>
            <?php endif;?>
        </div>
        <!-- Actions -->
        <div style="display:flex;flex-direction:column;gap:.3rem;flex-shrink:0">
            <button class="btn btn-ghost btn-xs" onclick="openUpdateBracelet(<?php echo htmlspecialchars(json_encode($b,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE),ENT_QUOTES);?>)" title="Modifier"><i class="fas fa-pen"></i></button>
            <form method="POST" data-confirm data-confirm-title="Supprimer le bracelet" data-confirm-target="<?php echo htmlspecialchars(addslashes($b['numero_bracelet']));?>" data-confirm-ok="Supprimer" style="margin:0">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $b['id'];?>">
                <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>
</div>
<?php endforeach;endif;?>
</div>

<?php if($totalPages>1):?><div style="display:flex;gap:.35rem;justify-content:center;margin-top:.65rem">
    <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>" class="btn btn-ghost btn-sm">← Préc.</a><?php endif;?>
    <span style="display:flex;align-items:center;font-size:.78rem;color:var(--txt-m)">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
    <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>" class="btn btn-ghost btn-sm">Suiv. →</a><?php endif;?>
</div><?php endif;?>

<!-- MODAL POSER UN BRACELET -->
<div id="newBraceletModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-circle-dot"></i> Poser un bracelet électronique</h2><button class="modal-cl" onclick="LSPD.closeModal('newBraceletModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
    <div style="background:rgba(37,99,235,.06);border:1px solid rgba(37,99,235,.18);border-radius:9px;padding:.65rem 1rem;margin-bottom:.85rem;font-size:.8rem;color:var(--blue-xl)">
        <i class="fas fa-circle-info" style="margin-right:.4rem"></i>Le bracelet doit être <strong>lié à un rapport existant</strong> de la LSPD. Sélectionnez d'abord le rapport, puis le citoyen sera automatiquement suggéré.
    </div>
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label">Rapport lié *</label>
            <select name="rapport_id" class="form-select" required id="br-rapport" onchange="suggestCitoyen(this)">
                <option value="">Sélectionner un rapport...</option>
                <?php foreach($rapports as $r):?><option value="<?php echo $r['id'];?>">#<?php echo str_pad($r['id'],6,'0',STR_PAD_LEFT);?> — <?php echo htmlspecialchars(mb_substr($r['title']??'',0,55));?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Citoyen *</label>
            <select name="citoyen_id" class="form-select" required id="br-citoyen">
                <option value="">Sélectionner...</option>
                <?php foreach($profils as $p):?><option value="<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Agent posant le bracelet</label>
            <select name="agent_id" class="form-select">
                <?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>" <?php echo $a['id']==$uid?'selected':'';?>><?php echo htmlspecialchars($a['username']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Date de pose *</label><input type="date" name="date_pose" class="form-input" required value="<?php echo date('Y-m-d');?>"></div>
        <div class="form-group"><label class="form-label">Date de fin prévue *</label><input type="date" name="date_fin_prevue" class="form-input" required value="<?php echo date('Y-m-d',strtotime('+30 days'));?>"></div>
        <div class="form-group form-full"><label class="form-label">Zone(s) autorisée(s)</label><input type="text" name="zone_autorisee" class="form-input" placeholder="Domicile uniquement, Vespucci Beach, Centre-ville..."></div>
        <div class="form-group form-full"><label class="form-label">Horaires autorisées</label><input type="text" name="horaires_autorisees" class="form-input" placeholder="08h00-22h00, 24h/24..."></div>
        <div class="form-group form-full"><label class="form-label">Conditions spéciales</label><textarea name="conditions_speciales" class="form-textarea" style="min-height:70px" placeholder="Conditions particulières, restrictions..."></textarea></div>
        <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes" class="form-textarea" style="min-height:55px"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('newBraceletModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Poser le bracelet</button></div>
</form></div></div>

<!-- MODAL METTRE À JOUR -->
<div id="updateBraceletModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Mettre à jour le bracelet</h2><button class="modal-cl" onclick="LSPD.closeModal('updateBraceletModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="ub-id">
    <div style="font-weight:700;font-size:.85rem;margin-bottom:.85rem" id="ub-info"></div>
    <div class="form-group"><label class="form-label">Statut</label>
        <select name="statut" id="ub-stat" class="form-select" onchange="toggleRetrait(this)">
            <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
        </select>
    </div>
    <div class="form-group" id="retraitBlock" style="display:none"><label class="form-label">Date de retrait</label><input type="date" name="date_retrait" id="ub-retrait" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="ub-notes" class="form-textarea" style="min-height:80px"></textarea></div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('updateBraceletModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<script>
function openUpdateBracelet(b) {
    document.getElementById('ub-id').value = b.id;
    document.getElementById('ub-info').textContent = b.numero_bracelet + ' — ' + b.citoyen;
    document.getElementById('ub-stat').value = b.statut;
    document.getElementById('ub-notes').value = b.notes || '';
    toggleRetrait(document.getElementById('ub-stat'));
    LSPD.openModal('updateBraceletModal');
}
function toggleRetrait(sel) {
    const rb = document.getElementById('retraitBlock');
    if (rb) rb.style.display = (sel.value === 'retire') ? 'block' : 'none';
}
function suggestCitoyen(sel) {
    // Pas d'auto-remplissage sans AJAX — le sélecteur reste disponible
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>