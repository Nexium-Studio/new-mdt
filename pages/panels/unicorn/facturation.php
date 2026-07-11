<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = 'Unicorn';
$companyColor = '#ec4899';
$companyFaction = 'UNICORN';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Facturation';
$db = Database::getInstance()->getConnection();
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'facturation.view')) faction_acl_deny();
$canCreate = faction_acl_can($db, $uid, $companyFaction, 'facturation.create');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'facturation.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'facturation.delete');
$canSend   = faction_acl_can($db, $uid, $companyFaction, 'facturation.send');
$canManage = $canCreate;
$message = '';
$messageType = '';

// MODIFIER STATUT FACTURE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    try {
        $stmt = $db->prepare("
            UPDATE company_invoices 
            SET status = ?
            WHERE id = ? AND faction = ?
        ");
        $stmt->execute([$_POST['status'], intval($_POST['invoice_id']), $companyFaction]);
        
        $message = 'Statut mis à jour';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// SUPPRIMER FACTURE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        $stmt = $db->prepare("DELETE FROM company_invoices WHERE id = ? AND faction = ?");
        $stmt->execute([intval($_POST['invoice_id']), $companyFaction]);
        
        $message = 'Facture supprimée';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// FILTRES
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$dateFilter = $_GET['date'] ?? 'all';

// CONSTRUCTION REQUÊTE
$query = "
    SELECT ci.*,
           u.username as employee_name,
           u.faction_rank as employee_rank
    FROM company_invoices ci
    JOIN users u ON ci.employee_id = u.id
    WHERE ci.faction = ?
";

$params = [$companyFaction];

if ($search) {
    $query .= " AND (ci.customer_name LIKE ? OR u.username LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($statusFilter !== 'all') {
    $query .= " AND ci.status = ?";
    $params[] = $statusFilter;
}

if ($dateFilter === 'today') {
    $query .= " AND DATE(ci.created_at) = CURDATE()";
} elseif ($dateFilter === 'week') {
    $query .= " AND YEARWEEK(ci.created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($dateFilter === 'month') {
    $query .= " AND MONTH(ci.created_at) = MONTH(CURDATE()) AND YEAR(ci.created_at) = YEAR(CURDATE())";
}

$query .= " ORDER BY ci.created_at DESC LIMIT 100";

$stmt = $db->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// STATISTIQUES
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN status = 'paid' THEN final_amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN status = 'pending' THEN final_amount ELSE 0 END) as total_pending
    FROM company_invoices
    WHERE faction = ?
        AND MONTH(created_at) = MONTH(CURRENT_DATE())
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$stmt->execute([$companyFaction]);
$stats = $stmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<style>
.filters-bar {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.1), rgba(219, 39, 119, 0.05));
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 14px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    gap: 1rem;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #94a3b8;
    letter-spacing: 1px;
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.1), rgba(219, 39, 119, 0.05));
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 900;
    font-family: 'Orbitron', monospace;
    margin-bottom: 0.5rem;
}

.stat-value.green {
    color: #22c55e;
}

.stat-value.orange {
    color: #f59e0b;
}

.stat-value.blue {
    color: #60a5fa;
}

.stat-value.red {
    color: #ef4444;
}

.stat-label {
    font-size: 0.75rem;
    color: #94a3b8;
    text-transform: uppercase;
}

.invoices-table {
    width: 100%;
    border-collapse: collapse;
}

.invoices-table th {
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

.invoices-table td {
    padding: 1rem;
    border-bottom: 1px solid rgba(59, 130, 246, 0.1);
    vertical-align: middle;
}

.invoices-table tr:hover {
    background: rgba(236, 72, 153, 0.05);
}

.invoice-id {
    font-family: 'JetBrains Mono', monospace;
    font-weight: 700;
    color: #60a5fa;
}

.invoice-amount {
    font-size: 1.1rem;
    font-weight: 900;
    font-family: 'Orbitron', monospace;
    color: #22c55e;
}

.invoice-discount {
    font-size: 0.85rem;
    color: #f59e0b;
    font-weight: 700;
}

.status-badge {
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    display: inline-block;
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

.invoice-actions {
    display: flex;
    gap: 0.5rem;
}

.description-preview {
    max-width: 300px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #94a3b8;
    font-size: 0.9rem;
}

@media (max-width: 1024px) {
    .filters-bar {
        grid-template-columns: 1fr;
    }
    
    .invoices-table {
        font-size: 0.85rem;
    }
    
    .invoices-table th,
    .invoices-table td {
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

<!-- Statistiques du Mois -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-value blue"><?php echo $stats['total'] ?? 0; ?></div>
        <div class="stat-label">Factures ce Mois</div>
    </div>
    <div class="stat-card">
        <div class="stat-value green"><?php echo $stats['paid_count'] ?? 0; ?></div>
        <div class="stat-label">Payées</div>
    </div>
    <div class="stat-card">
        <div class="stat-value orange"><?php echo $stats['pending_count'] ?? 0; ?></div>
        <div class="stat-label">En Attente</div>
    </div>
    <div class="stat-card">
        <div class="stat-value green">$<?php echo number_format($stats['total_paid'] ?? 0, 0); ?></div>
        <div class="stat-label">Encaissé</div>
    </div>
    <div class="stat-card">
        <div class="stat-value orange">$<?php echo number_format($stats['total_pending'] ?? 0, 0); ?></div>
        <div class="stat-label">En Attente</div>
    </div>
</div>

<!-- Filtres -->
<form method="GET" class="filters-bar">
    <div class="filter-group">
        <label class="filter-label">Rechercher</label>
        <input type="text" name="search" class="form-input" placeholder="Client ou employé..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    
    <div class="filter-group">
        <label class="filter-label">Statut</label>
        <select name="status" class="form-select">
            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Tous</option>
            <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Payées</option>
            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>En attente</option>
            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Annulées</option>
        </select>
    </div>
    
    <div class="filter-group">
        <label class="filter-label">Période</label>
        <select name="date" class="form-select">
            <option value="all" <?php echo $dateFilter === 'all' ? 'selected' : ''; ?>>Toutes</option>
            <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Aujourd'hui</option>
            <option value="week" <?php echo $dateFilter === 'week' ? 'selected' : ''; ?>>Cette semaine</option>
            <option value="month" <?php echo $dateFilter === 'month' ? 'selected' : ''; ?>>Ce mois</option>
        </select>
    </div>
    
    <div class="filter-group" style="grid-column: 1 / -1;">
        <button type="submit" class="btn btn-primary" style="width: auto;">
            <i class="fas fa-search"></i> Filtrer
        </button>
        <a href="facturation.php" class="btn btn-secondary" style="width: auto; margin-left: 1rem;">
            <i class="fas fa-times"></i> Réinitialiser
        </a>
    </div>
</form>

<!-- Liste Factures -->
<div class="card">
    <h2 style="margin-bottom: 1.5rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fas fa-file-invoice-dollar"></i>
        Factures (<?php echo count($invoices); ?> résultats)
    </h2>
    
    <?php if (empty($invoices)): ?>
        <div style="text-align: center; padding: 4rem 2rem; color: #64748b;">
            <i class="fas fa-file-invoice" style="font-size: 4rem; opacity: 0.3; margin-bottom: 1rem;"></i>
            <p>Aucune facture trouvée</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="invoices-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Employé</th>
                        <th>Montant</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td>
                                <span class="invoice-id">#<?php echo str_pad($inv['id'], 5, '0', STR_PAD_LEFT); ?></span>
                            </td>
                            <td style="font-weight: 600; color: #e8edf5;">
                                <?php echo date('d/m/Y H:i', strtotime($inv['created_at'])); ?>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #e8edf5;">
                                    <?php echo htmlspecialchars($inv['customer_name'] ?? 'N/A'); ?>
                                </div>
                                <?php if ($inv['description']): ?>
                                    <div class="description-preview" title="<?php echo htmlspecialchars($inv['description']); ?>">
                                        <?php echo htmlspecialchars($inv['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #e8edf5;">
                                    <?php echo htmlspecialchars($inv['employee_name']); ?>
                                </div>
                                <div style="font-size: 0.85rem; color: #64748b;">
                                    <?php echo htmlspecialchars($inv['employee_rank']); ?>
                                </div>
                            </td>
                            <td>
                                <div class="invoice-amount">$<?php echo number_format($inv['final_amount'], 2); ?></div>
                                <?php if ($inv['discount'] != 0): ?>
                                    <div class="invoice-discount">
                                        <?php echo $inv['discount'] > 0 ? '+' : ''; ?>$<?php echo number_format($inv['discount'], 2); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $inv['status']; ?>">
                                    <?php 
                                    $statusNames = [
                                        'pending' => 'En attente',
                                        'paid' => 'Payée',
                                        'cancelled' => 'Annulée'
                                    ];
                                    echo $statusNames[$inv['status']];
                                    ?>
                                </span>
                            </td>
                            <td>
                                <div class="invoice-actions">
                                    <button onclick="viewInvoice(<?php echo $inv['id']; ?>)" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($inv['status'] !== 'paid'): ?>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                            <input type="hidden" name="status" value="paid">
                                            <button type="submit" class="btn btn-secondary btn-sm" title="Marquer comme payée">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Supprimer cette facture ?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Détails Facture -->
<div id="invoiceModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-file-invoice-dollar"></i> Détails de la Facture</h2>
            <button class="modal-close" onclick="Company.closeModal('invoiceModal')">×</button>
        </div>
        <div class="modal-body" id="invoiceDetails">
            <div style="text-align: center; padding: 2rem; color: #64748b;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                <p style="margin-top: 1rem;">Chargement...</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="Company.closeModal('invoiceModal')">Fermer</button>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(SITE_URL . ASSETS_PATH); ?>/js/invoice-detail-view.js"></script>
<script>
function viewInvoice(id) {
    Company.openModal('invoiceModal');
    
    // Simuler le chargement - Dans une vraie app, utiliser AJAX pour récupérer les détails
    const invoice = <?php echo json_encode($invoices); ?>.find(inv => inv.id === id);
    
    if (invoice) {
        const statusNames = {
            'pending': 'En attente',
            'paid': 'Payée',
            'cancelled': 'Annulée'
        };
        
        const statusColors = {
            'pending': '#f59e0b',
            'paid': '#22c55e',
            'cancelled': '#94a3b8'
        };
        
        document.getElementById('invoiceDetails').innerHTML = `
            <div style="background: rgba(236, 72, 153, 0.08); padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div style="font-family: 'JetBrains Mono', monospace; font-size: 1.5rem; font-weight: 900; color: #60a5fa;">
                        #${String(invoice.id).padStart(5, '0')}
                    </div>
                    <div style="padding: 0.6rem 1.2rem; background: rgba(${statusColors[invoice.status]}, 0.15); border: 1px solid rgba(${statusColors[invoice.status]}, 0.3); border-radius: 8px; font-weight: 700; color: ${statusColors[invoice.status]};">
                        ${statusNames[invoice.status]}
                    </div>
                </div>
                <div style="color: #94a3b8; font-size: 0.9rem;">
                    <i class="fas fa-calendar"></i> ${new Date(invoice.created_at).toLocaleString('fr-FR')}
                </div>
            </div>
            
            <div style="display: grid; gap: 1.5rem;">
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; margin-bottom: 0.5rem;">Client</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #e8edf5;">${invoice.customer_name || 'N/A'}</div>
                </div>
                
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; margin-bottom: 0.5rem;">Employé</div>
                    <div style="font-weight: 700; color: #e8edf5;">${invoice.employee_name}</div>
                    <div style="font-size: 0.9rem; color: #64748b;">${invoice.employee_rank}</div>
                </div>
                
                ${invoice.description ? `
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; margin-bottom: 0.5rem;">Détail de la facture</div>
                        <div class="mdt-inv-modal-wrap" style="background: rgba(15, 23, 42, 0.45); padding: 0.75rem; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.15);">${typeof MDTInvoiceDetail !== 'undefined' ? MDTInvoiceDetail.format(invoice.description) : ''}</div>
                    </div>
                ` : ''}
                
                <div style="background: linear-gradient(135deg, rgba(236, 72, 153, 0.15), rgba(219, 39, 119, 0.08)); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(236, 72, 153, 0.3);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                        <span style="color: #94a3b8;">Montant initial</span>
                        <span style="font-weight: 700; color: #e8edf5;">$${parseFloat(invoice.amount).toFixed(2)}</span>
                    </div>
                    ${invoice.discount != 0 ? `
                        <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                            <span style="color: #94a3b8;">${invoice.discount > 0 ? 'Supplément' : 'Réduction'}</span>
                            <span style="font-weight: 700; color: #f59e0b;">${invoice.discount > 0 ? '+' : ''}$${parseFloat(invoice.discount).toFixed(2)}</span>
                        </div>
                    ` : ''}
                    <div style="border-top: 2px solid rgba(236, 72, 153, 0.3); padding-top: 1rem; display: flex; justify-content: space-between;">
                        <span style="font-size: 1.1rem; font-weight: 700; color: #e8edf5;">TOTAL</span>
                        <span style="font-size: 1.5rem; font-weight: 900; color: #22c55e; font-family: 'Orbitron', monospace;">$${parseFloat(invoice.final_amount).toFixed(2)}</span>
                    </div>
                </div>
            </div>
        `;
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>