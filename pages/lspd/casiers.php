<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Casiers Judiciaires'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

// ── Auto-create table saisies si absente ───────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `lspd_saisies` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `criminal_record_id` int(11) DEFAULT NULL COMMENT 'FK → criminal_records.id',
        `report_id` int(11) DEFAULT NULL COMMENT 'FK → reports.id',
        `citizen_id` int(11) DEFAULT NULL,
        `categorie` enum('arme','drogue','vehicule','argent','bijou','electronique','autre') NOT NULL DEFAULT 'autre',
        `description` varchar(300) NOT NULL,
        `quantite` varchar(100) DEFAULT NULL,
        `valeur_estimee` decimal(10,2) DEFAULT NULL,
        `lieu_saisie` varchar(200) DEFAULT NULL,
        `statut` enum('saisie','detruit','restitue','en_evidence') DEFAULT 'saisie',
        `created_by` int(11) DEFAULT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e){}

// ── ACTIONS POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    if ($action==='create') {
        try {
            $db->prepare("INSERT INTO criminal_records (citizen_id,record_type,offense_code,offense_title,description_rich,severity,fine_amount,jail_time,points,officer_id,incident_date,created_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([intval($_POST['citizen_id']),$_POST['record_type'],trim($_POST['offense_code']),trim($_POST['offense_title']),trim($_POST['description']??''),intval($_POST['severity']??1),floatval($_POST['fine']??0),intval($_POST['jail']??0),intval($_POST['points']??0),$uid,$_POST['incident_date'],$uid,'active']);
            $msg='Casier créé.';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }

    if ($action==='update_status') {
        $db->prepare("UPDATE criminal_records SET status=? WHERE id=?")->execute([$_POST['status'],intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }

    if ($action==='delete') {
        $db->prepare("DELETE FROM criminal_records WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Entrée supprimée.';$msgType='success';
    }

    if ($action==='add_saisie') {
        try {
            $db->prepare("INSERT INTO lspd_saisies (criminal_record_id,citizen_id,categorie,description,quantite,valeur_estimee,lieu_saisie,created_by) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([intval($_POST['record_id'])?:null,intval($_POST['citizen_id'])?:null,$_POST['categorie'],trim($_POST['description']),trim($_POST['quantite']??''),floatval($_POST['valeur']??0)?:null,trim($_POST['lieu']??''),$uid]);
            $msg='Saisie ajoutée.';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }

    if ($action==='delete_saisie') {
        $db->prepare("DELETE FROM lspd_saisies WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Saisie supprimée.';$msgType='success';
    }

    if ($msg && $msgType) {
        header('Location: casiers.php?msg='.urlencode($msg).'&type='.$msgType.(isset($_POST['citizen_id'])?'&cid='.(int)$_POST['citizen_id']:''));
        exit;
    }
}

if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── FILTRES ────────────────────────────────────────────────────────────────
$search  = trim($_GET['q']??'');
$fType   = $_GET['type_f']??'all';
$viewCid = intval($_GET['cid']??0);
$page    = max(1,(int)($_GET['page']??1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

// ── DONNÉES GROUPÉES : une carte par citoyen ──────────────────────────────
// Récupérer les citoyens qui ont au moins un casier
$citizenQ = "SELECT DISTINCT cr.citizen_id, p.firstname, p.lastname, p.age, p.phone, p.job,
             COUNT(cr.id) AS total_records,
             SUM(CASE WHEN cr.status='active' THEN 1 ELSE 0 END) AS active_count,
             SUM(cr.fine_amount) AS total_fines,
             MAX(cr.jail_time) AS max_jail
             FROM criminal_records cr
             LEFT JOIN profiles p ON p.id=cr.citizen_id
             WHERE 1=1";
$cParams=[];
if ($search) {
    $citizenQ.=" AND (p.firstname LIKE ? OR p.lastname LIKE ? OR cr.offense_title LIKE ?)";
    $cParams[]="%$search%";$cParams[]="%$search%";$cParams[]="%$search%";
}
if ($viewCid) { $citizenQ.=" AND cr.citizen_id=?"; $cParams[]=$viewCid; }
$citizenQ.=" GROUP BY cr.citizen_id ORDER BY active_count DESC, total_records DESC LIMIT $perPage OFFSET $offset";

$citizens=[];
try{$s=$db->prepare($citizenQ);$s->execute($cParams);$citizens=$s->fetchAll();}catch(Exception $e){}

// Compter le total
$totalCitizens=0;
try{$tc=$db->prepare("SELECT COUNT(DISTINCT citizen_id) FROM criminal_records cr LEFT JOIN profiles p ON p.id=cr.citizen_id WHERE 1=1".($search?" AND (p.firstname LIKE ? OR p.lastname LIKE ? OR cr.offense_title LIKE ?)":"").($viewCid?" AND cr.citizen_id=?":""));$tp=[];if($search){$tp[]="%$search%";$tp[]="%$search%";$tp[]="%$search%";}if($viewCid)$tp[]=$viewCid;$tc->execute($tp);$totalCitizens=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages = max(1,(int)ceil($totalCitizens/$perPage));

// ── Pour chaque citoyen, récupérer ses infractions + saisies ──────────────
$citizenRecords = [];
$citizenSaisies = [];
if (!empty($citizens)) {
    $cids = array_column($citizens,'citizen_id');
    $phs  = implode(',',array_fill(0,count($cids),'?'));
    try {
        $rows=$db->prepare("SELECT cr.*,u.username AS officier FROM criminal_records cr LEFT JOIN users u ON u.id=cr.officer_id WHERE cr.citizen_id IN ($phs) ORDER BY cr.incident_date DESC,cr.id DESC");
        $rows->execute($cids);
        foreach($rows->fetchAll() as $r) $citizenRecords[$r['citizen_id']][] = $r;
    } catch(Exception $e){}
    try {
        $sRows=$db->prepare("SELECT * FROM lspd_saisies WHERE citizen_id IN ($phs) ORDER BY created_at DESC");
        $sRows->execute($cids);
        foreach($sRows->fetchAll() as $r) $citizenSaisies[$r['citizen_id']][] = $r;
    } catch(Exception $e){}
}

// ── Stats globales ─────────────────────────────────────────────────────────
$statsC=[];
try{foreach($db->query("SELECT record_type,COUNT(*) as n FROM criminal_records WHERE status='active' GROUP BY record_type")->fetchAll() as $r)$statsC[$r['record_type']]=$r['n'];}catch(Exception $e){}

$allCitizens=[];try{$allCitizens=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname LIMIT 300")->fetchAll();}catch(Exception $e){}
$allOffenses=[];try{$allOffenses=$db->query("SELECT id,code,title,min_fine,max_fine,min_jail_months,max_jail_months,points,category FROM penal_code WHERE active=1 ORDER BY code LIMIT 500")->fetchAll();}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
$TYPES=['felony'=>['Crime','bg-red'],'misdemeanor'=>['Délit','bg-yellow'],'infraction'=>['Contravention','bg-blue'],'warning'=>['Avertissement','bg-gray']];
$SCAT=['arme'=>['Arme','fas fa-gun','bg-red'],'drogue'=>['Drogue','fas fa-pills','bg-orange'],'vehicule'=>['Véhicule','fas fa-car','bg-blue'],'argent'=>['Argent','fas fa-coins','bg-yellow'],'bijou'=>['Bijou','fas fa-gem','bg-purple'],'electronique'=>['Électronique','fas fa-laptop','bg-blue'],'autre'=>['Autre','fas fa-box','bg-gray']];
?>
<style>
.citoyen-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:14px;margin-bottom:1.1rem;overflow:hidden;transition:border-color .18s}
.citoyen-card:hover{border-color:var(--bdr-h)}
.citoyen-card.has-active{border-left:4px solid var(--red)}
.cc-hdr{display:flex;align-items:center;gap:.85rem;padding:1rem 1.2rem;cursor:pointer;user-select:none}
.cc-ava{width:42px;height:42px;border-radius:10px;background:rgba(37,99,235,.15);color:var(--blue-l);display:flex;align-items:center;justify-content:center;font-family:'IBM Plex Mono',monospace;font-weight:700;font-size:.9rem;flex-shrink:0}
.cc-body{padding:0 1.2rem 1.2rem;display:none}
.cc-body.open{display:block}
.offense-row{display:flex;align-items:center;gap:.7rem;padding:.55rem .8rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;margin-bottom:.4rem;transition:all .15s}
.offense-row:hover{border-color:var(--bdr-h)}
.saisie-tag{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:5px;font-size:.68rem;font-weight:600;margin:.15rem}
.stats-pills{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.35rem}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>" id="pageAlert"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<script>setTimeout(()=>{const a=document.getElementById('pageAlert');if(a)a.style.display='none';},4000);</script>

<!-- STATS -->
<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
    <div class="kpi"><div class="kpi-lbl">Personnes</div><div class="kpi-val blue"><?php echo $totalCitizens;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Crimes</div><div class="kpi-val red"><?php echo $statsC['felony']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Délits</div><div class="kpi-val yellow"><?php echo $statsC['misdemeanor']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Contraventions</div><div class="kpi-val"><?php echo $statsC['infraction']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Avertissements</div><div class="kpi-val"><?php echo $statsC['warning']??0;?></div></div>
</div>

<!-- BARRE ACTIONS -->
<div style="display:flex;gap:.65rem;margin-bottom:1.2rem;flex-wrap:wrap;align-items:center">
    <form method="GET" style="display:flex;gap:.4rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" class="form-input" placeholder="Rechercher par nom, infraction..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search||$viewCid):?><a href="casiers.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <button onclick="LSPD.openModal('newCasierModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouveau casier</button>
</div>

<!-- LISTE PAR CITOYEN -->
<?php if(empty($citizens)):?>
<div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-m)">
    <i class="fas fa-gavel" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
    <?php echo $search?'Aucun résultat pour «'.htmlspecialchars($search).'»':'Aucun casier enregistré';?>
</div>
<?php else: foreach($citizens as $cit):
    $records   = $citizenRecords[$cit['citizen_id']] ?? [];
    $saisies   = $citizenSaisies[$cit['citizen_id']] ?? [];
    $hasActive = intval($cit['active_count']) > 0;
    $initials  = strtoupper(substr($cit['firstname']??'?',0,1).substr($cit['lastname']??'',0,1));
    $fullName  = trim(($cit['firstname']??'').' '.($cit['lastname']??''));
?>
<div class="citoyen-card <?php echo $hasActive?'has-active':'';?>" id="cc-<?php echo $cit['citizen_id'];?>">
    <!-- HEADER CLIQUABLE -->
    <div class="cc-hdr" onclick="toggleCitoyen(<?php echo $cit['citizen_id'];?>)">
        <div class="cc-ava"><?php echo $initials;?></div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:.92rem;color:var(--txt);display:flex;align-items:center;gap:.55rem">
                <?php echo htmlspecialchars($fullName);?>
                <?php if($hasActive):?><span class="badge bg-red" style="font-size:.6rem">Actif</span><?php endif;?>
            </div>
            <div class="stats-pills">
                <?php if($cit['total_records']>0):?><span class="badge bg-gray" style="font-size:.62rem"><i class="fas fa-gavel"></i> <?php echo $cit['total_records'];?> infraction<?php echo $cit['total_records']>1?'s':'';?></span><?php endif;?>
                <?php if($cit['total_fines']>0):?><span class="badge bg-yellow" style="font-size:.62rem"><i class="fas fa-coins"></i> $<?php echo number_format($cit['total_fines'],0);?></span><?php endif;?>
                <?php if($cit['max_jail']>0):?><span class="badge bg-red" style="font-size:.62rem"><i class="fas fa-cell"></i> <?php echo $cit['max_jail'];?> mois max</span><?php endif;?>
                <?php if(!empty($saisies)):?><span class="badge bg-orange" style="font-size:.62rem"><i class="fas fa-box-archive"></i> <?php echo count($saisies);?> saisie<?php echo count($saisies)>1?'s':'';?></span><?php endif;?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0">
            <a href="profiles.php?id=<?php echo $cit['citizen_id'];?>" class="btn btn-ghost btn-xs" onclick="event.stopPropagation()" title="Profil civil"><i class="fas fa-user"></i></a>
            <i class="fas fa-chevron-down cc-chevron-<?php echo $cit['citizen_id'];?>" style="color:var(--txt-m);font-size:.75rem;transition:transform .2s"></i>
        </div>
    </div>

    <!-- BODY DÉPLIABLE -->
    <div class="cc-body" id="ccb-<?php echo $cit['citizen_id'];?>">
        <!-- INFRACTIONS -->
        <?php if(empty($records)):?>
        <div style="text-align:center;padding:1rem;color:var(--txt-m);font-size:.8rem">Aucune infraction</div>
        <?php else:?>
        <div style="margin-bottom:.85rem">
            <div style="font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.55rem"><i class="fas fa-gavel" style="margin-right:.3rem;color:var(--blue-l)"></i>Infractions</div>
            <?php foreach($records as $r):[$tl,$tb]=$TYPES[$r['record_type']]??[$r['record_type'],'bg-gray'];?>
            <div class="offense-row">
                <span class="badge <?php echo $tb;?>" style="font-size:.6rem;flex-shrink:0"><?php echo $tl;?></span>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($r['offense_title']);?></div>
                    <div style="font-size:.68rem;color:var(--txt-m);margin-top:.1rem;display:flex;gap:.7rem;flex-wrap:wrap">
                        <span class="mono"><?php echo htmlspecialchars($r['offense_code']);?></span>
                        <?php if($r['fine_amount']>0):?><span style="color:#fde68a">$<?php echo number_format($r['fine_amount'],0);?></span><?php endif;?>
                        <?php if($r['jail_time']>0):?><span style="color:#fca5a5"><?php echo $r['jail_time'];?> mois</span><?php endif;?>
                        <?php if($r['incident_date']):?><span><?php echo date('d/m/Y',strtotime($r['incident_date']));?></span><?php endif;?>
                        <span><?php echo htmlspecialchars($r['officier']??'—');?></span>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:.4rem;flex-shrink:0">
                    <select class="form-select" style="width:95px;font-size:.7rem;padding:.2rem .4rem" onchange="quickStatus(this,<?php echo $r['id'];?>)">
                        <option value="active" <?php echo $r['status']==='active'?'selected':'';?>>Actif</option>
                        <option value="completed" <?php echo $r['status']==='completed'?'selected':'';?>>Purgé</option>
                        <option value="expunged" <?php echo $r['status']==='expunged'?'selected':'';?>>Effacé</option>
                    </select>
                    <button class="btn btn-ghost btn-xs" onclick="openAddSaisie(<?php echo $r['id'];?>,<?php echo $cit['citizen_id'];?>,'<?php echo addslashes($fullName);?>')" title="Ajouter saisie"><i class="fas fa-box-archive"></i></button>
                    <form method="POST" data-confirm data-confirm-title="Supprimer cette infraction" data-confirm-target="<?php echo htmlspecialchars(addslashes($r['offense_title']));?>" data-confirm-ok="Supprimer" style="margin:0;display:inline">
                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $r['id'];?>"><input type="hidden" name="citizen_id" value="<?php echo $cit['citizen_id'];?>">
                        <button type="submit" class="btn btn-danger btn-xs" title="Supprimer"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </div>
            <?php endforeach;?>
        </div>
        <?php endif;?>

        <!-- SAISIES -->
        <?php if(!empty($saisies)):?>
        <div style="margin-bottom:.65rem">
            <div style="font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.45rem"><i class="fas fa-box-archive" style="margin-right:.3rem;color:var(--blue-l)"></i>Saisies</div>
            <div style="display:flex;flex-wrap:wrap;gap:.3rem">
                <?php foreach($saisies as $s):[$sl,$si,$sb]=$SCAT[$s['categorie']]??['Autre','fas fa-box','bg-gray'];?>
                <span class="saisie-tag badge <?php echo $sb;?>">
                    <i class="<?php echo $si;?>" style="font-size:.6rem"></i>
                    <?php echo htmlspecialchars($s['description']);?>
                    <?php if($s['quantite']):?> (<?php echo htmlspecialchars($s['quantite']);?>)<?php endif;?>
                    <button onclick="deleteSaisie(<?php echo $s['id'];?>,'<?php echo addslashes($s['description']);?>')" style="background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:.7rem;margin-left:.2rem;padding:0">×</button>
                </span>
                <?php endforeach;?>
            </div>
        </div>
        <?php endif;?>

        <!-- ACTIONS BAS -->
        <div style="display:flex;gap:.5rem;padding-top:.65rem;border-top:1px solid var(--bdr)">
            <button onclick="openNewRecord(<?php echo $cit['citizen_id'];?>,'<?php echo addslashes($fullName);?>')" class="btn btn-secondary btn-xs"><i class="fas fa-plus"></i> Ajouter infraction</button>
            <button onclick="openAddSaisie(null,<?php echo $cit['citizen_id'];?>,'<?php echo addslashes($fullName);?>')" class="btn btn-ghost btn-xs"><i class="fas fa-box-archive"></i> Ajouter saisie</button>
            <a href="profiles.php?id=<?php echo $cit['citizen_id'];?>" class="btn btn-ghost btn-xs"><i class="fas fa-user"></i> Profil</a>
        </div>
    </div>
</div>
<?php endforeach; endif;?>

<!-- PAGINATION -->
<?php if($totalPages>1):?>
<div style="display:flex;gap:.35rem;justify-content:center;margin-top:.85rem">
    <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>" class="btn btn-ghost btn-sm">← Préc.</a><?php endif;?>
    <span style="display:flex;align-items:center;font-size:.78rem;color:var(--txt-m)">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
    <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>" class="btn btn-ghost btn-sm">Suiv. →</a><?php endif;?>
</div>
<?php endif;?>

<!-- MODAL NOUVEAU CASIER -->
<div id="newCasierModal" class="modal-ov hidden" style="display:none">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-gavel"></i> Nouveau casier judiciaire</h2><button class="modal-cl" onclick="LSPD.closeModal('newCasierModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Citoyen *</label>
            <select name="citizen_id" class="form-select" required id="cit_sel">
                <option value="">Sélectionner...</option>
                <?php foreach($allCitizens as $p):?><option value="<?php echo $p['id'];?>" id="opt-c-<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Type *</label>
            <select name="record_type" class="form-select" required>
                <?php foreach($TYPES as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Date des faits *</label><input type="date" name="incident_date" class="form-input" value="<?php echo date('Y-m-d');?>" required></div>
        <div class="form-group"><label class="form-label">Sévérité (1-5)</label><input type="number" name="severity" class="form-input" value="1" min="1" max="5"></div>
        <div class="form-group form-full"><label class="form-label">Infraction depuis le code pénal</label>
            <select id="offense_sel" class="form-select" onchange="fillOffense(this)">
                <option value="">— Saisie manuelle —</option>
                <?php foreach($allOffenses as $o):?><option value="<?php echo $o['id'];?>" data-code="<?php echo htmlspecialchars($o['code']);?>" data-title="<?php echo htmlspecialchars($o['title']);?>" data-fine="<?php echo $o['max_fine'];?>" data-jail="<?php echo $o['max_jail_months'];?>" data-pts="<?php echo $o['points'];?>"><?php echo htmlspecialchars($o['code'].' — '.$o['title']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Code pénal *</label><input type="text" name="offense_code" id="off_code" class="form-input" required placeholder="PC 187..."></div>
        <div class="form-group"><label class="form-label">Intitulé *</label><input type="text" name="offense_title" id="off_title" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Amende ($)</label><input type="number" name="fine" id="off_fine" class="form-input" value="0" min="0"></div>
        <div class="form-group"><label class="form-label">Prison (mois)</label><input type="number" name="jail" id="off_jail" class="form-input" value="0" min="0"></div>
        <div class="form-group"><label class="form-label">Points permis</label><input type="number" name="points" id="off_pts" class="form-input" value="0" min="0"></div>
        <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" placeholder="Circonstances..."></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('newCasierModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>

<!-- MODAL AJOUTER SAISIE -->
<div id="addSaisieModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-box-archive"></i> Ajouter une saisie</h2><button class="modal-cl" onclick="LSPD.closeModal('addSaisieModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_saisie">
    <input type="hidden" name="record_id" id="saisie-rid">
    <input type="hidden" name="citizen_id" id="saisie-cid">
    <div style="font-weight:600;font-size:.85rem;margin-bottom:.85rem;color:var(--txt-s)" id="saisie-ctx"></div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Catégorie *</label>
            <select name="categorie" class="form-select" required>
                <?php foreach($SCAT as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Quantité / Détail</label><input type="text" name="quantite" class="form-input" placeholder="Ex: x2, 50g..."></div>
        <div class="form-group form-full"><label class="form-label">Description *</label><input type="text" name="description" class="form-input" required placeholder="Beretta M9, Cocaïne 2g..."></div>
        <div class="form-group"><label class="form-label">Valeur estimée ($)</label><input type="number" name="valeur" class="form-input" value="0" min="0" step="10"></div>
        <div class="form-group"><label class="form-label">Lieu de saisie</label><input type="text" name="lieu" class="form-input" placeholder="Domicile, Véhicule..."></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('addSaisieModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
</form></div></div>

<!-- FORM STATUS AJAX -->
<form id="statusForm" method="POST" style="display:none"><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" id="sf-id"><input type="hidden" name="status" id="sf-status"><input type="hidden" name="citizen_id" value="0"></form>
<!-- FORM DELETE SAISIE -->
<form id="deleteSaisieForm" method="POST" data-confirm data-confirm-title="Supprimer cette saisie" data-confirm-ok="Supprimer" style="display:none">
    <input type="hidden" name="action" value="delete_saisie">
    <input type="hidden" name="id" id="ds-id">
</form>

<script>
function toggleCitoyen(id){
    const b=document.getElementById('ccb-'+id);
    const ch=document.querySelector('.cc-chevron-'+id);
    if(!b)return;
    b.classList.toggle('open');
    if(ch)ch.style.transform=b.classList.contains('open')?'rotate(180deg)':'';
}

// Ouvrir les cartes des citoyens pré-filtrés
<?php if($viewCid):?>document.addEventListener('DOMContentLoaded',()=>toggleCitoyen(<?php echo $viewCid;?>));<?php endif;?>

function openNewRecord(cid,name){
    document.getElementById('cit_sel').value=cid;
    LSPD.openModal('newCasierModal');
}

function openAddSaisie(rid,cid,name){
    document.getElementById('saisie-rid').value=rid||'';
    document.getElementById('saisie-cid').value=cid;
    document.getElementById('saisie-ctx').textContent='Pour : '+name+(rid?' — Infraction #'+rid:'');
    LSPD.openModal('addSaisieModal');
}

function fillOffense(sel){
    const o=sel.options[sel.selectedIndex];
    if(!o.value) return;
    document.getElementById('off_code').value=o.dataset.code||'';
    document.getElementById('off_title').value=o.dataset.title||'';
    document.getElementById('off_fine').value=o.dataset.fine||'0';
    document.getElementById('off_jail').value=o.dataset.jail||'0';
    document.getElementById('off_pts').value=o.dataset.pts||'0';
}

function quickStatus(sel,id){
    document.getElementById('sf-id').value=id;
    document.getElementById('sf-status').value=sel.value;
    document.getElementById('statusForm').submit();
}

function deleteSaisie(id,desc){
    const f=document.getElementById('deleteSaisieForm');
    document.getElementById('ds-id').value=id;
    f.dataset.confirmTarget=desc;
    f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
}
<?php if($msg&&$msgType==='success'):?>
document.addEventListener('DOMContentLoaded',()=>LSPD.toast(<?php echo json_encode($msg);?>,'success'));
<?php elseif($msg&&$msgType==='error'):?>
document.addEventListener('DOMContentLoaded',()=>LSPD.toast(<?php echo json_encode($msg);?>,'error'));
<?php endif;?>
</script>
<?php include __DIR__ . '/includes/footer.php';?>