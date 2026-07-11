<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = "Secrétariat à l'Événementiel";
$__secLabel = "Secrétariat Événementiel"; $__secColor = '#7c3aed'; $__secIcon = 'fas fa-calendar-star';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// ── Tables auto-création ──────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS `gouv_evenements` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `nom` varchar(200) NOT NULL,
  `type` enum('concert','tournoi','marche','ceremonie','conference','sportif','culturel','autre') DEFAULT 'autre',
  `description` text,
  `lieu` varchar(200),
  `date_debut` datetime,
  `date_fin` datetime,
  `organisateur` varchar(150),
  `organisateur_faction` varchar(80),
  `capacite_max` int DEFAULT 0,
  `budget_demande` decimal(12,2) DEFAULT 0,
  `budget_alloue` decimal(12,2) DEFAULT 0,
  `statut` enum('demande','en_etude','approuve','refuse','annule','termine') DEFAULT 'demande',
  `niveau` enum('local','citywide','officiel') DEFAULT 'local',
  `observations` text,
  `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP, `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_evt_participants` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `evenement_id` int NOT NULL,
  `nom` varchar(150), `faction` varchar(80), `role` varchar(100), `confirme` tinyint DEFAULT 0,
  FOREIGN KEY (evenement_id) REFERENCES gouv_evenements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `gouv_evt_documents` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `evenement_id` int NOT NULL,
  `type` enum('programme','contrat','rapport','bilan','autre') DEFAULT 'autre',
  `titre` varchar(200), `contenu` text, `created_by` int, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (evenement_id) REFERENCES gouv_evenements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── POST ACTIONS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_event') {
        try {
            $db->prepare("INSERT INTO gouv_evenements (nom,type,description,lieu,date_debut,date_fin,organisateur,organisateur_faction,capacite_max,budget_demande,niveau,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   trim($_POST['nom']), $_POST['type'], trim($_POST['description']??''),
                   trim($_POST['lieu']??''), $_POST['date_debut']?:null, $_POST['date_fin']?:null,
                   trim($_POST['organisateur']??''), trim($_POST['organisateur_faction']??''),
                   (int)($_POST['capacite_max']??0), (float)($_POST['budget_demande']??0),
                   $_POST['niveau']??'local', $_SESSION['user_id']
               ]);
            $msg = 'Événement créé avec succès.'; $msgType = 'ok';
        } catch (Exception $e) { $msg = $e->getMessage(); $msgType = 'err'; }
    }

    if ($action === 'update_statut') {
        $id=(int)$_POST['event_id']; $st=$_POST['statut'];
        $budAlloue=$_POST['budget_alloue']??null; $obs=trim($_POST['observations']??'');
        try {
            $db->prepare("UPDATE gouv_evenements SET statut=?,budget_alloue=?,observations=? WHERE id=?")
               ->execute([$st, $budAlloue?:0, $obs, $id]);
            $msg = 'Événement mis à jour.'; $msgType = 'ok';
        } catch (Exception $e) { $msg = $e->getMessage(); $msgType = 'err'; }
    }

    if ($action === 'add_doc') {
        try {
            $db->prepare("INSERT INTO gouv_evt_documents (evenement_id,type,titre,contenu,created_by) VALUES (?,?,?,?,?)")
               ->execute([(int)$_POST['event_id'],$_POST['doc_type'],trim($_POST['doc_titre']),trim($_POST['doc_contenu']),$_SESSION['user_id']]);
            $msg = 'Document ajouté.'; $msgType = 'ok';
        } catch (Exception $e) { $msg = $e->getMessage(); $msgType = 'err'; }
    }

    if ($action === 'delete_event') {
        $db->prepare("DELETE FROM gouv_evenements WHERE id=?")->execute([(int)$_POST['event_id']]);
        $msg = 'Événement supprimé.'; $msgType = 'ok';
    }

    if ($msg) { header('Location: sec_evenementiel.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg = htmlspecialchars($_GET['msg']); $msgType = $_GET['type']??'ok'; }

// ── DATA ──────────────────────────────────────────────────────────────────────
$fStat=$_GET['stat']??''; $fType=$_GET['type_f']??''; $search=$_GET['q']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;
$where=['1=1']; $params=[];
if($search){$where[]="(e.nom LIKE ? OR e.organisateur LIKE ? OR e.lieu LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;$params[]=$s;}
if($fStat){$where[]="e.statut=?";$params[]=$fStat;}
if($fType){$where[]="e.type=?";$params[]=$fType;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM gouv_evenements e $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$events=[];
try{$s=$db->prepare("SELECT e.* FROM gouv_evenements e $wStr ORDER BY e.date_debut DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$events=$s->fetchAll();}catch(Exception $e){}

$statsE=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM gouv_evenements GROUP BY statut")->fetchAll() as $r)$statsE[$r['statut']]=$r['n'];}catch(Exception $e){}
$budgetTotal=0;try{$budgetTotal=(float)$db->query("SELECT COALESCE(SUM(budget_alloue),0) FROM gouv_evenements WHERE statut='approuve'")->fetchColumn();}catch(Exception $e){}
$prochains=[];try{$prochains=$db->query("SELECT * FROM gouv_evenements WHERE statut='approuve' AND date_debut >= NOW() ORDER BY date_debut ASC LIMIT 5")->fetchAll();}catch(Exception $e){}

$statCfg=['demande'=>['bg-blue','Demande'],'en_etude'=>['bg-yellow','En étude'],'approuve'=>['bg-green','Approuvé'],'refuse'=>['bg-red','Refusé'],'annule'=>['bg-gray','Annulé'],'termine'=>['bg-gray','Terminé']];
$typeCfg=['concert'=>'Concert','tournoi'=>'Tournoi','marche'=>'Marché','ceremonie'=>'Cérémonie','conference'=>'Conférence','sportif'=>'Sportif','culturel'=>'Culturel','autre'=>'Autre'];
$niveauCfg=['local'=>['bg-gray','Local'],'citywide'=>['bg-blue','Citywide'],'officiel'=>['bg-purple','Officiel']];

// Récupérer détail event si ?id=
$viewEvent=null; $viewId=(int)($_GET['id']??0);
if($viewId){
    try{$viewEvent=$db->prepare("SELECT * FROM gouv_evenements WHERE id=?");$viewEvent->execute([$viewId]);$viewEvent=$viewEvent->fetch();}catch(Exception $e){}
}
$viewDocs=[]; if($viewEvent){try{$viewDocs=$db->query("SELECT * FROM gouv_evt_documents WHERE evenement_id=$viewId ORDER BY created_at DESC")->fetchAll();}catch(Exception $e){}}

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<?php if($viewEvent): // ── VUE DÉTAIL ───────────────────────────────────────?>
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem">
  <a href="sec_evenementiel.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
  <h2 style="font-size:1.1rem;font-weight:700"><?php echo htmlspecialchars($viewEvent['nom']);?></h2>
  <?php [$vb,$vl]=$statCfg[$viewEvent['statut']]??['bg-gray','—'];?><span class="badge <?php echo $vb;?>"><?php echo $vl;?></span>
</div>

<div class="grid2">
<div>
  <div class="card">
    <div class="card-title"><i class="fas fa-calendar-star"></i> Informations</div>
    <div style="display:flex;flex-direction:column;gap:.65rem;font-size:.83rem">
      <?php foreach([['Type',$typeCfg[$viewEvent['type']]??$viewEvent['type']],['Lieu',$viewEvent['lieu']??'—'],['Organisateur',$viewEvent['organisateur']??'—'],['Faction',$viewEvent['organisateur_faction']??'—'],['Capacité max',$viewEvent['capacite_max']?number_format($viewEvent['capacite_max']).' pers.':'—'],['Budget demandé','$'.number_format($viewEvent['budget_demande'],0)],['Budget alloué','$'.number_format($viewEvent['budget_alloue'],0)],['Niveau',($niveauCfg[$viewEvent['niveau']][1]??'—')]] as [$l,$v]):?>
      <div style="display:flex;justify-content:space-between;padding:.42rem 0;border-bottom:1px solid rgba(255,255,255,.04)">
        <span style="color:var(--txt-m)"><?php echo $l;?></span>
        <span style="font-weight:500;text-align:right"><?php echo htmlspecialchars((string)$v);?></span>
      </div>
      <?php endforeach;?>
      <?php if($viewEvent['date_debut']):?><div style="display:flex;justify-content:space-between;padding:.42rem 0;border-bottom:1px solid rgba(255,255,255,.04)"><span style="color:var(--txt-m)">Début</span><span class="mono" style="font-size:.78rem"><?php echo date('d/m/Y H:i',strtotime($viewEvent['date_debut']));?></span></div><?php endif;?>
      <?php if($viewEvent['date_fin']):?><div style="display:flex;justify-content:space-between;padding:.42rem 0"><span style="color:var(--txt-m)">Fin</span><span class="mono" style="font-size:.78rem"><?php echo date('d/m/Y H:i',strtotime($viewEvent['date_fin']));?></span></div><?php endif;?>
    </div>
    <?php if($viewEvent['description']):?><div style="margin-top:1rem;padding:.75rem;background:var(--bg-ov);border-radius:8px;font-size:.81rem;line-height:1.6;color:var(--txt-s)"><?php echo nl2br(htmlspecialchars($viewEvent['description']));?></div><?php endif;?>
    <?php if($viewEvent['observations']):?><div style="margin-top:.65rem;padding:.75rem;background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.15);border-radius:8px;font-size:.81rem;line-height:1.6"><strong style="color:#fcd34d;font-size:.68rem">Observations:</strong><br><?php echo nl2br(htmlspecialchars($viewEvent['observations']));?></div><?php endif;?>
  </div>

  <!-- Mise à jour statut -->
  <div class="card">
    <div class="card-title"><i class="fas fa-pen"></i> Mettre à Jour</div>
    <form method="POST">
      <input type="hidden" name="action" value="update_statut">
      <input type="hidden" name="event_id" value="<?php echo $viewEvent['id'];?>">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Statut</label>
          <select name="statut" class="form-select">
            <?php foreach($statCfg as $sk=>[$sb,$sl]):?><option value="<?php echo $sk;?>" <?php echo $viewEvent['statut']===$sk?'selected':'';?>><?php echo $sl;?></option><?php endforeach;?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Budget alloué ($)</label>
          <input type="number" name="budget_alloue" class="form-input" step="0.01" min="0" value="<?php echo $viewEvent['budget_alloue'];?>">
        </div>
        <div class="form-group form-full"><label class="form-label">Observations</label>
          <textarea name="observations" class="form-textarea"><?php echo htmlspecialchars($viewEvent['observations']??'');?></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.75rem"><i class="fas fa-save"></i> Enregistrer</button>
    </form>
  </div>
</div>

<div>
  <!-- Documents -->
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem">
      <div class="card-title" style="margin:0"><i class="fas fa-file-alt"></i> Documents (<?php echo count($viewDocs);?>)</div>
      <button class="btn btn-purple btn-sm" onclick="Gov.openModal('addDocModal')"><i class="fas fa-plus"></i> Ajouter</button>
    </div>
    <?php if(empty($viewDocs)):?>
    <div style="text-align:center;padding:2rem;color:var(--txt-m);font-size:.82rem">Aucun document</div>
    <?php else: foreach($viewDocs as $doc):?>
    <div style="padding:.65rem .75rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;margin-bottom:.45rem">
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem">
        <span class="badge bg-purple" style="font-size:.58rem"><?php echo $doc['type'];?></span>
        <span style="font-weight:600;font-size:.83rem"><?php echo htmlspecialchars($doc['titre']);?></span>
      </div>
      <div style="font-size:.77rem;color:var(--txt-s);line-height:1.55"><?php echo nl2br(htmlspecialchars(mb_substr($doc['contenu'],0,200)));?></div>
      <div class="mono" style="font-size:.62rem;color:var(--txt-m);margin-top:.3rem"><?php echo date('d/m/Y H:i',strtotime($doc['created_at']));?></div>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>
</div>

<!-- Modal ajout document -->
<div id="addDocModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-file-plus"></i> Ajouter un Document</h2><button class="modal-cl" onclick="Gov.closeModal('addDocModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_doc"><input type="hidden" name="event_id" value="<?php echo $viewId;?>">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type *</label>
      <select name="doc_type" class="form-select"><option value="programme">Programme</option><option value="contrat">Contrat</option><option value="rapport">Rapport</option><option value="bilan">Bilan</option><option value="autre">Autre</option></select>
    </div>
    <div class="form-group"><label class="form-label">Titre *</label><input type="text" name="doc_titre" class="form-input" required></div>
    <div class="form-group form-full"><label class="form-label">Contenu *</label><textarea name="doc_contenu" class="form-textarea" required style="min-height:120px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('addDocModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Ajouter</button></div>
</form></div></div>

<?php else: // ── LISTE ─────────────────────────────────────────────────────?>

<!-- KPIs -->
<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico" style="background:rgba(124,58,237,.12);color:#c4b5fd"><i class="fas fa-calendar-star"></i></div><div class="kpi-lbl">Événements Total</div><div class="kpi-val purple"><?php echo array_sum($statsE);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Approuvés</div><div class="kpi-val green"><?php echo $statsE['approuve']??0;?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-clock"></i></div><div class="kpi-lbl">En étude</div><div class="kpi-val gold"><?php echo ($statsE['demande']??0)+($statsE['en_etude']??0);?></div></div>
  <div class="kpi"><div class="kpi-ico" style="background:rgba(21,128,61,.12);color:#86efac"><i class="fas fa-coins"></i></div><div class="kpi-lbl">Budget alloué</div><div class="kpi-val teal">$<?php echo number_format($budgetTotal,0);?></div></div>
</div>

<!-- Prochains événements approuvés -->
<?php if(!empty($prochains)):?>
<div class="card" style="border-color:rgba(124,58,237,.3)">
  <div class="card-title" style="color:#c4b5fd"><i class="fas fa-calendar-check" style="color:#7c3aed"></i> Prochains Événements Approuvés</div>
  <div style="display:flex;flex-direction:column;gap:.5rem">
  <?php foreach($prochains as $ev):?>
  <div style="display:flex;align-items:center;gap:.85rem;padding:.6rem .85rem;background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.15);border-radius:8px">
    <div style="width:42px;height:42px;border-radius:8px;background:rgba(124,58,237,.15);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0">
      <span class="mono" style="font-size:.7rem;color:#c4b5fd;font-weight:700"><?php echo date('d',strtotime($ev['date_debut']));?></span>
      <span class="mono" style="font-size:.58rem;color:var(--txt-m)"><?php echo strtoupper(date('M',strtotime($ev['date_debut'])));?></span>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;font-size:.85rem"><?php echo htmlspecialchars($ev['nom']);?></div>
      <div style="font-size:.72rem;color:var(--txt-s)"><?php echo htmlspecialchars($ev['lieu']??'—');?> · <?php echo htmlspecialchars($ev['organisateur']??'—');?></div>
    </div>
    <a href="?id=<?php echo $ev['id'];?>" class="btn btn-ghost btn-xs"><i class="fas fa-eye"></i></a>
  </div>
  <?php endforeach;?>
  </div>
</div>
<?php endif;?>

<!-- Filtres -->
<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Nom, lieu, organisateur…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:160px">
    <select name="type_f" class="form-select" style="width:140px" onchange="this.form.submit()">
      <option value="">Tous types</option>
      <?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?>
    </select>
    <select name="stat" class="form-select" style="width:140px" onchange="this.form.submit()">
      <option value="">Tous statuts</option>
      <?php foreach($statCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fStat||$fType):?><a href="sec_evenementiel.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="Gov.openModal('newEvtModal')" class="btn btn-purple btn-sm"><i class="fas fa-plus"></i> Nouvel Événement</button>
</div>

<!-- Table -->
<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Événement</th><th>Type</th><th>Lieu</th><th>Organisateur</th><th>Date</th><th>Budget demandé</th><th>Niveau</th><th>Statut</th><th></th></tr></thead>
<tbody>
<?php if(empty($events)):?>
<tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-calendar-xmark" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun événement</td></tr>
<?php else: foreach($events as $ev):[$sb,$sl]=$statCfg[$ev['statut']]??['bg-gray','—'];[$nb,$nl]=$niveauCfg[$ev['niveau']]??['bg-gray','—'];?>
<tr>
  <td style="font-weight:600"><?php echo htmlspecialchars($ev['nom']);?></td>
  <td><span class="badge bg-purple" style="font-size:.6rem"><?php echo $typeCfg[$ev['type']]??$ev['type'];?></span></td>
  <td style="color:var(--txt-s);font-size:.8rem"><?php echo htmlspecialchars($ev['lieu']??'—');?></td>
  <td style="font-size:.8rem"><?php echo htmlspecialchars($ev['organisateur']??'—');?></td>
  <td class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo $ev['date_debut']?date('d/m/Y',strtotime($ev['date_debut'])):'—';?></td>
  <td class="mono" style="font-size:.8rem">$<?php echo number_format($ev['budget_demande'],0);?></td>
  <td><span class="badge <?php echo $nb;?>" style="font-size:.58rem"><?php echo $nl;?></span></td>
  <td><span class="badge <?php echo $sb;?>"><?php echo $sl;?></span></td>
  <td><div style="display:flex;gap:.3rem">
    <a href="?id=<?php echo $ev['id'];?>" class="btn btn-ghost btn-xs" title="Voir"><i class="fas fa-eye"></i></a>
    <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="event_id" value="<?php echo $ev['id'];?>"><button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('Supprimer ?')"><i class="fas fa-trash"></i></button></form>
  </div></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>

<?php if($totalPages>1):?><div class="pag">
  <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>">← Préc.</a><?php endif;?>
  <span class="pag-cur">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
  <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>">Suiv. →</a><?php endif;?>
</div><?php endif;?>

<?php endif; // fin if viewEvent ?>

<!-- MODAL NOUVEL ÉVÉNEMENT -->
<div id="newEvtModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-calendar-plus"></i> Nouvel Événement</h2><button class="modal-cl" onclick="Gov.closeModal('newEvtModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_event">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Nom de l'événement *</label><input type="text" name="nom" class="form-input" required placeholder="Grand Concert du Mois…"></div>
    <div class="form-group"><label class="form-label">Type *</label>
      <select name="type" class="form-select" required><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Niveau</label>
      <select name="niveau" class="form-select"><option value="local">Local</option><option value="citywide">Citywide</option><option value="officiel">Officiel</option></select>
    </div>
    <div class="form-group"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input" placeholder="Vinewood Bowl, Legion Square…"></div>
    <div class="form-group"><label class="form-label">Date & Heure de Début</label><input type="datetime-local" name="date_debut" class="form-input"></div>
    <div class="form-group"><label class="form-label">Date & Heure de Fin</label><input type="datetime-local" name="date_fin" class="form-input"></div>
    <div class="form-group"><label class="form-label">Organisateur</label><input type="text" name="organisateur" class="form-input" placeholder="Nom ou organisation"></div>
    <div class="form-group"><label class="form-label">Faction organisatrice</label><input type="text" name="organisateur_faction" class="form-input" placeholder="Faction, association…"></div>
    <div class="form-group"><label class="form-label">Capacité max (pers.)</label><input type="number" name="capacite_max" class="form-input" min="0" value="0"></div>
    <div class="form-group"><label class="form-label">Budget demandé ($)</label><input type="number" name="budget_demande" class="form-input" min="0" step="0.01" value="0"></div>
    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" style="min-height:90px" placeholder="Description détaillée de l'événement…"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newEvtModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>

<?php include __DIR__ . '/includes/footer.php'; ?>