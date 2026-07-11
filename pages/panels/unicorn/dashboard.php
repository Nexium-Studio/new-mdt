<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

// Configuration entreprise (défauts — MDT staff surcharge via panel_theme_auto)
$companyName = 'Unicorn';
$companyColor = '#ec4899';
$companyFaction = 'UNICORN';

// Vérifier accès
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
$message = '';
$messageType = '';

// RECALCULER LES FINANCES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recalculate_finances') {
    try {
        $db->beginTransaction();
        
        // 1. Calculer le CA brut (somme des factures payées)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(final_amount), 0) as total_ca
            FROM company_invoices
            WHERE faction = ? AND status = 'paid'
        ");
        $stmt->execute([$companyFaction]);
        $ca_brut = $stmt->fetchColumn();
        
        // 2. Calculer les charges (somme des dépenses déductibles)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_charges
            FROM company_expenses
            WHERE faction = ? AND is_tax_deductible = TRUE
        ");
        $stmt->execute([$companyFaction]);
        $charges = $stmt->fetchColumn();
        
        // 3. Calculer le bénéfice brut
        $benefice_brut = $ca_brut - $charges;
        
        // 4. Calculer les impôts selon le barème
        function calculateTax($beneficeBrut, $faction) {
            // Barème spécial pour Ammunation
            if ($faction === 'AMMUNATION') {
                if ($beneficeBrut <= 100000) {
                    return 0;
                } elseif ($beneficeBrut <= 1000000) {
                    return ($beneficeBrut - 100000) * 0.20;
                } else {
                    return 180000 + ($beneficeBrut - 1000000) * 0.20;
                }
            }
            
            // Barème normal pour les autres entreprises
            if ($beneficeBrut <= 50000) {
                return 0; // 0%
            } elseif ($beneficeBrut <= 100000) {
                return ($beneficeBrut - 50000) * 0.10; // 10%
            } elseif ($beneficeBrut <= 500000) {
                return 5000 + ($beneficeBrut - 100000) * 0.15; // 15%
            } else {
                return 65000 + ($beneficeBrut - 500000) * 0.20; // 20%
            }
        }
        
        $impot_du = calculateTax($benefice_brut, $companyFaction);
        
        // 5. Calculer le bénéfice net
        $benefice_net = $benefice_brut - $impot_du;
        
        // 6. Calculer la trésorerie (30% du bénéfice net)
        $tresorerie = $benefice_net * 0.30;
        
        // 7. Mettre à jour company_finances
        $stmt = $db->prepare("
            INSERT INTO company_finances (
                faction, ca_brut, charges, benefice_brut, impot_du, benefice_net, tresorerie
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ca_brut = VALUES(ca_brut),
                charges = VALUES(charges),
                benefice_brut = VALUES(benefice_brut),
                impot_du = VALUES(impot_du),
                benefice_net = VALUES(benefice_net),
                tresorerie = VALUES(tresorerie)
        ");
        $stmt->execute([
            $companyFaction,
            $ca_brut,
            $charges,
            $benefice_brut,
            $impot_du,
            $benefice_net,
            $tresorerie
        ]);
        
        $db->commit();
        
        $message = 'Finances recalculées avec succès !';
        $messageType = 'success';
        
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Récupérer les finances
$stmt = $db->prepare("SELECT * FROM company_finances WHERE faction = ?");
$stmt->execute([$companyFaction]);
$finances = $stmt->fetch() ?: [
    'ca_brut' => 0,
    'charges' => 0,
    'benefice_brut' => 0,
    'impot_du' => 0,
    'benefice_net' => 0,
    'tresorerie' => 0
];

// Stats employés
$stmt = $db->prepare("SELECT COUNT(*) as total FROM company_employees WHERE faction = ?");
$stmt->execute([$companyFaction]);
$totalEmployees = $stmt->fetchColumn();

// Factures du mois
$stmt = $db->prepare("
    SELECT COUNT(*) as total, SUM(final_amount) as sum 
    FROM company_invoices 
    WHERE faction = ? 
    AND MONTH(created_at) = MONTH(CURRENT_DATE())
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$stmt->execute([$companyFaction]);
$monthStats = $stmt->fetch();

// Prises de service aujourd'hui
$stmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM company_shifts 
    WHERE faction = ? 
    AND DATE(shift_start) = CURDATE()
    AND shift_end IS NULL
");
$stmt->execute([$companyFaction]);
$activeShifts = $stmt->fetchColumn();

// Top 5 vendeurs du mois
$stmt = $db->prepare("
    SELECT u.username, ce.individual_ca, ce.rank
    FROM company_employees ce
    JOIN users u ON ce.user_id = u.id
    WHERE ce.faction = ?
    ORDER BY ce.individual_ca DESC
    LIMIT 5
");
$stmt->execute([$companyFaction]);
$topSellers = $stmt->fetchAll();

// Dernières factures
$stmt = $db->prepare("
    SELECT ci.*, u.username as employee_name
    FROM company_invoices ci
    JOIN users u ON ci.employee_id = u.id
    WHERE ci.faction = ?
    ORDER BY ci.created_at DESC
    LIMIT 10
");
$stmt->execute([$companyFaction]);
$recentInvoices = $stmt->fetchAll();

// Calculer les distributions
$primes_disponibles = $finances['benefice_net'] * 0.30;
$dividendes_disponibles = $finances['benefice_brut'] * 0.40;

include __DIR__ . '/includes/header.php';
?>

<?php if (isset($message)): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Bouton Recalculer Finances -->
<div style="margin-bottom: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
    <form method="POST" onsubmit="return confirm('Recalculer toutes les finances ? (CA, charges, impôts, bénéfices)');">
        <input type="hidden" name="action" value="recalculate_finances">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-calculator"></i> Recalculer les Finances
        </button>
    </form>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-dollar-sign"></i>
        </div>
        <div class="stat-label">CA Brut</div>
        <div class="stat-value">$<?php echo number_format($finances['ca_brut'], 0); ?></div>
        <div class="stat-trend positive">
            <i class="fas fa-arrow-up"></i>
            <span>Ce mois</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-label">Bénéfice Net</div>
        <div class="stat-value">$<?php echo number_format($finances['benefice_net'], 0); ?></div>
        <div class="stat-trend positive">
            <i class="fas fa-arrow-up"></i>
            <span>Après impôts</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-label">Employés</div>
        <div class="stat-value"><?php echo $totalEmployees; ?></div>
        <div class="stat-trend">
            <i class="fas fa-user-clock"></i>
            <span><?php echo $activeShifts; ?> en service</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="stat-label">Factures (Mois)</div>
        <div class="stat-value"><?php echo $monthStats['total'] ?? 0; ?></div>
        <div class="stat-trend positive">
            <i class="fas fa-dollar-sign"></i>
            <span>$<?php echo number_format($monthStats['sum'] ?? 0, 0); ?></span>
        </div>
    </div>
</div>

<!-- Finances Détaillées -->
<div class="card finances-card">
    <h2 class="card-title-lg">
        <i class="fas fa-chart-pie"></i>
        Finances Détaillées
    </h2>
    
    <div class="finance-row">
        <div class="finance-label">
            <i class="fas fa-cash-register"></i>
            Chiffre d'Affaires Brut
        </div>
        <div class="finance-value neutral">$<?php echo number_format($finances['ca_brut'], 2); ?></div>
    </div>
    
    <div class="finance-row">
        <div class="finance-label">
            <i class="fas fa-minus-circle"></i>
            Charges d'Exploitation
        </div>
        <div class="finance-value negative">-$<?php echo number_format($finances['charges'], 2); ?></div>
    </div>
    
    <div class="finance-row">
        <div class="finance-label">
            <i class="fas fa-equals"></i>
            Bénéfice Brut (Avant Impôts)
        </div>
        <div class="finance-value positive">$<?php echo number_format($finances['benefice_brut'], 2); ?></div>
    </div>
    
    <div class="finance-row">
        <div class="finance-label">
            <i class="fas fa-landmark"></i>
            Impôts Dus
        </div>
        <div class="finance-value negative">-$<?php echo number_format($finances['impot_du'], 2); ?></div>
    </div>
    
    <div class="finance-row finance-row--highlight">
        <div class="finance-label" style="font-size: 1.05rem; color: #f1f5f9;">
            <i class="fas fa-trophy"></i>
            <strong>Bénéfice Net</strong>
        </div>
        <div class="finance-value positive" style="font-size: 1.65rem;">$<?php echo number_format($finances['benefice_net'], 2); ?></div>
    </div>
    
    <div class="finance-section-divider">
        <h3 class="card-title-md" style="color:#f1f5f9;">
            <i class="fas fa-share-alt"></i> Distribution des Bénéfices
        </h3>
        
        <div class="finance-row">
            <div class="finance-label">
                <i class="fas fa-gift"></i>
                Pool Primes (30% Bénéfice Net)
            </div>
            <div class="finance-value positive">$<?php echo number_format($primes_disponibles, 2); ?></div>
        </div>
        
        <div class="finance-row">
            <div class="finance-label">
                <i class="fas fa-crown"></i>
                Dividendes PDG/Co-PDG (40% Bénéfice Brut)
            </div>
            <div class="finance-value positive">$<?php echo number_format($dividendes_disponibles, 2); ?></div>
        </div>
        
        <div class="finance-row">
            <div class="finance-label">
                <i class="fas fa-piggy-bank"></i>
                Trésorerie (30% Bénéfice Net)
            </div>
            <div class="finance-value neutral">$<?php echo number_format($finances['tresorerie'], 2); ?></div>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- Top Vendeurs -->
    <div class="card">
        <h2 class="card-title-md">
            <i class="fas fa-trophy"></i>
            Top 5 Vendeurs
        </h2>
        
        <div class="top-sellers">
            <?php if (empty($topSellers)): ?>
                <p class="empty-hint">Aucune vente ce mois</p>
            <?php else: ?>
                <?php foreach ($topSellers as $index => $seller): ?>
                    <div class="seller-item">
                        <div class="seller-rank"><?php echo $index + 1; ?></div>
                        <div class="seller-info">
                            <div class="seller-name"><?php echo htmlspecialchars($seller['username']); ?></div>
                            <div class="seller-role"><?php echo htmlspecialchars($seller['rank']); ?></div>
                        </div>
                        <div class="seller-ca">$<?php echo number_format($seller['individual_ca'], 0); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Dernières Factures -->
    <div class="card">
        <h2 class="card-title-md">
            <i class="fas fa-file-invoice"></i>
            Dernières Factures
        </h2>
        
        <?php if (empty($recentInvoices)): ?>
            <p class="empty-hint">Aucune facture</p>
        <?php else: ?>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Employé</th>
                        <th>Montant</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentInvoices as $inv): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($inv['customer_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($inv['employee_name']); ?></td>
                            <td style="font-weight: 700; color: #22c55e;">$<?php echo number_format($inv['final_amount'], 2); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $inv['status']; ?>">
                                    <?php 
                                    $statusNames = ['pending' => 'En attente', 'paid' => 'Payée', 'cancelled' => 'Annulée'];
                                    echo $statusNames[$inv['status']] ?? $inv['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>