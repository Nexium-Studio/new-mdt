<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🏠 Dynasty';
$companyColor   = '#22c55e';
$companyFaction = 'DYNASTY';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Contrats';
$pageSubtitle = 'Archives BV / BL';
$db        = Database::getInstance()->getConnection();

/* ── Filtres ── */
$fType   = $_GET['type']   ?? 'all';   // BV / BL / all
$fStatut = $_GET['statut'] ?? 'all';
$fAgent  = $_GET['agent']  ?? 'all';
$fMois   = $_GET['mois']   ?? 'all';
$search  = trim($_GET['q'] ?? '');

/* ── Stats globales ── */
$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM dynasty_locations) nb_bl_total,
        (SELECT COUNT(*) FROM dynasty_locations WHERE statut='actif') nb_bl_actif,
        (SELECT COALESCE(SUM(montant_loyer),0) FROM dynasty_locations WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())) ca_bl_mois,
        (SELECT COUNT(*) FROM dynasty_ventes) nb_bv_total,
        (SELECT COUNT(*) FROM dynasty_ventes WHERE statut IN('signe','acte_final')) nb_bv_final,
        (SELECT COALESCE(SUM(prix_negocie),0) FROM dynasty_ventes WHERE statut IN('signe','acte_final') AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())) ca_bv_mois,
        (SELECT COALESCE(SUM(commission_amt),0) FROM dynasty_locations) + (SELECT COALESCE(SUM(commission_amt),0) FROM dynasty_ventes) comm_totale
")->fetch(PDO::FETCH_ASSOC);

/* ── Agents ── */
$agents = $db->query("
    SELECT u.id, u.username,
           COALESCE(NULLIF(u.faction_rank,''), 'Agent') AS rank,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE u.faction='DYNASTY' AND u.is_active=1
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Récupérer contrats (UNION BL + BV) ── */
$blWhere  = ['1=1']; $blParams  = [];
$bvWhere  = ['1=1']; $bvParams  = [];

if ($fAgent !== 'all') {
    $blWhere[] = 'l.agent_id=?'; $blParams[] = $fAgent;
    $bvWhere[] = 'v.agent_id=?'; $bvParams[] = $fAgent;
}
if ($search) {
    $blWhere[] = '(l.reference LIKE ? OR l.locataire_nom LIKE ? OR b.titre LIKE ?)';
    $blParams  = array_merge($blParams,["%$search%","%$search%","%$search%"]);
    $bvWhere[] = '(v.reference LIKE ? OR v.acheteur_nom LIKE ? OR b.titre LIKE ?)';
    $bvParams  = array_merge($bvParams,["%$search%","%$search%","%$search%"]);
}
if ($fMois !== 'all') {
    $blWhere[] = 'DATE_FORMAT(l.created_at,"%Y-%m")=?'; $blParams[] = $fMois;
    $bvWhere[] = 'DATE_FORMAT(v.created_at,"%Y-%m")=?'; $bvParams[] = $fMois;
}
if ($fStatut !== 'all') {
    $blWhere[] = 'l.statut=?'; $blParams[] = $fStatut;
    $bvWhere[] = 'v.statut=?'; $bvParams[] = $fStatut;
}

$bl_query = "
    SELECT 'BL' type_doc, l.id, l.reference, l.locataire_nom client, l.montant_loyer montant,
           l.commission_amt, l.statut, l.created_at, l.date_debut, l.date_fin,
           b.titre instance_titre, b.quartier bien_quartier, u.username agent_nom,
           NULL prix_initial, NULL remise, l.type_bail mode_extra
    FROM dynasty_locations l
    JOIN dynasty_biens b ON l.bien_id=b.id
    JOIN users u ON l.agent_id=u.id
    WHERE ".implode(' AND ',$blWhere);

$bv_query = "
    SELECT 'BV' type_doc, v.id, v.reference, v.acheteur_nom client, v.prix_negocie montant,
           v.commission_amt, v.statut, v.created_at, v.date_vente date_debut, v.date_vente date_fin,
           b.titre instance_titre, b.quartier bien_quartier, u.username agent_nom,
           v.prix_initial, v.remise, v.mode_paiement mode_extra
    FROM dynasty_ventes v
    JOIN dynasty_biens b ON v.bien_id=b.id
    JOIN users u ON v.agent_id=u.id
    WHERE ".implode(' AND ',$bvWhere);

if ($fType === 'BL') {
    $stmt = $db->prepare($bl_query." ORDER BY created_at DESC LIMIT 300");
    $stmt->execute($blParams);
} elseif ($fType === 'BV') {
    $stmt = $db->prepare($bv_query." ORDER BY created_at DESC LIMIT 300");
    $stmt->execute($bvParams);
} else {
    // UNION — on doit combiner les params
    $stmt = $db->prepare("($bl_query) UNION ALL ($bv_query) ORDER BY created_at DESC LIMIT 300");
    $stmt->execute(array_merge($blParams,$bvParams));
}
$contrats = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Mois disponibles pour filtre ── */
$mois_bl = $db->query("SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') m FROM dynasty_locations ORDER BY m DESC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN);
$mois_bv = $db->query("SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') m FROM dynasty_ventes ORDER BY m DESC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN);
$mois_all = array_unique(array_merge($mois_bl,$mois_bv));
rsort($mois_all);

include __DIR__ . '/includes/header.php';
?>

<!-- Header page -->
<div class="page-header">
    <div>
        <div class="page-header-title"><i class="fas fa-file-signature"></i> Contrats BV / BL</div>
        <div class="page-header-sub"><?php echo count($contrats); ?> contrat<?php echo count($contrats)>1?'s':''; ?> affiché<?php echo count($contrats)>1?'s':''; ?> · Bon de Location et Bon de Vente</div>
    </div>
    <div class="page-header-actions">
        <a href="locations.php?open=new" class="btn btn-secondary"><i class="fas fa-key"></i> Nouveau BL</a>
        <a href="ventes.php?open=new"    class="btn btn-secondary"><i class="fas fa-handshake"></i> Nouveau BV</a>
    </div>
</div>

<!-- Stats -->
<div class="dy-stats">
    <div class="dy-stat">
        <div class="dy-stat-ico" style="background:linear-gradient(135deg,#3b82f6,#2563eb)"><i class="fas fa-key"></i></div>
        <div>
            <div class="dy-stat-val"><?php echo $stats['nb_bl_total']; ?></div>
            <div class="dy-stat-lbl">Total BL</div>
            <div class="dy-stat-sub"><?php echo $stats['nb_bl_actif']; ?> actif(s) · $<?php echo number_format($stats['ca_bl_mois'],0); ?> ce mois</div>
        </div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-handshake"></i></div>
        <div>
            <div class="dy-stat-val"><?php echo $stats['nb_bv_total']; ?></div>
            <div class="dy-stat-lbl">Total BV</div>
            <div class="dy-stat-sub"><?php echo $stats['nb_bv_final']; ?> signés · $<?php echo number_format($stats['ca_bv_mois'],0); ?> ce mois</div>
        </div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico" style="background:linear-gradient(135deg,#22c55e,#15803d)"><i class="fas fa-dollar-sign"></i></div>
        <div>
            <div class="dy-stat-val">$<?php echo number_format(($stats['ca_bl_mois']+$stats['ca_bv_mois']),0); ?></div>
            <div class="dy-stat-lbl">CA ce mois</div>
            <div class="dy-stat-sub">BL + BV combinés</div>
        </div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)"><i class="fas fa-percentage"></i></div>
        <div>
            <div class="dy-stat-val">$<?php echo number_format($stats['comm_totale'],0); ?></div>
            <div class="dy-stat-lbl">Commissions totales</div>
            <div class="dy-stat-sub">Tous contrats confondus</div>
        </div>
    </div>
</div>

<!-- Filtres -->
<form method="GET">
<div class="filters-bar">
    <div class="filter-group" style="flex:2;">
        <label class="filter-lbl">Recherche</label>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-input" placeholder="Référence, client, bien...">
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Type</label>
        <select name="type" class="form-select">
            <option value="all">BV + BL</option>
            <option value="BL" <?php echo $fType==='BL'?'selected':''; ?>>BL — Locations</option>
            <option value="BV" <?php echo $fType==='BV'?'selected':''; ?>>BV — Ventes</option>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Statut</label>
        <select name="statut" class="form-select">
            <option value="all">Tous</option>
            <!-- BL -->
            <?php if($fType!=='BV'): ?>
            <optgroup label="Location">
                <option value="actif"   <?php echo $fStatut==='actif'?'selected':''; ?>>Actif</option>
                <option value="termine" <?php echo $fStatut==='termine'?'selected':''; ?>>Terminé</option>
                <option value="resilie" <?php echo $fStatut==='resilie'?'selected':''; ?>>Résilié</option>
                <option value="impaye"  <?php echo $fStatut==='impaye'?'selected':''; ?>>Impayé</option>
            </optgroup>
            <?php endif; ?>
            <?php if($fType!=='BL'): ?>
            <optgroup label="Vente">
                <option value="en_cours"  <?php echo $fStatut==='en_cours'?'selected':''; ?>>En cours</option>
                <option value="signe"     <?php echo $fStatut==='signe'?'selected':''; ?>>Signé</option>
                <option value="acte_final"<?php echo $fStatut==='acte_final'?'selected':''; ?>>Acte Final</option>
                <option value="annule"    <?php echo $fStatut==='annule'?'selected':''; ?>>Annulé</option>
            </optgroup>
            <?php endif; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Agent</label>
        <select name="agent" class="form-select">
            <option value="all">Tous les agents</option>
            <?php foreach($agents as $a): ?><option value="<?php echo $a['id']; ?>" <?php echo $fAgent==$a['id']?'selected':''; ?>><?php echo htmlspecialchars($a['username']); ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Période</label>
        <select name="mois" class="form-select">
            <option value="all">Toutes les périodes</option>
            <?php foreach($mois_all as $m): ?>
            <option value="<?php echo $m; ?>" <?php echo $fMois===$m?'selected':''; ?>><?php echo date('F Y',strtotime($m.'-01')); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;"><i class="fas fa-search"></i></button>
    <?php if($search||$fType!=='all'||$fStatut!=='all'||$fAgent!=='all'||$fMois!=='all'): ?>
    <a href="contrats.php" class="btn btn-secondary btn-sm" style="align-self:flex-end;"><i class="fas fa-times"></i></a>
    <?php endif; ?>
</div>
</form>

<!-- Table contrats -->
<?php if(empty($contrats)): ?>
<div class="card">
    <div class="empty-state">
        <i class="fas fa-file-contract"></i>
        <p>Aucun contrat ne correspond à vos critères.</p>
        <div style="display:flex;gap:.75rem;justify-content:center;">
            <a href="locations.php?open=new" class="btn btn-secondary"><i class="fas fa-key"></i> Nouveau BL</a>
            <a href="ventes.php?open=new"    class="btn btn-secondary" style="color:#f59e0b;"><i class="fas fa-handshake"></i> Nouveau BV</a>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden;">
<table class="dy-table">
    <thead>
        <tr>
            <th>Type</th>
            <th>Référence</th>
            <th>Bien</th>
            <th>Client</th>
            <th>Montant</th>
            <th>Commission</th>
            <th>Date</th>
            <th>Détail</th>
            <th>Agent</th>
            <th>Statut</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $typeBailShort = ['semaine'=>'sem.','mois'=>'mois','trimestre'=>'trim.','annee'=>'an(s)'];
    $modeShort     = ['especes'=>'Espèces','virement'=>'Virement','cheque'=>'Chèque','financement'=>'Financement'];
    foreach($contrats as $c):
        $isBL = $c['type_doc'] === 'BL';
        $href = $isBL ? "locations.php?view={$c['id']}" : "ventes.php?view={$c['id']}";
    ?>
    <tr>
        <!-- Type -->
        <td>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <div style="width:30px;height:30px;border-radius:8px;background:<?php echo $isBL?'linear-gradient(135deg,#3b82f6,#2563eb)':'linear-gradient(135deg,#f59e0b,#d97706)'; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?php echo $isBL?'fa-key':'fa-handshake'; ?>" style="color:#fff;font-size:.72rem;"></i>
                </div>
                <span style="font-family:'JetBrains Mono',monospace;font-size:.72rem;font-weight:800;color:<?php echo $isBL?'#60a5fa':'#f59e0b'; ?>;"><?php echo $c['type_doc']; ?></span>
            </div>
        </td>

        <!-- Référence -->
        <td>
            <a href="<?php echo $href; ?>" style="font-family:'JetBrains Mono',monospace;font-size:.78rem;font-weight:700;color:<?php echo $isBL?'#60a5fa':'#f59e0b'; ?>;text-decoration:none;">
                <?php echo htmlspecialchars($c['reference']); ?>
            </a>
        </td>

        <!-- Bien -->
        <td>
            <div style="font-weight:600;font-size:.84rem;color:var(--tx);"><?php echo htmlspecialchars($c['instance_titre']); ?></div>
            <?php if($c['bien_quartier']): ?><div style="font-size:.7rem;color:var(--tx2);"><?php echo htmlspecialchars($c['bien_quartier']); ?></div><?php endif; ?>
        </td>

        <!-- Client -->
        <td style="font-weight:600;font-size:.84rem;"><?php echo htmlspecialchars($c['client']); ?></td>

        <!-- Montant -->
        <td style="font-weight:700;font-family:'Orbitron',monospace;font-size:.82rem;color:<?php echo $isBL?'#22c55e':'#f59e0b'; ?>;">
            $<?php echo number_format($c['montant'],0); ?>
        </td>

        <!-- Commission -->
        <td style="font-size:.82rem;color:#22c55e;">$<?php echo number_format($c['commission_amt'],0); ?></td>

        <!-- Date -->
        <td style="font-size:.78rem;color:var(--tx2);">
            <?php echo date('d/m/Y',strtotime($c['date_debut'])); ?>
            <?php if($isBL && $c['date_debut'] !== $c['date_fin']): ?>
            <div style="font-size:.68rem;">au <?php echo date('d/m/Y',strtotime($c['date_fin'])); ?></div>
            <?php endif; ?>
        </td>

        <!-- Détail spécifique -->
        <td style="font-size:.75rem;color:var(--tx2);">
            <?php if($isBL): ?>
                <span style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.18);color:#60a5fa;padding:.15rem .42rem;border-radius:5px;font-size:.68rem;"><?php echo ucfirst($c['mode_extra']); ?></span>
            <?php else: ?>
                <?php if($c['remise']>0): ?><span style="color:#ef4444;">-$<?php echo number_format($c['remise'],0); ?></span> · <?php endif; ?>
                <?php echo $modeShort[$c['mode_extra']]??$c['mode_extra']; ?>
            <?php endif; ?>
        </td>

        <!-- Agent -->
        <td style="font-size:.82rem;"><?php echo htmlspecialchars($c['agent_nom']); ?></td>

        <!-- Statut -->
        <td><span class="badge badge-<?php echo $c['statut']; ?>"><?php echo str_replace('_',' ',ucfirst($c['statut'])); ?></span></td>

        <!-- Actions -->
        <td>
            <a href="<?php echo $href; ?>" class="btn btn-secondary btn-sm btn-icon" title="Voir contrat"><i class="fas fa-eye"></i></a>
            <a href="<?php echo $href; ?>" target="_blank" class="btn btn-secondary btn-sm btn-icon" title="Ouvrir dans nouvel onglet" style="margin-left:.25rem;"><i class="fas fa-external-link-alt"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Totaux -->
<?php
$totalMontant = array_sum(array_column($contrats,'montant'));
$totalComm    = array_sum(array_column($contrats,'commission_amt'));
$nbBL = count(array_filter($contrats,fn($c)=>$c['type_doc']==='BL'));
$nbBV = count(array_filter($contrats,fn($c)=>$c['type_doc']==='BV'));
?>
<div style="display:flex;justify-content:flex-end;gap:1.5rem;padding:1rem 1.35rem;background:rgba(34,197,94,.02);border-top:1px solid var(--bd);font-size:.82rem;flex-wrap:wrap;">
    <span style="color:var(--tx2);"><?php echo $nbBL; ?> BL · <?php echo $nbBV; ?> BV</span>
    <span>Commissions totales : <strong style="color:#22c55e;">$<?php echo number_format($totalComm,2); ?></strong></span>
    <span>Volume total : <strong style="color:#22c55e;">$<?php echo number_format($totalMontant,2); ?></strong></span>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>