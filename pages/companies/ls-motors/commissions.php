<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = 'LS Motors';
$companyColor = '#3b82f6';
$companyFaction = 'LS_MOTORS';
$dealershipKey = 'LS_MOTORS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Commissions Vendeurs';
$db = Database::getInstance()->getConnection();
$dk = $db->quote($dealershipKey);
$message = ''; $msgType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'info_vente.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'info_vente.edit_coeff');

// Période
$mois = $_GET['mois'] ?? date('Y-m');
$vendeurFilter = $_GET['vendeur'] ?? '';

// Stats globales du mois (concession)
$statsGlobales = $db->query("
    SELECT 
        COUNT(*) as nb_ventes,
        SUM(v.prix_final) as revenus_total,
        SUM(v.commission_vendeur) as commissions_total
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id AND vh.dealership_key = $dk
    WHERE v.statut != 'annulee'
      AND DATE_FORMAT(v.created_at, '%Y-%m') = " . $db->quote($mois) . "
")->fetch();

// Commissions par vendeur
$sql = "
    SELECT 
        u.id as vendeur_id,
        u.username as vendeur_nom,
        COUNT(v.id) as nb_ventes,
        SUM(v.prix_final) as revenus_total,
        SUM(v.commission_vendeur) as commission_total,
        AVG(v.commission_pct) as commission_pct_moyen,
        AVG(v.prix_final) as panier_moyen
    FROM users u
    LEFT JOIN (
        SELECT v.* FROM lsm_ventes v
        INNER JOIN lsm_vehicules vh ON v.vehicule_id = vh.id AND vh.dealership_key = $dk
        WHERE v.statut != 'annulee' AND DATE_FORMAT(v.created_at, '%Y-%m') = '$mois'
    ) v ON u.id = v.vendeur_id
    WHERE u.faction = '$companyFaction'
";

if ($vendeurFilter) {
    $sql .= " AND u.id = " . (int)$vendeurFilter;
}

$sql .= " GROUP BY u.id, u.username ORDER BY commission_total DESC";

$commissions = $db->query($sql)->fetchAll();

// Détail ventes pour un vendeur spécifique
$detailVentes = null;
if ($vendeurFilter) {
    $detailVentes = $db->query("
        SELECT 
            v.*,
            vh.modele as vehicule_modele,
            m.nom as marque_nom,
            c.nom as client_nom
        FROM lsm_ventes v
        JOIN lsm_vehicules vh ON v.vehicule_id = vh.id
        LEFT JOIN lsm_marques m ON vh.marque_id = m.id
        JOIN lsm_clients c ON v.client_id = c.id
        WHERE v.vendeur_id = " . (int)$vendeurFilter . "
          AND v.statut != 'annulee'
          AND vh.dealership_key = $dk
          AND DATE_FORMAT(v.created_at, '%Y-%m') = '$mois'
        ORDER BY v.created_at DESC
    ")->fetchAll();
}

// Top vendeurs all-time
$topVendeurs = $db->query("
    SELECT 
        u.username as vendeur_nom,
        COUNT(v.id) as nb_ventes,
        SUM(v.commission_vendeur) as commission_total
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id AND vh.dealership_key = $dk
    JOIN users u ON v.vendeur_id = u.id
    WHERE v.statut != 'annulee'
    GROUP BY u.id, u.username
    ORDER BY commission_total DESC
    LIMIT 5
")->fetchAll();

// Liste vendeurs pour filtre
$vendeurs = $db->query("SELECT id, username FROM users WHERE faction='$companyFaction' ORDER BY username")->fetchAll();

include __DIR__ . '/includes/header.php';
?>



<?php if ($message): ?>
    <div class="alert alert-<?php echo $msgType; ?>">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Stats globales -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $statsGlobales['nb_ventes'] ?? 0; ?></div>
        <div class="stat-label">Ventes du Mois</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">$<?php echo number_format($statsGlobales['revenus_total'] ?? 0, 0); ?></div>
        <div class="stat-label">Revenus du Mois</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">$<?php echo number_format($statsGlobales['commissions_total'] ?? 0, 2); ?></div>
        <div class="stat-label">Commissions Totales</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo count($commissions); ?></div>
        <div class="stat-label">Vendeurs Actifs</div>
    </div>
</div>

<!-- Filtres -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2><i class="fas fa-dollar-sign"></i> Commissions Vendeurs</h2>
    </div>
    
    <form class="filters-bar" method="GET">
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.5rem;">Mois</label>
            <input type="month" name="mois" class="form-input" value="<?php echo $mois; ?>" style="max-width: 180px;" onchange="this.form.submit()">
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.5rem;">Vendeur</label>
            <select name="vendeur" class="form-select" style="max-width: 200px;" onchange="this.form.submit()">
                <option value="">Tous les vendeurs</option>
                <?php foreach ($vendeurs as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $vendeurFilter == $v['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($v['username']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($vendeurFilter): ?>
            <a href="?mois=<?php echo $mois; ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<?php if ($vendeurFilter && $detailVentes): ?>
    <!-- Détail ventes vendeur -->
    <div class="card">
        <h2 style="margin-bottom: 1.5rem;">
            <i class="fas fa-list"></i> Détail des Ventes - 
            <?php 
            $vendeurNom = array_filter($commissions, fn($c) => $c['vendeur_id'] == $vendeurFilter);
            echo htmlspecialchars(reset($vendeurNom)['vendeur_nom'] ?? 'Vendeur');
            ?>
        </h2>
        
        <?php if (empty($detailVentes)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune vente ce mois</p>
        <?php else: ?>
            <?php foreach ($detailVentes as $vente): ?>
                <div class="vente-row">
                    <div>
                        <div style="font-weight: 700; color: #e8edf5; margin-bottom: 0.25rem;">
                            <?php echo htmlspecialchars($vente['marque_nom']); ?> <?php echo htmlspecialchars($vente['vehicule_modele']); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #64748b;">
                            Client: <?php echo htmlspecialchars($vente['client_nom']); ?> • 
                            <?php echo date('d/m/Y', strtotime($vente['created_at'])); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Prix Vente</div>
                        <div style="font-weight: 700; color: #22c55e;">
                            $<?php echo number_format($vente['prix_final'], 0); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Commission %</div>
                        <div style="font-weight: 700; color: #3b82f6;">
                            <?php echo number_format($vente['commission_pct'], 1); ?>%
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Commission</div>
                        <div style="font-weight: 700; font-size: 1.2rem; color: #f59e0b;">
                            $<?php echo number_format($vente['commission_vendeur'], 2); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Liste commissions par vendeur -->
    <?php if (empty($commissions)): ?>
        <div class="card" style="text-align: center; padding: 4rem;">
            <i class="fas fa-dollar-sign" style="font-size: 5rem; color: #64748b; margin-bottom: 1.5rem;"></i>
            <h3 style="color: #94a3b8;">Aucune commission ce mois</h3>
            <p style="color: #64748b;">Les ventes généreront des commissions</p>
        </div>
    <?php else: ?>
        <?php foreach ($commissions as $index => $commission): 
            $topClass = '';
            if ($index === 0 && $commission['commission_total'] > 0) $topClass = 'top-1';
            elseif ($index === 1 && $commission['commission_total'] > 0) $topClass = 'top-2';
            elseif ($index === 2 && $commission['commission_total'] > 0) $topClass = 'top-3';
        ?>
            <div class="commission-card <?php echo $topClass; ?>">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <h3 style="font-size: 1.5rem; font-weight: 700; color: #e8edf5; margin-bottom: 0.5rem;">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($commission['vendeur_nom']); ?>
                        </h3>
                        
                        <div style="display: flex; gap: 2rem; margin-top: 1rem;">
                            <div>
                                <div style="font-size: 0.75rem; color: #64748b;">Nombre de Ventes</div>
                                <div style="font-size: 1.3rem; font-weight: 700; color: #3b82f6;">
                                    <?php echo $commission['nb_ventes'] ?? 0; ?>
                                </div>
                            </div>
                            
                            <div>
                                <div style="font-size: 0.75rem; color: #64748b;">Revenus Générés</div>
                                <div style="font-size: 1.3rem; font-weight: 700; color: #22c55e;">
                                    $<?php echo number_format($commission['revenus_total'] ?? 0, 0); ?>
                                </div>
                            </div>
                            
                            <div>
                                <div style="font-size: 0.75rem; color: #64748b;">Panier Moyen</div>
                                <div style="font-size: 1.3rem; font-weight: 700; color: #64748b;">
                                    $<?php echo number_format($commission['panier_moyen'] ?? 0, 0); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: right;">
                        <div style="font-size: 0.9rem; color: #64748b; margin-bottom: 0.5rem;">Commission Totale</div>
                        <div style="font-size: 2.5rem; font-weight: 800; color: #f59e0b; font-family: 'Orbitron', monospace;">
                            $<?php echo number_format($commission['commission_total'] ?? 0, 2); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                            Taux moyen: <?php echo number_format($commission['commission_pct_moyen'] ?? 0, 1); ?>%
                        </div>
                    </div>
                </div>
                
                <?php if (($commission['nb_ventes'] ?? 0) > 0): ?>
                    <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
                        <a href="?mois=<?php echo $mois; ?>&vendeur=<?php echo $commission['vendeur_id']; ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-list"></i> Voir le Détail des Ventes
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Top vendeurs all-time -->
<div class="card">
    <h2 style="margin-bottom: 1.5rem;"><i class="fas fa-trophy"></i> Top 5 Vendeurs (Tout Temps)</h2>
    
    <?php if (empty($topVendeurs)): ?>
        <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune donnée</p>
    <?php else: ?>
        <div style="display: grid; gap: 1rem;">
            <?php foreach ($topVendeurs as $index => $vendeur): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem; background: rgba(239, 68, 68, 0.05); border-radius: 12px; border-left: 4px solid <?php echo ['#f59e0b', '#94a3b8', '#d4a574', '#64748b', '#475569'][$index] ?? '#64748b'; ?>;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="font-size: 2rem; font-weight: 800; color: <?php echo ['#f59e0b', '#94a3b8', '#d4a574', '#64748b', '#475569'][$index] ?? '#64748b'; ?>;">
                            #<?php echo $index + 1; ?>
                        </div>
                        <div>
                            <div style="font-size: 1.1rem; font-weight: 700; color: #e8edf5;">
                                <?php echo htmlspecialchars($vendeur['vendeur_nom']); ?>
                            </div>
                            <div style="font-size: 0.85rem; color: #64748b;">
                                <?php echo $vendeur['nb_ventes']; ?> ventes
                            </div>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 1.5rem; font-weight: 700; color: #f59e0b;">
                            $<?php echo number_format($vendeur['commission_total'], 2); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: #64748b;">Commissions</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>