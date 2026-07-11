<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Gestion des Dossiers';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';
$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $year = date('Y');
            $seq  = (int)$db->prepare("SELECT COUNT(*)+1 FROM avocat_dossiers WHERE YEAR(created_at)=?")->execute([$year]) ? $db->query("SELECT COALESCE(MAX(id),0)+1 FROM avocat_dossiers")->fetchColumn() : 1;
            $num  = 'DOS-'.$year.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO avocat_dossiers (numero,client_id,avocat_id,titre,chef_accusation,statut,priorite,notes_juridiques,strategie,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$num, intval($_POST['client_id']), intval($_POST['avocat_id']??$uid), trim($_POST['titre']), trim($_POST['chef_accusation']), $_POST['statut']??'ouvert', $_POST['priorite']??'normale', trim($_POST['notes_juridiques']??''), trim($_POST['strategie']??''), $uid]);
            $msg="Dossier $num créé."; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    if ($action === 'update') {
        $db->prepare("UPDATE avocat_dossiers SET titre=?,chef_accusation=?,statut=?,priorite=?,notes_juridiques=?,strategie=?,avocat_id=?,updated_at=NOW() WHERE id=?")
           ->execute([trim($_POST['titre']),trim($_POST['chef_accusation']),trim($_POST['statut']),trim($_POST['priorite']),trim($_POST['notes_juridiques']??''),trim($_POST['strategie']??''),intval($_POST['avocat_id']??$uid),intval($_POST['dossier_id'])]);
        $msg='Dossier mis à jour.'; $msgType='ok';
    }

    if ($action === 'add_preuve') {
        $did = intval($_POST['dossier_id']);
        $stmt=$db->prepare("SELECT preuves FROM avocat_dossiers WHERE id=?"); $stmt->execute([$did]);
        $preuves = json_decode($stmt->fetchColumn()??'[]',true)?:[];
        $preuves[] = ['texte'=>trim($_POST['preuve']),'date'=>date('Y-m-d H:i'),'by'=>mdt_session_display_name()];
        $db->prepare("UPDATE avocat_dossiers SET preuves=? WHERE id=?")->execute([json_encode($preuves),$did]);
        $msg='Preuve ajoutée.'; $msgType='ok';
    }

    if ($action === 'close') {
        $db->prepare("UPDATE avocat_dossiers SET statut=?,closed_at=NOW() WHERE id=?")->execute([$_POST['verdict']??'ferme',intval($_POST['dossier_id'])]);
        $msg='Dossier clôturé.'; $msgType='ok';
    }
}

// Filtres
$filterStatut = $_GET['statut'] ?? 'all';
$filterMine   = isset($_GET['mine']) ? (int)$_GET['mine'] : 0;
$search = trim($_GET['q'] ?? '');

$q = "SELECT d.*, p.firstname, p.lastname, u.username AS avocat_name
      FROM avocat_dossiers d
      LEFT JOIN avocat_clients ac ON ac.id=d.client_id
      LEFT JOIN profiles p ON p.id=ac.profile_id
      LEFT JOIN users u ON u.id=d.avocat_id
      WHERE 1=1";
$params = [];
if ($filterStatut !== 'all') { $q .= " AND d.statut=?"; $params[]=$filterStatut; }
if ($filterMine)             { $q .= " AND d.avocat_id=?"; $params[]=$uid; }
if ($search) { $q .= " AND (d.titre LIKE ? OR d.numero LIKE ? OR p.lastname LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }
$q .= " ORDER BY FIELD(d.priorite,'critique','urgente','normale'), d.updated_at DESC";
$stmt=$db->prepare($q); $stmt->execute($params); $dossiers=$stmt->fetchAll();

// Vue détail
$viewDossier = null;
if (isset($_GET['id'])) {
    $stmt=$db->prepare("SELECT d.*,p.firstname,p.lastname,u.username AS avocat_name FROM avocat_dossiers d LEFT JOIN avocat_clients ac ON ac.id=d.client_id LEFT JOIN profiles p ON p.id=ac.profile_id LEFT JOIN users u ON u.id=d.avocat_id WHERE d.id=?");
    $stmt->execute([intval($_GET['id'])]); $viewDossier=$stmt->fetch();
}

// Clients pour le select
$allClients = $db->query("SELECT ac.id, p.firstname, p.lastname FROM avocat_clients ac JOIN profiles p ON p.id=ac.profile_id WHERE ac.statut='actif' ORDER BY p.lastname")->fetchAll();
// Avocats du cabinet + staff (peuvent être responsables d'un dossier)
$allAvocats = $db->query("
    SELECT id, username,
           COALESCE(faction_rank, role) AS faction_rank
    FROM users
    WHERE (faction='AVOCATS' OR role IN ('Administrateur','Développeur','Staff'))
      AND is_active=1
    ORDER BY faction='AVOCATS' DESC, username
")->fetchAll();

// Stats
$statsCounts = $db->query("SELECT statut, COUNT(*) as n FROM avocat_dossiers GROUP BY statut")->fetchAll(\PDO::FETCH_KEY_PAIR);

include __DIR__ . '/includes/header.php';
?>
<style>
.dos-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1.1rem;transition:all .2s;cursor:pointer;margin-bottom:.7rem}
.dos-card:hover{border-color:var(--bdr-h)}
.dos-card.priorite-critique{border-left:3px solid #f87171}
.dos-card.priorite-urgente{border-left:3px solid #fcd34d}
.dos-card.priorite-normale{border-left:3px solid var(--bdr)}
.preuve-item{background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;padding:.75rem;margin-bottom:.5rem}
</style>

<?php if($msg): ?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<?php if($viewDossier):
    $preuves = json_decode($viewDossier['preuves']??'[]',true)?:[];
    $processo = $db->prepare("SELECT * FROM avocat_proces WHERE dossier_id=? ORDER BY date_audience DESC");$processo->execute([$viewDossier['id']]);$processo=$processo->fetchAll();
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <a href="dossiers.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Dossiers</a>
    <div style="display:flex;gap:.5rem">
        <button onclick="Cabinet.openModal('editDossierModal')" class="btn btn-secondary btn-sm"><i class="fas fa-pen"></i> Modifier</button>
        <?php if(!in_array($viewDossier['statut'],['ferme','gagne','perdu'])):?>
        <button onclick="Cabinet.openModal('closeModal')" class="btn btn-danger btn-sm"><i class="fas fa-lock"></i> Clôturer</button>
        <?php endif;?>
    </div>
</div>

<div class="grid2">
  <div>
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
            <div>
                <div class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($viewDossier['numero']??'');?></div>
                <div style="font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;color:#f5edd6;margin-top:.2rem"><?php echo htmlspecialchars($viewDossier['titre']);?></div>
            </div>
            <?php $pCls=['critique'=>'bg-red','urgente'=>'bg-yellow','normale'=>'bg-gray'][$viewDossier['priorite']]??'bg-gray';?>
            <span class="badge <?php echo $pCls;?>"><?php echo $viewDossier['priorite'];?></span>
        </div>
        <?php foreach([['Client',$viewDossier['firstname'].' '.$viewDossier['lastname']],['Avocat',$viewDossier['avocat_name']??'—'],['Chef d\'accusation',$viewDossier['chef_accusation']??'—']] as [$l,$v]):?>
        <div style="display:flex;gap:.5rem;margin-bottom:.4rem;font-size:.82rem"><span style="color:var(--txt-m);min-width:120px;font-size:.75rem"><?php echo $l;?></span><span><?php echo htmlspecialchars($v??'—');?></span></div>
        <?php endforeach;?>
        <div style="margin-top:.75rem">
            <?php $stCls=['ouvert'=>'bg-blue','en_cours'=>'bg-yellow','tribunal'=>'bg-purple','ferme'=>'bg-gray','gagne'=>'bg-green','perdu'=>'bg-red'][$viewDossier['statut']]??'bg-gray';?>
            <span class="badge <?php echo $stCls;?>"><?php echo $viewDossier['statut'];?></span>
        </div>
    </div>
    <?php if($viewDossier['notes_juridiques']):?>
    <div class="card"><div class="card-title"><i class="fas fa-file-alt"></i> Notes juridiques</div>
        <div style="font-size:.845rem;line-height:1.7;color:var(--txt-s);white-space:pre-wrap"><?php echo htmlspecialchars($viewDossier['notes_juridiques']);?></div>
    </div><?php endif;?>
    <?php if($viewDossier['strategie']):?>
    <div class="card" style="border-color:rgba(217,119,6,.3)"><div class="card-title"><i class="fas fa-chess"></i> Stratégie de défense <span class="badge bg-gold" style="margin-left:.4rem;font-size:.6rem">CONFIDENTIEL</span></div>
        <div style="font-size:.845rem;line-height:1.7;color:var(--txt-s);white-space:pre-wrap"><?php echo htmlspecialchars($viewDossier['strategie']);?></div>
    </div><?php endif;?>
  </div>
  <div>
    <!-- Preuves -->
    <div class="card">
        <div class="card-title"><i class="fas fa-search"></i> Preuves (<?php echo count($preuves);?>)</div>
        <?php foreach($preuves as $pr):?>
        <div class="preuve-item">
            <div style="font-size:.82rem;line-height:1.5"><?php echo htmlspecialchars($pr['texte']??'');?></div>
            <div style="font-size:.68rem;color:var(--txt-m);margin-top:.35rem"><i class="fas fa-user"></i> <?php echo htmlspecialchars($pr['by']??'');?> · <?php echo $pr['date']??'';?></div>
        </div>
        <?php endforeach;?>
        <form method="POST" style="margin-top:.75rem">
            <input type="hidden" name="action" value="add_preuve">
            <input type="hidden" name="dossier_id" value="<?php echo $viewDossier['id'];?>">
            <textarea name="preuve" class="form-textarea" style="min-height:70px;margin-bottom:.5rem" placeholder="Décrire la preuve…" required></textarea>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> Ajouter preuve</button>
        </form>
    </div>
    <!-- Procès liés -->
    <div class="card">
        <div class="card-title"><i class="fas fa-gavel"></i> Procès liés (<?php echo count($processo);?>)</div>
        <?php foreach($processo as $pr):?>
        <div style="padding:.65rem .75rem;background:var(--bg-ov);border-radius:8px;margin-bottom:.4rem">
            <div style="font-weight:600;font-size:.82rem"><?php echo htmlspecialchars($pr['titre']);?></div>
            <div style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y H:i',strtotime($pr['date_audience']));?> · Juge: <?php echo htmlspecialchars($pr['juge']??'—');?></div>
        </div>
        <?php endforeach;if(empty($processo)):?><p style="color:var(--txt-m);font-size:.82rem;text-align:center;padding:.75rem">Aucun procès</p><?php endif;?>
        <a href="proces.php?dossier_id=<?php echo $viewDossier['id'];?>" class="btn btn-secondary btn-sm" style="margin-top:.5rem"><i class="fas fa-plus"></i> Planifier un procès</a>
    </div>
  </div>
</div>

<!-- MODAL MODIFIER -->
<div id="editDossierModal" class="modal-ov hidden">
    <div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Modifier le dossier</h2><button class="modal-cl" onclick="Cabinet.closeModal('editDossierModal')">×</button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update"><input type="hidden" name="dossier_id" value="<?php echo $viewDossier['id'];?>">
        <div class="form-grid">
            <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" value="<?php echo htmlspecialchars($viewDossier['titre']);?>" required></div>
            <div class="form-group form-full"><label class="form-label">Chef d'accusation</label><textarea name="chef_accusation" class="form-textarea"><?php echo htmlspecialchars($viewDossier['chef_accusation']??'');?></textarea></div>
            <div class="form-group"><label class="form-label">Statut</label><select name="statut" class="form-select">
                <?php foreach(['ouvert'=>'Ouvert','en_cours'=>'En cours','tribunal'=>'Tribunal','ferme'=>'Fermé','gagne'=>'Gagné','perdu'=>'Perdu'] as $k=>$v):?>
                <option value="<?php echo $k;?>" <?php echo $viewDossier['statut']===$k?'selected':'';?>><?php echo $v;?></option>
                <?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Priorité</label><select name="priorite" class="form-select">
                <?php foreach(['normale'=>'Normale','urgente'=>'Urgente','critique'=>'Critique'] as $k=>$v):?>
                <option value="<?php echo $k;?>" <?php echo $viewDossier['priorite']===$k?'selected':'';?>><?php echo $v;?></option>
                <?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Avocat responsable</label><select name="avocat_id" class="form-select">
                <?php foreach($allAvocats as $av):?><option value="<?php echo $av['id'];?>" <?php echo $viewDossier['avocat_id']==$av['id']?'selected':'';?>><?php echo htmlspecialchars($av['username'].' — '.$av['faction_rank']);?></option><?php endforeach;?>
            </select></div>
            <div class="form-group form-full"><label class="form-label">Notes juridiques</label><textarea name="notes_juridiques" class="form-textarea" style="min-height:100px"><?php echo htmlspecialchars($viewDossier['notes_juridiques']??'');?></textarea></div>
            <div class="form-group form-full"><label class="form-label">Stratégie de défense <span class="badge bg-gold" style="font-size:.6rem">CONFIDENTIEL</span></label><textarea name="strategie" class="form-textarea" style="min-height:100px"><?php echo htmlspecialchars($viewDossier['strategie']??'');?></textarea></div>
        </div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Cabinet.closeModal('editDossierModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
    </form></div>
</div>

<!-- MODAL CLÔTURER -->
<div id="closeModal" class="modal-ov hidden">
    <div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-lock"></i> Clôturer le dossier</h2><button class="modal-cl" onclick="Cabinet.closeModal('closeModal')">×</button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="close"><input type="hidden" name="dossier_id" value="<?php echo $viewDossier['id'];?>">
        <div class="form-group"><label class="form-label">Verdict / Issue *</label><select name="verdict" class="form-select">
            <option value="ferme">Fermé (sans verdict)</option>
            <option value="gagne">Gagné ✓</option>
            <option value="perdu">Perdu ✗</option>
        </select></div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Cabinet.closeModal('closeModal')">Annuler</button><button type="submit" class="btn btn-danger"><i class="fas fa-lock"></i> Clôturer</button></div>
    </form></div>
</div>

<?php else:?>
<!-- LISTE DOSSIERS -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <?php foreach(['ouvert'=>[($statsCounts['ouvert']??0),'Ouverts','bg-blue'],'en_cours'=>[($statsCounts['en_cours']??0),'En cours','bg-yellow'],'tribunal'=>[($statsCounts['tribunal']??0),'Tribunal','bg-purple'],'gagne'=>[($statsCounts['gagne']??0),'Gagnés','bg-green'],'perdu'=>[($statsCounts['perdu']??0),'Perdus','bg-red']] as $s=>[$n,$l,$c]):?>
        <div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:9px;padding:.5rem .9rem;text-align:center;cursor:pointer" onclick="location.href='?statut=<?php echo $s;?>'">
            <div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.07em"><?php echo $l;?></div>
            <div style="font-size:1.2rem;font-weight:700;color:#f5edd6"><?php echo $n;?></div>
        </div>
        <?php endforeach;?>
    </div>
    <?php if(!empty($allClients)):?>
    <button onclick="Cabinet.openModal('newDossierModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouveau dossier</button>
    <?php endif;?>
</div>

<form method="GET" style="display:flex;gap:.6rem;margin-bottom:1.25rem;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Titre, numéro, client…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
    <select name="statut" class="form-select" style="width:140px" onchange="this.form.submit()">
        <option value="all" <?php echo $filterStatut==='all'?'selected':'';?>>Tous statuts</option>
        <?php foreach(['ouvert'=>'Ouvert','en_cours'=>'En cours','tribunal'=>'Tribunal','ferme'=>'Fermé','gagne'=>'Gagné','perdu'=>'Perdu'] as $k=>$v):?>
        <option value="<?php echo $k;?>" <?php echo $filterStatut===$k?'selected':'';?>><?php echo $v;?></option>
        <?php endforeach;?>
    </select>
    <label style="display:flex;align-items:center;gap:.4rem;color:var(--txt-s);font-size:.82rem;cursor:pointer">
        <input type="checkbox" name="mine" value="1" <?php echo $filterMine?'checked':'';?> onchange="this.form.submit()" style="accent-color:var(--gold)"> Mes dossiers
    </label>
    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    <a href="dossiers.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
</form>

<?php if(empty($dossiers)):?>
<div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)">
    <i class="fas fa-folder-open" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun dossier
</div>
<?php else:?>
<?php foreach($dossiers as $d):
    $stCls=['ouvert'=>'bg-blue','en_cours'=>'bg-yellow','tribunal'=>'bg-purple','ferme'=>'bg-gray','gagne'=>'bg-green','perdu'=>'bg-red'][$d['statut']]??'bg-gray';
    $stLbl=['ouvert'=>'Ouvert','en_cours'=>'En cours','tribunal'=>'Tribunal','ferme'=>'Fermé','gagne'=>'Gagné','perdu'=>'Perdu'][$d['statut']]??$d['statut'];
    $prCls=['critique'=>'bg-red','urgente'=>'bg-yellow','normale'=>''][$d['priorite']]??'';
?>
<div class="dos-card priorite-<?php echo $d['priorite'];?>" onclick="location.href='dossiers.php?id=<?php echo $d['id'];?>'">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem">
        <div style="flex:1">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem">
                <span class="mono" style="font-size:.68rem;color:var(--txt-m)"><?php echo htmlspecialchars($d['numero']??'');?></span>
                <?php if($prCls):?><span class="badge <?php echo $prCls;?>" style="font-size:.6rem"><?php echo $d['priorite'];?></span><?php endif;?>
            </div>
            <div style="font-weight:700;font-size:.9rem;color:#f5edd6"><?php echo htmlspecialchars($d['titre']);?></div>
            <div style="font-size:.72rem;color:var(--txt-m);margin-top:.2rem">
                Client: <strong style="color:var(--txt-s)"><?php echo htmlspecialchars(($d['firstname']??'').' '.($d['lastname']??''));?></strong>
                · Avocat: <strong><?php echo htmlspecialchars($d['avocat_name']??'—');?></strong>
                · Màj: <?php echo date('d/m/y',strtotime($d['updated_at']));?>
            </div>
        </div>
        <span class="badge <?php echo $stCls;?>"><?php echo $stLbl;?></span>
    </div>
</div>
<?php endforeach;?>
<?php endif;?>

<!-- MODAL NOUVEAU DOSSIER -->
<?php if(!empty($allClients)):?>
<div id="newDossierModal" class="modal-ov hidden">
    <div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-folder-plus"></i> Nouveau dossier</h2><button class="modal-cl" onclick="Cabinet.closeModal('newDossierModal')">×</button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Client *</label><select name="client_id" class="form-select" required>
                <option value="">Sélectionner…</option>
                <?php foreach($allClients as $c):?><option value="<?php echo $c['id'];?>"><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></option><?php endforeach;?>
            </select></div>
            <div class="form-group"><label class="form-label">Avocat responsable</label><select name="avocat_id" class="form-select">
                <?php foreach($allAvocats as $av):?><option value="<?php echo $av['id'];?>" <?php echo $av['id']==$uid?'selected':'';?>><?php echo htmlspecialchars($av['username'].' — '.$av['faction_rank']);?></option><?php endforeach;?>
            </select></div>
            <div class="form-group form-full"><label class="form-label">Titre du dossier *</label><input type="text" name="titre" class="form-input" required placeholder="Ex: Défense — Accusation de vol aggravé"></div>
            <div class="form-group form-full"><label class="form-label">Chef d'accusation</label><textarea name="chef_accusation" class="form-textarea" placeholder="Infractions reprochées…"></textarea></div>
            <div class="form-group"><label class="form-label">Statut</label><select name="statut" class="form-select">
                <?php foreach(['ouvert'=>'Ouvert','en_cours'=>'En cours'] as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Priorité</label><select name="priorite" class="form-select">
                <?php foreach(['normale'=>'Normale','urgente'=>'Urgente','critique'=>'Critique'] as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
            <div class="form-group form-full"><label class="form-label">Notes juridiques</label><textarea name="notes_juridiques" class="form-textarea"></textarea></div>
            <div class="form-group form-full"><label class="form-label">Stratégie <span class="badge bg-gold" style="font-size:.6rem">CONFIDENTIEL</span></label><textarea name="strategie" class="form-textarea"></textarea></div>
        </div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Cabinet.closeModal('newDossierModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
    </form></div>
</div>
<?php endif;?>
<?php endif;?>

<?php include __DIR__ . '/includes/footer.php';?>