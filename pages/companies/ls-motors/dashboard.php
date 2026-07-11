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

$pageTitle = 'Dashboard';
$db = Database::getInstance()->getConnection();

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'dashboard.view')) faction_acl_deny();
$canStats = faction_acl_can($db, $uid, $companyFaction, 'dashboard.stats');
// Stats globales (périmètre concession)
$dk = $db->quote($dealershipKey);
$stats = [
    'stock_total' => $db->query("SELECT COUNT(*) FROM lsm_vehicules WHERE dealership_key=$dk AND statut IN ('disponible','reserve')")->fetchColumn(),
    'stock_disponible' => $db->query("SELECT COUNT(*) FROM lsm_vehicules WHERE dealership_key=$dk AND statut = 'disponible'")->fetchColumn(),
    'valeur_stock' => $db->query("SELECT SUM(prix_vente) FROM lsm_vehicules WHERE dealership_key=$dk AND statut IN ('disponible','reserve')")->fetchColumn() ?: 0,
    'ventes_mois' => $db->query("SELECT COUNT(*) FROM lsm_ventes v JOIN lsm_vehicules vh ON v.vehicule_id=vh.id WHERE vh.dealership_key=$dk AND MONTH(v.created_at) = MONTH(CURDATE()) AND YEAR(v.created_at) = YEAR(CURDATE()) AND v.statut != 'annulee'")->fetchColumn(),
    'revenus_mois' => $db->query("SELECT SUM(v.prix_final) FROM lsm_ventes v JOIN lsm_vehicules vh ON v.vehicule_id=vh.id WHERE vh.dealership_key=$dk AND MONTH(v.created_at) = MONTH(CURDATE()) AND YEAR(v.created_at) = YEAR(CURDATE()) AND v.statut != 'annulee'")->fetchColumn() ?: 0,
    'ventes_jour' => $db->query("SELECT COUNT(*) FROM lsm_ventes v JOIN lsm_vehicules vh ON v.vehicule_id=vh.id WHERE vh.dealership_key=$dk AND DATE(v.created_at) = CURDATE() AND v.statut != 'annulee'")->fetchColumn(),
    'plaques_attente' => $db->query("SELECT COUNT(*) FROM lsm_plaques p JOIN lsm_vehicules v ON p.vehicule_id=v.id WHERE v.dealership_key=$dk AND p.statut IN ('en_attente','attribuee')")->fetchColumn(),
];

// Dernières ventes
$dernieresVentes = $db->query("
    SELECT v.*, vh.modele, m.nom as marque_nom, c.nom as client_nom, u.username as vendeur_nom
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id
    LEFT JOIN lsm_marques m ON vh.marque_id = m.id
    JOIN lsm_clients c ON v.client_id = c.id
    JOIN users u ON v.vendeur_id = u.id
    WHERE v.statut != 'annulee' AND vh.dealership_key = $dk
    ORDER BY v.created_at DESC
    LIMIT 5
")->fetchAll();

// Top vendeurs du mois
$topVendeurs = $db->query("
    SELECT u.username, COUNT(v.id) as nb_ventes, SUM(v.prix_final) as total_ventes, SUM(v.commission_vendeur) as total_commissions
    FROM users u
    LEFT JOIN lsm_ventes v ON u.id = v.vendeur_id 
        AND MONTH(v.created_at) = MONTH(CURDATE()) 
        AND YEAR(v.created_at) = YEAR(CURDATE())
        AND v.statut != 'annulee'
    INNER JOIN lsm_vehicules vhv ON v.vehicule_id = vhv.id AND vhv.dealership_key = $dk
    WHERE u.faction = '$companyFaction'
    GROUP BY u.id, u.username
    HAVING nb_ventes > 0
    ORDER BY nb_ventes DESC
    LIMIT 5
")->fetchAll();

// Répartition par catégorie
$repartitionCat = $db->query("
    SELECT c.nom, c.couleur, COUNT(v.id) as nb_vehicules, SUM(v.prix_vente) as valeur
    FROM lsm_vehicules v
    LEFT JOIN lsm_categories c ON v.categorie_id = c.id
    WHERE v.dealership_key = $dk AND v.statut IN ('disponible','reserve')
    GROUP BY c.id, c.nom, c.couleur
    ORDER BY nb_vehicules DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>



<!-- Stats principales -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value"><?php echo $stats['stock_total']; ?></div>
                <div class="stat-label">Véhicules en Stock</div>
            </div>
            <div class="stat-icon">
                <i class="fas fa-warehouse"></i>
            </div>
        </div>
        <div style="font-size: 0.9rem; color: #94a3b8;">
            <?php echo $stats['stock_disponible']; ?> disponibles
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">$<?php echo number_format($stats['valeur_stock'], 0); ?></div>
                <div class="stat-label">Valeur du Stock</div>
            </div>
            <div class="stat-icon">
                <i class="fas fa-dollar-sign"></i>
            </div>
        </div>
        <div style="font-size: 0.9rem; color: #94a3b8;">
            Inventaire total
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value"><?php echo $stats['ventes_mois']; ?></div>
                <div class="stat-label">Ventes du Mois</div>
            </div>
            <div class="stat-icon">
                <i class="fas fa-handshake"></i>
            </div>
        </div>
        <div style="font-size: 0.9rem; color: #94a3b8;">
            <?php echo $stats['ventes_jour']; ?> aujourd'hui
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">$<?php echo number_format($stats['revenus_mois'], 0); ?></div>
                <div class="stat-label">Revenus du Mois</div>
            </div>
            <div class="stat-icon">
                <i class="fas fa-chart-line"></i>
            </div>
        </div>
        <div style="font-size: 0.9rem; color: #94a3b8;">
            Chiffre d'affaires
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- Dernières ventes -->
    <div class="card">
        <h2 style="margin-bottom: 1.5rem;"><i class="fas fa-receipt"></i> Dernières Ventes</h2>
        
        <?php if (empty($dernieresVentes)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune vente récente</p>
        <?php else: ?>
            <div class="ventes-list">
                <?php foreach ($dernieresVentes as $vente): ?>
                    <div class="vente-item">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div>
                                <div style="font-weight: 700; font-size: 1.1rem; color: #e8edf5; margin-bottom: 0.25rem;">
                                    <?php echo htmlspecialchars($vente['marque_nom']); ?> <?php echo htmlspecialchars($vente['modele']); ?>
                                </div>
                                <div style="color: #64748b; font-size: 0.9rem;">
                                    Client: <?php echo htmlspecialchars($vente['client_nom']); ?> • 
                                    Vendeur: <?php echo htmlspecialchars($vente['vendeur_nom']); ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 1.3rem; font-weight: 700; color: #22c55e;">
                                    $<?php echo number_format($vente['prix_final'], 0); ?>
                                </div>
                                <div style="font-size: 0.85rem; color: #64748b;">
                                    <?php echo date('d/m/Y', strtotime($vente['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Top vendeurs -->
    <div class="card">
        <h2 style="margin-bottom: 1.5rem;"><i class="fas fa-trophy"></i> Top Vendeurs</h2>
        
        <?php if (empty($topVendeurs)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune vente ce mois</p>
        <?php else: ?>
            <?php foreach ($topVendeurs as $i => $vendeur): ?>
                <div style="padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="width: 30px; height: 30px; background: linear-gradient(135deg, #ef4444, #b91c1c); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                <?php echo $i + 1; ?>
                            </div>
                            <span style="font-weight: 600;"><?php echo htmlspecialchars($vendeur['username']); ?></span>
                        </div>
                        <span style="font-weight: 700; color: #ef4444;"><?php echo $vendeur['nb_ventes']; ?> ventes</span>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b;">
                        CA: $<?php echo number_format($vendeur['total_ventes'], 0); ?> • 
                        Commission: $<?php echo number_format($vendeur['total_commissions'], 0); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Répartition par catégorie -->
<div class="card">
    <h2 style="margin-bottom: 1.5rem;"><i class="fas fa-chart-pie"></i> Répartition du Stock par Catégorie</h2>
    
    <?php if (empty($repartitionCat)): ?>
        <p style="text-align: center; color: #64748b; padding: 2rem;">Aucun véhicule en stock</p>
    <?php else: ?>
        <?php 
        $totalVehicules = array_sum(array_column($repartitionCat, 'nb_vehicules'));
        foreach ($repartitionCat as $cat): 
            $percentage = $totalVehicules > 0 ? ($cat['nb_vehicules'] / $totalVehicules) * 100 : 0;
        ?>
            <div style="padding: 1.25rem; background: rgba(15, 23, 41, 0.6); border-radius: 10px; margin-bottom: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo $cat['couleur']; ?>;"></div>
                        <span style="font-weight: 600; font-size: 1.05rem;"><?php echo htmlspecialchars($cat['nom']); ?></span>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-weight: 700; color: #ef4444;"><?php echo $cat['nb_vehicules']; ?> véhicules</div>
                        <div style="font-size: 0.85rem; color: #64748b;">$<?php echo number_format($cat['valeur'], 0); ?></div>
                    </div>
                </div>
                <div style="height: 8px; background: rgba(239, 68, 68, 0.1); border-radius: 4px; overflow: hidden;">
                    <div style="height: 100%; width: <?php echo $percentage; ?>%; background: <?php echo $cat['couleur']; ?>; transition: width 0.5s;"></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Actions rapides -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
    <a href="stock.php" class="card" style="text-decoration: none; cursor: pointer; text-align: center; padding: 2rem;">
        <div style="font-size: 3rem; color: #ef4444; margin-bottom: 1rem;">
            <i class="fas fa-warehouse"></i>
        </div>
        <h3 style="color: #e8edf5; margin-bottom: 0.5rem;">Gérer le Stock</h3>
        <p style="color: #64748b;">Ajouter et gérer les véhicules</p>
    </a>
    
    <a href="ventes.php" class="card" style="text-decoration: none; cursor: pointer; text-align: center; padding: 2rem;">
        <div style="font-size: 3rem; color: #22c55e; margin-bottom: 1rem;">
            <i class="fas fa-handshake"></i>
        </div>
        <h3 style="color: #e8edf5; margin-bottom: 0.5rem;">Nouvelle Vente</h3>
        <p style="color: #64748b;">Créer une vente</p>
    </a>
    
    <a href="plaques.php" class="card" style="text-decoration: none; cursor: pointer; text-align: center; padding: 2rem;">
        <div style="font-size: 3rem; color: #3b82f6; margin-bottom: 1rem;">
            <i class="fas fa-id-card"></i>
        </div>
        <h3 style="color: #e8edf5; margin-bottom: 0.5rem;">Plaques</h3>
        <p style="color: #64748b;"><?php echo $stats['plaques_attente']; ?> en attente</p>
    </a>
    
    <a href="clients.php" class="card" style="text-decoration: none; cursor: pointer; text-align: center; padding: 2rem;">
        <div style="font-size: 3rem; color: #f59e0b; margin-bottom: 1rem;">
            <i class="fas fa-users"></i>
        </div>
        <h3 style="color: #e8edf5; margin-bottom: 0.5rem;">Clients</h3>
        <p style="color: #64748b;">Gérer la base clients</p>
    </a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>