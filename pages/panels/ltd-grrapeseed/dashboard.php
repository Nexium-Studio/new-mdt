<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

// Configuration entreprise
$companyName = 'LTD Grapeseed';
$companyColor = '#0ea5e9';
$companyFaction = 'LTD_GRRAPESEED';

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

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.15rem;
    margin-bottom: 1.75rem;
}

.stat-card {
    background: color-mix(in srgb, var(--panel-color, var(--company-color)) 9%, rgba(255,255,255,0.02));
    border: 1px solid color-mix(in srgb, var(--panel-color, var(--company-color)) 24%, transparent);
    border-radius: 14px;
    padding: 1.35rem 1.25rem;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 88px;
    height: 88px;
    background: radial-gradient(circle, color-mix(in srgb, var(--panel-color, var(--company-color)) 18%, transparent) 0%, transparent 70%);
    border-radius: 50%;
    transform: translate(28%, -28%);
    pointer-events: none;
}

.stat-icon {
    width: 46px;
    height: 46px;
    background: color-mix(in srgb, var(--panel-color, var(--company-color)) 14%, transparent);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.85rem;
    color: var(--panel-color, var(--company-color));
    font-size: 1.25rem;
}

.stat-label {
    font-size: 0.85rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: 900;
    color: #e8edf5;
    font-family: 'Orbitron', monospace;
}

.stat-trend {
    font-size: 0.85rem;
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.stat-trend.positive {
    color: #22c55e;
}

.stat-trend.negative {
    color: #ef4444;
}

.finances-card {
    background: rgba(12, 16, 28, 0.55);
    border: 1px solid color-mix(in srgb, var(--panel-color, var(--company-color)) 28%, transparent);
    border-radius: 14px;
    padding: 1.75rem;
    margin-bottom: 1.75rem;
}

.finance-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 0;
    border-bottom: 1px solid rgba(59, 130, 246, 0.1);
}

.finance-row:last-child {
    border-bottom: none;
}

.finance-label {
    font-weight: 600;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.finance-value {
    font-size: 1.25rem;
    font-weight: 800;
    font-family: 'Orbitron', monospace;
}

.finance-value.positive {
    color: #22c55e;
}

.finance-value.negative {
    color: #ef4444;
}

.finance-value.neutral {
    color: #60a5fa;
}

.top-sellers {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.seller-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 1rem;
    background: color-mix(in srgb, var(--panel-color, var(--company-color)) 6%, transparent);
    border: 1px solid color-mix(in srgb, var(--panel-color, var(--company-color)) 16%, transparent);
    border-radius: 12px;
    transition: background 0.2s, transform 0.2s;
}

.seller-item:hover {
    background: color-mix(in srgb, var(--panel-color, var(--company-color)) 11%, transparent);
    transform: translateX(4px);
}

.seller-rank {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--panel-color, var(--company-color)), color-mix(in srgb, var(--panel-color, var(--company-color)) 55%, #000));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 1.25rem;
    color: white;
}

.seller-info {
    flex: 1;
}

.seller-name {
    font-weight: 700;
    color: #e8edf5;
    margin-bottom: 0.25rem;
}

.seller-role {
    font-size: 0.85rem;
    color: #64748b;
}

.seller-ca {
    font-weight: 800;
    color: #22c55e;
    font-family: 'Orbitron', monospace;
}

.invoice-table {
    width: 100%;
    border-collapse: collapse;
}

.invoice-table th {
    text-align: left;
    padding: 1rem;
    background: color-mix(in srgb, var(--panel-color, var(--company-color)) 10%, transparent);
    border-bottom: 2px solid color-mix(in srgb, var(--panel-color, var(--company-color)) 28%, transparent);
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #94a3b8;
    font-weight: 700;
}

.invoice-table td {
    padding: 1rem;
    border-bottom: 1px solid rgba(59, 130, 246, 0.1);
}

.invoice-table tr:hover {
    background: color-mix(in srgb, var(--panel-color, var(--company-color)) 6%, transparent);
}

.status-badge {
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}

.status-paid {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.status-pending {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.status-cancelled {
    background: rgba(100, 116, 139, 0.15);
    color: #94a3b8;
    border: 1px solid rgba(100, 116, 139, 0.3);
}

.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 2rem;
}

@media (max-width: 1200px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 10px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-weight: 500;
}

.alert-success {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: #22c55e;
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
}
</style>

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
    <h2 style="margin-bottom: 1.5rem; font-size: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
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
    
    <div class="finance-row" style="background: color-mix(in srgb, var(--panel-color, var(--company-color)) 10%, transparent); margin: 1rem -1.75rem; padding: 1.35rem 1.75rem; border-radius: 12px;">
        <div class="finance-label" style="font-size: 1.1rem; color: #e8edf5;">
            <i class="fas fa-trophy"></i>
            <strong>Bénéfice Net</strong>
        </div>
        <div class="finance-value positive" style="font-size: 1.75rem;">$<?php echo number_format($finances['benefice_net'], 2); ?></div>
    </div>
    
    <div style="margin-top: 1.75rem; padding-top: 1.75rem; border-top: 1px solid color-mix(in srgb, var(--panel-color, var(--company-color)) 22%, transparent);">
        <h3 style="margin-bottom: 1rem; color: #e8edf5; font-size: 1.1rem;">
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
        <h2 style="margin-bottom: 1.5rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-trophy"></i>
            Top 5 Vendeurs
        </h2>
        
        <div class="top-sellers">
            <?php if (empty($topSellers)): ?>
                <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune vente ce mois</p>
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
        <h2 style="margin-bottom: 1.5rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-file-invoice"></i>
            Dernières Factures
        </h2>
        
        <?php if (empty($recentInvoices)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune facture</p>
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