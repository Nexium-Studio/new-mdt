<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$companyName = '🇺🇸 United States Secret Service';
$companyColor = '#1e3a8a';
$companyFaction = 'USSS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Intelligence & Surveillance';
$db = Database::getInstance()->getConnection();
$message = ''; $msgType = '';

// Générer référence
function generateRefIntelligence($db, $type) {
    $year = date('Y');
    $prefix = match($type) {
        'SURVEILLANCE' => 'SRV',
        'ECOUTE' => 'WTP',
        'INFILTRATION' => 'INF',
        'INFORMATEUR' => 'CI',
        'ANALYSE_COMPORTEMENTALE' => 'BEH',
        'TRACKING_GPS' => 'GPS',
        default => 'INT'
    };
    $seq = $db->query("SELECT COUNT(*) + 1 FROM usss_intelligence WHERE YEAR(created_at) = $year AND type = '$type'")->fetchColumn();
    return sprintf("%s-%d-%05d", $prefix, $year, $seq);
}

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Créer surveillance
    if ($action === 'create_surveillance') {
        try {
            $db->beginTransaction();
            
            $type = $_POST['type'];
            $reference = generateRefIntelligence($db, $type);
            $agentId = $db->query("SELECT id FROM usss_agents WHERE user_id = " . $_SESSION['user_id'])->fetchColumn();
            
            $cibleDetails = [
                'age' => sanitize($_POST['cible_age'] ?? ''),
                'adresse' => sanitize($_POST['cible_adresse'] ?? ''),
                'telephone' => sanitize($_POST['cible_telephone'] ?? ''),
                'vehicule' => sanitize($_POST['cible_vehicule'] ?? ''),
                'associes' => sanitize($_POST['cible_associes'] ?? '')
            ];
            
            $db->prepare("
                INSERT INTO usss_intelligence 
                (reference, type, cible_nom, cible_details, niveau_menace, agent_responsable, 
                 division_id, date_debut, rapport, localisation_gps)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $reference,
                $type,
                sanitize($_POST['cible_nom']),
                json_encode($cibleDetails),
                $_POST['niveau_menace'] ?? 'MOYEN',
                $agentId,
                !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null,
                $_POST['date_debut'] ?? date('Y-m-d'),
                sanitize($_POST['rapport'] ?? ''),
                sanitize($_POST['localisation_gps'] ?? '')
            ]);
            
            $db->commit();
            $message = "✅ Surveillance créée - Référence: $reference";
            $msgType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Mettre à jour rapport
    if ($action === 'update_rapport') {
        try {
            $surveillanceId = (int)$_POST['surveillance_id'];
            
            $db->prepare("UPDATE usss_intelligence SET rapport = ? WHERE id = ?")
               ->execute([sanitize($_POST['rapport']), $surveillanceId]);
            
            $message = "✅ Rapport mis à jour";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Ajouter données collectées
    if ($action === 'add_donnees') {
        try {
            $surveillanceId = (int)$_POST['surveillance_id'];
            $surveillance = $db->query("SELECT donnees_collectees FROM usss_intelligence WHERE id = $surveillanceId")->fetch();
            
            $donnees = $surveillance['donnees_collectees'] ? json_decode($surveillance['donnees_collectees'], true) : [];
            $donnees[] = [
                'date' => date('Y-m-d H:i:s'),
                'type' => $_POST['donnee_type'],
                'contenu' => sanitize($_POST['donnee_contenu']),
                'agent' => $_SESSION['username']
            ];
            
            $db->prepare("UPDATE usss_intelligence SET donnees_collectees = ? WHERE id = ?")
               ->execute([json_encode($donnees), $surveillanceId]);
            
            $message = "✅ Données ajoutées";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Terminer surveillance
    if ($action === 'terminer_surveillance') {
        try {
            $surveillanceId = (int)$_POST['surveillance_id'];
            
            $db->prepare("UPDATE usss_intelligence SET statut_surveillance = 'TERMINEE', date_fin = CURDATE() WHERE id = ?")
               ->execute([$surveillanceId]);
            
            $message = "✅ Surveillance terminée";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Filtres
$filterType = $_GET['type'] ?? '';
$filterStatut = $_GET['statut'] ?? '';
$filterMenace = $_GET['menace'] ?? '';
$search = $_GET['search'] ?? '';

// Requête
$sql = "
    SELECT 
        i.*,
        a.nom_rp as agent_nom,
        a.prenom_rp as agent_prenom,
        a.badge_number as agent_badge,
        d.nom as division_nom
    FROM usss_intelligence i
    JOIN usss_agents a ON i.agent_responsable = a.id
    LEFT JOIN usss_divisions d ON i.division_id = d.id
    WHERE 1=1
";

if ($filterType) $sql .= " AND i.type = " . $db->quote($filterType);
if ($filterStatut) $sql .= " AND i.statut_surveillance = " . $db->quote($filterStatut);
if ($filterMenace) $sql .= " AND i.niveau_menace = " . $db->quote($filterMenace);
if ($search) $sql .= " AND (i.reference LIKE " . $db->quote("%$search%") . " OR i.cible_nom LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY 
    CASE i.niveau_menace 
        WHEN 'CRITIQUE' THEN 1 
        WHEN 'ELEVE' THEN 2 
        WHEN 'MOYEN' THEN 3 
        WHEN 'FAIBLE' THEN 4 
    END,
    i.created_at DESC";

$surveillances = $db->query($sql)->fetchAll();

// Listes
$divisions = $db->query("SELECT id, nom FROM usss_divisions WHERE actif = 1 ORDER BY nom")->fetchAll();

// Stats
$stats = [
    'total' => count($surveillances),
    'active' => count(array_filter($surveillances, fn($s) => $s['statut_surveillance'] === 'ACTIVE')),
    'critique' => count(array_filter($surveillances, fn($s) => $s['niveau_menace'] === 'CRITIQUE')),
    'terminee' => count(array_filter($surveillances, fn($s) => $s['statut_surveillance'] === 'TERMINEE')),
];

$typeColors = [
    'SURVEILLANCE' => '#3b82f6',
    'ECOUTE' => '#8b5cf6',
    'INFILTRATION' => '#dc2626',
    'INFORMATEUR' => '#f59e0b',
    'ANALYSE_COMPORTEMENTALE' => '#22c55e',
    'TRACKING_GPS' => '#64748b'
];

$menaceColors = [
    'FAIBLE' => '#64748b',
    'MOYEN' => '#3b82f6',
    'ELEVE' => '#f59e0b',
    'CRITIQUE' => '#dc2626'
];

$statutColors = [
    'ACTIVE' => '#22c55e',
    'SUSPENDUE' => '#f59e0b',
    'TERMINEE' => '#64748b'
];

if (isset($_GET['msg'])) { $message=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ─────────────────────────────────────────────────────────────────
$search=$_GET['q']??''; $fType=$_GET['type_f']??''; $fStat=$_GET['stat']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(i.reference LIKE ? OR i.cible_nom LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($fType){$where[]="i.type=?";$params[]=$fType;}
if($fStat){$where[]="i.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM usss_intelligence i $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$surveillances=[];
try{$s=$db->prepare("SELECT i.*,a.nom_rp,a.prenom_rp,a.badge_number FROM usss_intelligence i JOIN usss_agents a ON i.agent_responsable=a.id $wStr ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$surveillances=$s->fetchAll();}catch(Exception $e){}
$statsI=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM usss_intelligence GROUP BY statut")->fetchAll() as $r)$statsI[$r['statut']]=$r['n'];}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,nom_rp,prenom_rp,badge_number FROM usss_agents WHERE statut='EN_SERVICE' ORDER BY nom_rp")->fetchAll();}catch(Exception $e){}
$divisions=[];try{$divisions=$db->query("SELECT id,nom FROM usss_divisions WHERE actif=1 ORDER BY ordre")->fetchAll();}catch(Exception $e){}

$typeCfg=['SURVEILLANCE'=>'Surveillance','ECOUTE'=>'Écoute','INFILTRATION'=>'Infiltration','INFORMATEUR'=>'Informateur','ANALYSE_COMPORTEMENTALE'=>'Analyse Comportementale','TRACKING_GPS'=>'Tracking GPS'];
$menaceCfg=['FAIBLE'=>'b-slate','MOYEN'=>'b-blue','ELEVE'=>'b-amber','CRITIQUE'=>'b-red'];
$statCfg=['EN_COURS'=>'b-amber','SUSPENDUE'=>'b-slate','TERMINEE'=>'b-green','CLASSEE'=>'b-slate'];

include __DIR__ . '/includes/header.php';
?>
<?php if($message):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $message;?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
<?php foreach([['En Cours',$statsI['EN_COURS']??0,'var(--amber-l)'],['Terminées',$statsI['TERMINEE']??0,'var(--green-l)'],['Classées',$statsI['CLASSEE']??0,'var(--txt-m)'],['Total',array_sum($statsI),'var(--blue-xl)']] as [$l,$v,$c]):?>
<div class="kpi" style="--kpi-color:<?php echo $c;?>"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-eye"></i></div><div class="kpi-lbl" style="margin-top:2.1rem"><?php echo $l;?></div><div class="kpi-val"><?php echo (int)$v;?></div></div>
<?php endforeach;?>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Référence, cible…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="type_f" class="form-select" style="width:155px" onchange="this.form.submit()">
      <option value="">Tous types</option>
      <?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?>
    </select>
    <select name="stat" class="form-select" style="width:130px" onchange="this.form.submit()">
      <option value="">Tous statuts</option>
      <?php foreach($statCfg as $k=>$b):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo str_replace('_',' ',$k);?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fType||$fStat):?><a href="intelligence.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="USSS.openModal('newSrvModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle Surveillance</button>
</div>

<div class="tbl-wrap">
<table class="tbl">
<thead><tr><th>Référence</th><th>Type</th><th>Cible</th><th>Menace</th><th>Agent</th><th>Début</th><th>Statut</th></tr></thead>
<tbody>
<?php if(empty($surveillances)):?>
<tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-eye" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucune surveillance</td></tr>
<?php else: foreach($surveillances as $s):$mc=$menaceCfg[$s['niveau_menace']??'MOYEN']??'b-slate';[$sb]=$statCfg[$s['statut']]??['b-slate'];?>
<tr>
  <td><span class="ref"><?php echo htmlspecialchars($s['reference']);?></span></td>
  <td><span class="badge b-slate" style="font-size:.6rem"><?php echo $typeCfg[$s['type']]??$s['type'];?></span></td>
  <td style="font-weight:600;font-size:.84rem"><?php echo htmlspecialchars($s['cible_nom']);?></td>
  <td><span class="badge <?php echo $mc;?>" style="font-size:.6rem"><?php echo $s['niveau_menace']??'—';?></span></td>
  <td style="font-size:.78rem"><?php echo htmlspecialchars($s['nom_rp'].' '.$s['prenom_rp']);?></td>
  <td class="mono" style="font-size:.68rem;color:var(--txt-m)"><?php echo $s['date_debut']?date('d.m.Y',strtotime($s['date_debut'])):'—';?></td>
  <td><span class="badge <?php echo $sb;?>" style="font-size:.6rem"><?php echo str_replace('_',' ',$s['statut']);?></span></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table>
</div>

<?php if($totalPages>1):?><div class="pag"><span class="pag-cur">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
  <?php if($page>1):?><a href="?page=<?php echo $page-1;?>">← Préc.</a><?php endif;?>
  <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>">Suiv. →</a><?php endif;?>
</div><?php endif;?>

<!-- MODAL SURVEILLANCE -->
<div id="newSrvModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-eye"></i> Nouvelle Surveillance</h2><button class="modal-cl" onclick="USSS.closeModal('newSrvModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_surveillance">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type *</label>
      <select name="type" class="form-select" required><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Niveau de menace</label>
      <select name="niveau_menace" class="form-select"><option value="FAIBLE">Faible</option><option value="MOYEN" selected>Moyen</option><option value="ELEVE">Élevé</option><option value="CRITIQUE">Critique</option></select>
    </div>
    <div class="form-group form-full"><label class="form-label">Nom de la cible *</label><input type="text" name="cible_nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Âge</label><input type="text" name="cible_age" class="form-input" placeholder="Ex : 35"></div>
    <div class="form-group"><label class="form-label">Adresse</label><input type="text" name="cible_adresse" class="form-input"></div>
    <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="cible_telephone" class="form-input"></div>
    <div class="form-group"><label class="form-label">Véhicule</label><input type="text" name="cible_vehicule" class="form-input"></div>
    <div class="form-group"><label class="form-label">Division</label>
      <select name="division_id" class="form-select"><option value="">Aucune</option><?php foreach($divisions as $dv):?><option value="<?php echo $dv['id'];?>"><?php echo htmlspecialchars($dv['nom']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Date de début</label><input type="date" name="date_debut" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group"><label class="form-label">Localisation GPS</label><input type="text" name="localisation_gps" class="form-input" placeholder="Lat, Long"></div>
    <div class="form-group form-full"><label class="form-label">Rapport initial</label><textarea name="rapport" class="form-textarea" style="min-height:80px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newSrvModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>