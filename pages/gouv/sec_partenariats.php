<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Partenariats Public-Privé"; $__secLabel = "Secrétariat aux Entreprises"; $__secColor = '#0891b2'; $__secIcon = 'fas fa-handshake';
$db = Database::getInstance()->getConnection(); $msg=''; $msgType='';
$db->exec("CREATE TABLE IF NOT EXISTS `gouv_partenariats` (
  `id` int AUTO_INCREMENT PRIMARY KEY, `reference` varchar(80) UNIQUE,
  `titre` varchar(250) NOT NULL, `description` text,
  `partenaire` varchar(150), `partenaire_faction` varchar(80),
  `type` enum('concession','delegation','convention','co_financement','autre') DEFAULT 'convention',
  `domaine` varchar(150), `duree_mois` int DEFAULT 12,
  `montant_public` decimal(14,2) DEFAULT 0, `montant_prive` decimal(14,2) DEFAULT 0,
  `date_debut` date, `date_fin` date,
  `statut` enum('negociation','actif','suspendu','termine','rompu') DEFAULT 'negociation',
  `avantages_publics` text, `obligations_privees` text,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='create_part') {
        $year=date('Y'); $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_partenariats WHERE YEAR(created_at)=$year")->fetchColumn();
        $ref=sprintf("PPP-%d-%04d",$year,$seq);
        $db->prepare("INSERT INTO gouv_partenariats (reference,titre,description,partenaire,partenaire_faction,type,domaine,duree_mois,montant_public,montant_prive,date_debut,date_fin,avantages_publics,obligations_privees,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$ref,trim($_POST['titre']),trim($_POST['description']??''),trim($_POST['partenaire']),trim($_POST['partenaire_faction']??''),$_POST['type'],trim($_POST['domaine']??''),(int)$_POST['duree_mois'],(float)$_POST['montant_public'],(float)$_POST['montant_prive'],$_POST['date_debut']?:null,$_POST['date_fin']?:null,trim($_POST['avantages_publics']??''),trim($_POST['obligations_privees']??''),$_SESSION['user_id']]);
        $msg="Partenariat $ref créé."; $msgType='ok';
    }
    if($action==='update_part') {
        $db->prepare("UPDATE gouv_partenariats SET statut=? WHERE id=?")->execute([$_POST['statut'],(int)$_POST['part_id']]);
        $msg='Statut mis à jour.'; $msgType='ok';
    }
    if($msg){header('Location: sec_partenariats.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}
$parts=[];try{$parts=$db->query("SELECT * FROM gouv_partenariats ORDER BY created_at DESC")->fetchAll();}catch(Exception $e){}
$statsP=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_partenariats GROUP BY statut")->fetchAll() as $r)$statsP[$r['statut']]=$r['n'];}catch(Exception $e){}
$totalPub=0;try{$totalPub=(float)$db->query("SELECT SUM(montant_public) FROM gouv_partenariats WHERE statut='actif'")->fetchColumn();}catch(Exception $e){}
$statCfg=['negociation'=>['bg-blue','Négociation'],'actif'=>['bg-green','Actif'],'suspendu'=>['bg-yellow','Suspendu'],'termine'=>['bg-gray','Terminé'],'rompu'=>['bg-red','Rompu']];
$typeCfg=['concession'=>'Concession','delegation'=>'Délégation','convention'=>'Convention','co_financement'=>'Co-financement','autre'=>'Autre'];
include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>
<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(8,145,178,.12);color:#67e8f9"><i class="fas fa-handshake"></i></div><div class="kpi-lbl">Partenariats Actifs</div><div class="kpi-val cyan"><?php echo $statsP['actif']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(29,78,216,.12);color:var(--gov-l)"><i class="fas fa-clock"></i></div><div class="kpi-lbl">En Négociation</div><div class="kpi-val blue"><?php echo $statsP['negociation']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-coins"></i></div><div class="kpi-lbl">Investissement Public</div><div class="kpi-val green">$<?php echo number_format($totalPub,0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(100,116,139,.12);color:#94a3b8"><i class="fas fa-archive"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo array_sum($statsP);?></div></div>
</div>
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem"><button onclick="Gov.openModal('newPartModal')" class="btn btn-cyan btn-sm"><i class="fas fa-plus"></i> Nouveau Partenariat</button></div>

<?php if(empty($parts)):?><div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-m)"><i class="fas fa-handshake" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun partenariat</div>
<?php else: foreach($parts as $p):[$pb,$pl]=$statCfg[$p['statut']]??['bg-gray','—'];?>
<div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1rem 1.25rem;margin-bottom:.75rem">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.55rem">
    <div>
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
        <span class="mono" style="font-size:.72rem;color:var(--gov-l)"><?php echo $p['reference'];?></span>
        <span class="badge bg-cyan" style="font-size:.58rem"><?php echo $typeCfg[$p['type']]??'—';?></span>
        <span class="badge <?php echo $pb;?>" style="font-size:.58rem"><?php echo $pl;?></span>
      </div>
      <div style="font-weight:700;font-size:.9rem"><?php echo htmlspecialchars($p['titre']);?></div>
      <?php if($p['partenaire']):?><div style="font-size:.75rem;color:var(--txt-s);margin-top:.18rem"><i class="fas fa-building" style="margin-right:.3rem;opacity:.6"></i><?php echo htmlspecialchars($p['partenaire']);?><?php if($p['partenaire_faction']):?> · <?php echo htmlspecialchars($p['partenaire_faction']);?><?php endif;?></div><?php endif;?>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <div style="font-size:.72rem;color:var(--txt-m)">Public</div>
      <div class="mono" style="font-size:.85rem;color:#4ade80;font-weight:600">$<?php echo number_format($p['montant_public'],0);?></div>
      <div style="font-size:.65rem;color:var(--txt-m)">Privé: $<?php echo number_format($p['montant_prive'],0);?></div>
    </div>
  </div>
  <?php if($p['description']):?><div style="font-size:.79rem;color:var(--txt-s);line-height:1.5;margin-bottom:.45rem"><?php echo htmlspecialchars(mb_substr($p['description'],0,150));?></div><?php endif;?>
  <div style="display:flex;gap:.5rem;margin-top:.35rem">
    <?php foreach(['actif','suspendu','rompu'] as $ns): if($p['statut']!==$ns):?>
    <form method="POST" style="display:inline"><input type="hidden" name="action" value="update_part"><input type="hidden" name="part_id" value="<?php echo $p['id'];?>"><input type="hidden" name="statut" value="<?php echo $ns;?>">
      <button type="submit" class="btn btn-ghost btn-xs"><?php echo ucfirst($ns);?></button>
    </form>
    <?php endif; endforeach;?>
  </div>
</div>
<?php endforeach; endif;?>

<div id="newPartModal" class="modal-ov hidden"><div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-handshake"></i> Nouveau Partenariat PPP</h2><button class="modal-cl" onclick="Gov.closeModal('newPartModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_part">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Type</label><select name="type" class="form-select"><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Domaine</label><input type="text" name="domaine" class="form-input" placeholder="Infrastructures, santé…"></div>
    <div class="form-group"><label class="form-label">Partenaire *</label><input type="text" name="partenaire" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Faction partenaire</label><input type="text" name="partenaire_faction" class="form-input"></div>
    <div class="form-group"><label class="form-label">Durée (mois)</label><input type="number" name="duree_mois" class="form-input" min="1" value="12"></div>
    <div class="form-group"><label class="form-label">Date de début</label><input type="date" name="date_debut" class="form-input"></div>
    <div class="form-group"><label class="form-label">Date de fin</label><input type="date" name="date_fin" class="form-input"></div>
    <div class="form-group"><label class="form-label">Part publique ($)</label><input type="number" name="montant_public" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group"><label class="form-label">Part privée ($)</label><input type="number" name="montant_prive" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" style="min-height:80px"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Avantages publics</label><textarea name="avantages_publics" class="form-textarea" style="min-height:70px"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Obligations du partenaire privé</label><textarea name="obligations_privees" class="form-textarea" style="min-height:70px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newPartModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>