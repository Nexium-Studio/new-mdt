<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
$panelCfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/../../../config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);

$companyName = $panelCfg['name'];
$companyColor = $panelCfg['color'];
$companyFaction = $panelCfg['faction'];
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Facturation';
$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    try {
        $stmt = $db->prepare('
            UPDATE company_invoices
            SET status = ?
            WHERE id = ? AND faction = ?
        ');
        $stmt->execute([$_POST['status'], (int) $_POST['invoice_id'], $companyFaction]);

        $message = 'Statut mis à jour';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        $stmt = $db->prepare('DELETE FROM company_invoices WHERE id = ? AND faction = ?');
        $stmt->execute([(int) $_POST['invoice_id'], $companyFaction]);

        $message = 'Facture supprimée';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$dateFilter = $_GET['date'] ?? 'all';

$query = '
    SELECT ci.*,
           u.username as employee_name,
           u.faction_rank as employee_rank
    FROM company_invoices ci
    JOIN users u ON ci.employee_id = u.id
    WHERE ci.faction = ?
';

$params = [$companyFaction];

if ($search) {
    $query .= ' AND (ci.customer_name LIKE ? OR u.username LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($statusFilter !== 'all') {
    $query .= ' AND ci.status = ?';
    $params[] = $statusFilter;
}

if ($dateFilter === 'today') {
    $query .= ' AND DATE(ci.created_at) = CURDATE()';
} elseif ($dateFilter === 'week') {
    $query .= ' AND YEARWEEK(ci.created_at, 1) = YEARWEEK(CURDATE(), 1)';
} elseif ($dateFilter === 'month') {
    $query .= ' AND MONTH(ci.created_at) = MONTH(CURDATE()) AND YEAR(ci.created_at) = YEAR(CURDATE())';
}

$query .= ' ORDER BY ci.created_at DESC LIMIT 100';

$stmt = $db->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = \'paid\' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = \'cancelled\' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN status = \'paid\' THEN final_amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN status = \'pending\' THEN final_amount ELSE 0 END) as total_pending
    FROM company_invoices
    WHERE faction = ?
        AND MONTH(created_at) = MONTH(CURRENT_DATE())
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
');
$stmt->execute([$companyFaction]);
$stats = $stmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="stats-row" aria-label="Synthèse mensuelle">
    <div class="stat-card">
        <div class="stat-value blue"><?php echo (int)($stats['total'] ?? 0); ?></div>
        <div class="stat-label">Factures (mois)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value green"><?php echo (int)($stats['paid_count'] ?? 0); ?></div>
        <div class="stat-label">Payées</div>
    </div>
    <div class="stat-card">
        <div class="stat-value orange"><?php echo (int)($stats['pending_count'] ?? 0); ?></div>
        <div class="stat-label">En attente</div>
    </div>
    <div class="stat-card">
        <div class="stat-value green">$<?php echo number_format((float)($stats['total_paid'] ?? 0), 0, ',', ' '); ?></div>
        <div class="stat-label">Encaissé</div>
    </div>
    <div class="stat-card">
        <div class="stat-value orange">$<?php echo number_format((float)($stats['total_pending'] ?? 0), 0, ',', ' '); ?></div>
        <div class="stat-label">À encaisser</div>
    </div>
</section>

<form method="GET" class="filters-bar" aria-label="Filtres factures">
    <div class="filter-group">
        <label class="filter-label" for="f_search">Recherche</label>
        <input id="f_search" type="text" name="search" class="form-input" placeholder="Client ou employé…" value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="filter-group">
        <label class="filter-label" for="f_status">Statut</label>
        <select id="f_status" name="status" class="form-select">
            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Tous</option>
            <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Payées</option>
            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>En attente</option>
            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Annulées</option>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label" for="f_date">Période</label>
        <select id="f_date" name="date" class="form-select">
            <option value="all" <?php echo $dateFilter === 'all' ? 'selected' : ''; ?>>Toutes</option>
            <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Aujourd’hui</option>
            <option value="week" <?php echo $dateFilter === 'week' ? 'selected' : ''; ?>>Cette semaine</option>
            <option value="month" <?php echo $dateFilter === 'month' ? 'selected' : ''; ?>>Ce mois</option>
        </select>
    </div>
    <div class="filter-group" style="flex-basis:100%;display:flex;gap:.65rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Appliquer</button>
        <a href="facturation.php" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> Réinitialiser</a>
    </div>
</form>

<div class="card">
    <h2 class="lm-card-head"><i class="fas fa-file-invoice-dollar"></i> Factures <span class="lm-muted" style="font-weight:600;font-size:.8rem">(<?php echo count($invoices); ?> résultats)</span></h2>

    <?php if (empty($invoices)): ?>
        <div class="lm-empty-state">
            <i class="fas fa-file-invoice"></i>
            <p>Aucune facture ne correspond à ces critères.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="invoices-table">
                <thead>
                    <tr>
                        <th>Réf.</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Employé</th>
                        <th>Montant</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><span class="invoice-id">#<?php echo str_pad((string)$inv['id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                            <td style="font-weight:600;color:var(--lm-text);white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($inv['created_at'])); ?></td>
                            <td>
                                <div style="font-weight:700"><?php echo htmlspecialchars($inv['customer_name'] ?? 'N/A'); ?></div>
                                <?php if (!empty($inv['description'])): ?>
                                    <div class="description-preview" title="<?php echo htmlspecialchars($inv['description']); ?>">
                                        <?php echo htmlspecialchars($inv['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight:600"><?php echo htmlspecialchars($inv['employee_name']); ?></div>
                                <div class="lm-muted" style="font-size:.8rem"><?php echo htmlspecialchars($inv['employee_rank'] ?? '—'); ?></div>
                            </td>
                            <td>
                                <div class="invoice-amount">$<?php echo number_format((float)$inv['final_amount'], 2, ',', ' '); ?></div>
                                <?php if ((float)$inv['discount'] != 0.0): ?>
                                    <div class="invoice-discount">
                                        <?php echo (float)$inv['discount'] > 0 ? '+' : ''; ?>$<?php echo number_format((float)$inv['discount'], 2, ',', ' '); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $st = [
                                    'pending' => 'En attente',
                                    'paid' => 'Payée',
                                    'cancelled' => 'Annulée',
                                ];
                                ?>
                                <span class="status-badge status-<?php echo htmlspecialchars($inv['status']); ?>">
                                    <?php echo $st[$inv['status']] ?? $inv['status']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="invoice-actions">
                                    <button type="button" onclick="viewInvoice(<?php echo (int)$inv['id']; ?>)" class="btn btn-secondary btn-sm" title="Détails">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($inv['status'] !== 'paid'): ?>
                                        <form method="POST" style="margin:0;display:inline">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="invoice_id" value="<?php echo (int)$inv['id']; ?>">
                                            <input type="hidden" name="status" value="paid">
                                            <button type="submit" class="btn btn-secondary btn-sm" title="Marquer payée"><i class="fas fa-check"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="margin:0;display:inline" onsubmit="return confirm('Supprimer cette facture ?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="invoice_id" value="<?php echo (int)$inv['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" title="Supprimer"><i class="fas fa-trash"></i></button>
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

<div id="invoiceModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-file-invoice-dollar"></i> Détail facture</h2>
            <button type="button" class="modal-close" onclick="Company.closeModal('invoiceModal')" aria-label="Fermer">×</button>
        </div>
        <div class="modal-body" id="invoiceDetails">
            <div class="lm-empty-state">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Chargement…</p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="Company.closeModal('invoiceModal')">Fermer</button>
        </div>
    </div>
</div>

<script>
var invoicesData = <?php echo json_encode($invoices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function lmEsc(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function viewInvoice(id) {
    Company.openModal('invoiceModal');
    var invoice = invoicesData.find(function (inv) { return inv.id === id; });
    var el = document.getElementById('invoiceDetails');
    if (!invoice) {
        el.innerHTML = '<p class="lm-empty-state">Facture introuvable.</p>';
        return;
    }
    var statusNames = { pending: 'En attente', paid: 'Payée', cancelled: 'Annulée' };
    var html = '<div class="lm-invoice-detail">' +
        '<div class="lm-invoice-detail__hero">' +
        '<div class="lm-invoice-detail__id">#' + String(invoice.id).padStart(5, '0') + '</div>' +
        '<span class="status-badge status-' + lmEsc(invoice.status) + '">' + lmEsc(statusNames[invoice.status] || invoice.status) + '</span>' +
        '</div>' +
        '<p class="lm-muted" style="margin:0"><i class="fas fa-calendar"></i> ' + lmEsc(new Date(invoice.created_at).toLocaleString('fr-FR')) + '</p>' +
        '<div><span class="form-label">Client</span><div style="font-size:1.1rem;font-weight:800;margin-top:.35rem">' + lmEsc(invoice.customer_name || 'N/A') + '</div></div>' +
        '<div><span class="form-label">Employé</span><div style="font-weight:700;margin-top:.35rem">' + lmEsc(invoice.employee_name) + '</div>' +
        '<div class="lm-muted" style="font-size:.85rem">' + lmEsc(invoice.employee_rank || '') + '</div></div>';

    if (invoice.description) {
        html += '<div><span class="form-label">Description</span><div class="sanction-reason" style="margin-top:.5rem">' + lmEsc(invoice.description) + '</div></div>';
    }

    html += '<div class="lm-invoice-detail__totalbox">' +
        '<div style="display:flex;justify-content:space-between;margin-bottom:.5rem"><span class="lm-muted">Montant initial</span><span style="font-weight:700">$' + parseFloat(invoice.amount).toFixed(2) + '</span></div>';

    if (parseFloat(invoice.discount) !== 0) {
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:.5rem"><span class="lm-muted">' + (parseFloat(invoice.discount) > 0 ? 'Supplément' : 'Réduction') + '</span><span style="font-weight:700;color:#fbbf24">' + (parseFloat(invoice.discount) > 0 ? '+' : '') + '$' + parseFloat(invoice.discount).toFixed(2) + '</span></div>';
    }

    html += '<div style="border-top:1px solid var(--lm-line);padding-top:.85rem;display:flex;justify-content:space-between;align-items:center">' +
        '<span style="font-weight:800">Total</span>' +
        '<span class="lm-invoice-detail__total">$' + parseFloat(invoice.final_amount).toFixed(2) + '</span>' +
        '</div></div></div>';

    el.innerHTML = html;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
