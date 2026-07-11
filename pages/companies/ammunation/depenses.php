<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🔫 Ammunation';
$companyColor   = '#dc2626';
$companyFaction = 'AMMUNATION';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Dépenses';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'depenses.view')) faction_acl_deny();
$canAdd    = faction_acl_can($db, $uid, $companyFaction, 'depenses.add');
$canManage = faction_acl_can($db, $uid, $companyFaction, 'depenses.delete');
$canDelete = $canManage;

$EXPENSE_CATS=['Salaires','Fournisseurs','Loyer','Équipement','Marketing','Transport','Divers'];

// AJOUTER
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add' && $canManage) {
    try {
        $db->beginTransaction();
        $amt  = floatval($_POST['amount']);
        $dedu = isset($_POST['is_tax_deductible'])?1:0;
        $db->prepare("INSERT INTO company_expenses (faction,added_by,expense_name,amount,is_tax_deductible,description,expense_date) VALUES (?,?,?,?,?,?,?)")
           ->execute([$companyFaction,$_SESSION['user_id'],trim($_POST['expense_name']),$amt,$dedu,trim($_POST['description']),$_POST['expense_date']]);
        if ($dedu) {
            $db->prepare("UPDATE company_finances SET charges=charges+?,benefice_brut=ca_brut-(charges+?) WHERE faction=?")
               ->execute([$amt,$amt,$companyFaction]);
        }
        $db->prepare("INSERT IGNORE INTO company_finances (faction,ca_brut,charges,benefice_brut,impot_du,benefice_net,tresorerie) VALUES (?,0,0,0,0,0,0)")
           ->execute([$companyFaction]);
        $db->commit();
        $msg='Dépense ajoutée.'; $msgType='success';
    } catch(Exception $e) { if($db->inTransaction())$db->rollBack(); $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
}

// SUPPRIMER
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete' && $canManage) {
    try {
        $db->beginTransaction();
        $stmt=$db->prepare("SELECT * FROM company_expenses WHERE id=? AND faction=?");
        $stmt->execute([intval($_POST['expense_id']),$companyFaction]);
        $exp=$stmt->fetch();
        if ($exp) {
            $db->prepare("DELETE FROM company_expenses WHERE id=? AND faction=?")->execute([$exp['id'],$companyFaction]);
            if ($exp['is_tax_deductible']) {
                $db->prepare("UPDATE company_finances SET charges=GREATEST(charges-?,0) WHERE faction=?")
                   ->execute([$exp['amount'],$companyFaction]);
            }
        }
        $db->commit();
        $msg='Dépense supprimée.'; $msgType='success';
    } catch(Exception $e) { if($db->inTransaction())$db->rollBack(); $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
}

// DATA
$stmt=$db->prepare("SELECT ce.*, u.username as added_by_name FROM company_expenses ce JOIN users u ON ce.added_by=u.id WHERE ce.faction=? ORDER BY ce.expense_date DESC LIMIT 100");
$stmt->execute([$companyFaction]); $expenses=$stmt->fetchAll();

$stmt=$db->prepare("SELECT COALESCE(SUM(amount),0) as total, COALESCE(SUM(CASE WHEN is_tax_deductible=1 THEN amount ELSE 0 END),0) as deductible FROM company_expenses WHERE faction=? AND MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())");
$stmt->execute([$companyFaction]); $stats=$stmt->fetch();

include __DIR__ . '/includes/header.php';
?>
<style>
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.75rem}
.scard{background:rgba(13,21,38,.8);border:1px solid rgba(220,38,38,.14);border-radius:12px;padding:1.25rem;text-align:center}
.scard-lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:.4rem}
.scard-val{font-size:1.6rem;font-weight:900;color:#f1f5f9}
.deductible-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .55rem;border-radius:5px;font-size:.68rem;font-weight:700}
.deductible-yes{background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.2)}
.deductible-no {background:rgba(100,116,139,.12);color:#94a3b8;border:1px solid rgba(100,116,139,.2)}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<div class="stats-row">
    <div class="scard">
        <div class="scard-lbl">Dépenses (mois)</div>
        <div class="scard-val text-red">$<?php echo number_format($stats['total'],2); ?></div>
    </div>
    <div class="scard">
        <div class="scard-lbl">Charges déductibles</div>
        <div class="scard-val text-yellow">$<?php echo number_format($stats['deductible'],2); ?></div>
    </div>
    <div class="scard">
        <div class="scard-lbl">Non déductibles</div>
        <div class="scard-val text-muted">$<?php echo number_format($stats['total']-$stats['deductible'],2); ?></div>
    </div>
</div>

<?php if($canManage): ?>
<div style="margin-bottom:1.5rem">
    <button onclick="Company.openModal('addModal')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvelle Dépense
    </button>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow:hidden">
    <table class="data-table">
        <thead><tr><th>#</th><th>Date</th><th>Libellé</th><th>Montant</th><th>Déductible</th><th>Ajouté par</th><th>Description</th><?php if($canManage): ?><th>Action</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if(empty($expenses)): ?>
        <tr><td colspan="8" style="text-align:center;padding:3rem;color:#475569">Aucune dépense</td></tr>
        <?php else: ?>
        <?php foreach($expenses as $e): ?>
        <tr>
            <td class="mono" style="color:#475569">#<?php echo $e['id']; ?></td>
            <td style="color:#94a3b8;font-size:.8rem"><?php echo date('d/m/Y',strtotime($e['expense_date'])); ?></td>
            <td style="font-weight:600"><?php echo htmlspecialchars($e['expense_name']); ?></td>
            <td style="font-weight:800;color:#f87171">$<?php echo number_format($e['amount'],2); ?></td>
            <td>
                <?php if($e['is_tax_deductible']): ?>
                <span class="deductible-badge deductible-yes"><i class="fas fa-check"></i> Oui</span>
                <?php else: ?>
                <span class="deductible-badge deductible-no"><i class="fas fa-times"></i> Non</span>
                <?php endif; ?>
            </td>
            <td style="color:#94a3b8;font-size:.82rem"><?php echo htmlspecialchars($e['added_by_name']); ?></td>
            <td style="font-size:.8rem;color:#64748b"><?php echo htmlspecialchars($e['description']??''); ?></td>
            <?php if($canManage): ?>
            <td>
                <form method="POST" style="margin:0" data-confirm="Supprimer cette dépense ?">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="expense_id" value="<?php echo $e['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if($canManage): ?>
<div id="addModal" class="modal-overlay hidden">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Nouvelle Dépense</h2>
            <button class="modal-close" onclick="Company.closeModal('addModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Libellé *</label>
                        <input type="text" name="expense_name" class="form-input" placeholder="Ex: Achat munitions fournisseur" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Montant ($) *</label>
                        <input type="number" name="amount" class="form-input" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date *</label>
                        <input type="date" name="expense_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Détails supplémentaires…"></textarea>
                    </div>
                    <div class="form-group form-full">
                        <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;color:#94a3b8;font-size:.875rem">
                            <input type="checkbox" name="is_tax_deductible" value="1" style="width:16px;height:16px;accent-color:var(--cc)">
                            Charge déductible (impact sur bénéfice brut)
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="Company.closeModal('addModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>