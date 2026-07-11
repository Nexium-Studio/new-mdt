<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Saisies'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

require_once __DIR__ . '/../../includes/lspd_cdn.php';
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

// ── Table ──────────────────────────────────────────────────────────────────
try{$db->exec("CREATE TABLE IF NOT EXISTS `lspd_saisies` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `criminal_record_id` int(11) DEFAULT NULL,
    `report_id` int(11) DEFAULT NULL,
    `citizen_id` int(11) DEFAULT NULL,
    `categorie` enum('arme','drogue','vehicule','argent','bijou','electronique','document','autre') NOT NULL DEFAULT 'autre',
    `description` varchar(300) NOT NULL,
    `quantite` varchar(100) DEFAULT NULL,
    `valeur_estimee` decimal(10,2) DEFAULT NULL,
    `lieu_saisie` varchar(200) DEFAULT NULL,
    `numero_evidence` varchar(50) DEFAULT NULL,
    `statut` enum('saisie','detruit','restitue','en_evidence','transfiere') DEFAULT 'saisie',
    `notes` text DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Colonnes ajoutées dans une version ultérieure — on les crée si absentes
$existingCols = array_column($db->query("SHOW COLUMNS FROM lspd_saisies")->fetchAll(PDO::FETCH_ASSOC),'Field');
$colsToAdd = [
    'numero_evidence' => "ALTER TABLE lspd_saisies ADD COLUMN `numero_evidence` varchar(50) DEFAULT NULL AFTER `lieu_saisie`",
    'statut'          => "ALTER TABLE lspd_saisies ADD COLUMN `statut` enum('saisie','detruit','restitue','en_evidence','transfiere') DEFAULT 'saisie' AFTER `numero_evidence`",
    'notes'           => "ALTER TABLE lspd_saisies ADD COLUMN `notes` text DEFAULT NULL AFTER `statut`",
    'updated_at'      => "ALTER TABLE lspd_saisies ADD COLUMN `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    'document'        => null, // valeur d'enum, traitée séparément
];
foreach ($colsToAdd as $col => $sql) {
    if ($col === 'document') continue;
    if (!in_array($col, $existingCols) && $sql) {
        try { $db->exec($sql); } catch(Exception $e) {}
    }
}
// S'assurer que l'enum categorie contient 'document'
try {
    $db->exec("ALTER TABLE lspd_saisies MODIFY COLUMN `categorie` enum('arme','drogue','vehicule','argent','bijou','electronique','document','autre') NOT NULL DEFAULT 'autre'");
} catch(Exception $e) {}

}catch(Exception $e){}
try { $db->exec("ALTER TABLE lspd_saisies ADD COLUMN IF NOT EXISTS images TEXT NULL"); } catch(Exception $e){}

// ── ACTIONS POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    if ($action==='create') {
        try {
            $num='SZ-'.date('Y').'-'.str_pad((int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM lspd_saisies")->fetchColumn(),4,'0',STR_PAD_LEFT);
            $imgArr=json_decode($_POST['images']??'',true);$imgVal=(is_array($imgArr)&&$imgArr)?json_encode(array_values($imgArr)):null;
            $db->prepare("INSERT INTO lspd_saisies (criminal_record_id,report_id,citizen_id,categorie,description,quantite,valeur_estimee,lieu_saisie,numero_evidence,statut,notes,created_by,images) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([intval($_POST['record_id'])?:null,intval($_POST['report_id'])?:null,intval($_POST['citizen_id'])?:null,$_POST['categorie'],trim($_POST['description']),trim($_POST['quantite']??''),floatval($_POST['valeur']??0)?:null,trim($_POST['lieu']??''),$num,$_POST['statut']??'saisie',trim($_POST['notes']??''),$uid,$imgVal]);
            $msg="Saisie $num enregistrée.";$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }

    if ($action==='update_statut') {
        $db->prepare("UPDATE lspd_saisies SET statut=? WHERE id=?")->execute([$_POST['statut'],intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }

    if ($action==='delete') {
        $db->prepare("DELETE FROM lspd_saisies WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Saisie supprimée.';$msgType='success';
    }

    if ($msg) { header('Location: saisies.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ───────────────────────────────────────────────────────────────────
$search=$_GET['q']??''; $fCat=$_GET['cat']??'all'; $fStat=$_GET['stat']??'all';
$page=max(1,(int)($_GET['page']??1)); $perPage=25; $offset=($page-1)*$perPage;

$where=['1=1']; $params=[];
if ($search){$where[]="(s.description LIKE ? OR s.numero_evidence LIKE ? OR CONCAT(p.firstname,' ',p.lastname) LIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
if ($fCat!=='all'){$where[]="s.categorie=?";$params[]=$fCat;}
if ($fStat!=='all'){$where[]="s.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);

$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM lspd_saisies s LEFT JOIN profiles p ON p.id=s.citizen_id $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));

$saisies=[];
try{$s=$db->prepare("SELECT s.*,CONCAT(p.firstname,' ',p.lastname) AS citoyen,u.username AS agent FROM lspd_saisies s LEFT JOIN profiles p ON p.id=s.citizen_id LEFT JOIN users u ON u.id=s.created_by $wStr ORDER BY s.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$saisies=$s->fetchAll();}catch(Exception $e){}

// Stats
$stats=[];try{foreach($db->query("SELECT categorie,COUNT(*) n,SUM(valeur_estimee) v FROM lspd_saisies GROUP BY categorie")->fetchAll() as $r)$stats[$r['categorie']]=[$r['n'],$r['v']];}catch(Exception $e){}
$totalValeur=0;try{$totalValeur=(float)$db->query("SELECT COALESCE(SUM(valeur_estimee),0) FROM lspd_saisies WHERE statut NOT IN ('restitue','detruit')")->fetchColumn();}catch(Exception $e){}
$totalItems=0;try{$totalItems=(int)$db->query("SELECT COUNT(*) FROM lspd_saisies")->fetchColumn();}catch(Exception $e){}

$citizens=[];try{$citizens=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname LIMIT 300")->fetchAll();}catch(Exception $e){}
$reports=[];try{$reports=$db->query("SELECT id,title FROM reports WHERE faction='LSPD' ORDER BY created_at DESC LIMIT 100")->fetchAll();}catch(Exception $e){}

$CATS=['arme'=>['Arme','fas fa-gun','bg-red'],'drogue'=>['Drogue','fas fa-pills','bg-orange'],'vehicule'=>['Véhicule','fas fa-car','bg-blue'],'argent'=>['Argent','fas fa-coins','bg-yellow'],'bijou'=>['Bijou','fas fa-gem','bg-purple'],'electronique'=>['Électronique','fas fa-laptop','bg-blue'],'document'=>['Document','fas fa-file','bg-gray'],'autre'=>['Autre','fas fa-box','bg-gray']];
$STATUTS=['saisie'=>['Saisie','bg-red'],'en_evidence'=>['En évidence','bg-yellow'],'transfiere'=>['Transféré','bg-blue'],'restitue'=>['Restitué','bg-green'],'detruit'=>['Détruit','bg-gray']];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.3rem">
    <div class="kpi"><div class="kpi-ico"><i class="fas fa-box-archive"></i></div><div class="kpi-lbl">Total saisies</div><div class="kpi-val blue"><?php echo $totalItems;?></div></div>
    <div class="kpi"><div class="kpi-ico"><i class="fas fa-coins"></i></div><div class="kpi-lbl">Valeur en évidence</div><div class="kpi-val yellow">$<?php echo number_format($totalValeur,0);?></div></div>
    <div class="kpi"><div class="kpi-ico"><i class="fas fa-gun"></i></div><div class="kpi-lbl">Armes saisies</div><div class="kpi-val red"><?php echo $stats['arme'][0]??0;?></div></div>
    <div class="kpi"><div class="kpi-ico"><i class="fas fa-pills"></i></div><div class="kpi-lbl">Drogues</div><div class="kpi-val orange"><?php echo $stats['drogue'][0]??0;?></div></div>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1.1rem;flex-wrap:wrap;align-items:center">
    <form method="GET" style="display:flex;gap:.4rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" class="form-input" placeholder="Rechercher saisie, n° évidence, citoyen..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="cat" class="form-select" style="width:130px" onchange="this.form.submit()">
            <option value="all">Toutes catégories</option>
            <?php foreach($CATS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fCat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <select name="stat" class="form-select" style="width:120px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search||$fCat!=='all'||$fStat!=='all'):?><a href="saisies.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <button onclick="LSPD.openModal('newSaisieModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle saisie</button>
</div>

<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Image</th><th>N° Évidence</th><th>Catégorie</th><th>Description</th><th>Citoyen</th><th>Valeur</th><th>Lieu</th><th>Agent</th><th>Date</th><th>Statut</th><th></th></tr></thead>
<tbody>
<?php if(empty($saisies)):?><tr><td colspan="11" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune saisie</td></tr>
<?php else:foreach($saisies as $s):[$cl,$cb]=$CATS[$s['categorie']]??['Autre','bg-gray'];[$sl,$sb]=$STATUTS[$s['statut']]??[$s['statut'],'bg-gray'];
    $sImgs=json_decode($s['images']??'[]',true)?:[];$sImg=$sImgs[0]??'';?>
<tr>
    <td style="width:48px">
        <?php if($sImg):?><img src="<?php echo htmlspecialchars($sImg);?>" alt="" onclick="window.open('<?php echo htmlspecialchars($sImg);?>','_blank')" style="width:40px;height:40px;border-radius:7px;object-fit:cover;border:1px solid rgba(37,99,235,.25);cursor:pointer">
        <?php else:?><div style="width:40px;height:40px;border-radius:7px;background:rgba(37,99,235,.05);display:grid;place-items:center;color:var(--txt-m)"><i class="fas fa-image" style="font-size:.8rem;opacity:.4"></i></div><?php endif;?>
    </td>
    <td class="mono" style="font-size:.72rem;color:var(--blue-l)"><?php echo htmlspecialchars($s['numero_evidence']??'—');?></td>
    <td><span class="badge <?php echo $cb;?>" style="font-size:.62rem"><?php echo $cl;?></span></td>
    <td style="max-width:200px">
        <div style="font-weight:600;font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($s['description']);?></div>
        <?php if($s['quantite']):?><div style="font-size:.68rem;color:var(--txt-m)"><?php echo htmlspecialchars($s['quantite']);?></div><?php endif;?>
    </td>
    <td style="font-size:.79rem"><?php echo $s['citoyen']?htmlspecialchars($s['citoyen']):'<span style="color:var(--txt-m)">—</span>';?></td>
    <td class="mono" style="color:#fde68a;font-size:.79rem"><?php echo $s['valeur_estimee']>0?'$'.number_format($s['valeur_estimee'],0):'—';?></td>
    <td style="font-size:.75rem;color:var(--txt-m)"><?php echo htmlspecialchars($s['lieu_saisie']??'—');?></td>
    <td style="font-size:.75rem"><?php echo htmlspecialchars($s['agent']??'—');?></td>
    <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y',strtotime($s['created_at']));?></td>
    <td>
        <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $s['id'];?>">
            <select name="statut" class="form-select" style="width:110px;font-size:.7rem;padding:.22rem .4rem" onchange="this.form.submit()">
                <?php foreach($STATUTS as $k=>[$sl2]):?><option value="<?php echo $k;?>" <?php echo $s['statut']===$k?'selected':'';?>><?php echo $sl2;?></option><?php endforeach;?>
            </select>
        </form>
    </td>
    <td>
        <form method="POST" data-confirm data-confirm-title="Supprimer la saisie" data-confirm-target="<?php echo htmlspecialchars(addslashes($s['description']));?>" data-confirm-ok="Supprimer" style="margin:0;display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $s['id'];?>">
            <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div>

<?php if($totalPages>1):?>
<div style="display:flex;gap:.35rem;justify-content:center;margin-top:.65rem">
    <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&cat=<?php echo $fCat;?>&stat=<?php echo $fStat;?>" class="btn btn-ghost btn-sm">← Préc.</a><?php endif;?>
    <span style="display:flex;align-items:center;font-size:.78rem;color:var(--txt-m)">Page <?php echo $page;?>/<?php echo $totalPages;?> — <?php echo $total;?> résultat(s)</span>
    <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&cat=<?php echo $fCat;?>&stat=<?php echo $fStat;?>" class="btn btn-ghost btn-sm">Suiv. →</a><?php endif;?>
</div>
<?php endif;?>

<!-- MODAL NOUVELLE SAISIE -->
<div id="newSaisieModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-box-archive"></i> Enregistrer une saisie</h2><button class="modal-cl" onclick="LSPD.closeModal('newSaisieModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Catégorie *</label>
            <select name="categorie" class="form-select" required>
                <?php foreach($CATS as $k=>[$l,$i,$b]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Statut</label>
            <select name="statut" class="form-select">
                <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group form-full"><label class="form-label">Description *</label><input type="text" name="description" class="form-input" required placeholder="Beretta M9, Sachet de cocaïne, Montre Rolex..."></div>
        <div class="form-group"><label class="form-label">Quantité / Poids</label><input type="text" name="quantite" class="form-input" placeholder="x1, 50g, 500€..."></div>
        <div class="form-group"><label class="form-label">Valeur estimée ($)</label><input type="number" name="valeur" class="form-input" value="0" min="0" step="10"></div>
        <div class="form-group"><label class="form-label">Lieu de saisie</label><input type="text" name="lieu" class="form-input" placeholder="Domicile, Véhicule, Sur la personne..."></div>
        <div class="form-group"><label class="form-label">Citoyen concerné</label>
            <select name="citizen_id" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach($citizens as $c):?><option value="<?php echo $c['id'];?>"><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Rapport lié</label>
            <select name="report_id" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach($reports as $r):?><option value="<?php echo $r['id'];?>">#<?php echo str_pad($r['id'],6,'0',STR_PAD_LEFT);?> — <?php echo htmlspecialchars(mb_substr($r['title']??'',0,40));?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group form-full"><label class="form-label">Image(s) de la saisie</label><input type="hidden" name="images" id="saisieImagesHidden" value="[]"><div id="saisieImagesField"></div></div>
        <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes" class="form-textarea" style="min-height:70px" placeholder="Informations supplémentaires, état de la saisie..."></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('newSaisieModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
</form></div></div>

<script>
(function(){
    function go(){ if(window.mdtImageField){ mdtImageField({container:'saisieImagesField',hidden:'saisieImagesHidden',uploadUrl:'saisies.php',listUrl:'saisies.php?img_action=cdn_list'}); return true; } return false; }
    if(!go()){ var n=0,iv=setInterval(function(){ if(go()||++n>60) clearInterval(iv); },50); }
})();
</script>

<?php include __DIR__ . '/includes/footer.php';?>