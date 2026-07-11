<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$companyFaction = 'USSS';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff'])) redirect(SITE_URL . '/pages/dashboard.php');
$pageTitle = 'Agents';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';
$isAdmin = hasRole(['*','Administrateur','Développeur']);

// Grades USSS depuis la DB (roles.php) ──────────────────────────────────────
try {
    $__usssGStmt = $db->prepare(
        "SELECT fg.name FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id
         WHERE f.faction_key='USSS' ORDER BY fg.rank_order ASC"
    );
    $__usssGStmt->execute();
    $usssGrades = array_column($__usssGStmt->fetchAll(), 'name');
} catch(Exception $e) {
    $usssGrades = ['Trainee Agent','Special Agent','Assistant Director','Deputy Director','Director'];
}

// ── ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'recruter_agent' && $isAdmin) {
        try {
            $db->beginTransaction();
            $userId      = (int)$_POST['user_id'];
            $badgeNumber = strtoupper(trim($_POST['badge_number']));
            // Vérifs
            if ($db->query("SELECT id FROM usss_agents WHERE user_id=$userId")->fetch()) throw new Exception('Utilisateur déjà agent USSS.');
            if ($db->query("SELECT id FROM usss_agents WHERE badge_number=".$db->quote($badgeNumber))->fetch()) throw new Exception('Badge déjà utilisé.');
            $db->prepare("INSERT INTO usss_agents (user_id,badge_number,nom_rp,prenom_rp,grade,division_id,date_entree,niveau_habilitation,statut) VALUES (?,?,?,?,?,?,?,?,'EN_SERVICE')")
               ->execute([$userId,$badgeNumber,trim($_POST['nom_rp']),trim($_POST['prenom_rp']),trim($_POST['grade']),!empty($_POST['division_id'])?(int)$_POST['division_id']:null,$_POST['date_entree']??date('Y-m-d'),$_POST['niveau_habilitation']??'CONFIDENTIEL']);
            $db->prepare("UPDATE users SET faction='USSS',faction_rank=? WHERE id=?")->execute([trim($_POST['grade']),$userId]);
            $db->commit();
            $msg = "Agent $badgeNumber recruté."; $msgType = 'success';
        } catch (Exception $e) { if($db->inTransaction())$db->rollBack(); $msg=$e->getMessage(); $msgType='error'; }
    }

    if ($action === 'changer_statut') {
        try {
            $db->prepare("UPDATE usss_agents SET statut=? WHERE id=?")->execute([$_POST['nouveau_statut'],(int)$_POST['agent_id']]);
            $msg = 'Statut mis à jour.'; $msgType = 'success';
        } catch (Exception $e) { $msg=$e->getMessage(); $msgType='error'; }
    }

    if ($action === 'promouvoir_agent' && $isAdmin) {
        try {
            $db->beginTransaction();
            $agentId = (int)$_POST['agent_id'];
            $agent   = $db->query("SELECT * FROM usss_agents WHERE id=$agentId")->fetch();
            $ng      = trim($_POST['nouveau_grade']);
            $nd      = !empty($_POST['nouvelle_division'])?(int)$_POST['nouvelle_division']:$agent['division_id'];
            $db->prepare("INSERT INTO usss_promotions (agent_id,ancien_grade,nouveau_grade,ancienne_division,nouvelle_division,raison,approuve_par,date_promotion) VALUES (?,?,?,?,?,?,?,CURDATE())")
               ->execute([$agentId,$agent['grade'],$ng,$agent['division_id'],$nd,trim($_POST['raison']??''),$_SESSION['user_id']]);
            $db->prepare("UPDATE usss_agents SET grade=?,division_id=? WHERE id=?")->execute([$ng,$nd,$agentId]);
            $db->prepare("UPDATE users SET faction_rank=? WHERE id=?")->execute([$ng,$agent['user_id']]);
            $db->commit();
            $msg = 'Agent promu.'; $msgType = 'success';
        } catch (Exception $e) { if($db->inTransaction())$db->rollBack(); $msg=$e->getMessage(); $msgType='error'; }
    }

    if ($action === 'sanctionner_agent') {
        try {
            $agentId = (int)$_POST['agent_id'];
            $db->prepare("INSERT INTO usss_sanctions (agent_id,type_sanction,motif,created_by) VALUES (?,?,?,?)")
               ->execute([$agentId,$_POST['type_sanction'],trim($_POST['motif']),$_SESSION['user_id']]);
            $db->prepare("UPDATE usss_agents SET sanctions=sanctions+1 WHERE id=?")->execute([$agentId]);
            $msg = 'Sanction enregistrée.'; $msgType = 'success';
        } catch (Exception $e) { $msg=$e->getMessage(); $msgType='error'; }
    }

    if ($msg) { header('Location: agents.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ─────────────────────────────────────────────────────────────────
$search=$_GET['search']??''; $fDiv=$_GET['division']??''; $fStat=$_GET['statut']??'';
$sql="SELECT a.*,d.nom as div_nom,d.code as div_code,u.username,s.nom_rp as sup_nom,s.prenom_rp as sup_prenom FROM usss_agents a JOIN users u ON a.user_id=u.id LEFT JOIN usss_divisions d ON a.division_id=d.id LEFT JOIN usss_agents s ON a.superieur_id=s.id WHERE 1=1";
if($fDiv) $sql.=" AND a.division_id=".(int)$fDiv;
if($fStat) $sql.=" AND a.statut=".$db->quote($fStat);
if($search) $sql.=" AND (a.nom_rp LIKE ".$db->quote("%$search%")." OR a.prenom_rp LIKE ".$db->quote("%$search%")." OR a.badge_number LIKE ".$db->quote("%$search%").")";
$sql.=" ORDER BY a.created_at DESC";
$agents=[];try{$agents=$db->query($sql)->fetchAll();}catch(Exception $e){}

$divisions=[];try{$divisions=$db->query("SELECT * FROM usss_divisions WHERE actif=1 ORDER BY ordre")->fetchAll();}catch(Exception $e){}
$usersDisponibles=[];try{$usersDisponibles=$db->query("SELECT id,username,email FROM users WHERE faction='NONE' OR faction IS NULL OR faction='USSS' ORDER BY username")->fetchAll();}catch(Exception $e){}

$stats=['total'=>count($agents),'en_service'=>count(array_filter($agents,fn($a)=>$a['statut']==='EN_SERVICE')),'hors_service'=>count(array_filter($agents,fn($a)=>$a['statut']==='HORS_SERVICE')),'suspension'=>count(array_filter($agents,fn($a)=>$a['statut']==='SUSPENSION'))];

$statCfg=['EN_SERVICE'=>['b-green','fa-circle-check'],'HORS_SERVICE'=>['b-slate','fa-circle'],'CONGE'=>['b-blue','fa-moon'],'SUSPENSION'=>['b-amber','fa-pause'],'ENQUETE_INTERNE'=>['b-red','fa-search'],'DEMISSION'=>['b-slate','fa-sign-out-alt']];
$habCfg=['CONFIDENTIEL'=>['b-blue','cls-conf'],'SECRET'=>['b-amber','cls-secret'],'TOP_SECRET'=>['b-red','cls-ts']];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<!-- KPIs -->
<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
  <?php foreach([['Total Agents',$stats['total'],'fas fa-id-badge','var(--blue)'],['En Service',$stats['en_service'],'fas fa-circle-check','var(--green-l)'],['Hors Service',$stats['hors_service'],'fas fa-circle','var(--txt-m)'],['Suspensions',$stats['suspension'],'fas fa-pause','var(--amber-l)']] as [$l,$v,$i,$c]):?>
  <div class="kpi" style="--kpi-color:<?php echo $c;?>">
    <div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="<?php echo $i;?>"></i></div>
    <div class="kpi-lbl" style="margin-top:2.1rem"><?php echo $l;?></div>
    <div class="kpi-val"><?php echo $v;?></div>
  </div>
  <?php endforeach;?>
</div>

<!-- Filtres + bouton recruter -->
<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="search" class="form-input" placeholder="Nom, badge…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:160px">
    <select name="division" class="form-select" style="width:160px" onchange="this.form.submit()">
      <option value="">Toutes divisions</option>
      <?php foreach($divisions as $d):?><option value="<?php echo $d['id'];?>" <?php echo $fDiv==$d['id']?'selected':'';?>><?php echo htmlspecialchars($d['nom']);?></option><?php endforeach;?>
    </select>
    <select name="statut" class="form-select" style="width:145px" onchange="this.form.submit()">
      <option value="">Tous statuts</option>
      <?php foreach(array_keys($statCfg) as $s):?><option value="<?php echo $s;?>" <?php echo $fStat===$s?'selected':'';?>><?php echo str_replace('_',' ',$s);?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fDiv||$fStat):?><a href="agents.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <?php if($isAdmin):?>
  <button onclick="USSS.openModal('recruterModal')" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Recruter</button>
  <?php endif;?>
</div>

<!-- TABLE AGENTS -->
<?php if(empty($agents)):?>
<div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-m)">
  <i class="fas fa-id-badge" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
  Aucun agent trouvé
</div>
<?php else:?>
<div class="tbl-wrap">
<table class="tbl">
<thead><tr><th>Badge</th><th>Agent</th><th>Grade</th><th>Division</th><th>Habilitation</th><th>Statut</th><th>Entrée</th><th>Heures</th><?php if($isAdmin):?><th>Actions</th><?php endif;?></tr></thead>
<tbody>
<?php foreach($agents as $a):
  [$sb]=$statCfg[$a['statut']]??['b-slate'];
  [$hb,$hcls]=$habCfg[$a['niveau_habilitation']]??['b-blue','cls-conf'];
?>
<tr>
  <td><span class="ref"><?php echo htmlspecialchars($a['badge_number']);?></span></td>
  <td>
    <div style="display:flex;align-items:center;gap:.6rem">
      <div style="width:32px;height:32px;border-radius:6px;background:linear-gradient(135deg,#1e3a8a,#1a56db);display:flex;align-items:center;justify-content:center;font-family:'Barlow Condensed',sans-serif;font-weight:700;color:#fff;font-size:.85rem;border:1px solid rgba(200,168,75,.3);flex-shrink:0">
        <?php echo strtoupper(substr($a['nom_rp'],0,1).substr($a['prenom_rp'],0,1));?>
      </div>
      <div>
        <div style="font-weight:600;font-size:.85rem"><?php echo htmlspecialchars($a['nom_rp'].' '.$a['prenom_rp']);?></div>
        <div style="font-size:.68rem;color:var(--txt-m)"><?php echo htmlspecialchars($a['username']);?></div>
      </div>
    </div>
  </td>
  <td style="font-size:.82rem"><?php echo htmlspecialchars($a['grade']);?></td>
  <td>
    <?php if($a['div_nom']):?>
    <span class="badge b-blue" style="font-size:.6rem"><?php echo htmlspecialchars($a['div_code']);?></span>
    <div style="font-size:.7rem;color:var(--txt-s);margin-top:.15rem"><?php echo htmlspecialchars($a['div_nom']);?></div>
    <?php else:?><span style="color:var(--txt-m);font-size:.78rem">—</span><?php endif;?>
  </td>
  <td><span class="cls-stamp <?php echo $hcls;?>" style="font-size:.6rem"><?php echo $a['niveau_habilitation'];?></span></td>
  <td>
    <div style="display:flex;align-items:center;gap:.4rem">
      <span class="sdot <?php echo $a['statut']==='EN_SERVICE'?'active':($a['statut']==='SUSPENSION'?'warn':($a['statut']==='ENQUETE_INTERNE'?'danger':'inactive'));?>"></span>
      <span class="badge <?php echo $sb;?>" style="font-size:.6rem"><?php echo str_replace('_',' ',$a['statut']);?></span>
    </div>
  </td>
  <td class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo $a['date_entree']?date('d.m.Y',strtotime($a['date_entree'])):'—';?></td>
  <td class="mono" style="font-size:.75rem"><?php echo number_format($a['heures_service']??0);?>h</td>
  <?php if($isAdmin):?>
  <td>
    <div style="display:flex;gap:.3rem">
      <button class="btn btn-ghost btn-xs" onclick='openAgent(<?php echo json_encode($a,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE);?>)' title="Modifier"><i class="fas fa-pen"></i></button>
      <button class="btn btn-ghost btn-xs" onclick='openPromo(<?php echo $a['id'];?>,<?php echo json_encode($a['grade'],JSON_HEX_APOS);?>)' title="Promouvoir"><i class="fas fa-arrow-up"></i></button>
      <button class="btn btn-ghost btn-xs" onclick='openSanction(<?php echo $a['id'];?>)' title="Sanctionner"><i class="fas fa-gavel"></i></button>
      <form method="POST" style="margin:0;display:inline" data-confirm data-confirm-title="Changer le statut" data-confirm-msg='Mettre l'"'"'agent <?php echo addslashes($a['badge_number']);?> <?php echo $a['statut']==='EN_SERVICE'?'HORS SERVICE':'EN SERVICE';?> ?' data-confirm-ok="Confirmer">
        <input type="hidden" name="action" value="changer_statut">
        <input type="hidden" name="agent_id" value="<?php echo $a['id'];?>">
        <input type="hidden" name="nouveau_statut" value="<?php echo $a['statut']==='EN_SERVICE'?'HORS_SERVICE':'EN_SERVICE';?>">
        <button type="submit" class="btn btn-ghost btn-xs" title="<?php echo $a['statut']==='EN_SERVICE'?'Hors service':'En service';?>">
          <i class="fas fa-<?php echo $a['statut']==='EN_SERVICE'?'pause':'play';?>"></i>
        </button>
      </form>
    </div>
  </td>
  <?php endif;?>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
<?php endif;?>

<!-- MODAL RECRUTER -->
<div id="recruterModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-user-plus"></i> Recruter un Agent</h2><button class="modal-cl" onclick="USSS.closeModal('recruterModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="recruter_agent">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Utilisateur *</label>
      <select name="user_id" class="form-select" required>
        <option value="">Sélectionner…</option>
        <?php foreach($usersDisponibles as $u):?><option value="<?php echo $u['id'];?>"><?php echo htmlspecialchars($u['username'].' ('.$u['email'].')');?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Nom RP *</label><input type="text" name="nom_rp" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Prénom RP *</label><input type="text" name="prenom_rp" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Badge *</label><input type="text" name="badge_number" class="form-input" required placeholder="USSS-001"></div>
    <div class="form-group"><label class="form-label">Grade *</label>
      <select name="grade" class="form-select" required>
        <option value="">Sélectionner un grade…</option>
        <?php foreach($usssGrades as $__ug):?>
        <option value="<?php echo htmlspecialchars($__ug);?>"><?php echo htmlspecialchars($__ug);?></option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Division</label>
      <select name="division_id" class="form-select"><option value="">Aucune</option><?php foreach($divisions as $d):?><option value="<?php echo $d['id'];?>"><?php echo htmlspecialchars($d['nom']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Habilitation</label>
      <select name="niveau_habilitation" class="form-select"><option value="CONFIDENTIEL">CONFIDENTIEL</option><option value="SECRET">SECRET</option><option value="TOP_SECRET">TOP SECRET</option></select>
    </div>
    <div class="form-group"><label class="form-label">Date d'entrée</label><input type="date" name="date_entree" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('recruterModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Recruter</button></div>
</form></div></div>

<!-- MODAL PROMOUVOIR -->
<div id="promoModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-arrow-up"></i> Promouvoir l'Agent</h2><button class="modal-cl" onclick="USSS.closeModal('promoModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="promouvoir_agent"><input type="hidden" name="agent_id" id="promo-id">
  <div style="font-size:.82rem;color:var(--txt-s);margin-bottom:.85rem">Grade actuel : <strong id="promo-grade" style="color:var(--txt)"></strong></div>
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Nouveau grade *</label>
      <select name="nouveau_grade" class="form-select" required>
        <option value="">Sélectionner…</option>
        <?php foreach($usssGrades as $__ug):?>
        <option value="<?php echo htmlspecialchars($__ug);?>"><?php echo htmlspecialchars($__ug);?></option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="form-group form-full"><label class="form-label">Nouvelle division</label>
      <select name="nouvelle_division" class="form-select"><option value="">Inchangée</option><?php foreach($divisions as $d):?><option value="<?php echo $d['id'];?>"><?php echo htmlspecialchars($d['nom']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group form-full"><label class="form-label">Raison</label><textarea name="raison" class="form-textarea" style="min-height:70px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('promoModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Promouvoir</button></div>
</form></div></div>

<!-- MODAL SANCTION -->
<div id="sanctionModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-gavel"></i> Sanctionner l'Agent</h2><button class="modal-cl" onclick="USSS.closeModal('sanctionModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="sanctionner_agent"><input type="hidden" name="agent_id" id="sanction-id">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Type de sanction *</label>
      <select name="type_sanction" class="form-select" required>
        <option value="AVERTISSEMENT">Avertissement</option>
        <option value="BLÂME">Blâme</option>
        <option value="MISE_A_PIED">Mise à pied</option>
        <option value="SUSPENSION">Suspension</option>
        <option value="RENVOI">Renvoi</option>
      </select>
    </div>
    <div class="form-group form-full"><label class="form-label">Motif *</label><textarea name="motif" class="form-textarea" required style="min-height:80px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('sanctionModal')">Annuler</button><button type="submit" class="btn btn-danger"><i class="fas fa-gavel"></i> Sanctionner</button></div>
</form></div></div>

<script>
function openAgent(a){ document.getElementById('promo-id').value=a.id; USSS.openModal('promoModal'); document.getElementById('promo-grade').textContent=a.grade; }
function openPromo(id,grade){ document.getElementById('promo-id').value=id; document.getElementById('promo-grade').textContent=grade; USSS.openModal('promoModal'); }
function openSanction(id){ document.getElementById('sanction-id').value=id; USSS.openModal('sanctionModal'); }
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>