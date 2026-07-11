<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
$companyName = "🏨 Pearl's Resort"; $companyColor = '#d4a853'; $companyFaction = 'PEARLS_RESORT';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle = 'Dépenses'; $db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

// Colonnes réelles company_expenses : id, faction, added_by, expense_name, amount, is_tax_deductible, description, expense_date, created_at

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'depenses.view')) faction_acl_deny();
$canAdd    = faction_acl_can($db, $uid, $companyFaction, 'depenses.add');
$canManage = faction_acl_can($db, $uid, $companyFaction, 'depenses.delete');
$canDelete = $canManage;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add' && $canManage) {
    try {
        $deductible = isset($_POST['is_tax_deductible']) ? 1 : 0;
        $db->prepare("INSERT INTO company_expenses (faction, added_by, expense_name, amount, is_tax_deductible, description, expense_date) VALUES (?,?,?,?,?,?,CURDATE())")
           ->execute([$companyFaction, $_SESSION['user_id'], trim($_POST['expense_name']), (float)$_POST['amount'], $deductible, trim($_POST['description'])]);
        $message = 'Dépense enregistrée.'; $messageType = 'success';
    } catch (Exception $e) { $message = 'Erreur: '.$e->getMessage(); $messageType = 'error'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && $canManage) {
    $db->prepare("DELETE FROM company_expenses WHERE id=? AND faction=?")->execute([(int)$_POST['exp_id'], $companyFaction]);
    $message = 'Dépense supprimée.'; $messageType = 'success';
}

// Récupérer les dépenses — added_by joint à users
$expenses = $db->prepare("
    SELECT e.*, u.username
    FROM company_expenses e LEFT JOIN users u ON e.added_by = u.id
    WHERE e.faction=? ORDER BY e.expense_date DESC, e.created_at DESC LIMIT 60
"); $expenses->execute([$companyFaction]); $expenses = $expenses->fetchAll();

$totalMonth = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM company_expenses WHERE faction=? AND MONTH(expense_date)=MONTH(NOW()) AND YEAR(expense_date)=YEAR(NOW())");
$totalMonth->execute([$companyFaction]); $totalMonth = (float)$totalMonth->fetchColumn();
$totalDeductible = array_sum(array_column(array_filter($expenses, fn($e) => $e['is_tax_deductible']), 'amount'));

$hotelExpenseNames = ['Salaires','Maintenance Hôtel','Fournitures','Produits Ménagers','Équipement','Marketing','Services Externes','Impôts & Taxes','Autre'];

include __DIR__ . '/includes/header.php';
?>
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card" style="border-color:rgba(239,68,68,.2);"><div class="stat-icon" style="color:#ef4444;background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);"><i class="fas fa-arrow-trend-down"></i></div><div><div class="stat-label">Dépenses ce mois</div><div class="stat-value" style="font-size:1.15rem;color:#ef4444;">$<?php echo number_format($totalMonth,0,',',' '); ?></div></div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-receipt"></i></div><div><div class="stat-label">Déductibles (total)</div><div class="stat-value" style="font-size:1.15rem;color:#10b981;">$<?php echo number_format($totalDeductible,0,',',' '); ?></div></div></div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
    <p style="color:var(--text-2);"><?php echo count($expenses); ?> dépense(s)</p>
    <?php if ($canManage): ?><button onclick="Pearl.openModal('modal-expense')" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter une dépense</button><?php endif; ?>
</div>
<?php if ($message): ?><div class="alert alert-<?php echo $messageType==='success'?'success':'error'; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nom</th><th>Description</th><th>Montant</th><th>Déductible</th><th>Par</th><th>Date</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-2);">Aucune dépense</td></tr>
                <?php else: foreach ($expenses as $e): ?>
                <tr>
                    <td><span class="badge badge-slate" style="font-size:.65rem;"><?php echo htmlspecialchars($e['expense_name']); ?></span></td>
                    <td style="max-width:220px;font-size:.83rem;color:var(--text-2);"><?php echo htmlspecialchars($e['description'] ?? '—'); ?></td>
                    <td style="font-weight:700;color:#ef4444;">$<?php echo number_format($e['amount'],0,',',' '); ?></td>
                    <td><?php echo $e['is_tax_deductible'] ? '<span class="badge badge-green" style="font-size:.62rem;">Oui</span>' : '<span style="color:var(--text-2);font-size:.75rem;">Non</span>'; ?></td>
                    <td style="font-size:.78rem;"><?php echo htmlspecialchars($e['username'] ?? '—'); ?></td>
                    <td style="font-size:.72rem;font-family:'DM Mono',monospace;color:var(--text-2);"><?php echo $e['expense_date'] ? date('d/m/Y', strtotime($e['expense_date'])) : '—'; ?></td>
                    <?php if ($canManage): ?><td><form method="POST" data-confirm="Supprimer ?" style="display:inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="exp_id" value="<?php echo $e['id']; ?>"><button type="submit" class="btn btn-danger btn-sm" style="padding:.35rem .6rem;"><i class="fas fa-trash" style="font-size:.7rem;"></i></button></form></td><?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($canManage): ?>
<div id="modal-expense" class="modal-overlay hidden">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header"><h2><i class="fas fa-money-bill-wave"></i> Ajouter une dépense</h2><button class="modal-close" onclick="Pearl.closeModal('modal-expense')">×</button></div>
        <form method="POST"><div class="modal-body"><input type="hidden" name="action" value="add">
            <div class="form-grid">
                <div class="form-group"><label class="form-label">Nom / Type *</label>
                    <select name="expense_name" class="form-select" required><?php foreach ($hotelExpenseNames as $n): ?><option><?php echo $n; ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group"><label class="form-label">Montant ($) *</label><input type="number" name="amount" class="form-input" min="0" step="0.01" required></div>
                <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" rows="2" placeholder="Détails…"></textarea></div>
                <div class="form-group form-full">
                    <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer;">
                        <input type="checkbox" name="is_tax_deductible" style="width:16px;height:16px;accent-color:var(--gold);">
                        <span style="font-size:.875rem;font-weight:600;">Dépense déductible fiscalement</span>
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Pearl.closeModal('modal-expense')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>