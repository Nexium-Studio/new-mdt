<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Trésorerie d'État"; $__secLabel = "Secrétariat aux Finances"; $__secColor = '#15803d'; $__secIcon = 'fas fa-vault';
$db = Database::getInstance()->getConnection(); $msg=''; $msgType='';
$db->exec("CREATE TABLE IF NOT EXISTS `gouv_tresorerie` (
  `id` int AUTO_INCREMENT PRIMARY KEY, `reference` varchar(80) UNIQUE,
  `type` enum('recette','depense','transfert','virement','dotation') DEFAULT 'recette',
  `categorie` varchar(120), `libelle` varchar(250) NOT NULL,
  `montant` decimal(16,2) NOT NULL, `devise` varchar(10) DEFAULT 'USD',
  `date_operation` date, `date_valeur` date,
  `beneficiaire` varchar(150), `beneficiaire_faction` varchar(80),
  `compte_source` varchar(80), `compte_dest` varchar(80),
  `statut` enum('previsionnel','confirme','annule') DEFAULT 'confirme',
  `justification` text, `piece_comptable` varchar(150),
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='add_operation') {
        $year=date('Y'); $month=date('m'); $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_tresorerie WHERE YEAR(created_at)=$year")->fetchColumn();
        $prefix=['recette'=>'REC','depense'=>'DEP','transfert'=>'TRF','virement'=>'VIR','dotation'=>'DOT'][$_POST['type']]??'OPE';
        $ref=sprintf("%s-%d%02d-%04d",$prefix,$year,$month,$seq);
        $db->prepare("INSERT INTO gouv_tresorerie (reference,type,categorie,libelle,montant,date_operation,date_valeur,beneficiaire,beneficiaire_faction,compte_source,compte_dest,statut,justification,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$ref,$_POST['type'],trim($_POST['categorie']??''),trim($_POST['libelle']),(float)$_POST['montant'],$_POST['date_operation']?:date('Y-m-d'),$_POST['date_valeur']?:null,trim($_POST['beneficiaire']??''),trim($_POST['beneficiaire_faction']??''),trim($_POST['compte_source']??''),trim($_POST['compte_dest']??''),$_POST['statut']??'confirme',trim($_POST['justification']??''),$_SESSION['user_id']]);
        $msg="Opération $ref enregistrée."; $msgType='ok';
    }
    if($msg){header('Location: sec_tresorerie.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}
$fType=$_GET['type_f']??''; $search=$_GET['q']??''; $page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(t.libelle LIKE ? OR t.reference LIKE ? OR t.beneficiaire LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;$params[]=$s;}
if($fType){$where[]="t.type=?";$params[]=$fType;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM gouv_tresorerie t $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$ops=[];try{$s=$db->prepare("SELECT * FROM gouv_tresorerie t $wStr ORDER BY t.date_operation DESC,t.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$ops=$s->fetchAll();}catch(Exception $e){}
$solde=[];try{$solde=$db->query("SELECT SUM(CASE WHEN type IN ('recette','dotation') THEN montant ELSE -montant END) solde, SUM(CASE WHEN type IN ('recette','dotation') AND statut='confirme' THEN montant ELSE 0 END) total_recettes, SUM(CASE WHEN type IN ('depense','transfert','virement') AND statut='confirme' THEN montant ELSE 0 END) total_depenses FROM gouv_tresorerie WHERE statut='confirme'")->fetch();}catch(Exception $e){$solde=['solde'=>0,'total_recettes'=>0,'total_depenses'=>0];}
$typeCfg=['recette'=>'Recette','depense'=>'Dépense','transfert'=>'Transfert','virement'=>'Virement','dotation'=>'Dotation'];
$typeColor=['recette'=>'#4ade80','depense'=>'#f87171','transfert'=>'var(--gov-l)','virement'=>'#fcd34d','dotation'=>'#c4b5fd'];
include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>
<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(21,128,61,.12);color:#86efac"><i class="fas fa-vault"></i></div><div class="kpi-lbl">Solde Trésorerie</div><div class="kpi-val <?php echo ($solde['solde']??0)>=0?'teal':'red';?>">$<?php echo number_format(abs($solde['solde']??0),0);?></div><div class="kpi-sub"><?php echo ($solde['solde']??0)>=0?'Excédent':'Déficit';?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-arrow-down"></i></div><div class="kpi-lbl">Total Recettes</div><div class="kpi-val green">$<?php echo number_format($solde['total_recettes']??0,0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-arrow-up"></i></div><div class="kpi-lbl">Total Dépenses</div><div class="kpi-val red">$<?php echo number_format($solde['total_depenses']??0,0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(29,78,216,.12);color:var(--gov-l)"><i class="fas fa-list"></i></div><div class="kpi-lbl">Opérations</div><div class="kpi-val blue"><?php echo $total;?></div></div>
</div>
<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Libellé, référence…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="type_f" class="form-select" style="width:130px" onchange="this.form.submit()"><option value="">Tous types</option><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fType):?><a href="sec_tresorerie.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="Gov.openModal('newOpModal')" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Nouvelle Opération</button>
</div>
<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Référence</th><th>Type</th><th>Libellé</th><th>Bénéficiaire</th><th>Montant</th><th>Date</th><th>Statut</th></tr></thead>
<tbody>
<?php if(empty($ops)):?><tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-vault" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucune opération</td></tr>
<?php else: foreach($ops as $op):$isRec=in_array($op['type'],['recette','dotation']);?>
<tr>
  <td><span class="mono" style="font-size:.73rem;color:var(--gov-l)"><?php echo $op['reference'];?></span></td>
  <td><span style="font-size:.78rem;font-weight:600;color:<?php echo $typeColor[$op['type']]??'var(--txt)';?>"><?php echo $typeCfg[$op['type']]??'—';?></span></td>
  <td style="max-width:200px"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem"><?php echo htmlspecialchars($op['libelle']);?></div><?php if($op['categorie']):?><div style="font-size:.65rem;color:var(--txt-m)"><?php echo htmlspecialchars($op['categorie']);?></div><?php endif;?></td>
  <td style="font-size:.79rem"><?php echo htmlspecialchars($op['beneficiaire']??'—');?></td>
  <td class="mono" style="font-size:.85rem;font-weight:700;color:<?php echo $isRec?'#4ade80':'#f87171';?>"><?php echo $isRec?'+':'-';?>$<?php echo number_format($op['montant'],0);?></td>
  <td class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo $op['date_operation']?date('d/m/Y',strtotime($op['date_operation'])):'—';?></td>
  <td><span class="badge <?php echo $op['statut']==='confirme'?'bg-green':($op['statut']==='previsionnel'?'bg-yellow':'bg-red');?>" style="font-size:.6rem"><?php echo ucfirst($op['statut']);?></span></td>
</tr>
<?php endforeach; endif;?>
</tbody></table></div>
<?php if($totalPages>1):?><div class="pag"><?php if($page>1):?><a href="?page=<?php echo $page-1;?>">← Préc.</a><?php endif;?><span class="pag-cur"><?php echo $page;?>/<?php echo $totalPages;?></span><?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>">Suiv. →</a><?php endif;?></div><?php endif;?>

<div id="newOpModal" class="modal-ov hidden"><div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-vault"></i> Nouvelle Opération de Trésorerie</h2><button class="modal-cl" onclick="Gov.closeModal('newOpModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_operation">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-select" required><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Statut</label><select name="statut" class="form-select"><option value="confirme">Confirmé</option><option value="previsionnel">Prévisionnel</option></select></div>
    <div class="form-group form-full"><label class="form-label">Libellé *</label><input type="text" name="libelle" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Catégorie</label><input type="text" name="categorie" class="form-input" placeholder="Impôts, subventions…"></div>
    <div class="form-group"><label class="form-label">Montant ($) *</label><input type="number" name="montant" class="form-input" min="0" step="0.01" required></div>
    <div class="form-group"><label class="form-label">Date opération</label><input type="date" name="date_operation" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group"><label class="form-label">Date valeur</label><input type="date" name="date_valeur" class="form-input"></div>
    <div class="form-group"><label class="form-label">Bénéficiaire / Payeur</label><input type="text" name="beneficiaire" class="form-input"></div>
    <div class="form-group"><label class="form-label">Faction</label><input type="text" name="beneficiaire_faction" class="form-input"></div>
    <div class="form-group"><label class="form-label">Compte source</label><input type="text" name="compte_source" class="form-input" placeholder="Trésor Public…"></div>
    <div class="form-group"><label class="form-label">Compte destination</label><input type="text" name="compte_dest" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Justification</label><textarea name="justification" class="form-textarea" style="min-height:70px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newOpModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>