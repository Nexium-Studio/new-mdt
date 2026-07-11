<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Rapports Financiers"; $__secLabel = "Secrétariat aux Finances"; $__secColor = '#15803d'; $__secIcon = 'fas fa-chart-pie';
$db = Database::getInstance()->getConnection(); $msg=''; $msgType='';
$db->exec("CREATE TABLE IF NOT EXISTS `gouv_rapports_fin` (
  `id` int AUTO_INCREMENT PRIMARY KEY, `reference` varchar(80) UNIQUE,
  `titre` varchar(250) NOT NULL, `type` enum('mensuel','trimestriel','annuel','exceptionnel','audit') DEFAULT 'mensuel',
  `periode_debut` date, `periode_fin` date,
  `recettes` decimal(16,2) DEFAULT 0, `depenses` decimal(16,2) DEFAULT 0,
  `solde_net` decimal(16,2) DEFAULT 0,
  `commentaire` text, `recommandations` text, `visibilite` enum('interne','confidentiel','public') DEFAULT 'interne',
  `statut` enum('brouillon','valide','publie') DEFAULT 'brouillon',
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='create_rapport') {
        $year=date('Y'); $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_rapports_fin WHERE YEAR(created_at)=$year")->fetchColumn();
        $prefix=['mensuel'=>'RPT-M','trimestriel'=>'RPT-T','annuel'=>'RPT-A','exceptionnel'=>'RPT-X','audit'=>'RPT-AU'][$_POST['type']]??'RPT';
        $ref=sprintf("%s-%d-%04d",$prefix,$year,$seq);
        $rec=(float)$_POST['recettes']; $dep=(float)$_POST['depenses'];
        $db->prepare("INSERT INTO gouv_rapports_fin (reference,titre,type,periode_debut,periode_fin,recettes,depenses,solde_net,commentaire,recommandations,visibilite,statut,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$ref,trim($_POST['titre']),$_POST['type'],$_POST['periode_debut']?:null,$_POST['periode_fin']?:null,$rec,$dep,$rec-$dep,trim($_POST['commentaire']??''),trim($_POST['recommandations']??''),$_POST['visibilite']??'interne',$_POST['statut']??'brouillon',$_SESSION['user_id']]);
        $msg="Rapport $ref créé."; $msgType='ok';
    }
    if($action==='update_rapport') {
        $db->prepare("UPDATE gouv_rapports_fin SET statut=? WHERE id=?")->execute([$_POST['statut'],(int)$_POST['rapport_id']]);
        $msg='Statut mis à jour.'; $msgType='ok';
    }
    if($msg){header('Location: sec_rapports_fin.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}
$rapports=[];try{$rapports=$db->query("SELECT * FROM gouv_rapports_fin ORDER BY created_at DESC LIMIT 30")->fetchAll();}catch(Exception $e){}
// Agrégat trésorerie pour rapport auto
$tresoAgg=[];try{$tresoAgg=$db->query("SELECT SUM(CASE WHEN type IN ('recette','dotation') THEN montant ELSE 0 END) rec, SUM(CASE WHEN type IN ('depense','transfert','virement') THEN montant ELSE 0 END) dep FROM gouv_tresorerie WHERE statut='confirme' AND MONTH(date_operation)=MONTH(CURDATE()) AND YEAR(date_operation)=YEAR(CURDATE())")->fetch();}catch(Exception $e){$tresoAgg=['rec'=>0,'dep'=>0];}
// Impôts du mois
$impots=[];try{$impots=$db->query("SELECT COALESCE(SUM(impot_du),0) v FROM company_finances")->fetchColumn();}catch(Exception $e){$impots=0;}
$statCfg=['brouillon'=>['bg-gray','Brouillon'],'valide'=>['bg-blue','Validé'],'publie'=>['bg-green','Publié']];
$typeCfg=['mensuel'=>'Mensuel','trimestriel'=>'Trimestriel','annuel'=>'Annuel','exceptionnel'=>'Exceptionnel','audit'=>'Audit'];
include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<!-- Synthèse mois courant -->
<div class="card" style="border-color:rgba(21,128,61,.3);margin-bottom:1.25rem">
  <div class="card-title" style="color:#86efac"><i class="fas fa-chart-line" style="color:#15803d"></i> Synthèse du Mois Courant — <?php echo date('F Y');?></div>
  <div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">
    <div class="kpi"><div class="kpi-lbl">Recettes du mois</div><div class="kpi-val green" style="font-size:1.3rem">$<?php echo number_format($tresoAgg['rec']??0,0);?></div></div>
    <div class="kpi"><div class="kpi-lbl">Dépenses du mois</div><div class="kpi-val red" style="font-size:1.3rem">$<?php echo number_format($tresoAgg['dep']??0,0);?></div></div>
    <div class="kpi"><div class="kpi-lbl">Solde net</div><div class="kpi-val <?php echo (($tresoAgg['rec']??0)-($tresoAgg['dep']??0))>=0?'teal':'red';?>" style="font-size:1.3rem">$<?php echo number_format(($tresoAgg['rec']??0)-($tresoAgg['dep']??0),0);?></div></div>
    <div class="kpi"><div class="kpi-lbl">Impôts dus (total)</div><div class="kpi-val gold" style="font-size:1.3rem">$<?php echo number_format((float)$impots,0);?></div></div>
  </div>
  <button onclick="Gov.openModal('newRapportModal')" class="btn btn-success btn-sm"><i class="fas fa-file-plus"></i> Générer un rapport</button>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h3 style="font-size:.9rem;font-weight:700">Rapports Archivés (<?php echo count($rapports);?>)</h3></div>

<?php if(empty($rapports)):?><div class="card" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-chart-pie" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun rapport</div>
<?php else: foreach($rapports as $r):[$rb,$rl]=$statCfg[$r['statut']]??['bg-gray','—'];$positif=($r['solde_net']??0)>=0;?>
<div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:10px;padding:.95rem 1.2rem;margin-bottom:.6rem;display:flex;align-items:center;gap:1.1rem">
  <div style="width:52px;height:52px;border-radius:10px;background:rgba(21,128,61,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <i class="fas fa-file-chart-column" style="color:#86efac;font-size:1.1rem"></i>
  </div>
  <div style="flex:1;min-width:0">
    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
      <span class="mono" style="font-size:.72rem;color:var(--gov-l)"><?php echo $r['reference'];?></span>
      <span class="badge bg-teal" style="font-size:.58rem"><?php echo $typeCfg[$r['type']]??'—';?></span>
      <span class="badge <?php echo $rb;?>" style="font-size:.58rem"><?php echo $rl;?></span>
    </div>
    <div style="font-weight:600;font-size:.87rem"><?php echo htmlspecialchars($r['titre']);?></div>
    <?php if($r['periode_debut']):?><div style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y',strtotime($r['periode_debut']));?> → <?php echo $r['periode_fin']?date('d/m/Y',strtotime($r['periode_fin'])):'?';?></div><?php endif;?>
  </div>
  <div style="text-align:right;flex-shrink:0">
    <div class="mono" style="font-size:.9rem;font-weight:700;color:<?php echo $positif?'#86efac':'#f87171';?>"><?php echo $positif?'+':'-';?>$<?php echo number_format(abs($r['solde_net']),0);?></div>
    <div style="font-size:.65rem;color:var(--txt-m)">R: $<?php echo number_format($r['recettes'],0);?> / D: $<?php echo number_format($r['depenses'],0);?></div>
    <?php if($r['statut']==='brouillon'):?>
    <form method="POST" style="margin-top:.3rem"><input type="hidden" name="action" value="update_rapport"><input type="hidden" name="rapport_id" value="<?php echo $r['id'];?>"><input type="hidden" name="statut" value="valide"><button type="submit" class="btn btn-success btn-xs">Valider</button></form>
    <?php elseif($r['statut']==='valide'):?>
    <form method="POST" style="margin-top:.3rem"><input type="hidden" name="action" value="update_rapport"><input type="hidden" name="rapport_id" value="<?php echo $r['id'];?>"><input type="hidden" name="statut" value="publie"><button type="submit" class="btn btn-primary btn-xs">Publier</button></form>
    <?php endif;?>
  </div>
</div>
<?php endforeach; endif;?>

<div id="newRapportModal" class="modal-ov hidden"><div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-file-chart-column"></i> Créer un Rapport Financier</h2><button class="modal-cl" onclick="Gov.closeModal('newRapportModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_rapport">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required value="Rapport Financier <?php echo date('F Y');?>"></div>
    <div class="form-group"><label class="form-label">Type</label><select name="type" class="form-select"><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $k==='mensuel'?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Statut initial</label><select name="statut" class="form-select"><option value="brouillon">Brouillon</option><option value="valide">Validé</option></select></div>
    <div class="form-group"><label class="form-label">Début de période</label><input type="date" name="periode_debut" class="form-input" value="<?php echo date('Y-m-01');?>"></div>
    <div class="form-group"><label class="form-label">Fin de période</label><input type="date" name="periode_fin" class="form-input" value="<?php echo date('Y-m-t');?>"></div>
    <div class="form-group"><label class="form-label">Recettes totales ($)</label><input type="number" name="recettes" class="form-input" min="0" step="0.01" value="<?php echo round($tresoAgg['rec']??0,2);?>"></div>
    <div class="form-group"><label class="form-label">Dépenses totales ($)</label><input type="number" name="depenses" class="form-input" min="0" step="0.01" value="<?php echo round($tresoAgg['dep']??0,2);?>"></div>
    <div class="form-group"><label class="form-label">Visibilité</label><select name="visibilite" class="form-select"><option value="interne">Interne</option><option value="confidentiel">Confidentiel</option><option value="public">Public</option></select></div>
    <div class="form-group form-full"><label class="form-label">Commentaire</label><textarea name="commentaire" class="form-textarea" style="min-height:80px"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Recommandations</label><textarea name="recommandations" class="form-textarea" style="min-height:70px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newRapportModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>