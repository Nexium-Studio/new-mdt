<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Registre des Entreprises";
$__secLabel = "Secrétariat aux Entreprises"; $__secColor = '#0891b2'; $__secIcon = 'fas fa-building';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_registre_entreprises` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `siret` varchar(60) UNIQUE,
  `nom` varchar(200) NOT NULL,
  `faction_key` varchar(80),
  `secteur` enum('hotellerie','automobile','commerce','services','transport','restauration','media','immobilier','securite','finance','autre') DEFAULT 'autre',
  `forme_juridique` enum('SARL','SAS','SA','EI','EURL','SNC','autre') DEFAULT 'SAS',
  `adresse` varchar(250),
  `dirigeant` varchar(150),
  `dirigeant_faction` varchar(80),
  `capital_social` decimal(14,2) DEFAULT 0,
  `nb_employes` int DEFAULT 0,
  `statut` enum('active','suspendue','en_liquidation','radiee','en_procedure') DEFAULT 'active',
  `date_creation` date,
  `date_radiation` date,
  `observations` text,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP, `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_entreprise_historique` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `entreprise_id` int NOT NULL,
  `type_action` enum('creation','modification','suspension','reactivation','radiation','audit','autre') DEFAULT 'autre',
  `description` text,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (entreprise_id) REFERENCES gouv_registre_entreprises(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_entreprise') {
        try {
            $year=date('Y');
            $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_registre_entreprises WHERE YEAR(created_at)=$year")->fetchColumn();
            $siret=sprintf("LS%d%06d",$year,$seq);
            $db->prepare("INSERT INTO gouv_registre_entreprises (siret,nom,faction_key,secteur,forme_juridique,adresse,dirigeant,dirigeant_faction,capital_social,nb_employes,date_creation,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $siret, trim($_POST['nom']), trim($_POST['faction_key']??''),
                   $_POST['secteur'], $_POST['forme_juridique'],
                   trim($_POST['adresse']??''), trim($_POST['dirigeant']??''),
                   trim($_POST['dirigeant_faction']??''),
                   (float)($_POST['capital_social']??0),
                   (int)($_POST['nb_employes']??0),
                   $_POST['date_creation']??date('Y-m-d'),
                   $_SESSION['user_id']
               ]);
            $entId=$db->lastInsertId();
            $db->prepare("INSERT INTO gouv_entreprise_historique (entreprise_id,type_action,description,created_by) VALUES (?,?,?,?)")
               ->execute([$entId,'creation',"Immatriculation — SIRET: $siret",$_SESSION['user_id']]);
            $msg="Entreprise immatriculée — SIRET: $siret"; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }

    if ($action === 'update_statut_ent') {
        $id=(int)$_POST['ent_id'];
        try {
            $old=$db->query("SELECT statut FROM gouv_registre_entreprises WHERE id=$id")->fetchColumn();
            $new=$_POST['statut']; $obs=trim($_POST['observations']??'');
            $db->prepare("UPDATE gouv_registre_entreprises SET statut=?,observations=?,nb_employes=?,capital_social=? WHERE id=?")
               ->execute([$new,$obs,(int)$_POST['nb_employes'],(float)$_POST['capital_social'],$id]);
            if($old!==$new) {
                $typeMap=['active'=>'reactivation','suspendue'=>'suspension','radiee'=>'radiation','en_liquidation'=>'suspension'];
                $db->prepare("INSERT INTO gouv_entreprise_historique (entreprise_id,type_action,description,created_by) VALUES (?,?,?,?)")
                   ->execute([$id,$typeMap[$new]??'modification',"Statut : $old → $new. ".($obs?"Obs: ".mb_substr($obs,0,100):''),$_SESSION['user_id']]);
            }
            $msg='Entreprise mise à jour.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }

    if ($msg) { header('Location: sec_entreprises.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'ok'; }

// ── DATA ─────────────────────────────────────────────────────────────────
$search=$_GET['q']??''; $fStat=$_GET['stat']??''; $fSec=$_GET['sec']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(e.nom LIKE ? OR e.siret LIKE ? OR e.dirigeant LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;$params[]=$s;}
if($fStat){$where[]="e.statut=?";$params[]=$fStat;}
if($fSec){$where[]="e.secteur=?";$params[]=$fSec;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM gouv_registre_entreprises e $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$entreprises=[];
try{$s=$db->prepare("SELECT e.*,cf.ca_brut,cf.impot_du FROM gouv_registre_entreprises e LEFT JOIN company_finances cf ON cf.faction=e.faction_key $wStr ORDER BY e.nom ASC LIMIT $perPage OFFSET $offset");$s->execute($params);$entreprises=$s->fetchAll();}catch(Exception $e){
    try{$s=$db->prepare("SELECT * FROM gouv_registre_entreprises e $wStr ORDER BY e.nom ASC LIMIT $perPage OFFSET $offset");$s->execute($params);$entreprises=$s->fetchAll();}catch(Exception $e2){}
}

$statsE=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_registre_entreprises GROUP BY statut")->fetchAll() as $r)$statsE[$r['statut']]=$r['n'];}catch(Exception $e){}
$totalEmployes=0;try{$totalEmployes=(int)$db->query("SELECT SUM(nb_employes) FROM gouv_registre_entreprises WHERE statut='active'")->fetchColumn();}catch(Exception $e){}
$totalCapital=0;try{$totalCapital=(float)$db->query("SELECT SUM(capital_social) FROM gouv_registre_entreprises WHERE statut='active'")->fetchColumn();}catch(Exception $e){}

// Vue détail
$viewEnt=null; $viewId=(int)($_GET['id']??0);
if($viewId){try{$viewEnt=$db->query("SELECT * FROM gouv_registre_entreprises WHERE id=$viewId")->fetch();}catch(Exception $e){}}
$viewHisto=[]; if($viewEnt){try{$viewHisto=$db->query("SELECT * FROM gouv_entreprise_historique WHERE entreprise_id=$viewId ORDER BY created_at DESC LIMIT 15")->fetchAll();}catch(Exception $e){}}
$viewFinances=null; if($viewEnt&&$viewEnt['faction_key']){try{$viewFinances=$db->query("SELECT * FROM company_finances WHERE faction=".$db->quote($viewEnt['faction_key']))->fetch();}catch(Exception $e){}}

$statCfg=['active'=>['bg-green','Active'],'suspendue'=>['bg-yellow','Suspendue'],'en_liquidation'=>['bg-red','En liquidation'],'radiee'=>['bg-gray','Radiée'],'en_procedure'=>['bg-red','Procédure légale']];
$secteurCfg=['hotellerie'=>'Hôtellerie / Bar','automobile'=>'Automobile','commerce'=>'Commerce','services'=>'Services','transport'=>'Transport','restauration'=>'Restauration','media'=>'Médias','immobilier'=>'Immobilier','securite'=>'Sécurité','finance'=>'Finance','autre'=>'Autre'];
$fjCfg=['SARL'=>'SARL','SAS'=>'SAS','SA'=>'SA','EI'=>'EI','EURL'=>'EURL','SNC'=>'SNC','autre'=>'Autre'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<?php if($viewEnt): // ── DÉTAIL ─────────────────────────────────────────?>
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem">
  <a href="sec_entreprises.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
  <h2 style="font-size:1.1rem;font-weight:700"><?php echo htmlspecialchars($viewEnt['nom']);?></h2>
  <?php [$vb,$vl]=$statCfg[$viewEnt['statut']]??['bg-gray','—'];?><span class="badge <?php echo $vb;?>"><?php echo $vl;?></span>
  <span class="mono" style="font-size:.78rem;color:var(--gov-l)"><?php echo $viewEnt['siret'];?></span>
</div>

<div class="grid2">
<div>
  <div class="card">
    <div class="card-title"><i class="fas fa-building"></i> Fiche Entreprise</div>
    <?php foreach([['SIRET',$viewEnt['siret']],['Forme juridique',$fjCfg[$viewEnt['forme_juridique']]??'—'],['Secteur',$secteurCfg[$viewEnt['secteur']]??'—'],['Dirigeant',$viewEnt['dirigeant']??'—'],['Faction',$viewEnt['faction_key']??'—'],['Adresse',$viewEnt['adresse']??'—'],['Capital social','$'.number_format($viewEnt['capital_social'],0)],['Employés',number_format($viewEnt['nb_employes']).' pers.'],['Date création',$viewEnt['date_creation']?date('d/m/Y',strtotime($viewEnt['date_creation'])):'—']] as [$l,$v]):?>
    <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.82rem">
      <span style="color:var(--txt-m)"><?php echo $l;?></span><span style="font-weight:500"><?php echo htmlspecialchars((string)$v);?></span>
    </div>
    <?php endforeach;?>
    <?php if($viewEnt['observations']):?><div style="margin-top:.75rem;padding:.65rem;background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.15);border-radius:7px;font-size:.8rem;color:var(--txt-s)"><?php echo nl2br(htmlspecialchars($viewEnt['observations']));?></div><?php endif;?>
  </div>

  <?php if($viewFinances):?>
  <div class="card">
    <div class="card-title"><i class="fas fa-chart-bar"></i> Données Financières</div>
    <div class="kpi-row" style="grid-template-columns:repeat(2,1fr)">
      <div class="kpi"><div class="kpi-lbl">CA Brut</div><div class="kpi-val blue" style="font-size:1.2rem">$<?php echo number_format($viewFinances['ca_brut'],0);?></div></div>
      <div class="kpi"><div class="kpi-lbl">Impôts Dus</div><div class="kpi-val gold" style="font-size:1.2rem">$<?php echo number_format($viewFinances['impot_du'],0);?></div></div>
      <div class="kpi"><div class="kpi-lbl">Bénéfice Net</div><div class="kpi-val green" style="font-size:1.2rem">$<?php echo number_format($viewFinances['benefice_net'],0);?></div></div>
      <div class="kpi"><div class="kpi-lbl">Trésorerie</div><div class="kpi-val" style="font-size:1.2rem">$<?php echo number_format($viewFinances['tresorerie'],0);?></div></div>
    </div>
  </div>
  <?php endif;?>

  <div class="card">
    <div class="card-title"><i class="fas fa-pen"></i> Mettre à Jour</div>
    <form method="POST">
      <input type="hidden" name="action" value="update_statut_ent">
      <input type="hidden" name="ent_id" value="<?php echo $viewEnt['id'];?>">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Statut</label>
          <select name="statut" class="form-select"><?php foreach($statCfg as $sk=>[$sb,$sl]):?><option value="<?php echo $sk;?>" <?php echo $viewEnt['statut']===$sk?'selected':'';?>><?php echo $sl;?></option><?php endforeach;?></select>
        </div>
        <div class="form-group"><label class="form-label">Nb Employés</label><input type="number" name="nb_employes" class="form-input" min="0" value="<?php echo $viewEnt['nb_employes'];?>"></div>
        <div class="form-group"><label class="form-label">Capital Social ($)</label><input type="number" name="capital_social" class="form-input" min="0" step="0.01" value="<?php echo $viewEnt['capital_social'];?>"></div>
        <div class="form-group form-full"><label class="form-label">Observations</label><textarea name="observations" class="form-textarea"><?php echo htmlspecialchars($viewEnt['observations']??'');?></textarea></div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.65rem"><i class="fas fa-save"></i> Enregistrer</button>
    </form>
  </div>
</div>

<div>
  <div class="card">
    <div class="card-title"><i class="fas fa-history"></i> Historique (<?php echo count($viewHisto);?>)</div>
    <?php if(empty($viewHisto)):?><div style="text-align:center;padding:2rem;color:var(--txt-m);font-size:.82rem">Aucun historique</div>
    <?php else: foreach($viewHisto as $h):
      $hColor=['creation'=>'#4ade80','modification'=>'var(--gov-l)','suspension'=>'#fcd34d','reactivation'=>'#4ade80','radiation'=>'#f87171','audit'=>'#c4b5fd'][$h['type_action']]??'var(--txt-s)';
    ?>
    <div style="display:flex;gap:.65rem;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.04)">
      <div style="width:7px;height:7px;border-radius:50%;background:<?php echo $hColor;?>;margin-top:.35rem;flex-shrink:0"></div>
      <div style="flex:1">
        <div style="font-size:.75rem;font-weight:600;color:<?php echo $hColor;?>"><?php echo ucfirst(str_replace('_',' ',$h['type_action']));?></div>
        <div style="font-size:.79rem;color:var(--txt-s)"><?php echo htmlspecialchars($h['description']??'—');?></div>
        <div class="mono" style="font-size:.62rem;color:var(--txt-m);margin-top:.12rem"><?php echo date('d/m/Y H:i',strtotime($h['created_at']));?></div>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>
</div>

<?php else: // ── LISTE ──────────────────────────────────────────────────?>

<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(8,145,178,.12);color:#67e8f9"><i class="fas fa-building"></i></div><div class="kpi-lbl">Entreprises Actives</div><div class="kpi-val cyan"><?php echo $statsE['active']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-pause"></i></div><div class="kpi-lbl">Suspendues</div><div class="kpi-val gold"><?php echo $statsE['suspendue']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-users"></i></div><div class="kpi-lbl">Emplois Déclarés</div><div class="kpi-val green"><?php echo number_format($totalEmployes);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(29,78,216,.12);color:var(--gov-l)"><i class="fas fa-coins"></i></div><div class="kpi-lbl">Capital Total</div><div class="kpi-val blue">$<?php echo number_format($totalCapital,0);?></div></div>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Nom, SIRET, dirigeant…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="sec" class="form-select" style="width:150px" onchange="this.form.submit()">
      <option value="">Tous secteurs</option>
      <?php foreach($secteurCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fSec===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?>
    </select>
    <select name="stat" class="form-select" style="width:140px" onchange="this.form.submit()">
      <option value="">Tous statuts</option>
      <?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fStat||$fSec):?><a href="sec_entreprises.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="Gov.openModal('newEntModal')" class="btn btn-cyan btn-sm"><i class="fas fa-plus"></i> Immatriculer</button>
</div>

<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>SIRET</th><th>Entreprise</th><th>Forme</th><th>Secteur</th><th>Dirigeant</th><th>Employés</th><th>Capital</th><th>Statut</th><th></th></tr></thead>
<tbody>
<?php if(empty($entreprises)):?>
<tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-building" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucune entreprise</td></tr>
<?php else: foreach($entreprises as $ent):[$eb,$el]=$statCfg[$ent['statut']]??['bg-gray','—'];?>
<tr>
  <td><span class="mono" style="font-size:.73rem;color:var(--gov-l)"><?php echo $ent['siret'];?></span></td>
  <td>
    <div style="font-weight:600;font-size:.84rem"><?php echo htmlspecialchars($ent['nom']);?></div>
    <?php if($ent['faction_key']):?><div style="font-size:.68rem;color:var(--txt-m)"><?php echo htmlspecialchars($ent['faction_key']);?></div><?php endif;?>
  </td>
  <td><span class="badge bg-blue" style="font-size:.58rem"><?php echo $fjCfg[$ent['forme_juridique']]??'—';?></span></td>
  <td style="font-size:.79rem;color:var(--txt-s)"><?php echo $secteurCfg[$ent['secteur']]??'—';?></td>
  <td style="font-size:.8rem"><?php echo htmlspecialchars($ent['dirigeant']??'—');?></td>
  <td class="mono" style="font-size:.78rem"><?php echo number_format($ent['nb_employes']);?></td>
  <td class="mono" style="font-size:.78rem">$<?php echo number_format($ent['capital_social'],0);?></td>
  <td><span class="badge <?php echo $eb;?>"><?php echo $el;?></span></td>
  <td><a href="?id=<?php echo $ent['id'];?>" class="btn btn-ghost btn-xs"><i class="fas fa-eye"></i></a></td>
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

<!-- MODAL IMMATRICULER -->
<div id="newEntModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-building-circle-check"></i> Immatriculer une Entreprise</h2><button class="modal-cl" onclick="Gov.closeModal('newEntModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_entreprise">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Nom de l'entreprise *</label><input type="text" name="nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Forme juridique *</label>
      <select name="forme_juridique" class="form-select" required><?php foreach($fjCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Secteur *</label>
      <select name="secteur" class="form-select" required><?php foreach($secteurCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Faction (clé)</label><input type="text" name="faction_key" class="form-input" placeholder="BENNYS, BAHAMAS…" style="text-transform:uppercase"></div>
    <div class="form-group"><label class="form-label">Dirigeant *</label><input type="text" name="dirigeant" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Faction du dirigeant</label><input type="text" name="dirigeant_faction" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Adresse du siège</label><input type="text" name="adresse" class="form-input"></div>
    <div class="form-group"><label class="form-label">Capital Social ($)</label><input type="number" name="capital_social" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group"><label class="form-label">Nombre d'employés</label><input type="number" name="nb_employes" class="form-input" min="0" value="0"></div>
    <div class="form-group"><label class="form-label">Date de création</label><input type="date" name="date_creation" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newEntModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Immatriculer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>