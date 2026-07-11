<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/faction_helper.php';
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

$pageTitle = 'Dépenses';
$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

$__gradeStmt = $db->prepare('SELECT faction_grade FROM users WHERE id=? LIMIT 1');
$__gradeStmt->execute([$_SESSION['user_id'] ?? 0]);
$__grade = (string)($__gradeStmt->fetchColumn() ?: '');
$permissions = getGradePermissions($companyFaction, $__grade);
$canManage   = $permissions['can_manage_finances'] || $permissions['is_owner'] || $permissions['is_ceo'] || hasRole(['*', 'Administrateur']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add' && $canManage) {
    try {
        $db->beginTransaction();

        $amount = (float) $_POST['amount'];
        $isTaxDeductible = isset($_POST['is_tax_deductible']) ? 1 : 0;

        $stmt = $db->prepare('
            INSERT INTO company_expenses (
                faction, added_by, expense_name, amount, is_tax_deductible, description, expense_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $companyFaction,
            $_SESSION['user_id'],
            trim($_POST['expense_name']),
            $amount,
            $isTaxDeductible,
            trim($_POST['description'] ?? ''),
            $_POST['expense_date'],
        ]);

        if ($isTaxDeductible) {
            $stmt = $db->prepare('
                UPDATE company_finances
                SET charges = charges + ?,
                    benefice_brut = ca_brut - charges
                WHERE faction = ?
            ');
            $stmt->execute([$amount, $companyFaction]);
        }

        $stmt = $db->prepare('
            INSERT IGNORE INTO company_finances (faction, ca_brut, charges, benefice_brut, impot_du, benefice_net, tresorerie)
            VALUES (?, 0, 0, 0, 0, 0, 0)
        ');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    try {
        $db->beginTransaction();

        $stmt = $db->prepare('SELECT * FROM company_expenses WHERE id = ? AND faction = ?');
        $stmt->execute([(int) $_POST['expense_id'], $companyFaction]);
        $expense = $stmt->fetch();

        if ($expense) {
            $stmt = $db->prepare('DELETE FROM company_expenses WHERE id = ? AND faction = ?');
            $stmt->execute([$expense['id'], $companyFaction]);

            if ($expense['is_tax_deductible']) {
                $stmt = $db->prepare('
                    UPDATE company_finances
                    SET charges = charges - ?,
                        benefice_brut = ca_brut - charges
                    WHERE faction = ?
                ');
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

$stmt = $db->prepare('
    SELECT ce.*,
           u.username as added_by_name
    FROM company_expenses ce
    JOIN users u ON ce.added_by = u.id
    WHERE ce.faction = ?
    ORDER BY ce.expense_date DESC, ce.created_at DESC
    LIMIT 100
');
$stmt->execute([$companyFaction]);
$expenses = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT
        COUNT(*) as total_count,
        SUM(amount) as total_amount,
        SUM(CASE WHEN is_tax_deductible = 1 THEN amount ELSE 0 END) as deductible_amount,
        SUM(CASE WHEN is_tax_deductible = 0 THEN amount ELSE 0 END) as non_deductible_amount,
        SUM(CASE WHEN MONTH(expense_date) = MONTH(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE()) THEN amount ELSE 0 END) as month_total
    FROM company_expenses
    WHERE faction = ?
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

<section class="info-box" aria-labelledby="exp-info-title">
    <div class="info-icon-box" aria-hidden="true"><i class="fas fa-scale-balanced"></i></div>
    <div class="info-content">
        <h3 id="exp-info-title">Déductibilité & charges</h3>
        <p>Les dépenses déductibles diminuent le bénéfice brut imposable. Les autres restent en suivi interne sans impacter la ligne fiscale simulée ici.</p>
        <ul class="info-list">
            <li>Déductibles : loyer, pièces, fournitures liées à l’activité.</li>
            <li>Non déductibles : amendes, dépenses personnelles, hors périmètre.</li>
        </ul>
    </div>
</section>

<div class="stats-grid">
    <div class="stat-box">
        <div class="stat-icon blue"><i class="fas fa-receipt"></i></div>
        <div class="stat-value blue"><?php echo (int)($stats['total_count'] ?? 0); ?></div>
        <div class="stat-label">Écritures</div>
    </div>
    <div class="stat-box">
        <div class="stat-icon red"><i class="fas fa-sack-dollar"></i></div>
        <div class="stat-value red">$<?php echo number_format((float)($stats['total_amount'] ?? 0), 0, ',', ' '); ?></div>
        <div class="stat-label">Montant total</div>
    </div>
    <div class="stat-box">
        <div class="stat-icon green"><i class="fas fa-percent"></i></div>
        <div class="stat-value green">$<?php echo number_format((float)($stats['deductible_amount'] ?? 0), 0, ',', ' '); ?></div>
        <div class="stat-label">Déductibles</div>
    </div>
    <div class="stat-box">
        <div class="stat-icon orange"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-value orange">$<?php echo number_format((float)($stats['month_total'] ?? 0), 0, ',', ' '); ?></div>
        <div class="stat-label">Ce mois</div>
    </div>
</div>

<?php if ($canManage): ?>
<div class="lm-toolbar" style="display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem">
    <button type="button" onclick="Company.openModal('addExpenseModal')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Saisir une dépense
    </button>
</div>
<?php else: ?>
<div class="info-banner">
    <div class="info-icon"><i class="fas fa-lock"></i></div>
    <div>Consultation seule : votre grade ne permet pas d’ajouter ou supprimer des dépenses.</div>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="lm-card-head"><i class="fas fa-list-ul"></i> Journal <span class="lm-muted" style="font-weight:600;font-size:.8rem">(<?php echo count($expenses); ?>)</span></h2>

    <?php if (empty($expenses)): ?>
        <div class="lm-empty-state">
            <i class="fas fa-receipt"></i>
            <p>Aucune dépense enregistrée.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="expenses-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Libellé</th>
                        <th>Montant</th>
                        <th>Déductible</th>
                        <th>Saisie par</th>
                        <?php if ($canManage): ?>
                            <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td style="font-weight:700;color:var(--lm-text);white-space:nowrap"><?php echo date('d/m/Y', strtotime($expense['expense_date'])); ?></td>
                            <td>
                                <div style="font-weight:800;margin-bottom:.2rem"><?php echo htmlspecialchars($expense['expense_name']); ?></div>
                                <?php if ($expense['description'] !== ''): ?>
                                    <div class="lm-muted" style="font-size:.82rem;max-width:360px"><?php echo htmlspecialchars($expense['description']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="expense-amount">−$<?php echo number_format((float)$expense['amount'], 2, ',', ' '); ?></span></td>
                            <td>
                                <?php if ($expense['is_tax_deductible']): ?>
                                    <span class="deductible-badge deductible-yes"><i class="fas fa-check"></i> Oui</span>
                                <?php else: ?>
                                    <span class="deductible-badge deductible-no"><i class="fas fa-times"></i> Non</span>
                                <?php endif; ?>
                            </td>
                            <td class="lm-muted"><?php echo htmlspecialchars($expense['added_by_name']); ?></td>
                            <?php if ($canManage): ?>
                                <td>
                                    <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer cette dépense ?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="expense_id" value="<?php echo (int)$expense['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" title="Supprimer"><i class="fas fa-trash"></i></button>
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

<?php if ($canManage): ?>
<div id="addExpenseModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouvelle dépense</h2>
            <button type="button" class="modal-close" onclick="Company.closeModal('addExpenseModal')" aria-label="Fermer">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">

                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label" for="expense_name">Intitulé</label>
                        <input id="expense_name" type="text" name="expense_name" class="form-input" required placeholder="Ex. : lot de pneus, loyer atelier…">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="expense_amount">Montant (USD)</label>
                        <input id="expense_amount" type="number" name="amount" class="form-input" step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="expense_date">Date</label>
                        <input id="expense_date" type="date" name="expense_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group form-full">
                        <label class="form-label" for="expense_desc">Commentaire</label>
                        <textarea id="expense_desc" name="description" class="form-textarea" rows="3" placeholder="Référence facture, fournisseur…"></textarea>
                    </div>

                    <div class="form-group form-full">
                        <label class="lm-checkbox-tile">
                            <input type="checkbox" name="is_tax_deductible" checked>
                            <div>
                                <strong>Dépense déductible</strong>
                                <span>Réduit le bénéfice brut dans la simulation comptable.</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('addExpenseModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Valider</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
