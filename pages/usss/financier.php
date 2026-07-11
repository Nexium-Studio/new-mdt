<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$companyName = '🇺🇸 United States Secret Service';
$companyColor = '#1e3a8a';
$companyFaction = 'USSS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Crimes Financiers';
$db = Database::getInstance()->getConnection();
$message = ''; $msgType = '';

// Générer référence
function generateRefFinancier($db, $type) {
    $year = date('Y');
    $prefix = match($type) {
        'BLANCHIMENT' => 'AML',
        'FAUX_BILLETS' => 'CTF',
        'FRAUDE_BANCAIRE' => 'BFR',
        'ESCROQUERIE' => 'FRD',
        'CRYPTO_MONNAIE' => 'CRY',
        default => 'FIN'
    };
    $seq = $db->query("SELECT COUNT(*) + 1 FROM usss_crimes_financiers WHERE YEAR(created_at) = $year AND type = '$type'")->fetchColumn();
    return sprintf("%s-%d-%05d", $prefix, $year, $seq);
}

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Créer crime financier
    if ($action === 'create_crime') {
        try {
            $db->beginTransaction();
            
            $type = $_POST['type'];
            $reference = generateRefFinancier($db, $type);
            $agentId = $db->query("SELECT id FROM usss_agents WHERE user_id = " . $_SESSION['user_id'])->fetchColumn();
            
            $comptesImpliques = [];
            if (!empty($_POST['compte_iban'])) {
                foreach ($_POST['compte_iban'] as $index => $iban) {
                    if (!empty($iban)) {
                        $comptesImpliques[] = [
                            'iban' => sanitize($iban),
                            'banque' => sanitize($_POST['compte_banque'][$index] ?? ''),
                            'titulaire' => sanitize($_POST['compte_titulaire'][$index] ?? '')
                        ];
                    }
                }
            }
            
            $db->prepare("
                INSERT INTO usss_crimes_financiers 
                (reference, dossier_id, type, suspect_nom, montant_estime, comptes_impliques, 
                 agent_enqueteur, rapport)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $reference,
                !empty($_POST['dossier_id']) ? (int)$_POST['dossier_id'] : null,
                $type,
                sanitize($_POST['suspect_nom']),
                !empty($_POST['montant_estime']) ? (float)$_POST['montant_estime'] : 0,
                !empty($comptesImpliques) ? json_encode($comptesImpliques) : null,
                $agentId,
                sanitize($_POST['rapport'] ?? '')
            ]);
            
            $db->commit();
            $message = "✅ Crime financier créé - Référence: $reference";
            $msgType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Geler compte
    if ($action === 'geler_compte') {
        try {
            $crimeId = (int)$_POST['crime_id'];
            $crime = $db->query("SELECT comptes_geles FROM usss_crimes_financiers WHERE id = $crimeId")->fetch();
            
            $comptesGeles = $crime['comptes_geles'] ? json_decode($crime['comptes_geles'], true) : [];
            $comptesGeles[] = [
                'iban' => sanitize($_POST['iban']),
                'banque' => sanitize($_POST['banque']),
                'montant' => (float)$_POST['montant'],
                'date_gel' => date('Y-m-d H:i:s'),
                'agent' => $_SESSION['username']
            ];
            
            $db->prepare("UPDATE usss_crimes_financiers SET comptes_geles = ?, statut = 'GELE' WHERE id = ?")
               ->execute([json_encode($comptesGeles), $crimeId]);
            
            $message = "✅ Compte gelé";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Ajouter transaction suspecte
    if ($action === 'add_transaction') {
        try {
            $crimeId = (int)$_POST['crime_id'];
            $crime = $db->query("SELECT transactions_suspectes FROM usss_crimes_financiers WHERE id = $crimeId")->fetch();
            
            $transactions = $crime['transactions_suspectes'] ? json_decode($crime['transactions_suspectes'], true) : [];
            $transactions[] = [
                'date' => $_POST['transaction_date'],
                'montant' => (float)$_POST['transaction_montant'],
                'beneficiaire' => sanitize($_POST['transaction_beneficiaire']),
                'description' => sanitize($_POST['transaction_description']),
                'ajout_le' => date('Y-m-d H:i:s')
            ];
            
            $db->prepare("UPDATE usss_crimes_financiers SET transactions_suspectes = ? WHERE id = ?")
               ->execute([json_encode($transactions), $crimeId]);
            
            $message = "✅ Transaction ajoutée";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Changer statut
    if ($action === 'change_statut') {
        try {
            $crimeId = (int)$_POST['crime_id'];
            $db->prepare("UPDATE usss_crimes_financiers SET statut = ? WHERE id = ?")
               ->execute([$_POST['nouveau_statut'], $crimeId]);
            
            $message = "✅ Statut modifié";
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
        c.*,
        a.nom_rp as agent_nom,
        a.prenom_rp as agent_prenom,
        a.badge_number as agent_badge,
        d.reference as dossier_reference,
        d.titre as dossier_titre
    FROM usss_crimes_financiers c
    JOIN usss_agents a ON c.agent_enqueteur = a.id
    LEFT JOIN usss_dossiers d ON c.dossier_id = d.id
    WHERE 1=1
";

if ($filterType) $sql .= " AND c.type = " . $db->quote($filterType);
if ($filterStatut) $sql .= " AND c.statut = " . $db->quote($filterStatut);
if ($search) $sql .= " AND (c.reference LIKE " . $db->quote("%$search%") . " OR c.suspect_nom LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY c.montant_estime DESC, c.created_at DESC";

$crimes = $db->query($sql)->fetchAll();

// Dossiers
$dossiers = $db->query("SELECT id, reference, titre FROM usss_dossiers WHERE statut IN ('OUVERT', 'EN_COURS') ORDER BY created_at DESC")->fetchAll();

// Stats
$stats = [
    'total' => count($crimes),
    'enquete' => count(array_filter($crimes, fn($c) => $c['statut'] === 'ENQUETE')),
    'gele' => count(array_filter($crimes, fn($c) => $c['statut'] === 'GELE')),
    'montant_total' => array_sum(array_column($crimes, 'montant_estime')),
];

$typeColors = [
    'BLANCHIMENT' => '#dc2626',
    'FAUX_BILLETS' => '#f59e0b',
    'FRAUDE_BANCAIRE' => '#8b5cf6',
    'ESCROQUERIE' => '#ef4444',
    'CRYPTO_MONNAIE' => '#3b82f6'
];

$statutColors = [
    'ENQUETE' => '#f59e0b',
    'GELE' => '#3b82f6',
    'SAISI' => '#22c55e',
    'TRANSMIS_DOJ' => '#8b5cf6',
    'CLASSE' => '#64748b'
];

if (isset($_GET['msg'])) { $message=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

$fType=$_GET['type_f']??''; $fStat=$_GET['stat']??''; $search=$_GET['q']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(cf.reference LIKE ? OR cf.suspect_nom LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($fType){$where[]="cf.type=?";$params[]=$fType;}
if($fStat){$where[]="cf.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM usss_crimes_financiers cf $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$crimes=[];
try{$s=$db->prepare("SELECT cf.*,a.nom_rp,a.prenom_rp,a.badge_number FROM usss_crimes_financiers cf JOIN usss_agents a ON cf.agent_responsable=a.id $wStr ORDER BY cf.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$crimes=$s->fetchAll();}catch(Exception $e){}
$statsF=[];try{foreach($db->query("SELECT statut,COUNT(*) n,SUM(montant_estime) m FROM usss_crimes_financiers GROUP BY statut")->fetchAll() as $r)$statsF[$r['statut']]=[$r['n'],$r['m']];}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,nom_rp,prenom_rp,badge_number FROM usss_agents WHERE statut='EN_SERVICE' ORDER BY nom_rp")->fetchAll();}catch(Exception $e){}

$typeCfg=['BLANCHIMENT'=>'Blanchiment','FAUX_BILLETS'=>'Faux Billets','FRAUDE_BANCAIRE'=>'Fraude Bancaire','ESCROQUERIE'=>'Escroquerie','CRYPTO_MONNAIE'=>'Crypto-monnaie'];
$statCfg=['OUVERT'=>'b-blue','EN_ENQUETE'=>'b-amber','TRANSMIS_DOJ'=>'b-purple','CLOS'=>'b-green','CLASSE'=>'b-slate'];

include __DIR__ . '/includes/header.php';
?>
<?php if($message):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $message;?></div><?php endif;?>
<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
<?php $totOpen=$statsF['OUVERT'][1]??0+($statsF['EN_ENQUETE'][1]??0);?>
<div class="kpi" style="--kpi-color:var(--blue-xl)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-folder-open"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Ouverts</div><div class="kpi-val"><?php echo (int)($statsF['OUVERT'][0]??0);?></div></div>
<div class="kpi" style="--kpi-color:var(--amber-l)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-search"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">En Enquête</div><div class="kpi-val"><?php echo (int)($statsF['EN_ENQUETE'][0]??0);?></div></div>
<div class="kpi" style="--kpi-color:var(--green-l)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-check"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Clos</div><div class="kpi-val"><?php echo (int)($statsF['CLOS'][0]??0);?></div></div>
<div class="kpi" style="--kpi-color:var(--gold-l)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-coins"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Montant Total</div><div class="kpi-val" style="font-size:1.1rem">$<?php echo number_format(array_sum(array_column($statsF,1)),0);?></div></div>
</div>
<div style="display:flex;gap:.65rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="form-input" placeholder="Référence, suspect…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:150px">
    <select name="type_f" class="form-select" style="width:150px" onchange="this.form.submit()"><option value="">Tous types</option><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select>
    <select name="stat" class="form-select" style="width:130px" onchange="this.form.submit()"><option value="">Tous statuts</option><?php foreach(array_keys($statCfg) as $s):?><option value="<?php echo $s;?>" <?php echo $fStat===$s?'selected':'';?>><?php echo str_replace('_',' ',$s);?></option><?php endforeach;?></select>
    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
    <?php if($search||$fType||$fStat):?><a href="financier.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
  </form>
  <button onclick="USSS.openModal('newCrimeModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouveau Dossier</button>
</div>
<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Référence</th><th>Type</th><th>Suspect</th><th>Montant</th><th>Agent</th><th>Statut</th><th>Date</th></tr></thead>
<tbody>
<?php if(empty($crimes)):?><tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-money-bill-wave" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun dossier financier</td></tr>
<?php else: foreach($crimes as $c):[$sb]=$statCfg[$c['statut']]??['b-slate'];?>
<tr>
  <td><span class="ref"><?php echo htmlspecialchars($c['reference']);?></span></td>
  <td><span class="badge b-slate" style="font-size:.6rem"><?php echo $typeCfg[$c['type']]??$c['type'];?></span></td>
  <td style="font-weight:600;font-size:.84rem"><?php echo htmlspecialchars($c['suspect_nom']);?></td>
  <td class="mono" style="font-size:.78rem;color:var(--gold-l)">$<?php echo number_format($c['montant_estime']??0,0);?></td>
  <td style="font-size:.75rem"><?php echo htmlspecialchars($c['nom_rp'].' '.$c['prenom_rp']);?></td>
  <td><span class="badge <?php echo $sb;?>" style="font-size:.6rem"><?php echo str_replace('_',' ',$c['statut']);?></span></td>
  <td class="mono" style="font-size:.68rem;color:var(--txt-m)"><?php echo date('d.m.Y',strtotime($c['created_at']));?></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>
<?php if($totalPages>1):?><div class="pag"><span class="pag-cur">Page <?php echo $page;?>/<?php echo $totalPages;?></span><?php if($page>1):?><a href="?page=<?php echo $page-1;?>">← Préc.</a><?php endif;?><?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>">Suiv. →</a><?php endif;?></div><?php endif;?>

<!-- MODAL CRÉER CRIME FINANCIER -->
<div id="newCrimeModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-money-bill-wave"></i> Nouveau Dossier Financier</h2><button class="modal-cl" onclick="USSS.closeModal('newCrimeModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_crime">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-select" required><?php foreach($typeCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">Nom du suspect *</label><input type="text" name="suspect_nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Montant estimé ($)</label><input type="number" name="montant_estime" class="form-input" min="0" step="100" value="0"></div>
    <div class="form-group"><label class="form-label">Date de découverte</label><input type="date" name="date_decouverte" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
    <div class="form-group form-full"><label class="form-label">Description *</label><textarea name="description" class="form-textarea" required style="min-height:80px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newCrimeModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>