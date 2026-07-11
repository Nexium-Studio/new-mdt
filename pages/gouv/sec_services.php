<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Services Publics"; $__secLabel = "Justice & Service Public"; $__secColor = '#b45309'; $__secIcon = 'fas fa-city';
$db = Database::getInstance()->getConnection(); $msg=''; $msgType='';

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_services_publics` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `nom` varchar(200) NOT NULL, `code` varchar(40) UNIQUE,
  `categorie` enum('infrastructure','education','sante','securite','culture','transport','social','autre') DEFAULT 'autre',
  `responsable` varchar(150), `description` text,
  `budget_annuel` decimal(14,2) DEFAULT 0, `budget_depense` decimal(14,2) DEFAULT 0,
  `statut` enum('actif','en_travaux','suspendu','ferme') DEFAULT 'actif',
  `note_qualite` tinyint DEFAULT 5,
  `nb_usagers_mois` int DEFAULT 0,
  `observations` text,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP, `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_services_demandes` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `service_id` int NOT NULL,
  `citoyen_nom` varchar(150), `citoyen_faction` varchar(80),
  `type_demande` enum('renseignement','prestation','plainte','amelioration') DEFAULT 'renseignement',
  `objet` varchar(250) NOT NULL, `details` text,
  `statut` enum('en_attente','en_cours','traitee','refusee') DEFAULT 'en_attente',
  `reponse` text,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (service_id) REFERENCES gouv_services_publics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='create_service') {
        try {
            $code=strtoupper(preg_replace('/[^A-Z0-9]/', '', substr($_POST['nom'], 0, 4)).rand(100,999));
            $db->prepare("INSERT INTO gouv_services_publics (nom,code,categorie,responsable,description,budget_annuel,created_by) VALUES (?,?,?,?,?,?,?)")
               ->execute([trim($_POST['nom']),$code,$_POST['categorie'],trim($_POST['responsable']??''),trim($_POST['description']??''),(float)($_POST['budget_annuel']??0),$_SESSION['user_id']]);
            $msg='Service créé.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }
    if($action==='update_service') {
        $id=(int)$_POST['service_id'];
        $db->prepare("UPDATE gouv_services_publics SET statut=?,note_qualite=?,budget_depense=?,nb_usagers_mois=?,observations=? WHERE id=?")
           ->execute([$_POST['statut'],(int)$_POST['note_qualite'],(float)$_POST['budget_depense'],(int)$_POST['nb_usagers_mois'],trim($_POST['observations']??''),$id]);
        $msg='Service mis à jour.'; $msgType='ok';
    }
    if($action==='create_demande') {
        $db->prepare("INSERT INTO gouv_services_demandes (service_id,citoyen_nom,citoyen_faction,type_demande,objet,details,created_by) VALUES (?,?,?,?,?,?,?)")
           ->execute([(int)$_POST['service_id'],trim($_POST['citoyen_nom']),trim($_POST['citoyen_faction']??''),$_POST['type_demande'],trim($_POST['objet']),trim($_POST['details']??''),$_SESSION['user_id']]);
        $msg='Demande enregistrée.'; $msgType='ok';
    }
    if($action==='reply_demande') {
        $db->prepare("UPDATE gouv_services_demandes SET statut=?,reponse=? WHERE id=?")
           ->execute([$_POST['statut'],trim($_POST['reponse']??''),(int)$_POST['demande_id']]);
        $msg='Demande mise à jour.'; $msgType='ok';
    }
    if($msg){header('Location: sec_services.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}

$services=[];try{$services=$db->query("SELECT * FROM gouv_services_publics ORDER BY categorie,nom")->fetchAll();}catch(Exception $e){}
$demandesRecentes=[];try{$demandesRecentes=$db->query("SELECT d.*,s.nom as service_nom FROM gouv_services_demandes d JOIN gouv_services_publics s ON d.service_id=s.id WHERE d.statut='en_attente' ORDER BY d.created_at DESC LIMIT 10")->fetchAll();}catch(Exception $e){}
$statsS=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_services_publics GROUP BY statut")->fetchAll() as $r)$statsS[$r['statut']]=$r['n'];}catch(Exception $e){}
$budgetTotal=0;try{$budgetTotal=(float)$db->query("SELECT SUM(budget_annuel) FROM gouv_services_publics WHERE statut='actif'")->fetchColumn();}catch(Exception $e){}

$catCfg=['infrastructure'=>'Infrastructure','education'=>'Éducation','sante'=>'Santé','securite'=>'Sécurité','culture'=>'Culture','transport'=>'Transport','social'=>'Social','autre'=>'Autre'];
$statCfg=['actif'=>['bg-green','Actif'],'en_travaux'=>['bg-yellow','En travaux'],'suspendu'=>['bg-gray','Suspendu'],'ferme'=>['bg-red','Fermé']];
$demandeStatCfg=['en_attente'=>['bg-yellow','En attente'],'en_cours'=>['bg-blue','En cours'],'traitee'=>['bg-green','Traitée'],'refusee'=>['bg-red','Refusée']];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>
<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-city"></i></div><div class="kpi-lbl">Services Actifs</div><div class="kpi-val green"><?php echo $statsS['actif']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-hard-hat"></i></div><div class="kpi-lbl">En Travaux</div><div class="kpi-val gold"><?php echo $statsS['en_travaux']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(29,78,216,.12);color:var(--gov-l)"><i class="fas fa-inbox"></i></div><div class="kpi-lbl">Demandes en Attente</div><div class="kpi-val blue"><?php echo count($demandesRecentes);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(180,83,9,.12);color:#fde68a"><i class="fas fa-coins"></i></div><div class="kpi-lbl">Budget Services</div><div class="kpi-val amber">$<?php echo number_format($budgetTotal,0);?></div></div>
</div>
<div class="grid2">
<div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem">
    <h3 style="font-size:.9rem;font-weight:700">Services Publics (<?php echo count($services);?>)</h3>
    <button onclick="Gov.openModal('newSrvModal')" class="btn btn-warning btn-sm"><i class="fas fa-plus"></i> Créer</button>
  </div>
  <?php foreach($services as $s):[$sb,$sl]=$statCfg[$s['statut']]??['bg-gray','—'];$pct=min(100,round(($s['budget_depense']/max(1,$s['budget_annuel']))*100));?>
  <div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:10px;padding:.85rem 1rem;margin-bottom:.55rem">
    <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.45rem">
      <span class="badge bg-amber" style="font-size:.58rem"><?php echo $catCfg[$s['categorie']]??'—';?></span>
      <span style="font-weight:600;font-size:.85rem;flex:1"><?php echo htmlspecialchars($s['nom']);?></span>
      <span class="badge <?php echo $sb;?>" style="font-size:.58rem"><?php echo $sl;?></span>
      <button class="btn btn-ghost btn-xs" onclick='openSrvEdit(<?php echo json_encode($s,JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
    </div>
    <?php if($s['responsable']):?><div style="font-size:.72rem;color:var(--txt-s);margin-bottom:.35rem"><i class="fas fa-user" style="margin-right:.3rem;opacity:.6"></i><?php echo htmlspecialchars($s['responsable']);?></div><?php endif;?>
    <div style="display:flex;gap:1rem;font-size:.73rem;color:var(--txt-m);margin-bottom:.4rem">
      <span>Budget: $<?php echo number_format($s['budget_annuel'],0);?></span>
      <span>Dépensé: $<?php echo number_format($s['budget_depense'],0);?></span>
      <span><?php echo $s['nb_usagers_mois'];?> usagers/mois</span>
      <span>Note: <?php echo $s['note_qualite'];?>/10</span>
    </div>
    <div class="progress-bar" style="height:4px"><div class="progress-fill" style="width:<?php echo $pct;?>%;background:<?php echo $pct>90?'#f87171':($pct>70?'#fcd34d':'#60a5fa');?>"></div></div>
  </div>
  <?php endforeach;?>
</div>
<div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem">
    <h3 style="font-size:.9rem;font-weight:700">Demandes en Attente</h3>
    <button onclick="Gov.openModal('newDemandeModal')" class="btn btn-ghost btn-sm"><i class="fas fa-plus"></i> Enregistrer</button>
  </div>
  <?php if(empty($demandesRecentes)):?><div class="card" style="text-align:center;padding:2.5rem;color:var(--txt-m)">Aucune demande en attente</div>
  <?php else: foreach($demandesRecentes as $d):?>
  <div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:10px;padding:.8rem 1rem;margin-bottom:.5rem">
    <div style="display:flex;align-items:flex-start;gap:.65rem;margin-bottom:.35rem">
      <span class="badge bg-yellow" style="font-size:.58rem"><?php echo str_replace('_',' ',$d['type_demande']);?></span>
      <span style="font-weight:600;font-size:.83rem;flex:1"><?php echo htmlspecialchars($d['objet']);?></span>
    </div>
    <div style="font-size:.73rem;color:var(--txt-s);margin-bottom:.3rem"><?php echo htmlspecialchars($d['citoyen_nom']);?> · <?php echo htmlspecialchars($d['service_nom']);?></div>
    <div class="mono" style="font-size:.62rem;color:var(--txt-m)"><?php echo date('d/m/Y H:i',strtotime($d['created_at']));?></div>
    <button class="btn btn-ghost btn-xs" style="margin-top:.35rem" onclick='openDemandeReply(<?php echo json_encode($d,JSON_HEX_APOS|JSON_HEX_QUOT);?>)'><i class="fas fa-reply"></i> Répondre</button>
  </div>
  <?php endforeach; endif;?>
</div>
</div>

<!-- Modals -->
<div id="newSrvModal" class="modal-ov hidden"><div class="modal"><div class="modal-hd"><h2><i class="fas fa-city"></i> Créer un Service</h2><button class="modal-cl" onclick="Gov.closeModal('newSrvModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_service">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Catégorie</label><select name="categorie" class="form-select"><?php foreach($catCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Responsable</label><input type="text" name="responsable" class="form-input"></div>
    <div class="form-group"><label class="form-label">Budget annuel ($)</label><input type="number" name="budget_annuel" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" style="min-height:70px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newSrvModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>

<div id="editSrvModal" class="modal-ov hidden"><div class="modal"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Modifier le Service</h2><button class="modal-cl" onclick="Gov.closeModal('editSrvModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update_service"><input type="hidden" name="service_id" id="es-id">
  <div id="es-nom" style="font-weight:700;font-size:.9rem;margin-bottom:.85rem"></div>
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Statut</label><select name="statut" id="es-stat" class="form-select"><?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Note qualité (/10)</label><input type="number" name="note_qualite" id="es-note" class="form-input" min="0" max="10"></div>
    <div class="form-group"><label class="form-label">Budget dépensé ($)</label><input type="number" name="budget_depense" id="es-dep" class="form-input" min="0" step="0.01"></div>
    <div class="form-group"><label class="form-label">Usagers / mois</label><input type="number" name="nb_usagers_mois" id="es-usa" class="form-input" min="0"></div>
    <div class="form-group form-full"><label class="form-label">Observations</label><textarea name="observations" id="es-obs" class="form-textarea" style="min-height:70px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('editSrvModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<div id="newDemandeModal" class="modal-ov hidden"><div class="modal"><div class="modal-hd"><h2><i class="fas fa-inbox"></i> Enregistrer une Demande</h2><button class="modal-cl" onclick="Gov.closeModal('newDemandeModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_demande">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Service *</label><select name="service_id" class="form-select" required><option value="">—</option><?php foreach($services as $s):?><option value="<?php echo $s['id'];?>"><?php echo htmlspecialchars($s['nom']);?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Type</label><select name="type_demande" class="form-select"><option value="renseignement">Renseignement</option><option value="prestation">Prestation</option><option value="plainte">Plainte</option><option value="amelioration">Amélioration</option></select></div>
    <div class="form-group"><label class="form-label">Citoyen</label><input type="text" name="citoyen_nom" class="form-input"></div>
    <div class="form-group"><label class="form-label">Faction</label><input type="text" name="citoyen_faction" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Objet *</label><input type="text" name="objet" class="form-input" required></div>
    <div class="form-group form-full"><label class="form-label">Détails</label><textarea name="details" class="form-textarea" style="min-height:80px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newDemandeModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
</form></div></div>

<div id="replyDemandeModal" class="modal-ov hidden"><div class="modal"><div class="modal-hd"><h2><i class="fas fa-reply"></i> Traiter la Demande</h2><button class="modal-cl" onclick="Gov.closeModal('replyDemandeModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="reply_demande"><input type="hidden" name="demande_id" id="rd-id">
  <div id="rd-obj" style="font-weight:600;font-size:.85rem;margin-bottom:.85rem"></div>
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Statut</label><select name="statut" class="form-select"><option value="en_cours">En cours</option><option value="traitee">Traitée</option><option value="refusee">Refusée</option></select></div>
    <div class="form-group form-full"><label class="form-label">Réponse</label><textarea name="reponse" id="rd-rep" class="form-textarea" style="min-height:90px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('replyDemandeModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<script>
function openSrvEdit(s){document.getElementById('es-id').value=s.id;document.getElementById('es-nom').textContent=s.nom;document.getElementById('es-stat').value=s.statut;document.getElementById('es-note').value=s.note_qualite;document.getElementById('es-dep').value=s.budget_depense;document.getElementById('es-usa').value=s.nb_usagers_mois;document.getElementById('es-obs').value=s.observations||'';Gov.openModal('editSrvModal');}
function openDemandeReply(d){document.getElementById('rd-id').value=d.id;document.getElementById('rd-obj').textContent=d.objet;document.getElementById('rd-rep').value=d.reponse||'';Gov.openModal('replyDemandeModal');}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>