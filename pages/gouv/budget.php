<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Budget de l\'État';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$BUDGET_CATEGORIES = [
    'Sécurité publique', 'Santé publique', 'Infrastructure',
    'Justice', 'Éducation', 'Services sociaux',
    'Administration', 'Économie', 'Divers',
];

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        try {
            $db->prepare("
                INSERT INTO gouv_budget_items (category, label, amount, type, description, added_by, budget_year)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                trim($_POST['category']),
                trim($_POST['label']),
                floatval($_POST['amount']),
                $_POST['type'],
                trim($_POST['description'] ?? ''),
                $_SESSION['user_id'],
                intval($_POST['budget_year'] ?? date('Y')),
            ]);
            $msg='Ligne budgétaire ajoutée.'; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    if ($action === 'delete_item') {
        $db->prepare("DELETE FROM gouv_budget_items WHERE id=?")->execute([intval($_POST['item_id'])]);
        $msg='Ligne supprimée.'; $msgType='ok';
    }
}

$year = intval($_GET['year'] ?? date('Y'));

// Données budget
$items = $db->prepare("
    SELECT bi.*, u.username AS added_by_name
    FROM gouv_budget_items bi
    LEFT JOIN users u ON u.id=bi.added_by
    WHERE bi.budget_year=?
    ORDER BY bi.type DESC, bi.category, bi.created_at DESC
");
$items->execute([$year]); $items=$items->fetchAll();

$totalRecettes  = array_sum(array_map(fn($i)=>$i['type']==='recette'?(float)$i['amount']:0, $items));
$totalDepenses  = array_sum(array_map(fn($i)=>$i['type']==='depense'?(float)$i['amount']:0, $items));
$solde          = $totalRecettes - $totalDepenses;

// Par catégorie
$byCat = [];
foreach ($items as $it) {
    $byCat[$it['type']][$it['category']][] = $it;
}

// Recettes fiscales (depuis company_finances)
$stmt = $db->query("SELECT COALESCE(SUM(impot_du),0) FROM company_finances");
$totalImpots = (float)$stmt->fetchColumn();

// Trésorerie publique estimée = somme impôts
$totalInvoices = (float)$db->query("SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE status='paid'")->fetchColumn();
$totalSams     = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM sams_invoices WHERE status='paid'")->fetchColumn();

// Années disponibles
$years = $db->query("SELECT DISTINCT budget_year FROM gouv_budget_items ORDER BY budget_year DESC")->fetchAll(\PDO::FETCH_COLUMN);
if (!in_array(date('Y'), $years)) array_unshift($years, (int)date('Y'));

include __DIR__ . '/includes/header.php';
?>
<style>
.budget-section{margin-bottom:1.75rem}
.budget-section-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;padding:.6rem .9rem;border-radius:8px}
.budget-section-hd.recette{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.18)}
.budget-section-hd.depense{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.18)}
.budget-item-row{display:flex;align-items:center;gap:.9rem;padding:.7rem .9rem;border-bottom:1px solid rgba(255,255,255,.04)}
.budget-item-row:last-child{border-bottom:none}
.budget-cat-group{background:var(--bg-c);border:1px solid var(--bdr);border-radius:10px;overflow:hidden;margin-bottom:.75rem}
.budget-cat-hd{padding:.6rem .9rem;background:var(--bg-ov);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--txt-m);display:flex;justify-content:space-between}
.solde-positive{color:#4ade80} .solde-negative{color:#f87171}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Sélecteur année + actions -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;align-items:center;gap:.75rem">
        <form method="GET" style="display:flex;gap:.5rem;align-items:center">
            <label class="form-label" style="margin:0">Année budgétaire :</label>
            <select name="year" class="form-select" style="width:100px" onchange="this.form.submit()">
                <?php foreach($years as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo $year==$y?'selected':''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <button onclick="Gov.openModal('addBudgetModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Nouvelle ligne budgétaire
    </button>
</div>

<!-- Solde global -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.4rem">
    <div style="background:rgba(34,197,94,.07);border:1px solid rgba(34,197,94,.2);border-radius:12px;padding:1.25rem;text-align:center">
        <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#4ade80;margin-bottom:.4rem">
            <i class="fas fa-arrow-up" style="margin-right:.3rem"></i>Recettes budgétaires
        </div>
        <div class="mono" style="font-size:1.5rem;font-weight:700;color:#4ade80">$<?php echo number_format($totalRecettes,2,'.',','); ?></div>
    </div>
    <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:1.25rem;text-align:center">
        <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#f87171;margin-bottom:.4rem">
            <i class="fas fa-arrow-down" style="margin-right:.3rem"></i>Dépenses budgétaires
        </div>
        <div class="mono" style="font-size:1.5rem;font-weight:700;color:#f87171">$<?php echo number_format($totalDepenses,2,'.',','); ?></div>
    </div>
    <div style="background:rgba(29,78,216,.07);border:2px solid rgba(29,78,216,.25);border-radius:12px;padding:1.25rem;text-align:center">
        <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--gov-l);margin-bottom:.4rem">
            <i class="fas fa-equals" style="margin-right:.3rem"></i>Solde budgétaire <?php echo $year; ?>
        </div>
        <div class="mono <?php echo $solde>=0?'solde-positive':'solde-negative'; ?>" style="font-size:1.5rem;font-weight:700">
            <?php echo $solde>=0?'+':''; ?>$<?php echo number_format($solde,2,'.',','); ?>
        </div>
    </div>
</div>

<!-- Recettes fiscales réelles (hors budget) -->
<div style="background:rgba(251,191,36,.06);border:1px solid rgba(251,191,36,.2);border-radius:11px;padding:1rem 1.25rem;margin-bottom:1.4rem">
    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#fcd34d;margin-bottom:.75rem">
        <i class="fas fa-coins" style="margin-right:.4rem"></i>Recettes fiscales réelles (calculées depuis les entreprises)
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.9rem">
        <div style="text-align:center">
            <div style="font-size:.68rem;color:var(--txt-m);margin-bottom:.2rem">Impôts sur bénéfices</div>
            <div class="mono txt-gold" style="font-size:1.2rem;font-weight:700">$<?php echo number_format($totalImpots,2,'.',','); ?></div>
        </div>
        <div style="text-align:center">
            <div style="font-size:.68rem;color:var(--txt-m);margin-bottom:.2rem">CA privé total</div>
            <div class="mono txt-blue" style="font-size:1.2rem;font-weight:700">$<?php echo number_format($totalInvoices,2,'.',','); ?></div>
        </div>
        <div style="text-align:center">
            <div style="font-size:.68rem;color:var(--txt-m);margin-bottom:.2rem">Recettes SAMS</div>
            <div class="mono" style="font-size:1.2rem;font-weight:700;color:#fb7185">$<?php echo number_format($totalSams,2,'.',','); ?></div>
        </div>
        <div style="text-align:center">
            <div style="font-size:.68rem;color:var(--txt-m);margin-bottom:.2rem">Trésorerie publique (=impôts)</div>
            <div class="mono txt-gold" style="font-size:1.2rem;font-weight:700">$<?php echo number_format($totalImpots,2,'.',','); ?></div>
        </div>
    </div>
</div>

<?php if(empty($items)): ?>
<div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)">
    <i class="fas fa-landmark" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
    <p>Aucune ligne budgétaire pour <?php echo $year; ?></p>
    <button onclick="Gov.openModal('addBudgetModal')" class="btn btn-primary" style="margin-top:1rem">
        <i class="fas fa-plus"></i> Ajouter la première ligne
    </button>
</div>
<?php else: ?>

<!-- Recettes -->
<?php if(!empty($byCat['recette'])): ?>
<div class="budget-section">
    <div class="budget-section-hd recette">
        <span style="font-size:.78rem;font-weight:700;color:#4ade80"><i class="fas fa-arrow-up" style="margin-right:.5rem"></i>RECETTES — <?php echo count(array_merge(...array_values($byCat['recette']))); ?> ligne(s)</span>
        <span class="mono" style="color:#4ade80;font-weight:700">+$<?php echo number_format($totalRecettes,2,'.',','); ?></span>
    </div>
    <?php foreach($byCat['recette'] as $cat => $citems): $catTotal=array_sum(array_column($citems,'amount')); ?>
    <div class="budget-cat-group">
        <div class="budget-cat-hd">
            <span><i class="fas fa-folder" style="margin-right:.4rem"></i><?php echo htmlspecialchars($cat); ?></span>
            <span class="mono txt-green">+$<?php echo number_format($catTotal,2,'.',','); ?></span>
        </div>
        <?php foreach($citems as $it): ?>
        <div class="budget-item-row">
            <div style="flex:1">
                <div style="font-weight:600;font-size:.82rem"><?php echo htmlspecialchars($it['label']); ?></div>
                <?php if($it['description']): ?><div style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($it['description']); ?></div><?php endif; ?>
                <div style="font-size:.68rem;color:var(--txt-m);margin-top:.15rem">
                    Ajouté par <?php echo htmlspecialchars($it['added_by_name']??'—'); ?> — <?php echo date('d/m/Y',strtotime($it['created_at'])); ?>
                </div>
            </div>
            <div class="mono txt-green" style="font-weight:700;flex-shrink:0">+$<?php echo number_format($it['amount'],2,'.',','); ?></div>
            <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer cette ligne ?')">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Dépenses -->
<?php if(!empty($byCat['depense'])): ?>
<div class="budget-section">
    <div class="budget-section-hd depense">
        <span style="font-size:.78rem;font-weight:700;color:#f87171"><i class="fas fa-arrow-down" style="margin-right:.5rem"></i>DÉPENSES — <?php echo count(array_merge(...array_values($byCat['depense']))); ?> ligne(s)</span>
        <span class="mono" style="color:#f87171;font-weight:700">−$<?php echo number_format($totalDepenses,2,'.',','); ?></span>
    </div>
    <?php foreach($byCat['depense'] as $cat => $ditems): $catTotal=array_sum(array_column($ditems,'amount')); ?>
    <div class="budget-cat-group">
        <div class="budget-cat-hd">
            <span><i class="fas fa-folder" style="margin-right:.4rem"></i><?php echo htmlspecialchars($cat); ?></span>
            <span class="mono txt-red">−$<?php echo number_format($catTotal,2,'.',','); ?></span>
        </div>
        <?php foreach($ditems as $it): ?>
        <div class="budget-item-row">
            <div style="flex:1">
                <div style="font-weight:600;font-size:.82rem"><?php echo htmlspecialchars($it['label']); ?></div>
                <?php if($it['description']): ?><div style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($it['description']); ?></div><?php endif; ?>
                <div style="font-size:.68rem;color:var(--txt-m);margin-top:.15rem">
                    Ajouté par <?php echo htmlspecialchars($it['added_by_name']??'—'); ?> — <?php echo date('d/m/Y',strtotime($it['created_at'])); ?>
                </div>
            </div>
            <div class="mono txt-red" style="font-weight:700;flex-shrink:0">−$<?php echo number_format($it['amount'],2,'.',','); ?></div>
            <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer cette ligne ?')">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- MODAL AJOUTER LIGNE -->
<div id="addBudgetModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-plus-circle"></i> Nouvelle ligne budgétaire</h2>
            <button class="modal-cl" onclick="Gov.closeModal('addBudgetModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="add_item">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Type *</label>
                        <select name="type" class="form-select" required>
                            <option value="recette">Recette</option>
                            <option value="depense">Dépense</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="category" class="form-select" required>
                            <?php foreach($BUDGET_CATEGORIES as $bc): ?>
                            <option value="<?php echo $bc; ?>"><?php echo $bc; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Libellé *</label>
                        <input type="text" name="label" class="form-input" required placeholder="Ex: Salaires LSPD, Subvention SAMS…">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Montant ($) *</label>
                        <input type="number" name="amount" class="form-input" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Année budgétaire</label>
                        <input type="number" name="budget_year" class="form-input" value="<?php echo $year; ?>" min="2020" max="2100">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Détails…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="Gov.closeModal('addBudgetModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Ajouter</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>