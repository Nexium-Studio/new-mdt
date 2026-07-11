<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Dossiers Judiciaires";
$__secLabel = "Secrétariat à la Justice & Service Public"; $__secColor = '#b45309'; $__secIcon = 'fas fa-balance-scale';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_dossiers_justice` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `reference` varchar(80) UNIQUE,
  `type` enum('civil','penal','commercial','administratif','constitutionnel','autre') DEFAULT 'civil',
  `categorie` enum('litige_contrat','litige_emploi','litige_propriete','dommages_interets','nullite_acte','recours_administratif','plainte_penale','autre') DEFAULT 'autre',
  `titre` varchar(250) NOT NULL,
  `description` text,
  `demandeur_nom` varchar(150),
  `demandeur_faction` varchar(80),
  `defendeur_nom` varchar(150),
  `defendeur_faction` varchar(80),
  `magistrat` varchar(150),
  `avocat_demandeur` varchar(150),
  `avocat_defendeur` varchar(150),
  `montant_litige` decimal(14,2) DEFAULT 0,
  `statut` enum('ouvert','instruction','audience_planifiee','jugement_rendu','appel','classe','archive') DEFAULT 'ouvert',
  `priorite` enum('faible','normale','haute','urgente') DEFAULT 'normale',
  `date_saisine` date,
  `date_audience` datetime,
  `decision` text,
  `montant_condamnation` decimal(14,2) DEFAULT 0,
  `observations` text,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP, `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_dossier_actes` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `dossier_id` int NOT NULL,
  `type_acte` enum('mise_en_demeure','citation','jugement','appel','ordonnance','rapport','correspondance','piece','autre') DEFAULT 'autre',
  `titre` varchar(200) NOT NULL,
  `contenu` text,
  `date_acte` date,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (dossier_id) REFERENCES gouv_dossiers_justice(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── POST ACTIONS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_dossier') {
        try {
            $year=date('Y'); $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_dossiers_justice WHERE YEAR(created_at)=$year")->fetchColumn();
            $typePrefix=strtoupper(substr($_POST['type'],0,3));
            $ref=sprintf("DOJ-%s-%d-%04d",$typePrefix,$year,$seq);
            $db->prepare("INSERT INTO gouv_dossiers_justice (reference,type,categorie,titre,description,demandeur_nom,demandeur_faction,defendeur_nom,defendeur_faction,magistrat,montant_litige,priorite,date_saisine,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$ref,$_POST['type'],$_POST['categorie'],trim($_POST['titre']),trim($_POST['description']??''),
                   trim($_POST['demandeur_nom']??''),trim($_POST['demandeur_faction']??''),
                   trim($_POST['defendeur_nom']??''),trim($_POST['defendeur_faction']??''),
                   trim($_POST['magistrat']??''),(float)($_POST['montant_litige']??0),
                   $_POST['priorite']??'normale',$_POST['date_saisine']?:date('Y-m-d'),$_SESSION['user_id']]);
            $msg="Dossier $ref créé."; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }

    if ($action === 'update_dossier') {
        $id=(int)$_POST['dossier_id'];
        try {
            $db->prepare("UPDATE gouv_dossiers_justice SET statut=?,date_audience=?,decision=?,montant_condamnation=?,magistrat=?,avocat_demandeur=?,avocat_defendeur=?,observations=? WHERE id=?")
               ->execute([$_POST['statut'],$_POST['date_audience']?:null,trim($_POST['decision']??''),
                   (float)($_POST['montant_condamnation']??0),trim($_POST['magistrat']??''),
                   trim($_POST['avocat_demandeur']??''),trim($_POST['avocat_defendeur']??''),
                   trim($_POST['observations']??''),$id]);
            $msg='Dossier mis à jour.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }

    if ($action === 'add_acte') {
        try {
            $db->prepare("INSERT INTO gouv_dossier_actes (dossier_id,type_acte,titre,contenu,date_acte,created_by) VALUES (?,?,?,?,?,?)")
               ->execute([(int)$_POST['dossier_id'],$_POST['type_acte'],trim($_POST['titre']),trim($_POST['contenu']??''),$_POST['date_acte']?:date('Y-m-d'),$_SESSION['user_id']]);
            $msg='Acte ajouté.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }

    if ($msg) { header('Location: sec_justice.php?msg='.urlencode($msg).'&type='.$msgType.(isset($_GET['id'])?'&id='.(int)$_GET['id']:'')); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'ok'; }

// DATA
$fStat=$_GET['stat']??''; $fType=$_GET['type_f']??''; $search=$_GET['q']??''; $fPrio=$_GET['prio']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(d.titre LIKE ? OR d.reference LIKE ? OR d.demandeur_nom LIKE ? OR d.defendeur_nom LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;$params[]=$s;$params[]=$s;}
if($fStat){$where[]="d.statut=?";$params[]=$fStat;}
if($fType){$where[]="d.type=?";$params[]=$fType;}
if($fPrio){$where[]="d.priorite=?";$params[]=$fPrio;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM gouv_dossiers_justice d $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$dossiers=[];try{$s=$db->prepare("SELECT * FROM gouv_dossiers_justice d $wStr ORDER BY FIELD(d.priorite,'urgente','haute','normale','faible'),d.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$dossiers=$s->fetchAll();}catch(Exception $e){}
$statsD=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_dossiers_justice GROUP BY statut")->fetchAll() as $r)$statsD[$r['statut']]=$r['n'];}catch(Exception $e){}
$totalMontant=0;try{$totalMontant=(float)$db->query("SELECT SUM(montant_condamnation) FROM gouv_dossiers_justice WHERE statut='jugement_rendu'")->fetchColumn();}catch(Exception $e){}

// Vue détail
$viewD=null; $viewId=(int)($_GET['id']??0);
if($viewId){try{$viewD=$db->query("SELECT * FROM gouv_dossiers_justice WHERE id=$viewId")->fetch();}catch(Exception $e){}}
$viewActes=[]; if($viewD){try{$viewActes=$db->query("SELECT * FROM gouv_dossier_actes WHERE dossier_id=$viewId ORDER BY date_acte DESC,created_at DESC")->fetchAll();}catch(Exception $e){}}

$statCfg=['ouvert'=>['bg-blue','Ouvert'],'instruction'=>['bg-yellow','Instruction'],'audience_planifiee'=>['bg-yellow','Audience planifiée'],'jugement_rendu'=>['bg-green','Jugement rendu'],'appel'=>['bg-red','Appel'],'classe'=>['bg-gray','Classé'],'archive'=>['bg-gray','Archivé']];
$prioCfg=['faible'=>['bg-gray','Faible'],'normale'=>['bg-blue','Normale'],'haute'=>['bg-yellow','Haute'],'urgente'=>['bg-red','Urgente']];
$typeCfg=['civil'=>'Civil','penal'=>'Pénal','commercial'=>'Commercial','administratif'=>'Administratif','constitutionnel'=>'Constitutionnel','autre'=>'Autre'];
$catCfg=['litige_contrat'=>'Litige contrat','litige_emploi'=>'Litige emploi','litige_propriete'=>'Litige propriété','dommages_interets'=>'Dommages-intérêts','nullite_acte'=>'Nullité d\'acte','recours_administratif'=>'Recours admin.','plainte_penale'=>'Plainte pénale','autre'=>'Autre'];
$acteCfg=['mise_en_demeure'=>'Mise en demeure','citation'=>'Citation','jugement'=>'Jugement','appel'=>'Appel','ordonnance'=>'Ordonnance','rapport'=>'Rapport','correspondance'=>'Correspondance','piece'=>'Pièce','autre'=>'Autre'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<?php if($viewD): // ── VUE DÉTAIL ──────────────────────────────────────────?>
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap">
  <a href="sec_justice.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
  <span class="mono" style="font-size:.85rem;color:var(--gov-l)"><?php echo $viewD['reference'];?></span>
  <?php [$sb,$sl]=$statCfg[$viewD['statut']]??['bg-gray','—'];?><span class="badge <?php echo $sb;?>"><?php echo $sl;?></span>
  <?php [$pb,$pl]=$prioCfg[$viewD['priorite']]??['bg-gray','—'];?><span class="badge <?php echo $pb;?>"><?php echo $pl;?></span>
</div>

<div class="grid2">
<div>
  <div class="card">
    <div class="card-title"><i class="fas fa-gavel" style="color:var(--jus,#b45309)"></i> <?php echo htmlspecialchars($viewD['titre']);?></div>
    <div style="display:flex;flex-direction:column;gap:.45rem;font-size:.82rem;margin-bottom:.85rem">
      <?php foreach([['Type',$typeCfg[$viewD['type']]??'—'],['Catégorie',$catCfg[$viewD['categorie']]??'—'],['Demandeur',$viewD['demandeur_nom']??'—'],['Faction dem.',$viewD['demandeur_faction']??'—'],['Défendeur',$viewD['defendeur_nom']??'—'],['Faction def.',$viewD['defendeur_faction']??'—'],['Magistrat',$viewD['magistrat']??'—'],['Avocat dem.',$viewD['avocat_demandeur']??'—'],['Avocat def.',$viewD['avocat_defendeur']??'—'],['Montant litige','$'.number_format($viewD['montant_litige'],0)],['Condamnation','$'.number_format($viewD['montant_condamnation'],0)],['Saisine',$viewD['date_saisine']?date('d/m/Y',strtotime($viewD['date_saisine'])):'—'],['Audience',$viewD['date_audience']?date('d/m/Y H:i',strtotime($viewD['date_audience'])):'—']] as [$l,$v]):?>
      <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid rgba(255,255,255,.04)">
        <span style="color:var(--txt-m)"><?php echo $l;?></span><span style="font-weight:500;text-align:right;max-width:55%"><?php echo htmlspecialchars((string)$v);?></span>
      </div>
      <?php endforeach;?>
    </div>
    <?php if($viewD['description']):?><div style="padding:.7rem;background:var(--bg-ov);border-radius:8px;font-size:.8rem;color:var(--txt-s);line-height:1.6"><?php echo nl2br(htmlspecialchars($viewD['description']));?></div><?php endif;?>
    <?php if($viewD['decision']):?><div style="margin-top:.65rem;padding:.7rem;background:rgba(34,197,94,.05);border:1px solid rgba(34,197,94,.15);border-radius:8px"><strong style="color:#4ade80;font-size:.68rem">Décision :</strong><div style="font-size:.8rem;color:var(--txt-s);margin-top:.25rem;line-height:1.6"><?php echo nl2br(htmlspecialchars($viewD['decision']));?></div></div><?php endif;?>
    <?php if($viewD['observations']):?><div style="margin-top:.65rem;padding:.7rem;background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.15);border-radius:8px"><strong style="color:#fcd34d;font-size:.68rem">Obs. :</strong> <span style="font-size:.8rem;color:var(--txt-s)"><?php echo nl2br(htmlspecialchars($viewD['observations']));?></span></div><?php endif;?>
  </div>

  <div class="card">
    <div class="card-title"><i class="fas fa-pen"></i> Mettre à Jour</div>
    <form method="POST">
      <input type="hidden" name="action" value="update_dossier">
      <input type="hidden" name="dossier_id" value="<?php echo $viewD['id'];?>">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Statut</label>
          <select name="statut" class="form-select"><?php foreach($statCfg as $sk=>[$sb,$sl]):?><option value="<?php echo $sk;?>" <?php echo $viewD['statut']===$sk?'selected':'';?>><?php echo $sl;?></option><?php endforeach;?></select>
        </div>
        <div class="form-group"><label class="form-label">Date d'audience</label><input type="datetime-local" name="date_audience" class="form-input" value="<?php echo $viewD['date_audience']?date('Y-m-d\TH:i',strtotime($viewD['date_audience'])):'';?>"></div>
        <div class="form-group"><label class="form-label">Magistrat</label><input type="text" name="magistrat" class="form-input" value="<?php echo htmlspecialchars($viewD['magistrat']??'');?>"></div>
        <div class="form-group"><label class="form-label">Montant condamnation ($)</label><input type="number" name="montant_condamnation" class="form-input" min="0" step="0.01" value="<?php echo $viewD['montant_condamnation'];?>"></div>
        <div class="form-group"><label class="form-label">Avocat demandeur</label><input type="text" name="avocat_demandeur" class="form-input" value="<?php echo htmlspecialchars($viewD['avocat_demandeur']??'');?>"></div>
        <div class="form-group"><label class="form-label">Avocat défendeur</label><input type="text" name="avocat_defendeur" class="form-input" value="<?php echo htmlspecialchars($viewD['avocat_defendeur']??'');?>"></div>
        <div class="form-group form-full"><label class="form-label">Décision / Jugement</label><textarea name="decision" class="form-textarea" style="min-height:90px"><?php echo htmlspecialchars($viewD['decision']??'');?></textarea></div>
        <div class="form-group form-full"><label class="form-label">Observations</label><textarea name="observations" class="form-textarea"><?php echo htmlspecialchars($viewD['observations']??'');?></textarea></div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.65rem"><i class="fas fa-save"></i> Enregistrer</button>
    </form>
  </div>
</div>

<div>
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem">
      <div class="card-title" style="margin:0"><i class="fas fa-file-lines"></i> Actes & Pièces (<?php echo count($viewActes);?>)</div>
      <button class="btn btn-warning btn-sm" onclick="Gov.openModal('addActeModal')"><i class="fas fa-plus"></i> Ajouter</button>
    </div>
    <?php if(empty($viewActes)):?><div style="text-align:center;padding:2rem;color:var(--txt-m);font-size:.82rem">Aucun acte</div>
    <?php else: foreach($viewActes as $acte):?>
    <div style="padding:.65rem .75rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;margin-bottom:.45rem">
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.22rem">
        <span class="badge bg-amber" style="font-size:.58rem"><?php echo $acteCfg[$acte['type_acte']]??$acte['type_acte'];?></span>
        <span style="font-weight:600;font-size:.83rem"><?php echo htmlspecialchars($acte['titre']);?></span>
        <span class="mono" style="font-size:.62rem;color:var(--txt-m);margin-left:auto"><?php echo $acte['date_acte']?date('d/m/Y',strtotime($acte['date_acte'])):'—';?></span>
      </div>
      <?php if($acte['contenu']):?><div style="font-size:.77rem;color:var(--txt-s);line-height:1.5"><?php echo nl2br(htmlspecialchars(mb_substr($acte['contenu'],0,200)));?></div><?php endif;?>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>
</div>

<!-- Modal ajouter acte -->
<div id="addActeModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-file-plus"></i> Ajouter un Acte</h2><button class="modal-cl" onclick="Gov.closeModal('addActeModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_acte"><input type="hidden" name="dossier_id" value="<?php echo $viewId;?>">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type d'acte *</label>
      <select name="type_acte" class="form-select"><?php foreach($acteCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Date de l'acte</label><input type="date" name="date_acte" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required></div>
    <div class="form-group form-full"><label class="form-label">Contenu</label><textarea name="contenu" class="form-textarea" style="min-height:120px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('addActeModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Ajouter</button></div>
</form></div></div>

<?php else: // ── LISTE ─────────────────────────────────────────────────────?>

<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(180,83,9,.12);color:#fde68a"><i class="fas fa-gavel"></i></div><div class="kpi-lbl">Total Dossiers</div><div class="kpi-val amber"><?php echo array_sum($statsD);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(29,78,216,.12);color:var(--gov-l)"><i class="fas fa-folder-open"></i></div><div class="kpi-lbl">Ouverts / Instruction</div><div class="kpi-val blue"><?php echo ($statsD['ouvert']??0)+($statsD['instruction']??0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-bolt"></i></div><div class="kpi-lbl">Urgents</div><div class="kpi-val red"><?php try{echo (int)$db->query("SELECT COUNT(*) FROM gouv_dossiers_justice WHERE priorite='urgente' AND statut NOT IN ('classe','archive')")->fetchColumn();}catch(Exception $e){echo 0;}?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-coins"></i></div><div class="kpi-lbl">Condamnations Totales</div><div class="kpi-val green">$<?php echo number_format($totalMontant,0);?></div></div>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Titre, référence, parties…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="type_f" class="form-select" style="width:135px" onchange="this.form.submit()"><option value="">Tous types</option><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select>
    <select name="stat" class="form-select" style="width:145px" onchange="this.form.submit()"><option value="">Tous statuts</option><?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
    <select name="prio" class="form-select" style="width:120px" onchange="this.form.submit()"><option value="">Priorité</option><?php foreach($prioCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $fPrio===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fStat||$fType||$fPrio):?><a href="sec_justice.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="Gov.openModal('newDossierModal')" class="btn btn-warning btn-sm"><i class="fas fa-plus"></i> Nouveau Dossier</button>
</div>

<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Référence</th><th>Titre</th><th>Type</th><th>Demandeur</th><th>Défendeur</th><th>Montant litige</th><th>Priorité</th><th>Statut</th><th></th></tr></thead>
<tbody>
<?php if(empty($dossiers)):?><tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-gavel" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun dossier</td></tr>
<?php else: foreach($dossiers as $d):[$sb,$sl]=$statCfg[$d['statut']]??['bg-gray','—'];[$pb,$pl]=$prioCfg[$d['priorite']]??['bg-gray','—'];?>
<tr>
  <td><span class="mono" style="font-size:.72rem;color:var(--gov-l)"><?php echo $d['reference'];?></span></td>
  <td style="max-width:200px"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;font-size:.83rem"><?php echo htmlspecialchars($d['titre']);?></div></td>
  <td><span class="badge bg-amber" style="font-size:.58rem"><?php echo $typeCfg[$d['type']]??'—';?></span></td>
  <td style="font-size:.79rem"><?php echo htmlspecialchars($d['demandeur_nom']??'—');?></td>
  <td style="font-size:.79rem"><?php echo htmlspecialchars($d['defendeur_nom']??'—');?></td>
  <td class="mono" style="font-size:.78rem">$<?php echo number_format($d['montant_litige'],0);?></td>
  <td><span class="badge <?php echo $pb;?>"><?php echo $pl;?></span></td>
  <td><span class="badge <?php echo $sb;?>"><?php echo $sl;?></span></td>
  <td><a href="?id=<?php echo $d['id'];?>" class="btn btn-ghost btn-xs"><i class="fas fa-eye"></i></a></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>
<?php if($totalPages>1):?><div class="pag">
  <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>">← Préc.</a><?php endif;?>
  <span class="pag-cur">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
  <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>">Suiv. →</a><?php endif;?>
</div><?php endif;?>
<?php endif;?>

<!-- MODAL CRÉER DOSSIER -->
<div id="newDossierModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-folder-plus"></i> Nouveau Dossier Judiciaire</h2><button class="modal-cl" onclick="Gov.closeModal('newDossierModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_dossier">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Titre du dossier *</label><input type="text" name="titre" class="form-input" required placeholder="Litige entre X et Y au sujet de…"></div>
    <div class="form-group"><label class="form-label">Type *</label>
      <select name="type" class="form-select" required><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Catégorie</label>
      <select name="categorie" class="form-select"><?php foreach($catCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Priorité</label>
      <select name="priorite" class="form-select"><?php foreach($prioCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $k==='normale'?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Date de saisine</label><input type="date" name="date_saisine" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group"><label class="form-label">Demandeur (nom) *</label><input type="text" name="demandeur_nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Faction demandeur</label><input type="text" name="demandeur_faction" class="form-input"></div>
    <div class="form-group"><label class="form-label">Défendeur (nom)</label><input type="text" name="defendeur_nom" class="form-input"></div>
    <div class="form-group"><label class="form-label">Faction défendeur</label><input type="text" name="defendeur_faction" class="form-input"></div>
    <div class="form-group"><label class="form-label">Montant du litige ($)</label><input type="number" name="montant_litige" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group"><label class="form-label">Magistrat assigné</label><input type="text" name="magistrat" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" style="min-height:90px" placeholder="Exposé des faits…"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newDossierModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>