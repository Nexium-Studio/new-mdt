<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = '🐈 UwU Coffee';
$companyColor = '#ec4899';
$companyFaction = 'UWU_COFFEE';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Effectifs';
$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Vérifier permissions
$stmt = $db->prepare("
    SELECT fg.can_recruit, fg.can_manage_finances, fg.is_owner, fg.is_ceo
    FROM faction_grades fg
    JOIN factions f ON f.id = fg.faction_id
    WHERE f.faction_key = ? AND fg.name = ?
");
$stmt->execute([$companyFaction, $_SESSION['user_faction_rank'] ?? '']);
$permissions = $stmt->fetch() ?: ['can_recruit' => false, 'can_manage_finances' => false, 'is_owner' => false, 'is_ceo' => false];

$canRecruit = $permissions['can_recruit'] || hasRole(['*', 'Administrateur']);
$canManageFinances = $permissions['can_manage_finances'] || hasRole(['*', 'Administrateur']);
$isOwnerOrCEO = $permissions['is_owner'] || $permissions['is_ceo'] || hasRole(['*', 'Administrateur']);

// RECRUTEMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recruit' && $canRecruit) {
    try {
        $userId = intval($_POST['citizen_id']);
        $rank = $_POST['rank'];
        $salaryPercentage = floatval($_POST['salary_percentage']);
        
        // Vérifier si l'utilisateur existe et n'a pas de faction
        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ? AND (faction IS NULL OR faction = 'NONE')");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception("Utilisateur introuvable ou déjà dans une faction");
        }
        
        // Mettre à jour la faction de l'utilisateur
        $stmt = $db->prepare("UPDATE users SET faction = ?, faction_rank = ? WHERE id = ?");
        $stmt->execute([$companyFaction, $rank, $userId]);
        
        // Ajouter dans company_employees
        $stmt = $db->prepare("
            INSERT INTO company_employees (user_id, faction, rank, salary_percentage, hired_date)
            VALUES (?, ?, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE rank = ?, salary_percentage = ?
        ");
        $stmt->execute([$userId, $companyFaction, $rank, $salaryPercentage, $rank, $salaryPercentage]);
        
        $message = "{$user['username']} recruté avec succès !";
        $messageType = 'success';
        
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// MODIFIER SALAIRE %
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_salary' && $canManageFinances) {
    try {
        $stmt = $db->prepare("
            UPDATE company_employees 
            SET salary_percentage = ?,
                calculated_salary = (individual_ca * ?) / 100
            WHERE id = ? AND faction = ?
        ");
        $percentage = floatval($_POST['percentage']);
        $stmt->execute([$percentage, $percentage, intval($_POST['employee_id']), $companyFaction]);
        
        $message = 'Pourcentage de salaire mis à jour';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// AJOUTER PRIME
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_bonus' && $isOwnerOrCEO) {
    try {
        $bonus = floatval($_POST['bonus']);
        
        if ($bonus > 15000) {
            throw new Exception("Prime maximum: 15 000$");
        }
        
        // Vérifier le total disponible (30% bénéfice net)
        $stmt = $db->prepare("SELECT benefice_net FROM company_finances WHERE faction = ?");
        $stmt->execute([$companyFaction]);
        $finances = $stmt->fetch();
        $bonusPool = $finances['benefice_net'] * 0.30;
        
        // Calculer total primes déjà distribuées
        $stmt = $db->prepare("SELECT SUM(bonus) as total FROM company_employees WHERE faction = ?");
        $stmt->execute([$companyFaction]);
        $totalBonus = $stmt->fetchColumn() ?? 0;
        
        if (($totalBonus + $bonus) > $bonusPool) {
            throw new Exception("Dépassement du pool de primes disponible ($" . number_format($bonusPool, 2) . ")");
        }
        
        $stmt = $db->prepare("UPDATE company_employees SET bonus = ? WHERE id = ? AND faction = ?");
        $stmt->execute([$bonus, intval($_POST['employee_id']), $companyFaction]);
        
        $message = 'Prime ajoutée';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// CALCULER DIVIDENDES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'calculate_dividends' && $isOwnerOrCEO) {
    try {
        // Récupérer le bénéfice brut
        $stmt = $db->prepare("SELECT benefice_brut FROM company_finances WHERE faction = ?");
        $stmt->execute([$companyFaction]);
        $finances = $stmt->fetch();
        $dividendesTotal = $finances['benefice_brut'] * 0.40;
        
        // Compter PDG et Co-PDG
        $stmt = $db->prepare("
            SELECT COUNT(*) as nb 
            FROM company_employees ce
            JOIN faction_grades fg2 ON fg2.name = ce.rank
            JOIN factions fac2 ON fac2.id = fg2.faction_id AND fac2.faction_key = ce.faction
            WHERE ce.faction = ? AND (fg2.is_owner = TRUE OR fg2.is_ceo = TRUE)
        ");
        $stmt->execute([$companyFaction]);
        $nbOwners = $stmt->fetchColumn();
        
        if ($nbOwners > 0) {
            $dividendPart = $dividendesTotal / $nbOwners;
            
            // Attribuer
            $stmt = $db->prepare("
                UPDATE company_employees ce
                JOIN faction_grades fg2 ON fg2.name = ce.rank
                JOIN factions fac2 ON fac2.id = fg2.faction_id AND fac2.faction_key = ce.faction
                SET ce.dividends = ?
                WHERE ce.faction = ? AND (fg2.is_owner = TRUE OR fg2.is_ceo = TRUE)
            ");
            $stmt->execute([$dividendPart, $companyFaction]);
            
            $message = "Dividendes calculés: $" . number_format($dividendPart, 2) . " par personne";
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// LISTE EMPLOYÉS
$stmt = $db->prepare("
    SELECT ce.*, u.username, u.email,
           fg2.is_owner, fg2.is_ceo
    FROM company_employees ce
    JOIN users u ON ce.user_id = u.id
    LEFT JOIN faction_grades fg2 ON fg2.name = ce.rank JOIN factions fac2 ON fac2.id=fg2.faction_id AND fac2.faction_key=ce.faction
    WHERE ce.faction = ?
    ORDER BY ce.individual_ca DESC
");
$stmt->execute([$companyFaction]);
$employees = $stmt->fetchAll();

// LISTE UTILISATEURS SANS FACTION (pour recrutement)
$stmt = $db->prepare("
    SELECT id, username, email 
    FROM users 
    WHERE (faction IS NULL OR faction = 'NONE')
    ORDER BY username
    LIMIT 100
");
$stmt->execute();
$citizens = $stmt->fetchAll();

// LISTE GRADES
$stmt = $db->prepare("SELECT fg.name AS rank_name
         FROM faction_grades fg
         JOIN factions f ON f.id = fg.faction_id
         WHERE f.faction_key = ?
         ORDER BY fg.rank_order ASC");
$stmt->execute([$companyFaction]);
$ranks = $stmt->fetchAll();

// CALCUL FINANCES POUR AFFICHAGE
$stmt = $db->prepare("SELECT benefice_net, benefice_brut FROM company_finances WHERE faction = ?");
$stmt->execute([$companyFaction]);
$finances = $stmt->fetch() ?: ['benefice_net' => 0, 'benefice_brut' => 0];
$bonusPool = $finances['benefice_net'] * 0.30;
$dividendsPool = $finances['benefice_brut'] * 0.40;

// Total primes déjà distribuées
$stmt = $db->prepare("SELECT SUM(bonus) as total FROM company_employees WHERE faction = ?");
$stmt->execute([$companyFaction]);
$totalBonusUsed = $stmt->fetchColumn() ?? 0;

include __DIR__ . '/includes/header.php';
?>

<style>
.employees-grid {
    display: grid;
    gap: 1.5rem;
}

.employee-card {
    background: linear-gradient(135deg, rgba(15, 23, 41, 0.8), rgba(10, 14, 26, 0.6));
    border: 1px solid rgba(236, 72, 153, 0.15);
    border-radius: 16px;
    padding: 1.75rem;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.employee-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, #ec4899, #db2777);
}

.employee-card:hover {
    border-color: rgba(236, 72, 153, 0.4);
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
}

.employee-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1.5rem;
}

.employee-info h3 {
    font-size: 1.25rem;
    font-weight: 800;
    color: #e8edf5;
    margin-bottom: 0.25rem;
}

.employee-rank {
    font-size: 0.9rem;
    color: #94a3b8;
    font-weight: 600;
}

.owner-badge {
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.3), rgba(219, 39, 119, 0.2));
    border: 1px solid rgba(236, 72, 153, 0.5);
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    color: #ec4899;
    text-transform: uppercase;
}

.employee-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 12px;
}

.stat-box {
    text-align: center;
}

.stat-box-label {
    font-size: 0.75rem;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 0.5rem;
}

.stat-box-value {
    font-size: 1.25rem;
    font-weight: 900;
    color: #e8edf5;
    font-family: 'Orbitron', monospace;
}

.stat-box-value.money {
    color: #22c55e;
}

.stat-box-value.percentage {
    color: #60a5fa;
}

.employee-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.info-banner {
    padding: 1.25rem;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(30, 64, 175, 0.05));
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 12px;
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.info-icon {
    width: 50px;
    height: 50px;
    background: rgba(59, 130, 246, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #60a5fa;
    flex-shrink: 0;
}

.pool-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.pool-card {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.1), rgba(219, 39, 119, 0.05));
    border: 2px solid rgba(236, 72, 153, 0.3);
    border-radius: 14px;
    padding: 1.5rem;
}

.pool-title {
    font-size: 0.85rem;
    color: #94a3b8;
    text-transform: uppercase;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pool-amount {
    font-size: 2rem;
    font-weight: 900;
    color: #22c55e;
    font-family: 'Orbitron', monospace;
    margin-bottom: 0.5rem;
}

.pool-progress {
    height: 8px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 10px;
    overflow: hidden;
    margin-top: 1rem;
}

.pool-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #22c55e, #16a34a);
    border-radius: 10px;
    transition: width 0.5s;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (!$canRecruit): ?>
    <div class="info-banner">
        <div class="info-icon">
            <i class="fas fa-info-circle"></i>
        </div>
        <div>
            <strong>Information:</strong> Vous ne pouvez pas recruter avec votre grade actuel.
        </div>
    </div>
<?php endif; ?>

<!-- Pools Financiers -->
<?php if ($isOwnerOrCEO): ?>
<div class="pool-cards">
    <div class="pool-card">
        <div class="pool-title">
            <i class="fas fa-gift"></i>
            Pool Primes (30% Bénéfice Net)
        </div>
        <div class="pool-amount">$<?php echo number_format($bonusPool, 0); ?></div>
        <div style="font-size: 0.85rem; color: #94a3b8;">
            Utilisé: $<?php echo number_format($totalBonusUsed, 0); ?> / Max 15k par personne
        </div>
        <div class="pool-progress">
            <div class="pool-progress-bar" style="width: <?php echo min(100, ($totalBonusUsed / max($bonusPool, 1)) * 100); ?>%"></div>
        </div>
    </div>
    
    <div class="pool-card">
        <div class="pool-title">
            <i class="fas fa-crown"></i>
            Pool Dividendes (40% Bénéfice Brut)
        </div>
        <div class="pool-amount">$<?php echo number_format($dividendsPool, 0); ?></div>
        <div style="font-size: 0.85rem; color: #94a3b8;">
            Pour PDG et Co-PDG uniquement
        </div>
        <form method="POST" style="margin-top: 1rem;">
            <input type="hidden" name="action" value="calculate_dividends">
            <button type="submit" class="btn btn-secondary btn-sm">
                <i class="fas fa-calculator"></i> Calculer & Attribuer
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Actions -->
<div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
    <?php if ($canRecruit): ?>
        <button onclick="Company.openModal('recruitModal')" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Recruter
        </button>
    <?php endif; ?>
    <span style="font-family: 'Orbitron', monospace; padding: 0.75rem 1.5rem; background: rgba(236, 72, 153, 0.1); border: 1px solid rgba(236, 72, 153, 0.3); border-radius: 10px; color: #ec4899;">
        <i class="fas fa-users"></i> <?php echo count($employees); ?> employé(s)
    </span>
</div>

<!-- Liste Employés -->
<div class="employees-grid">
    <?php foreach ($employees as $emp): ?>
        <?php
        $calculatedSalary = ($emp['individual_ca'] * $emp['salary_percentage']) / 100;
        $isOwner = $emp['is_owner'] || $emp['is_ceo'];
        ?>
        <div class="employee-card">
            <div class="employee-header">
                <div class="employee-info">
                    <h3><?php echo htmlspecialchars($emp['username']); ?></h3>
                    <div class="employee-rank"><?php echo htmlspecialchars($emp['rank']); ?></div>
                </div>
                <?php if ($isOwner): ?>
                    <span class="owner-badge">
                        <i class="fas fa-crown"></i> Direction
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="employee-stats">
                <div class="stat-box">
                    <div class="stat-box-label">CA Individuel</div>
                    <div class="stat-box-value money">$<?php echo number_format($emp['individual_ca'], 0); ?></div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-box-label">Pourcentage</div>
                    <div class="stat-box-value percentage"><?php echo $emp['salary_percentage']; ?>%</div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-box-label">Salaire</div>
                    <div class="stat-box-value money">$<?php echo number_format($calculatedSalary, 0); ?></div>
                </div>
                
                <?php if ($emp['bonus'] > 0): ?>
                <div class="stat-box">
                    <div class="stat-box-label">Prime</div>
                    <div class="stat-box-value money">$<?php echo number_format($emp['bonus'], 0); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($emp['dividends'] > 0 && $isOwner): ?>
                <div class="stat-box">
                    <div class="stat-box-label">Dividendes</div>
                    <div class="stat-box-value money">$<?php echo number_format($emp['dividends'], 0); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($canManageFinances): ?>
            <div class="employee-actions">
                <button onclick="editSalary(<?php echo $emp['id']; ?>, <?php echo $emp['salary_percentage']; ?>, '<?php echo htmlspecialchars($emp['username']); ?>')" class="btn btn-secondary btn-sm">
                    <i class="fas fa-percent"></i> Modifier %
                </button>
                
                <?php if ($isOwnerOrCEO && !$isOwner): ?>
                <button onclick="addBonus(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['username']); ?>')" class="btn btn-secondary btn-sm">
                    <i class="fas fa-gift"></i> Ajouter Prime
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal Recrutement -->
<?php if ($canRecruit): ?>
<div id="recruitModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-user-plus"></i> Recruter un Citoyen</h2>
            <button class="modal-close" onclick="Company.closeModal('recruitModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="recruit">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Utilisateur *</label>
                        <select name="citizen_id" class="form-select" required>
                            <option value="">Sélectionner</option>
                            <?php foreach ($citizens as $cit): ?>
                                <option value="<?php echo $cit['id']; ?>">
                                    <?php echo htmlspecialchars($cit['username']); ?>
                                    <?php echo $cit['email'] ? " ({$cit['email']})" : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Grade *</label>
                        <select name="rank" class="form-select" required>
                            <?php foreach ($ranks as $rank): ?>
                                <option value="<?php echo htmlspecialchars($rank['rank_name']); ?>">
                                    <?php echo htmlspecialchars($rank['rank_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Pourcentage de Paie *</label>
                        <input type="number" name="salary_percentage" class="form-input" value="70" min="0" max="100" step="0.01" required>
                        <small style="color: #64748b; font-size: 0.8rem;">Exemple: 70% du CA individuel</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('recruitModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Recruter
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal Modifier Salaire % -->
<div id="salaryModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-percent"></i> Modifier Pourcentage de Paie</h2>
            <button class="modal-close" onclick="Company.closeModal('salaryModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_salary">
                <input type="hidden" name="employee_id" id="salary_employee_id">
                
                <p style="margin-bottom: 1.5rem; color: #94a3b8;">
                    <strong>Employé:</strong> <span id="salary_employee_name"></span>
                </p>
                
                <div class="form-group">
                    <label class="form-label">Nouveau Pourcentage *</label>
                    <input type="number" name="percentage" id="salary_percentage" class="form-input" min="0" max="100" step="0.01" required>
                    <small style="color: #64748b; font-size: 0.8rem; margin-top: 0.5rem; display: block;">
                        Le salaire sera automatiquement recalculé : (CA individuel × Pourcentage) / 100
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('salaryModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ajouter Prime -->
<div id="bonusModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-gift"></i> Ajouter une Prime</h2>
            <button class="modal-close" onclick="Company.closeModal('bonusModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_bonus">
                <input type="hidden" name="employee_id" id="bonus_employee_id">
                
                <p style="margin-bottom: 1.5rem; color: #94a3b8;">
                    <strong>Employé:</strong> <span id="bonus_employee_name"></span>
                </p>
                
                <div class="form-group">
                    <label class="form-label">Montant de la Prime *</label>
                    <input type="number" name="bonus" class="form-input" min="0" max="15000" step="0.01" required>
                    <small style="color: #f59e0b; font-size: 0.8rem; margin-top: 0.5rem; display: block;">
                        <i class="fas fa-info-circle"></i> Maximum: 15 000$ par employé
                    </small>
                    <small style="color: #64748b; font-size: 0.8rem; margin-top: 0.25rem; display: block;">
                        Pool disponible: $<?php echo number_format($bonusPool - $totalBonusUsed, 2); ?>
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('bonusModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Ajouter
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editSalary(employeeId, currentPercentage, employeeName) {
    document.getElementById('salary_employee_id').value = employeeId;
    document.getElementById('salary_employee_name').textContent = employeeName;
    document.getElementById('salary_percentage').value = currentPercentage;
    Company.openModal('salaryModal');
}

function addBonus(employeeId, employeeName) {
    document.getElementById('bonus_employee_id').value = employeeId;
    document.getElementById('bonus_employee_name').textContent = employeeName;
    Company.openModal('bonusModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>