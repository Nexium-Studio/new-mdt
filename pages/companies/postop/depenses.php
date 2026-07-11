<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = '📬 PostOP';
$companyColor = '#10b981';
$companyFaction = 'POSTOP';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Dépenses';
$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'depenses.view')) faction_acl_deny();
$canAdd    = faction_acl_can($db, $uid, $companyFaction, 'depenses.add');
$canManage = faction_acl_can($db, $uid, $companyFaction, 'depenses.delete');
$canDelete = $canManage;

// AJOUTER DÉPENSE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add' && $canManage) {
    try {
        $db->beginTransaction();
        
        $amount = floatval($_POST['amount']);
        $isTaxDeductible = isset($_POST['is_tax_deductible']) ? 1 : 0;
        
        // Insérer la dépense
        $stmt = $db->prepare("
            INSERT INTO company_expenses (
                faction, added_by, expense_name, amount, is_tax_deductible, description, expense_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyFaction,
            $_SESSION['user_id'],
            trim($_POST['expense_name']),
            $amount,
            $isTaxDeductible,
            trim($_POST['description']),
            $_POST['expense_date']
        ]);
        
        // Mettre à jour les finances
        if ($isTaxDeductible) {
            // Les dépenses déductibles réduisent le bénéfice brut
            $stmt = $db->prepare("
                UPDATE company_finances 
                SET charges = charges + ?,
                    benefice_brut = ca_brut - charges
                WHERE faction = ?
            ");
            $stmt->execute([$amount, $companyFaction]);
        }
        
        // S'assurer que la ligne existe
        $stmt = $db->prepare("
            INSERT IGNORE INTO company_finances (faction, ca_brut, charges, benefice_brut, impot_du, benefice_net, tresorerie)
            VALUES (?, 0, 0, 0, 0, 0, 0)
        ");
        $stmt->execute([$companyFaction]);
        
        $db->commit();
        
        $message = 'Dépense ajoutée';
        $messageType = 'success';
        
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// SUPPRIMER DÉPENSE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    try {
        $db->beginTransaction();
        
        // Récupérer la dépense
        $stmt = $db->prepare("SELECT * FROM company_expenses WHERE id = ? AND faction = ?");
        $stmt->execute([intval($_POST['expense_id']), $companyFaction]);
        $expense = $stmt->fetch();
        
        if ($expense) {
            // Supprimer
            $stmt = $db->prepare("DELETE FROM company_expenses WHERE id = ? AND faction = ?");
            $stmt->execute([$expense['id'], $companyFaction]);
            
            // Mettre à jour les finances si déductible
            if ($expense['is_tax_deductible']) {
                $stmt = $db->prepare("
                    UPDATE company_finances 
                    SET charges = charges - ?,
                        benefice_brut = ca_brut - charges
                    WHERE faction = ?
                ");
                $stmt->execute([$expense['amount'], $companyFaction]);
            }
        }
        
        $db->commit();
        
        $message = 'Dépense supprimée';
        $messageType = 'success';
        
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// RÉCUPÉRER LES DÉPENSES
$stmt = $db->prepare("
    SELECT ce.*,
           u.username as added_by_name
    FROM company_expenses ce
    JOIN users u ON ce.added_by = u.id
    WHERE ce.faction = ?
    ORDER BY ce.expense_date DESC, ce.created_at DESC
    LIMIT 100
");
$stmt->execute([$companyFaction]);
$expenses = $stmt->fetchAll();

// STATISTIQUES
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_count,
        SUM(amount) as total_amount,
        SUM(CASE WHEN is_tax_deductible = 1 THEN amount ELSE 0 END) as deductible_amount,
        SUM(CASE WHEN is_tax_deductible = 0 THEN amount ELSE 0 END) as non_deductible_amount,
        SUM(CASE WHEN MONTH(expense_date) = MONTH(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE()) THEN amount ELSE 0 END) as month_total
    FROM company_expenses
    WHERE faction = ?
");
$stmt->execute([$companyFaction]);
$stats = $stmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-box {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.1), rgba(219, 39, 119, 0.05));
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 14px;
    padding: 1.75rem;
    text-align: center;
}

.stat-icon {
    width: 60px;
    height: 60px;
    background: rgba(236, 72, 153, 0.15);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    font-size: 1.75rem;
}

.stat-icon.red {
    color: #ef4444;
}

.stat-icon.orange {
    color: #f59e0b;
}

.stat-icon.green {
    color: #22c55e;
}

.stat-icon.blue {
    color: #60a5fa;
}

.stat-value {
    font-size: 2rem;
    font-weight: 900;
    font-family: 'Orbitron', monospace;
    margin-bottom: 0.5rem;
}

.stat-value.red {
    color: #ef4444;
}

.stat-value.orange {
    color: #f59e0b;
}

.stat-value.green {
    color: #22c55e;
}

.stat-value.blue {
    color: #60a5fa;
}

.stat-label {
    font-size: 0.75rem;
    color: #94a3b8;
    text-transform: uppercase;
}

.expenses-table {
    width: 100%;
    border-collapse: collapse;
}

.expenses-table th {
    text-align: left;
    padding: 1rem;
    background: rgba(236, 72, 153, 0.08);
    border-bottom: 2px solid rgba(236, 72, 153, 0.3);
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #94a3b8;
    font-weight: 700;
}

.expenses-table td {
    padding: 1rem;
    border-bottom: 1px solid rgba(59, 130, 246, 0.1);
}

.expenses-table tr:hover {
    background: rgba(236, 72, 153, 0.05);
}

.expense-amount {
    font-size: 1.1rem;
    font-weight: 900;
    font-family: 'Orbitron', monospace;
    color: #ef4444;
}

.deductible-badge {
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.deductible-yes {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.deductible-no {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

.info-box {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(30, 64, 175, 0.05));
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    display: flex;
    align-items: start;
    gap: 1rem;
}

.info-icon-box {
    width: 50px;
    height: 50px;
    background: rgba(59, 130, 246, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #60a5fa;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.info-content h3 {
    font-size: 1rem;
    font-weight: 700;
    color: #e8edf5;
    margin-bottom: 0.5rem;
}

.info-content p {
    color: #94a3b8;
    line-height: 1.6;
    font-size: 0.9rem;
}

.info-list {
    list-style: none;
    padding: 0;
    margin-top: 0.75rem;
}

.info-list li {
    padding: 0.5rem 0;
    padding-left: 1.5rem;
    position: relative;
}

.info-list li::before {
    content: '✓';
    position: absolute;
    left: 0;
    color: #22c55e;
    font-weight: 900;
}

@media (max-width: 1024px) {
    .expenses-table {
        font-size: 0.85rem;
    }
    
    .expenses-table th,
    .expenses-table td {
        padding: 0.75rem 0.5rem;
    }
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Info Déductibilité -->
<div class="info-box">
    <div class="info-icon-box">
        <i class="fas fa-info-circle"></i>
    </div>
    <div class="info-content">
        <h3>À propos des dépenses déductibles</h3>
        <p>Les dépenses déductibles réduisent directement votre bénéfice brut imposable, diminuant ainsi vos impôts.</p>
        <ul class="info-list">
            <li>Déductible : Charges d'exploitation (salaires, loyer, fournitures, etc.)</li>
            <li>Non-déductible : Amendes, dividendes, dépenses personnelles, etc.</li>
        </ul>
    </div>
</div>

<!-- Statistiques -->
<div class="stats-grid">
    <div class="stat-box">
        <div class="stat-icon blue">
            <i class="fas fa-receipt"></i>
        </div>
        <div class="stat-value blue"><?php echo $stats['total_count'] ?? 0; ?></div>
        <div class="stat-label">Dépenses Totales</div>
    </div>
    
    <div class="stat-box">
        <div class="stat-icon red">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-value red">$<?php echo number_format($stats['total_amount'] ?? 0, 0); ?></div>
        <div class="stat-label">Montant Total</div>
    </div>
    
    <div class="stat-box">
        <div class="stat-icon green">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-value green">$<?php echo number_format($stats['deductible_amount'] ?? 0, 0); ?></div>
        <div class="stat-label">Déductibles</div>
    </div>
    
    <div class="stat-box">
        <div class="stat-icon orange">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-value orange">$<?php echo number_format($stats['month_total'] ?? 0, 0); ?></div>
        <div class="stat-label">Ce Mois</div>
    </div>
</div>

<!-- Actions -->
<?php if ($canManage): ?>
    <div style="display: flex; gap: 1rem; margin-bottom: 2rem;">
        <button onclick="Company.openModal('addExpenseModal')" class="btn btn-primary">
            <i class="fas fa-plus"></i> Ajouter une Dépense
        </button>
    </div>
<?php else: ?>
    <div class="info-banner">
        <div class="info-icon">
            <i class="fas fa-info-circle"></i>
        </div>
        <div>
            <strong>Information:</strong> Vous ne pouvez pas gérer les dépenses avec votre grade actuel.
        </div>
    </div>
<?php endif; ?>

<!-- Liste Dépenses -->
<div class="card">
    <h2 style="margin-bottom: 1.5rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fas fa-list"></i>
        Historique des Dépenses (<?php echo count($expenses); ?>)
    </h2>
    
    <?php if (empty($expenses)): ?>
        <div style="text-align: center; padding: 4rem 2rem; color: #64748b;">
            <i class="fas fa-receipt" style="font-size: 4rem; opacity: 0.3; margin-bottom: 1rem;"></i>
            <p>Aucune dépense enregistrée</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="expenses-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Dépense</th>
                        <th>Montant</th>
                        <th>Déductible</th>
                        <th>Ajoutée par</th>
                        <?php if ($canManage): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td style="font-weight: 600; color: #e8edf5;">
                                <?php echo date('d/m/Y', strtotime($expense['expense_date'])); ?>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #e8edf5; margin-bottom: 0.25rem;">
                                    <?php echo htmlspecialchars($expense['expense_name']); ?>
                                </div>
                                <?php if ($expense['description']): ?>
                                    <div style="font-size: 0.85rem; color: #94a3b8;">
                                        <?php echo htmlspecialchars($expense['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="expense-amount">-$<?php echo number_format($expense['amount'], 2); ?></span>
                            </td>
                            <td>
                                <?php if ($expense['is_tax_deductible']): ?>
                                    <span class="deductible-badge deductible-yes">
                                        <i class="fas fa-check"></i> Oui
                                    </span>
                                <?php else: ?>
                                    <span class="deductible-badge deductible-no">
                                        <i class="fas fa-times"></i> Non
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="color: #94a3b8;">
                                <?php echo htmlspecialchars($expense['added_by_name']); ?>
                            </td>
                            <?php if ($canManage): ?>
                                <td>
                                    <form method="POST" style="margin: 0;" data-confirm="Supprimer cette dépense ?">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Ajouter Dépense -->
<?php if ($canManage): ?>
<div id="addExpenseModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Ajouter une Dépense</h2>
            <button class="modal-close" onclick="Company.closeModal('addExpenseModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom de la Dépense *</label>
                        <input type="text" name="expense_name" class="form-input" required placeholder="Ex: Achat de fournitures">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Montant *</label>
                        <input type="number" name="amount" class="form-input" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date *</label>
                        <input type="date" name="expense_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3" placeholder="Détails supplémentaires..."></textarea>
                    </div>
                    
                    <div class="form-group form-full">
                        <label style="display: flex; align-items: center; gap: 1rem; cursor: pointer; padding: 1rem; background: rgba(34, 197, 94, 0.08); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 10px;">
                            <input type="checkbox" name="is_tax_deductible" checked style="width: 20px; height: 20px; cursor: pointer;">
                            <div>
                                <div style="font-weight: 700; color: #22c55e; margin-bottom: 0.25rem;">
                                    <i class="fas fa-check-circle"></i> Dépense déductible des impôts
                                </div>
                                <div style="font-size: 0.85rem; color: #94a3b8;">
                                    Cette dépense réduira le bénéfice brut imposable
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('addExpenseModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Ajouter
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>