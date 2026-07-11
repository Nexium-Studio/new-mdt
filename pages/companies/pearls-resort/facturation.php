<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
$companyName = "🏨 Pearl's Resort"; $companyColor = '#d4a853'; $companyFaction = 'PEARLS_RESORT';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle = 'Facturation'; $db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

// Colonnes réelles company_invoices : id, faction, employee_id, customer_name, amount, discount, final_amount, description, status, created_at
// PAS de : client_name (→ customer_name), created_by (→ employee_id), discount_percentage (→ discount), paid_at

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'facturation.view')) faction_acl_deny();
$canCreate = faction_acl_can($db, $uid, $companyFaction, 'facturation.create');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'facturation.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'facturation.delete');
$canSend   = faction_acl_can($db, $uid, $companyFaction, 'facturation.send');
$canManage = $canCreate;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create' && $canManage) {
    try {
        $amount   = (float)$_POST['amount'];
        $discount = (float)($_POST['discount'] ?? 0);
        $final    = $amount - ($amount * $discount / 100);
        $db->prepare("INSERT INTO company_invoices (faction, employee_id, customer_name, amount, discount, final_amount, description, status, created_at) VALUES (?,?,?,?,?,?,'pending',NOW())")
           ->execute([$companyFaction, $_SESSION['user_id'], trim($_POST['client_name']), $amount, $discount, $final, trim($_POST['description'])]);
        $message = 'Facture créée.'; $messageType = 'success';
    } catch (Exception $e) { $message = 'Erreur: '.$e->getMessage(); $messageType = 'error'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay' && $canManage) {
    $db->prepare("UPDATE company_invoices SET status='paid' WHERE id=? AND faction=?")->execute([(int)$_POST['inv_id'], $companyFaction]);
    $message = 'Facture payée.'; $messageType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel' && $canManage) {
    $db->prepare("UPDATE company_invoices SET status='cancelled' WHERE id=? AND faction=?")->execute([(int)$_POST['inv_id'], $companyFaction]);
    $message = 'Facture annulée.'; $messageType = 'success';
}

$filterStatus = $_GET['status'] ?? '';
$where = ['ci.faction=?']; $params = [$companyFaction];
if ($filterStatus) { $where[] = 'ci.status=?'; $params[] = $filterStatus; }
$wStr = 'WHERE ' . implode(' AND ', $where);

$invoices = $db->prepare("
    SELECT ci.*, u.username as creator
    FROM company_invoices ci LEFT JOIN users u ON ci.employee_id = u.id
    $wStr ORDER BY ci.created_at DESC LIMIT 60
"); $invoices->execute($params); $invoices = $invoices->fetchAll();

$totals = ['pending'=>0,'paid'=>0,'total'=>0];
foreach ($invoices as $inv) {
    $totals['total'] += $inv['final_amount'];
    if ($inv['status']==='pending') $totals['pending'] += $inv['final_amount'];
    if ($inv['status']==='paid')    $totals['paid']    += $inv['final_amount'];
}

include __DIR__ . '/includes/header.php';
?>
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card" style="border-color:rgba(234,179,8,.2);"><div class="stat-icon" style="color:#eab308;background:rgba(234,179,8,.1);border-color:rgba(234,179,8,.2);"><i class="fas fa-file-invoice-dollar"></i></div><div><div class="stat-label">En attente</div><div class="stat-value" style="font-size:1.15rem;color:#eab308;">$<?php echo number_format($totals['pending'],0,',',' '); ?></div></div></div>
    <div class="stat-card" style="border-color:rgba(16,185,129,.25);"><div class="stat-icon" style="color:#10b981;background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.2);"><i class="fas fa-check-circle"></i></div><div><div class="stat-label">Payées</div><div class="stat-value" style="font-size:1.15rem;color:#10b981;">$<?php echo number_format($totals['paid'],0,',',' '); ?></div></div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-receipt"></i></div><div><div class="stat-label">Total affiché</div><div class="stat-value" style="font-size:1.15rem;">$<?php echo number_format($totals['total'],0,',',' '); ?></div></div></div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
    <div style="display:flex;gap:.5rem;">
        <?php foreach (['' => 'Toutes','pending'=>'En attente','paid'=>'Payées','cancelled'=>'Annulées'] as $v=>$l): ?>
        <a href="?status=<?php echo $v; ?>" class="btn btn-sm <?php echo $filterStatus===$v?'btn-primary':'btn-secondary'; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($canManage): ?><button onclick="Pearl.openModal('modal-invoice')" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle facture</button><?php endif; ?>
</div>

<?php if ($message): ?><div class="alert alert-<?php echo $messageType==='success'?'success':'error'; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Client</th><th>Description</th><th>Montant</th><th>Remise</th><th>Total</th><th>Statut</th><th>Créée par</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--text-2);">Aucune facture</td></tr>
                <?php else: foreach ($invoices as $inv):
                    $sm = ['pending'=>['badge-yellow','En attente'],'paid'=>['badge-green','Payée'],'cancelled'=>['badge-red','Annulée']][$inv['status']] ?? ['badge-slate',$inv['status']]; ?>
                <tr>
                    <td style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--text-2);">#<?php echo $inv['id']; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($inv['customer_name']); ?></td>
                    <td style="max-width:200px;font-size:.8rem;color:var(--text-2);"><?php echo htmlspecialchars($inv['description'] ?? '—'); ?></td>
                    <td style="font-family:'DM Mono',monospace;">$<?php echo number_format($inv['amount'],0); ?></td>
                    <td style="color:#eab308;"><?php echo $inv['discount'] > 0 ? "-{$inv['discount']}%" : '—'; ?></td>
                    <td style="font-weight:700;color:var(--gold);">$<?php echo number_format($inv['final_amount'],0); ?></td>
                    <td><span class="badge <?php echo $sm[0]; ?>"><?php echo $sm[1]; ?></span></td>
                    <td style="font-size:.78rem;"><?php echo htmlspecialchars($inv['creator'] ?? '—'); ?></td>
                    <td><?php if ($canManage && $inv['status']==='pending'): ?>
                        <div style="display:flex;gap:.3rem;">
                            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="pay"><input type="hidden" name="inv_id" value="<?php echo $inv['id']; ?>"><button type="submit" class="btn btn-success btn-sm" style="padding:.35rem .7rem;" title="Marquer payée"><i class="fas fa-check" style="font-size:.7rem;"></i></button></form>
                            <form method="POST" style="display:inline;" data-confirm="Annuler ?"><input type="hidden" name="action" value="cancel"><input type="hidden" name="inv_id" value="<?php echo $inv['id']; ?>"><button type="submit" class="btn btn-danger btn-sm" style="padding:.35rem .7rem;" title="Annuler"><i class="fas fa-xmark" style="font-size:.7rem;"></i></button></form>
                        </div>
                    <?php else: echo '<span style="font-size:.72rem;color:var(--text-2);">'.date('d/m/Y', strtotime($inv['created_at'])).'</span>'; endif; ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canManage): ?>
<div id="modal-invoice" class="modal-overlay hidden">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header"><h2><i class="fas fa-file-invoice-dollar"></i> Nouvelle facture</h2><button class="modal-close" onclick="Pearl.closeModal('modal-invoice')">×</button></div>
        <form method="POST"><div class="modal-body"><input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="form-group form-full"><label class="form-label">Client *</label><input type="text" name="client_name" class="form-input" required></div>
                <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" rows="2"></textarea></div>
                <div class="form-group"><label class="form-label">Montant ($) *</label><input type="number" name="amount" class="form-input" min="0" step="0.01" required></div>
                <div class="form-group"><label class="form-label">Remise (%)</label><input type="number" name="discount" class="form-input" min="0" max="100" step="0.5" value="0"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Pearl.closeModal('modal-invoice')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>