<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Agenda Public";
$__secLabel = "Secrétariat Événementiel"; $__secColor = '#7c3aed'; $__secIcon = 'fas fa-calendar-star';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_agenda` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `evenement_id` int DEFAULT NULL,
  `titre` varchar(200) NOT NULL,
  `description_publique` text,
  `lieu` varchar(200),
  `date_debut` datetime,
  `date_fin` datetime,
  `type` enum('concert','tournoi','marche','ceremonie','conference','sportif','culturel','annonce','autre') DEFAULT 'autre',
  `icone` varchar(80) DEFAULT 'fas fa-calendar',
  `couleur` varchar(20) DEFAULT '#1d4ed8',
  `public` tinyint DEFAULT 1,
  `gratuit` tinyint DEFAULT 1,
  `prix_entree` decimal(8,2) DEFAULT 0,
  `organisateur` varchar(150),
  `contact` varchar(200),
  `created_by` int,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='add_agenda') {
        try {
            $db->prepare("INSERT INTO gouv_agenda (evenement_id,titre,description_publique,lieu,date_debut,date_fin,type,icone,couleur,public,gratuit,prix_entree,organisateur,contact,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   !empty($_POST['evenement_id'])?(int)$_POST['evenement_id']:null,
                   trim($_POST['titre']), trim($_POST['description_publique']??''),
                   trim($_POST['lieu']??''), $_POST['date_debut']?:null, $_POST['date_fin']?:null,
                   $_POST['type']??'autre', $_POST['icone']??'fas fa-calendar',
                   $_POST['couleur']??'#1d4ed8',
                   (int)($_POST['public']??1), (int)($_POST['gratuit']??1),
                   (float)($_POST['prix_entree']??0),
                   trim($_POST['organisateur']??''), trim($_POST['contact']??''),
                   $_SESSION['user_id']
               ]);
            $msg='Entrée agenda créée.'; $msgType='ok';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='err';}
    }
    if($action==='delete_agenda') {
        $db->prepare("DELETE FROM gouv_agenda WHERE id=?")->execute([(int)$_POST['agenda_id']]);
        $msg='Entrée supprimée.'; $msgType='ok';
    }
    if($msg){header('Location: sec_agenda.php?msg='.urlencode($msg).'&type='.$msgType);exit;}
}
if(isset($_GET['msg'])){$msg=htmlspecialchars($_GET['msg']);$msgType=$_GET['type']??'ok';}

// Mois courant + suivant
$mois=$_GET['mois']??date('Y-m');
try{[$year,$month]=explode('-',$mois);}catch(Exception $e){$year=date('Y');$month=date('m');}
$dateStart=sprintf('%04d-%02d-01',$year,$month);
$dateEnd=date('Y-m-t',strtotime($dateStart));

$agenda=[];
try{$agenda=$db->query("SELECT * FROM gouv_agenda WHERE date_debut BETWEEN '$dateStart 00:00:00' AND '$dateEnd 23:59:59' ORDER BY date_debut ASC")->fetchAll();}catch(Exception $e){}
$futurs=[];
try{$futurs=$db->query("SELECT * FROM gouv_agenda WHERE date_debut > '$dateEnd 23:59:59' ORDER BY date_debut ASC LIMIT 5")->fetchAll();}catch(Exception $e){}
$events=[];try{$events=$db->query("SELECT id,nom FROM gouv_evenements WHERE statut='approuve' ORDER BY date_debut DESC LIMIT 50")->fetchAll();}catch(Exception $e){}

$iconesDispos=['fas fa-calendar'=>'Calendrier','fas fa-music'=>'Concert','fas fa-trophy'=>'Tournoi','fas fa-store'=>'Marché','fas fa-flag'=>'Cérémonie','fas fa-microphone'=>'Conférence','fas fa-futbol'=>'Sportif','fas fa-palette'=>'Culturel','fas fa-bullhorn'=>'Annonce'];
$typeCfg=['concert'=>'Concert','tournoi'=>'Tournoi','marche'=>'Marché','ceremonie'=>'Cérémonie','conference'=>'Conférence','sportif'=>'Sportif','culturel'=>'Culturel','annonce'=>'Annonce','autre'=>'Autre'];

// Mois prev/next
$prevMois=date('Y-m',strtotime($dateStart.' -1 month'));
$nextMois=date('Y-m',strtotime($dateStart.' +1 month'));
$moisLabel=strftime('%B %Y',strtotime($dateStart));

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
  <div style="display:flex;align-items:center;gap:.75rem">
    <a href="?mois=<?php echo $prevMois;?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-left"></i></a>
    <h2 style="font-size:1.1rem;font-weight:700;text-transform:capitalize"><?php echo $moisLabel;?></h2>
    <a href="?mois=<?php echo $nextMois;?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-right"></i></a>
    <a href="?mois=<?php echo date('Y-m');?>" class="btn btn-ghost btn-sm">Aujourd'hui</a>
  </div>
  <button onclick="Gov.openModal('newAgendaModal')" class="btn btn-purple btn-sm"><i class="fas fa-plus"></i> Ajouter au Calendrier</button>
</div>

<div class="grid2">
<div>
  <!-- Événements du mois -->
  <div class="card">
    <div class="card-title" style="color:#c4b5fd"><i class="fas fa-calendar-days" style="color:#7c3aed"></i> <?php echo ucfirst($moisLabel);?> (<?php echo count($agenda);?> événement<?php echo count($agenda)!==1?'s':'';?>)</div>
    <?php if(empty($agenda)):?>
    <div style="text-align:center;padding:2.5rem;color:var(--txt-m);font-size:.83rem">Aucun événement ce mois-ci</div>
    <?php else: foreach($agenda as $ev):?>
    <div style="display:flex;gap:.85rem;padding:.85rem;background:var(--bg-ov);border:1px solid var(--bdr);border-left:3px solid <?php echo $ev['couleur'];?>;border-radius:9px;margin-bottom:.55rem">
      <div style="width:44px;height:44px;border-radius:8px;background:<?php echo $ev['couleur'];?>1a;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0">
        <span class="mono" style="font-size:.75rem;font-weight:700;color:<?php echo $ev['couleur'];?>"><?php echo date('d',strtotime($ev['date_debut']));?></span>
        <span class="mono" style="font-size:.55rem;color:var(--txt-m)"><?php echo strtoupper(date('M',strtotime($ev['date_debut'])));?></span>
      </div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-bottom:.2rem">
          <i class="<?php echo $ev['icone'];?>" style="color:<?php echo $ev['couleur'];?>;font-size:.75rem"></i>
          <span style="font-weight:600;font-size:.85rem"><?php echo htmlspecialchars($ev['titre']);?></span>
          <?php if(!$ev['gratuit']):?><span class="badge bg-gold" style="font-size:.58rem">$<?php echo number_format($ev['prix_entree'],0);?></span><?php endif;?>
          <?php if(!$ev['public']):?><span class="badge bg-gray" style="font-size:.58rem">Privé</span><?php endif;?>
        </div>
        <?php if($ev['lieu']):?><div style="font-size:.73rem;color:var(--txt-s)"><i class="fas fa-location-dot" style="margin-right:.25rem;opacity:.6"></i><?php echo htmlspecialchars($ev['lieu']);?></div><?php endif;?>
        <?php if($ev['date_fin']&&date('H:i',strtotime($ev['date_debut'])) !== '00:00'):?>
        <div class="mono" style="font-size:.68rem;color:var(--txt-m);margin-top:.15rem"><?php echo date('H:i',strtotime($ev['date_debut']));?> → <?php echo date('H:i',strtotime($ev['date_fin']));?></div>
        <?php endif;?>
        <?php if($ev['description_publique']):?><div style="font-size:.74rem;color:var(--txt-s);margin-top:.25rem;line-height:1.5"><?php echo htmlspecialchars(mb_substr($ev['description_publique'],0,100));?></div><?php endif;?>
      </div>
      <form method="POST" style="flex-shrink:0">
        <input type="hidden" name="action" value="delete_agenda">
        <input type="hidden" name="agenda_id" value="<?php echo $ev['id'];?>">
        <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('Supprimer ?')"><i class="fas fa-trash"></i></button>
      </form>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>

<div>
  <!-- Événements à venir -->
  <?php if(!empty($futurs)):?>
  <div class="card" style="margin-bottom:1.35rem">
    <div class="card-title"><i class="fas fa-forward"></i> Prochains Événements</div>
    <?php foreach($futurs as $ev):?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .15rem;border-bottom:1px solid rgba(255,255,255,.04)">
      <div style="width:36px;height:36px;border-radius:7px;background:rgba(124,58,237,.12);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0">
        <span class="mono" style="font-size:.68rem;font-weight:700;color:#c4b5fd"><?php echo date('d',strtotime($ev['date_debut']));?></span>
        <span class="mono" style="font-size:.52rem;color:var(--txt-m)"><?php echo strtoupper(date('M',strtotime($ev['date_debut'])));?></span>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:.83rem;font-weight:600"><?php echo htmlspecialchars($ev['titre']);?></div>
        <div style="font-size:.7rem;color:var(--txt-m)"><?php echo htmlspecialchars($ev['lieu']??'—');?></div>
      </div>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>

  <!-- Légende types -->
  <div class="card">
    <div class="card-title"><i class="fas fa-tags"></i> Légende</div>
    <div style="display:flex;flex-direction:column;gap:.4rem">
      <?php foreach($typeCfg as $k=>$v):?>
      <div style="display:flex;align-items:center;gap:.5rem;font-size:.8rem">
        <span class="badge bg-purple" style="font-size:.58rem"><?php echo $v;?></span>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</div>
</div>

<!-- MODAL AJOUTER AU CALENDRIER -->
<div id="newAgendaModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-calendar-plus"></i> Ajouter au Calendrier Public</h2><button class="modal-cl" onclick="Gov.closeModal('newAgendaModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_agenda">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Type</label>
      <select name="type" class="form-select"><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Événement lié</label>
      <select name="evenement_id" class="form-select"><option value="">— Aucun —</option><?php foreach($events as $ev):?><option value="<?php echo $ev['id'];?>"><?php echo htmlspecialchars($ev['nom']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Début *</label><input type="datetime-local" name="date_debut" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Fin</label><input type="datetime-local" name="date_fin" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input"></div>
    <div class="form-group"><label class="form-label">Couleur</label><input type="color" name="couleur" class="form-input" value="#7c3aed" style="height:2.4rem;padding:.2rem"></div>
    <div class="form-group"><label class="form-label">Icône (FA class)</label>
      <select name="icone" class="form-select"><?php foreach($iconesDispos as $ic=>$il):?><option value="<?php echo $ic;?>"><?php echo $il;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label"><input type="checkbox" name="gratuit" value="1" checked style="margin-right:.4rem"> Événement gratuit</label></div>
    <div class="form-group"><label class="form-label">Prix d'entrée ($)</label><input type="number" name="prix_entree" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group"><label class="form-label">Organisateur</label><input type="text" name="organisateur" class="form-input"></div>
    <div class="form-group"><label class="form-label">Contact</label><input type="text" name="contact" class="form-input" placeholder="Email, Discord…"></div>
    <div class="form-group form-full"><label class="form-label">Description publique</label><textarea name="description_publique" class="form-textarea" style="min-height:80px"></textarea></div>
    <div class="form-group"><label class="form-label"><input type="checkbox" name="public" value="1" checked style="margin-right:.4rem"> Visible publiquement</label></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newAgendaModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Publier</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>