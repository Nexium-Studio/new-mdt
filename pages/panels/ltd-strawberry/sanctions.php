<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = 'LTD Strawberry';
$companyColor = '#0ea5e9';
$companyFaction = 'LTD_STRAWBERRY';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Sanctions';
$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'sanctions.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'sanctions.create');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'sanctions.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'sanctions.delete');

// AJOUTER SANCTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add' && $canManage) {
    try {
        $stmt = $db->prepare("
            INSERT INTO company_sanctions (
                faction, employee_id, issued_by, sanction_type, reason, date_issued, date_expires, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $companyFaction,
            intval($_POST['employee_id']),
            $_SESSION['user_id'],
            trim($_POST['sanction_type']),
            trim($_POST['reason']),
            $_POST['date_issued'],
            !empty($_POST['date_expires']) ? $_POST['date_expires'] : null
        ]);
        
        $message = 'Sanction ajoutée';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// MODIFIER STATUT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status' && $canManage) {
    try {
        $stmt = $db->prepare("
            UPDATE company_sanctions 
            SET status = ?
            WHERE id = ? AND faction = ?
        ");
        $stmt->execute([$_POST['status'], intval($_POST['sanction_id']), $companyFaction]);
        
        $message = 'Statut mis à jour';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// SUPPRIMER SANCTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    try {
        $stmt = $db->prepare("DELETE FROM company_sanctions WHERE id = ? AND faction = ?");
        $stmt->execute([intval($_POST['sanction_id']), $companyFaction]);
        
        $message = 'Sanction supprimée';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// RÉCUPÉRER SANCTIONS
$stmt = $db->prepare("
    SELECT cs.*,
           emp.username as employee_name,
           emp.faction_rank as employee_rank,
           issuer.username as issuer_name
    FROM company_sanctions cs
    JOIN users emp ON cs.employee_id = emp.id
    JOIN users issuer ON cs.issued_by = issuer.id
    WHERE cs.faction = ?
    ORDER BY cs.date_issued DESC
");
$stmt->execute([$companyFaction]);
$sanctions = $stmt->fetchAll();

// LISTE EMPLOYÉS
$stmt = $db->prepare("
    SELECT u.id, u.username, u.faction_rank
    FROM users u
    WHERE u.faction = ?
    ORDER BY u.username
");
$stmt->execute([$companyFaction]);
$employees = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
.sanctions-grid {
    display: grid;
    gap: 1.5rem;
}

.sanction-card {
    background: linear-gradient(135deg, rgba(15, 23, 41, 0.8), rgba(10, 14, 26, 0.6));
    border: 1px solid rgba(236, 72, 153, 0.15);
    border-left: 4px solid;
    border-radius: 14px;
    padding: 1.75rem;
    transition: all 0.3s;
    position: relative;
}

.sanction-card.active {
    border-left-color: #ef4444;
}

.sanction-card.completed {
    border-left-color: #22c55e;
    opacity: 0.7;
}

.sanction-card.cancelled {
    border-left-color: #64748b;
    opacity: 0.6;
}

.sanction-card:hover {
    border-color: rgba(236, 72, 153, 0.4);
    transform: translateX(5px);
}

.sanction-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1.25rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(236, 72, 153, 0.1);
}

.sanction-employee {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.employee-avatar {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #ec4899, #db2777);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 1.25rem;
    color: white;
}

.employee-info h3 {
    font-size: 1.1rem;
    font-weight: 800;
    color: #e8edf5;
    margin-bottom: 0.25rem;
}

.employee-rank {
    font-size: 0.85rem;
    color: #94a3b8;
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.5);
    color: #ef4444;
}

.status-completed {
    background: rgba(34, 197, 94, 0.2);
    border: 1px solid rgba(34, 197, 94, 0.5);
    color: #22c55e;
}

.status-cancelled {
    background: rgba(100, 116, 139, 0.2);
    border: 1px solid rgba(100, 116, 139, 0.5);
    color: #94a3b8;
}

.sanction-type {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1rem;
    background: rgba(236, 72, 153, 0.15);
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 8px;
    font-weight: 700;
    color: #ec4899;
    margin-bottom: 1rem;
}

.sanction-reason {
    background: rgba(0, 0, 0, 0.2);
    padding: 1rem;
    border-radius: 10px;
    margin-bottom: 1rem;
    color: #cbd5e1;
    line-height: 1.6;
}

.sanction-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(236, 72, 153, 0.1);
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.meta-icon {
    width: 36px;
    height: 36px;
    background: rgba(236, 72, 153, 0.1);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ec4899;
}

.meta-content {
    flex: 1;
}

.meta-label {
    font-size: 0.75rem;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}

.meta-value {
    font-weight: 700;
    color: #e8edf5;
}

.sanction-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-item {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.1), rgba(219, 39, 119, 0.05));
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 12px;
    padding: 1.25rem;
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 900;
    font-family: 'Orbitron', monospace;
    margin-bottom: 0.25rem;
}

.stat-value.active {
    color: #ef4444;
}

.stat-value.completed {
    color: #22c55e;
}

.stat-value.total {
    color: #60a5fa;
}

.stat-label {
    font-size: 0.75rem;
    color: #94a3b8;
    text-transform: uppercase;
}

.filter-tabs {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid rgba(236, 72, 153, 0.2);
}

.filter-tab {
    padding: 0.75rem 1.5rem;
    background: transparent;
    border: none;
    color: #94a3b8;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
}

.filter-tab.active {
    color: #ec4899;
    border-bottom-color: #ec4899;
}

.filter-tab:hover {
    color: #ec4899;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Stats -->
<?php
$activeCount = count(array_filter($sanctions, fn($s) => $s['status'] === 'active'));
$completedCount = count(array_filter($sanctions, fn($s) => $s['status'] === 'completed'));
$totalCount = count($sanctions);
?>

<div class="stats-bar">
    <div class="stat-item">
        <div class="stat-value active"><?php echo $activeCount; ?></div>
        <div class="stat-label">En cours</div>
    </div>
    <div class="stat-item">
        <div class="stat-value completed"><?php echo $completedCount; ?></div>
        <div class="stat-label">Terminées</div>
    </div>
    <div class="stat-item">
        <div class="stat-value total"><?php echo $totalCount; ?></div>
        <div class="stat-label">Total</div>
    </div>
</div>

<!-- Actions -->
<div style="display: flex; gap: 1rem; margin-bottom: 2rem;">
    <?php if ($canManage): ?>
        <button onclick="Company.openModal('addSanctionModal')" class="btn btn-primary">
            <i class="fas fa-plus"></i> Ajouter une Sanction
        </button>
    <?php endif; ?>
</div>

<!-- Filtres -->
<div class="filter-tabs">
    <button class="filter-tab active" onclick="filterSanctions('all')">
        <i class="fas fa-list"></i> Toutes
    </button>
    <button class="filter-tab" onclick="filterSanctions('active')">
        <i class="fas fa-exclamation-triangle"></i> En cours
    </button>
    <button class="filter-tab" onclick="filterSanctions('completed')">
        <i class="fas fa-check-circle"></i> Terminées
    </button>
    <button class="filter-tab" onclick="filterSanctions('cancelled')">
        <i class="fas fa-ban"></i> Annulées
    </button>
</div>

<!-- Liste Sanctions -->
<?php if (empty($sanctions)): ?>
    <div class="card">
        <div style="text-align: center; padding: 4rem 2rem; color: #64748b;">
            <i class="fas fa-gavel" style="font-size: 4rem; opacity: 0.3; margin-bottom: 1rem;"></i>
            <p>Aucune sanction enregistrée</p>
        </div>
    </div>
<?php else: ?>
    <div class="sanctions-grid">
        <?php foreach ($sanctions as $sanction): ?>
            <div class="sanction-card <?php echo $sanction['status']; ?>" data-status="<?php echo $sanction['status']; ?>">
                <div class="sanction-header">
                    <div class="sanction-employee">
                        <div class="employee-avatar">
                            <?php echo strtoupper(substr($sanction['employee_name'], 0, 1)); ?>
                        </div>
                        <div class="employee-info">
                            <h3><?php echo htmlspecialchars($sanction['employee_name']); ?></h3>
                            <div class="employee-rank"><?php echo htmlspecialchars($sanction['employee_rank']); ?></div>
                        </div>
                    </div>
                    <span class="status-badge status-<?php echo $sanction['status']; ?>">
                        <?php 
                        $statusNames = [
                            'active' => 'En cours',
                            'completed' => 'Terminée',
                            'cancelled' => 'Annulée'
                        ];
                        echo $statusNames[$sanction['status']];
                        ?>
                    </span>
                </div>
                
                <div class="sanction-type">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($sanction['sanction_type']); ?>
                </div>
                
                <div class="sanction-reason">
                    <strong>Motif :</strong><br>
                    <?php echo nl2br(htmlspecialchars($sanction['reason'])); ?>
                </div>
                
                <div class="sanction-meta">
                    <div class="meta-item">
                        <div class="meta-icon">
                            <i class="fas fa-calendar"></i>
                        </div>
                        <div class="meta-content">
                            <div class="meta-label">Date émission</div>
                            <div class="meta-value"><?php echo date('d/m/Y', strtotime($sanction['date_issued'])); ?></div>
                        </div>
                    </div>
                    
                    <?php if ($sanction['date_expires']): ?>
                    <div class="meta-item">
                        <div class="meta-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="meta-content">
                            <div class="meta-label">Date expiration</div>
                            <div class="meta-value"><?php echo date('d/m/Y', strtotime($sanction['date_expires'])); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="meta-item">
                        <div class="meta-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="meta-content">
                            <div class="meta-label">Émise par</div>
                            <div class="meta-value"><?php echo htmlspecialchars($sanction['issuer_name']); ?></div>
                        </div>
                    </div>
                </div>
                
                <?php if ($canManage): ?>
                <div class="sanction-actions">
                    <?php if ($sanction['status'] === 'active'): ?>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="sanction_id" value="<?php echo $sanction['id']; ?>">
                            <input type="hidden" name="status" value="completed">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i class="fas fa-check"></i> Marquer comme terminée
                            </button>
                        </form>
                        
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="sanction_id" value="<?php echo $sanction['id']; ?>">
                            <input type="hidden" name="status" value="cancelled">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i class="fas fa-ban"></i> Annuler
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Supprimer cette sanction ?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="sanction_id" value="<?php echo $sanction['id']; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <i class="fas fa-trash"></i> Supprimer
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal Ajouter Sanction -->
<?php if ($canManage): ?>
<div id="addSanctionModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-gavel"></i> Ajouter une Sanction</h2>
            <button class="modal-close" onclick="Company.closeModal('addSanctionModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Employé *</label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">Sélectionner</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>">
                                    <?php echo htmlspecialchars($emp['username']); ?> - <?php echo htmlspecialchars($emp['faction_rank']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Type de Sanction *</label>
                        <select name="sanction_type" class="form-select" required>
                            <option value="">Sélectionner</option>
                            <option value="Avertissement">Avertissement</option>
                            <option value="Blâme">Blâme</option>
                            <option value="Mise à pied">Mise à pied</option>
                            <option value="Rétrogradation">Rétrogradation</option>
                            <option value="Licenciement">Licenciement</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date d'émission *</label>
                        <input type="date" name="date_issued" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date d'expiration</label>
                        <input type="date" name="date_expires" class="form-input">
                        <small style="color: #64748b; font-size: 0.8rem; margin-top: 0.25rem; display: block;">
                            Laisser vide si permanente
                        </small>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Motif *</label>
                        <textarea name="reason" class="form-textarea" rows="5" required placeholder="Décrivez en détail les raisons de la sanction..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('addSanctionModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Ajouter
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function filterSanctions(status) {
    // Mettre à jour les tabs
    document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
    event.target.classList.add('active');
    
    // Filtrer les cartes
    const cards = document.querySelectorAll('.sanction-card');
    cards.forEach(card => {
        if (status === 'all' || card.dataset.status === status) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>