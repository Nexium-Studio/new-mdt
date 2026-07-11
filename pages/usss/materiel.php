<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$companyName = '🇺🇸 United States Secret Service';
$companyColor = '#1e3a8a';
$companyFaction = 'USSS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Gestion du Matériel';
$db = Database::getInstance()->getConnection();
$message = ''; $msgType = '';

// Permissions
$isAdmin = hasRole(['*', 'Administrateur']);

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';
    
    // Ajouter matériel
    if ($action === 'add_materiel') {
        try {
            $db->prepare("
                INSERT INTO usss_materiel 
                (type, nom, numero_serie, description, notes)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $_POST['type'],
                sanitize($_POST['nom']),
                strtoupper(sanitize($_POST['numero_serie'] ?? '')),
                sanitize($_POST['description'] ?? ''),
                sanitize($_POST['notes'] ?? '')
            ]);
            
            $message = "✅ Matériel ajouté à l'inventaire";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Assigner matériel
    if ($action === 'assign_materiel') {
        try {
            $materielId = (int)$_POST['materiel_id'];
            $agentId = (int)$_POST['agent_id'];
            
            $db->prepare("
                UPDATE usss_materiel 
                SET statut = 'ASSIGNE', agent_assigne = ?, date_assignation = CURDATE()
                WHERE id = ?
            ")->execute([$agentId, $materielId]);
            
            $message = "✅ Matériel assigné";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Retourner matériel
    if ($action === 'return_materiel') {
        try {
            $materielId = (int)$_POST['materiel_id'];
            
            $db->prepare("
                UPDATE usss_materiel 
                SET statut = 'DISPONIBLE', agent_assigne = NULL, date_assignation = NULL
                WHERE id = ?
            ")->execute([$materielId]);
            
            $message = "✅ Matériel retourné à l'inventaire";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Mettre en maintenance
    if ($action === 'maintenance_materiel') {
        try {
            $materielId = (int)$_POST['materiel_id'];
            
            $db->prepare("UPDATE usss_materiel SET statut = 'MAINTENANCE' WHERE id = ?")
               ->execute([$materielId]);
            
            $message = "✅ Matériel mis en maintenance";
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
$search = $_GET['search'] ?? '';

// Requête
$sql = "
    SELECT 
        m.*,
        a.nom_rp as agent_nom,
        a.prenom_rp as agent_prenom,
        a.badge_number as agent_badge
    FROM usss_materiel m
    LEFT JOIN usss_agents a ON m.agent_assigne = a.id
    WHERE 1=1
";

if ($filterType) $sql .= " AND m.type = " . $db->quote($filterType);
if ($filterStatut) $sql .= " AND m.statut = " . $db->quote($filterStatut);
if ($search) $sql .= " AND (m.nom LIKE " . $db->quote("%$search%") . " OR m.numero_serie LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY m.type, m.nom";

$materiels = $db->query($sql)->fetchAll();

// Agents
$agents = $db->query("SELECT id, nom_rp, prenom_rp, badge_number FROM usss_agents WHERE statut = 'EN_SERVICE' ORDER BY nom_rp")->fetchAll();

// Stats
$stats = [
    'total' => count($materiels),
    'disponible' => count(array_filter($materiels, fn($m) => $m['statut'] === 'DISPONIBLE')),
    'assigne' => count(array_filter($materiels, fn($m) => $m['statut'] === 'ASSIGNE')),
    'maintenance' => count(array_filter($materiels, fn($m) => $m['statut'] === 'MAINTENANCE')),
];

$typeColors = [
    'ARME' => '#dc2626',
    'VEHICULE' => '#3b82f6',
    'EQUIPEMENT_TACTIQUE' => '#f59e0b',
    'ELECTRONIQUE' => '#8b5cf6'
];

$typeIcons = [
    'ARME' => 'fa-gun',
    'VEHICULE' => 'fa-car',
    'EQUIPEMENT_TACTIQUE' => 'fa-vest',
    'ELECTRONIQUE' => 'fa-laptop'
];

$statutColors = [
    'DISPONIBLE' => '#22c55e',
    'ASSIGNE' => '#3b82f6',
    'MAINTENANCE' => '#f59e0b',
    'RETIRE' => '#64748b'
];

if (isset($_GET['msg'])) { $message=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }
$fType=$_GET['type_f']??''; $fStat=$_GET['stat']??''; $search=$_GET['q']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=25; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(m.nom LIKE ? OR m.numero_serie LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($fType){$where[]="m.type=?";$params[]=$fType;}
if($fStat){$where[]="m.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM usss_materiel m $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$materiel=[];
try{$s=$db->prepare("SELECT m.*,a.nom_rp,a.prenom_rp FROM usss_materiel m LEFT JOIN usss_agents a ON m.assigne_a=a.id $wStr ORDER BY m.type,m.nom LIMIT $perPage OFFSET $offset");$s->execute($params);$materiel=$s->fetchAll();}catch(Exception $e){}
$statsMat=[];try{foreach($db->query("SELECT type,COUNT(*) n FROM usss_materiel GROUP BY type")->fetchAll() as $r)$statsMat[$r['type']]=$r['n'];}catch(Exception $e){}

$typeCfg=['ARME'=>['Arme','fa-gun','var(--red-l)'],'VEHICULE'=>['Véhicule','fa-car','var(--blue-xl)'],'EQUIPEMENT_TACTIQUE'=>['Équipement Tactique','fa-helmet-safety','var(--amber-l)'],'ELECTRONIQUE'=>['Électronique','fa-laptop','var(--blue-xl)'],'COMMUNICATION'=>['Communication','fa-radio','var(--green-l)'],'AUTRE'=>['Autre','fa-box','var(--txt-s)']];
$statCfg=['DISPONIBLE'=>'b-green','ASSIGNE'=>'b-blue','EN_MAINTENANCE'=>'b-amber','HORS_SERVICE'=>'b-red','PERDU'=>'b-slate'];

include __DIR__ . '/includes/header.php';
?>
<?php if($message):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $message;?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(6,1fr);margin-bottom:1.25rem">
<?php foreach($typeCfg as $k=>[$l,$i,$c]):?>
<div class="kpi" style="--kpi-color:<?php echo $c;?>"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas <?php echo $i;?>"></i></div><div class="kpi-lbl" style="margin-top:2.1rem"><?php echo $l;?></div><div class="kpi-val"><?php echo $statsMat[$k]??0;?></div></div>
<?php endforeach;?>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Nom, n° de série…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="type_f" class="form-select" style="width:160px" onchange="this.form.submit()"><option value="">Tous types</option><?php foreach($typeCfg as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
    <select name="stat" class="form-select" style="width:140px" onchange="this.form.submit()"><option value="">Tous statuts</option><?php foreach(array_keys($statCfg) as $s):?><option value="<?php echo $s;?>" <?php echo $fStat===$s?'selected':'';?>><?php echo str_replace('_',' ',$s);?></option><?php endforeach;?></select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fType||$fStat):?><a href="materiel.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <?php if($isAdmin):?><button onclick="USSS.openModal('newMatModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Ajouter</button><?php endif;?>
</div>

<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Type</th><th>Nom</th><th>N° Série</th><th>Statut</th><th>Assigné à</th></tr></thead>
<tbody>
<?php if(empty($materiel)):?><tr><td colspan="5" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-boxes" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun matériel</td></tr>
<?php else: foreach($materiel as $m):[$tl,$ti,$tc]=$typeCfg[$m['type']]??['Autre','fa-box','var(--txt-s)'];[$sb]=$statCfg[$m['statut']]??['b-slate'];?>
<tr>
  <td><div style="display:flex;align-items:center;gap:.5rem"><i class="fas <?php echo $ti;?>" style="color:<?php echo $tc;?>;font-size:.75rem;width:14px"></i><span style="font-size:.78rem"><?php echo $tl;?></span></div></td>
  <td style="font-weight:600;font-size:.84rem"><?php echo htmlspecialchars($m['nom']);?></td>
  <td><span class="ref" style="font-size:.7rem"><?php echo $m['numero_serie']?htmlspecialchars($m['numero_serie']):'—';?></span></td>
  <td><span class="badge <?php echo $sb;?>" style="font-size:.6rem"><?php echo str_replace('_',' ',$m['statut']);?></span></td>
  <td style="font-size:.78rem"><?php echo $m['nom_rp']?htmlspecialchars($m['nom_rp'].' '.$m['prenom_rp']):'<span style="color:var(--txt-m)">Non assigné</span>';?></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>

<?php if($totalPages>1):?><div class="pag"><span class="pag-cur">Page <?php echo $page;?>/<?php echo $totalPages;?></span><?php if($page>1):?><a href="?page=<?php echo $page-1;?>">← Préc.</a><?php endif;?><?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>">Suiv. →</a><?php endif;?></div><?php endif;?>

<?php if($isAdmin):?>
<div id="newMatModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-boxes"></i> Ajouter du Matériel</h2><button class="modal-cl" onclick="USSS.closeModal('newMatModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_materiel">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-select" required><?php foreach($typeCfg as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">N° de série</label><input type="text" name="numero_serie" class="form-input" style="text-transform:uppercase"></div>
    <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea" style="min-height:60px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newMatModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Ajouter</button></div>
</form></div></div>
<?php endif;?>
<?php include __DIR__ . '/includes/footer.php'; ?>