<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Emprunts d'État"; $__secLabel = "Secrétariat aux Finances"; $__secColor = '#15803d'; $__secIcon = 'fas fa-money-bill-transfer';
$db = Database::getInstance()->getConnection(); $msg=''; $msgType='';
$db->exec("CREATE TABLE IF NOT EXISTS `gouv_emprunts` (
  `id` int AUTO_INCREMENT PRIMARY KEY, `reference` varchar(80) UNIQUE,
  `titre` varchar(250) NOT NULL, `objet` text,
  `preteur` varchar(150), `preteur_faction` varchar(80),
  `montant` decimal(16,2) NOT NULL, `taux_interet` decimal(6,3) DEFAULT 0,
  `duree_mois` int DEFAULT 12, `mensualite` decimal(12,2) DEFAULT 0,
  `date_debut` date, `date_fin` date,
  `montant_rembourse` decimal(16,2) DEFAULT 0,
  `statut` enum('demande','actif','rembourse','defaut','annule') DEFAULT 'demande',
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS `gouv_emprunts_remboursements` (
  `id` int AUTO_INCREMENT PRIMARY KEY, `emprunt_id` int NOT NULL,
  `montant_capital` decimal(12,2) DEFAULT 0, `montant_interet` decimal(12,2) DEFAULT 0,
  `date_remboursement` date, `notes` varchar(250),
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (emprunt_id) REFERENCES gouv_emprunts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='create_emprunt') {
        $year=date('Y'); $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_emprunts WHERE YEAR(created_at)=$year")->fetchColumn();
        $ref=sprintf("EMPR-%d-%04d",$year,$seq);
        $duree=(int)$_POST['duree_mois'];$montant=(float)$_POST['montant'];$taux=(float)$_POST['taux_interet'];
        $mensualite=$duree>0?round(($montant*($taux/1200)*pow(1+$taux/1200,$duree))/(pow(1+$taux/1200,$duree)-1),2):0;
        $db->prepare("INSERT INTO gouv_emprunts (reference,titre,objet,preteur,preteur_faction,montant,taux_interet,duree_mois,mensualite,date_debut,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$ref,trim($_POST['titre']),trim($_POST['objet']??''),trim($_POST['preteur']??''),trim($_POST['preteur_faction']??''),$montant,$taux,$duree,$mensualite,$_POST['date_debut']?:date('Y-m-d'),$_SESSION['user_id']]);
        $msg="Emprunt $ref créé."; $msgType='ok';
    }
    if($action==='add_remboursement') {
        $id=(int)$_POST['emprunt_id']; $cap=(float)$_POST['montant_capital']; $int=(float)$_POST['montant_interet'];
        $db->prepare("INSERT INTO gouv_emprunts_remboursements (emprunt_id,montant_capital,montant_interet,date_remboursement,notes,created_by) VALUES (?,?,?,?,?,?)")
           ->execute([$id,$cap,$int,$_POST['date_remboursement']?:date('Y-m-d'),trim($_POST['notes']??''),$_SESSION['user_id']]);
        $db->prepare("UPDATE gouv_emprunts SET montant_rembourse=montant_rembourse+? WHERE id=?")->execute([$cap+$int,$id]);
        $msg='Remboursement enregistré.'; $msgType='ok';
    }
    if($action==='update_emprunt_statut') {
        $db->prepare("UPDATE gouv_emprunts SET statut=? WHERE id=?")->execute([$_POST['statut'],(int)$_POST['emprunt_id']]);
        $msg='Statut mis à jour.'; $msgType='ok';
    }
    if($msg){header('Location: sec_emprunts.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}
$emprunts=[];try{$emprunts=$db->query("SELECT * FROM gouv_emprunts ORDER BY FIELD(statut,'actif','demande','rembourse','defaut','annule'),created_at DESC")->fetchAll();}catch(Exception $e){}
$statsEm=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_emprunts GROUP BY statut")->fetchAll() as $r)$statsEm[$r['statut']]=$r['n'];}catch(Exception $e){}
$totalDette=0;try{$totalDette=(float)$db->query("SELECT SUM(montant-montant_rembourse) FROM gouv_emprunts WHERE statut='actif'")->fetchColumn();}catch(Exception $e){}
$statCfg=['demande'=>['bg-blue','Demande'],'actif'=>['bg-yellow','Actif'],'rembourse'=>['bg-green','Remboursé'],'defaut'=>['bg-red','Défaut'],'annule'=>['bg-gray','Annulé']];
include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>
<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(21,128,61,.12);color:#86efac"><i class="fas fa-money-bill-transfer"></i></div><div class="kpi-lbl">Dette Active</div><div class="kpi-val teal">$<?php echo number_format($totalDette,0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-hourglass-half"></i></div><div class="kpi-lbl">Emprunts Actifs</div><div class="kpi-val gold"><?php echo $statsEm['actif']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-check"></i></div><div class="kpi-lbl">Remboursés</div><div class="kpi-val green"><?php echo $statsEm['rembourse']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-exclamation-triangle"></i></div><div class="kpi-lbl">Défauts</div><div class="kpi-val red"><?php echo $statsEm['defaut']??0;?></div></div>
</div>
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem"><button onclick="Gov.openModal('newEmpruntModal')" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Nouvel Emprunt</button></div>

<?php foreach($emprunts as $em):[$eb,$el]=$statCfg[$em['statut']]??['bg-gray','—'];$reste=$em['montant']-$em['montant_rembourse'];$pct=round(($em['montant_rembourse']/max(1,$em['montant']))*100);?>
<div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1rem 1.25rem;margin-bottom:.75rem">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.85rem;margin-bottom:.6rem">
    <div>
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
        <span class="mono" style="font-size:.72rem;color:var(--gov-l)"><?php echo $em['reference'];?></span>
        <span class="badge <?php echo $eb;?>" style="font-size:.58rem"><?php echo $el;?></span>
      </div>
      <div style="font-weight:700;font-size:.88rem"><?php echo htmlspecialchars($em['titre']);?></div>
      <?php if($em['preteur']):?><div style="font-size:.73rem;color:var(--txt-s)">Prêteur: <?php echo htmlspecialchars($em['preteur']);?></div><?php endif;?>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <div class="mono" style="font-size:1.1rem;font-weight:700;color:#86efac">$<?php echo number_format($em['montant'],0);?></div>
      <div style="font-size:.7rem;color:var(--txt-m)"><?php echo $em['taux_interet'];?>% — <?php echo $em['duree_mois'];?> mois</div>
      <div style="font-size:.7rem;color:#fcd34d">Reste: $<?php echo number_format($reste,0);?></div>
    </div>
  </div>
  <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $pct;?>%;background:#86efac"></div></div>
  <div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--txt-m);margin-top:.2rem"><span>Remboursé: $<?php echo number_format($em['montant_rembourse'],0);?></span><span><?php echo $pct;?>%</span></div>
  <?php if(in_array($em['statut'],['actif','demande'])):?>
  <div style="display:flex;gap:.5rem;margin-top:.55rem;flex-wrap:wrap">
    <?php if($em['statut']==='demande'):?><form method="POST" style="display:inline"><input type="hidden" name="action" value="update_emprunt_statut"><input type="hidden" name="emprunt_id" value="<?php echo $em['id'];?>"><input type="hidden" name="statut" value="actif"><button type="submit" class="btn btn-success btn-xs">Activer</button></form><?php endif;?>
    <?php if($em['statut']==='actif'):?><button class="btn btn-ghost btn-xs" onclick="document.getElementById('ri-<?php echo $em['id'];?>').classList.toggle('hidden')"><i class="fas fa-coins"></i> Remboursement</button><?php endif;?>
    <form method="POST" style="display:inline"><input type="hidden" name="action" value="update_emprunt_statut"><input type="hidden" name="emprunt_id" value="<?php echo $em['id'];?>"><input type="hidden" name="statut" value="rembourse"><button type="submit" class="btn btn-ghost btn-xs">Marquer remboursé</button></form>
  </div>
  <?php if($em['statut']==='actif'):?>
  <div id="ri-<?php echo $em['id'];?>" class="hidden" style="margin-top:.65rem;padding:.85rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px">
    <form method="POST" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.5rem;align-items:end">
      <input type="hidden" name="action" value="add_remboursement"><input type="hidden" name="emprunt_id" value="<?php echo $em['id'];?>">
      <div><div class="form-label">Capital ($)</div><input type="number" name="montant_capital" class="form-input form-input" min="0" step="0.01" value="<?php echo $em['mensualite'];?>"></div>
      <div><div class="form-label">Intérêts ($)</div><input type="number" name="montant_interet" class="form-input" min="0" step="0.01" value="0"></div>
      <div><div class="form-label">Date</div><input type="date" name="date_remboursement" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
      <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i></button>
    </form>
  </div>
  <?php endif;?>
  <?php endif;?>
</div>
<?php endforeach;?>

<div id="newEmpruntModal" class="modal-ov hidden"><div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-money-bill-transfer"></i> Nouvel Emprunt d'État</h2><button class="modal-cl" onclick="Gov.closeModal('newEmpruntModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_emprunt">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Prêteur *</label><input type="text" name="preteur" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Faction prêteur</label><input type="text" name="preteur_faction" class="form-input"></div>
    <div class="form-group"><label class="form-label">Montant ($) *</label><input type="number" name="montant" class="form-input" min="0" step="0.01" required></div>
    <div class="form-group"><label class="form-label">Taux d'intérêt (%)</label><input type="number" name="taux_interet" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group"><label class="form-label">Durée (mois)</label><input type="number" name="duree_mois" class="form-input" min="1" value="12"></div>
    <div class="form-group"><label class="form-label">Date de début</label><input type="date" name="date_debut" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group form-full"><label class="form-label">Objet / Finalité</label><textarea name="objet" class="form-textarea" style="min-height:75px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newEmpruntModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>