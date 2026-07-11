<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
$panelCfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/../../../config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);

$companyName = $panelCfg['name'];
$companyColor = $panelCfg['color'];
$companyFaction = $panelCfg['faction'];
$dealershipKey = $panelCfg['dealership_key'];

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle = 'Statistiques';
$db = Database::getInstance()->getConnection();
$dk = $db->quote($dealershipKey);

// Période
$periode = $_GET['periode'] ?? 'mois';
$dateDebut = $_GET['date_debut'] ?? date('Y-m-01');
$dateFin = $_GET['date_fin'] ?? date('Y-m-t');

// Stats globales (concession)
$statsGlobales = [
    'total_ventes' => $db->query("SELECT COUNT(*) FROM lsm_ventes v JOIN lsm_vehicules vh ON v.vehicule_id=vh.id WHERE vh.dealership_key=$dk AND v.statut != 'annulee'")->fetchColumn(),
    'revenus_total' => $db->query("SELECT SUM(v.prix_final) FROM lsm_ventes v JOIN lsm_vehicules vh ON v.vehicule_id=vh.id WHERE vh.dealership_key=$dk AND v.statut != 'annulee'")->fetchColumn() ?: 0,
    'marge_total' => $db->query("
        SELECT SUM(v.prix_final - vh.prix_achat) 
        FROM lsm_ventes v 
        JOIN lsm_vehicules vh ON v.vehicule_id = vh.id 
        WHERE v.statut != 'annulee' AND vh.dealership_key=$dk
    ")->fetchColumn() ?: 0,
    'stock_actuel' => $db->query("SELECT COUNT(*) FROM lsm_vehicules WHERE dealership_key=$dk AND statut IN ('disponible','reserve')")->fetchColumn(),
    'valeur_stock' => $db->query("SELECT SUM(prix_vente) FROM lsm_vehicules WHERE dealership_key=$dk AND statut IN ('disponible','reserve')")->fetchColumn() ?: 0,
    'nb_clients' => $db->query("SELECT COUNT(*) FROM lsm_clients")->fetchColumn(),
];

// Calcul taux de marge
$statsGlobales['taux_marge'] = $statsGlobales['revenus_total'] > 0 
    ? ($statsGlobales['marge_total'] / $statsGlobales['revenus_total']) * 100 
    : 0;

// Ventes par mois (12 derniers mois)
$ventesParMois = $db->query("
    SELECT 
        DATE_FORMAT(v.created_at, '%Y-%m') as mois,
        COUNT(*) as nb_ventes,
        SUM(v.prix_final) as revenus
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id AND vh.dealership_key = $dk
    WHERE v.statut != 'annulee'
      AND v.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(v.created_at, '%Y-%m')
    ORDER BY mois ASC
")->fetchAll();

// Top véhicules vendus
$topVehicules = $db->query("
    SELECT 
        CONCAT(m.nom, ' ', vh.modele) as vehicule,
        COUNT(v.id) as nb_ventes,
        SUM(v.prix_final) as revenus_total
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id
    LEFT JOIN lsm_marques m ON vh.marque_id = m.id
    WHERE v.statut != 'annulee' AND vh.dealership_key = $dk
    GROUP BY vh.marque_id, vh.modele
    ORDER BY nb_ventes DESC
    LIMIT 10
")->fetchAll();

// Ventes par catégorie
$ventesParCategorie = $db->query("
    SELECT 
        c.nom as categorie,
        c.couleur,
        COUNT(v.id) as nb_ventes,
        SUM(v.prix_final) as revenus
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id
    LEFT JOIN lsm_categories c ON vh.categorie_id = c.id
    WHERE v.statut != 'annulee'
    GROUP BY c.id, c.nom, c.couleur
    ORDER BY nb_ventes DESC
")->fetchAll();

// Performance vendeurs
$performanceVendeurs = $db->query("
    SELECT 
        u.username as vendeur,
        COUNT(v.id) as nb_ventes,
        SUM(v.prix_final) as revenus_total,
        SUM(v.commission_vendeur) as commissions_total,
        AVG(v.prix_final) as panier_moyen
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id AND vh.dealership_key = $dk
    JOIN users u ON v.vendeur_id = u.id
    WHERE v.statut != 'annulee'
    GROUP BY u.id, u.username
    ORDER BY nb_ventes DESC
")->fetchAll();

// Stats période sélectionnée
$statsPeriode = $db->query("
    SELECT 
        COUNT(*) as nb_ventes,
        SUM(v.prix_final) as revenus,
        AVG(v.prix_final) as panier_moyen
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id AND vh.dealership_key = $dk
    WHERE v.statut != 'annulee'
      AND DATE(v.created_at) BETWEEN " . $db->quote($dateDebut) . " AND " . $db->quote($dateFin) . "
")->fetch();

include __DIR__ . '/includes/header.php';
?>



<div class="card">
    <h2 style="margin-bottom: 1.5rem;"><i class="fas fa-chart-bar"></i> Statistiques Globales</h2>
    
    <!-- Filtres période -->
    <form method="GET" class="filters-bar" style="margin-bottom: 2rem;">
        <select name="periode" class="form-select" style="max-width: 150px;" onchange="this.form.submit()">
            <option value="semaine" <?php echo $periode === 'semaine' ? 'selected' : ''; ?>>Cette Semaine</option>
            <option value="mois" <?php echo $periode === 'mois' ? 'selected' : ''; ?>>Ce Mois</option>
            <option value="trimestre" <?php echo $periode === 'trimestre' ? 'selected' : ''; ?>>Ce Trimestre</option>
            <option value="annee" <?php echo $periode === 'annee' ? 'selected' : ''; ?>>Cette Année</option>
            <option value="custom" <?php echo $periode === 'custom' ? 'selected' : ''; ?>>Personnalisé</option>
        </select>
        
        <?php if ($periode === 'custom'): ?>
            <input type="date" name="date_debut" class="form-input" value="<?php echo $dateDebut; ?>" style="max-width: 180px;">
            <input type="date" name="date_fin" class="form-input" value="<?php echo $dateFin; ?>" style="max-width: 180px;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-search"></i> Filtrer
            </button>
        <?php endif; ?>
    </form>
    
    <!-- Stats cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $statsGlobales['total_ventes']; ?></div>
            <div class="stat-label">Ventes Totales</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">$<?php echo number_format($statsGlobales['revenus_total'], 0); ?></div>
            <div class="stat-label">Revenus Totaux</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">$<?php echo number_format($statsGlobales['marge_total'], 0); ?></div>
            <div class="stat-label">Marge Totale</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($statsGlobales['taux_marge'], 1); ?>%</div>
            <div class="stat-label">Taux de Marge</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $statsGlobales['stock_actuel']; ?></div>
            <div class="stat-label">Stock Actuel</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">$<?php echo number_format($statsGlobales['valeur_stock'], 0); ?></div>
            <div class="stat-label">Valeur Stock</div>
        </div>
    </div>
</div>

<!-- Évolution des ventes -->
<div class="chart-container">
    <div class="chart-title">
        <i class="fas fa-chart-line"></i> Évolution des Ventes (12 derniers mois)
    </div>
    
    <?php if (empty($ventesParMois)): ?>
        <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune donnée disponible</p>
    <?php else: ?>
        <?php
        $maxRevenus = max(array_column($ventesParMois, 'revenus'));
        ?>
        <div class="line-chart">
            <?php foreach ($ventesParMois as $mois): 
                $hauteur = ($mois['revenus'] / $maxRevenus) * 100;
            ?>
                <div class="line-bar" style="height: <?php echo $hauteur; ?>%;" title="<?php echo date('M Y', strtotime($mois['mois'].'-01')); ?>">
                    <div class="line-bar-value">$<?php echo number_format($mois['revenus']/1000, 0); ?>k</div>
                    <div class="line-bar-label"><?php echo date('M', strtotime($mois['mois'].'-01')); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div style="display: flex; justify-content: center; gap: 2rem; margin-top: 3rem; padding-top: 1rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
            <div style="text-align: center;">
                <div style="font-size: 0.85rem; color: #64748b;">Total Ventes</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;">
                    <?php echo array_sum(array_column($ventesParMois, 'nb_ventes')); ?>
                </div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 0.85rem; color: #64748b;">Revenus Totaux</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #22c55e;">
                    $<?php echo number_format(array_sum(array_column($ventesParMois, 'revenus')), 0); ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
    <!-- Top Véhicules -->
    <div class="chart-container">
        <div class="chart-title">
            <i class="fas fa-trophy"></i> Top 10 Véhicules Vendus
        </div>
        
        <?php if (empty($topVehicules)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune vente</p>
        <?php else: ?>
            <?php 
            $maxVentes = max(array_column($topVehicules, 'nb_ventes'));
            ?>
            <div class="bar-chart">
                <?php foreach ($topVehicules as $vehicule): 
                    $pourcentage = ($vehicule['nb_ventes'] / $maxVentes) * 100;
                ?>
                    <div class="bar-item">
                        <div class="bar-label"><?php echo htmlspecialchars($vehicule['vehicule']); ?></div>
                        <div class="bar-container">
                            <div class="bar-fill" style="width: <?php echo $pourcentage; ?>%;">
                                <span class="bar-value"><?php echo $vehicule['nb_ventes']; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Ventes par Catégorie -->
    <div class="chart-container">
        <div class="chart-title">
            <i class="fas fa-chart-pie"></i> Ventes par Catégorie
        </div>
        
        <?php if (empty($ventesParCategorie)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune vente</p>
        <?php else: ?>
            <?php 
            $maxVentesCategorie = max(array_column($ventesParCategorie, 'nb_ventes'));
            ?>
            <div class="bar-chart">
                <?php foreach ($ventesParCategorie as $cat): 
                    $pourcentage = ($cat['nb_ventes'] / $maxVentesCategorie) * 100;
                ?>
                    <div class="bar-item">
                        <div class="bar-label">
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo $cat['couleur']; ?>; margin-right: 0.5rem;"></span>
                            <?php echo htmlspecialchars($cat['categorie']); ?>
                        </div>
                        <div class="bar-container">
                            <div class="bar-fill" style="width: <?php echo $pourcentage; ?>%; background: <?php echo $cat['couleur']; ?>;">
                                <span class="bar-value"><?php echo $cat['nb_ventes']; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Performance Vendeurs -->
<div class="chart-container">
    <div class="chart-title">
        <i class="fas fa-users"></i> Performance des Vendeurs
    </div>
    
    <?php if (empty($performanceVendeurs)): ?>
        <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune vente</p>
    <?php else: ?>
        <table class="stats-table">
            <thead>
                <tr>
                    <th>Vendeur</th>
                    <th style="text-align: center;">Nb Ventes</th>
                    <th style="text-align: right;">Revenus Total</th>
                    <th style="text-align: right;">Commissions</th>
                    <th style="text-align: right;">Panier Moyen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($performanceVendeurs as $vendeur): ?>
                    <tr>
                        <td style="font-weight: 600;">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($vendeur['vendeur']); ?>
                        </td>
                        <td style="text-align: center; font-weight: 700; color: #3b82f6;">
                            <?php echo $vendeur['nb_ventes']; ?>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #22c55e;">
                            $<?php echo number_format($vendeur['revenus_total'], 0); ?>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #f59e0b;">
                            $<?php echo number_format($vendeur['commissions_total'], 2); ?>
                        </td>
                        <td style="text-align: right; color: #64748b;">
                            $<?php echo number_format($vendeur['panier_moyen'], 0); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>