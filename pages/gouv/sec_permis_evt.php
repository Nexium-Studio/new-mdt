<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Permis & Autorisations Événementiels";
$__secLabel = "Secrétariat Événementiel"; $__secColor = '#7c3aed'; $__secIcon = 'fas fa-calendar-star';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_permis_evt` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `evenement_id` int DEFAULT NULL,
  `type_permis` enum('occupation_espace','vente_alcool','feu_artifice','fermeture_route','amplification_sonore','securite_privee','multi') DEFAULT 'occupation_espace',
  `numero` varchar(80) UNIQUE,
  `demandeur` varchar(150) NOT NULL,
  `demandeur_faction` varchar(80),
  `lieu` varchar(200),
  `date_evt` date,
  `date_debut` date,
  `date_fin` date,
  `motif` text,
  `conditions` text,
  `statut` enum('en_attente','accorde','refuse','expire','suspendu') DEFAULT 'en_attente',
  `valideur` varchar(150),
  `frais` decimal(10,2) DEFAULT 0,
  `frais_payes` tinyint DEFAULT 0,
  `notes` text,
  `created_by` int,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_permis') {
        try {
            $year=date('Y');
            $seq=(int)$db->query("SELECT COUNT(*)+1 FROM gouv_permis_evt WHERE YEAR(created_at)=$year")->fetchColumn();
            $num=sprintf("PERM-%d-%04d",$year,$seq);
            $db->prepare("INSERT INTO gouv_permis_evt (evenement_id,type_permis,numero,demandeur,demandeur_faction,lieu,date_evt,date_debut,date_fin,motif,frais,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   !empty($_POST['evenement_id'])?(int)$_POST['evenement_id']:null,
                   $_POST['type_permis'], $num,
                   trim($_POST['demandeur']), trim($_POST['demandeur_faction']??''),
                   trim($_POST['lieu']??''), $_POST['date_evt']?:null,
                   $_POST['date_debut']?:null, $_POST['date_fin']?:null,
                   trim($_POST['motif']??''), (float)($_POST['frais']??0),
                   $_SESSION['user_id']
               ]);
            $msg="Permis $num créé."; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }
    if ($action === 'update_permis') {
        $id=(int)$_POST['permis_id'];
        try {
            $db->prepare("UPDATE gouv_permis_evt SET statut=?,conditions=?,notes=?,valideur=?,frais_payes=? WHERE id=?")
               ->execute([$_POST['statut'],trim($_POST['conditions']??''),trim($_POST['notes']??''),trim($_POST['valideur']??''),(int)($_POST['frais_payes']??0),$id]);
            $msg='Permis mis à jour.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }
    if ($msg) { header('Location: sec_permis_evt.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'ok'; }

$fStat=$_GET['stat']??''; $search=$_GET['q']??'';
$where=['1=1'];$params=[];
if($search){$where[]="(p.numero LIKE ? OR p.demandeur LIKE ? OR p.lieu LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;$params[]=$s;}
if($fStat){$where[]="p.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$permis=[];try{$s=$db->prepare("SELECT p.*,e.nom as evt_nom FROM gouv_permis_evt p LEFT JOIN gouv_evenements e ON p.evenement_id=e.id $wStr ORDER BY p.created_at DESC");$s->execute($params);$permis=$s->fetchAll();}catch(Exception $e){}
$statsP=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_permis_evt GROUP BY statut")->fetchAll() as $r)$statsP[$r['statut']]=$r['n'];}catch(Exception $e){}
$events=[];try{$events=$db->query("SELECT id,nom FROM gouv_evenements WHERE statut='approuve' ORDER BY date_debut DESC LIMIT 50")->fetchAll();}catch(Exception $e){}

$statCfg=['en_attente'=>['bg-yellow','En attente'],'accorde'=>['bg-green','Accordé'],'refuse'=>['bg-red','Refusé'],'expire'=>['bg-gray','Expiré'],'suspendu'=>['bg-red','Suspendu']];
$typeCfg=['occupation_espace'=>'Occupation espace','vente_alcool'=>'Vente alcool','feu_artifice'=>'Feu d\'artifice','fermeture_route'=>'Fermeture route','amplification_sonore'=>'Amplification sonore','securite_privee'=>'Sécurité privée','multi'=>'Multi-permis'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(124,58,237,.12);color:#c4b5fd"><i class="fas fa-ticket"></i></div><div class="kpi-lbl">Permis Total</div><div class="kpi-val purple"><?php echo array_sum($statsP);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-clock"></i></div><div class="kpi-lbl">En Attente</div><div class="kpi-val gold"><?php echo $statsP['en_attente']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-check"></i></div><div class="kpi-lbl">Accordés</div><div class="kpi-val green"><?php echo $statsP['accorde']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-ban"></i></div><div class="kpi-lbl">Refusés</div><div class="kpi-val red"><?php echo $statsP['refuse']??0;?></div></div>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="N°, demandeur, lieu…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="stat" class="form-select" style="width:140px" onchange="this.form.submit()">
      <option value="">Tous statuts</option>
      <?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fStat):?><a href="sec_permis_evt.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="Gov.openModal('newPermisModal')" class="btn btn-purple btn-sm"><i class="fas fa-plus"></i> Nouveau Permis</button>
</div>

<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>N° Permis</th><th>Type</th><th>Demandeur</th><th>Lieu</th><th>Date événement</th><th>Frais</th><th>Payé</th><th>Statut</th><th>Actions</th></tr></thead>
<tbody>
<?php if(empty($permis)):?>
<tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-ticket" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun permis</td></tr>
<?php else: foreach($permis as $p):[$sb,$sl]=$statCfg[$p['statut']]??['bg-gray','—'];$expired=$p['date_fin']&&strtotime($p['date_fin'])<strtotime('today')&&$p['statut']==='accorde';?>
<tr <?php echo $expired?'style="opacity:.6"':'';?>>
  <td><span class="mono" style="font-size:.78rem;color:var(--gov-l)"><?php echo htmlspecialchars($p['numero']);?></span></td>
  <td style="font-size:.8rem"><?php echo $typeCfg[$p['type_permis']]??$p['type_permis'];?></td>
  <td>
    <div style="font-weight:600;font-size:.83rem"><?php echo htmlspecialchars($p['demandeur']);?></div>
    <?php if($p['demandeur_faction']):?><div style="font-size:.7rem;color:var(--txt-m)"><?php echo htmlspecialchars($p['demandeur_faction']);?></div><?php endif;?>
  </td>
  <td style="font-size:.8rem;color:var(--txt-s)"><?php echo htmlspecialchars($p['lieu']??'—');?></td>
  <td class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo $p['date_evt']?date('d/m/Y',strtotime($p['date_evt'])):'—';?></td>
  <td class="mono" style="font-size:.8rem">$<?php echo number_format($p['frais'],0);?></td>
  <td><?php echo $p['frais_payes']?'<span class="badge bg-green">Oui</span>':'<span class="badge bg-yellow">Non</span>';?></td>
  <td><span class="badge <?php echo $sb;?>"><?php echo $sl;?><?php echo $expired?' (Expiré)':'';?></span></td>
  <td>
    <button class="btn btn-ghost btn-xs" onclick='openPermisEdit(<?php echo json_encode($p,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE);?>)' title="Modifier"><i class="fas fa-pen"></i></button>
  </td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>

<!-- MODAL NOUVEAU PERMIS -->
<div id="newPermisModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-ticket"></i> Nouveau Permis</h2><button class="modal-cl" onclick="Gov.closeModal('newPermisModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_permis">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type de permis *</label>
      <select name="type_permis" class="form-select" required><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Événement lié</label>
      <select name="evenement_id" class="form-select"><option value="">— Aucun —</option><?php foreach($events as $ev):?><option value="<?php echo $ev['id'];?>"><?php echo htmlspecialchars($ev['nom']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Demandeur *</label><input type="text" name="demandeur" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Faction / Organisation</label><input type="text" name="demandeur_faction" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Lieu concerné</label><input type="text" name="lieu" class="form-input" placeholder="Adresse ou zone géographique…"></div>
    <div class="form-group"><label class="form-label">Date de l'événement</label><input type="date" name="date_evt" class="form-input"></div>
    <div class="form-group"><label class="form-label">Début validité</label><input type="date" name="date_debut" class="form-input"></div>
    <div class="form-group"><label class="form-label">Fin validité</label><input type="date" name="date_fin" class="form-input"></div>
    <div class="form-group"><label class="form-label">Frais de dossier ($)</label><input type="number" name="frais" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group form-full"><label class="form-label">Motif de la demande</label><textarea name="motif" class="form-textarea" style="min-height:75px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newPermisModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>

<!-- MODAL EDIT PERMIS -->
<div id="editPermisModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Modifier le Permis</h2><button class="modal-cl" onclick="Gov.closeModal('editPermisModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update_permis"><input type="hidden" name="permis_id" id="ep-id">
  <div id="ep-num" style="font-family:'IBM Plex Mono',monospace;font-size:.82rem;color:var(--gov-l);margin-bottom:.85rem"></div>
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Statut</label>
      <select name="statut" id="ep-stat" class="form-select">
        <?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Validé par</label><input type="text" name="valideur" id="ep-val" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Conditions</label><textarea name="conditions" id="ep-cond" class="form-textarea" style="min-height:70px"></textarea></div>
    <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes" id="ep-notes" class="form-textarea" style="min-height:65px"></textarea></div>
    <div class="form-group"><label class="form-label" style="display:flex;align-items:center;gap:.5rem"><input type="checkbox" name="frais_payes" id="ep-paid" value="1"> Frais réglés</label></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('editPermisModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<script>
function openPermisEdit(p) {
  document.getElementById('ep-id').value=p.id;
  document.getElementById('ep-num').textContent=p.numero;
  document.getElementById('ep-stat').value=p.statut;
  document.getElementById('ep-val').value=p.valideur||'';
  document.getElementById('ep-cond').value=p.conditions||'';
  document.getElementById('ep-notes').value=p.notes||'';
  document.getElementById('ep-paid').checked=p.frais_payes=='1';
  Gov.openModal('editPermisModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>