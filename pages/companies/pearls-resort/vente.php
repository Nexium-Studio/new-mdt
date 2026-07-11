<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
$companyName = "🏨 Pearl's Resort"; $companyColor = '#d4a853'; $companyFaction = 'PEARLS_RESORT';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle = 'Ventes'; $db = Database::getInstance()->getConnection();
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
// Catégories centralisées (depuis staff > Catalogues & Stock)
require_once ROOT_PATH . '/includes/stock_central.php';
$_CATS      = stock_get_categories($db, $companyFaction);
$CAT_ICONS  = array_map(fn($c) => $c['icon'],  $_CATS);
$CAT_COLORS = array_map(fn($c) => $c['color'], $_CATS);
if (empty($CAT_ICONS)) { $CAT_ICONS = ['Autre' => 'fas fa-box']; $CAT_COLORS = ['Autre' => '#64748b']; }
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'vente.view')) faction_acl_deny();
$canCreate = faction_acl_can($db, $uid, $companyFaction, 'vente.create');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'vente.delete');
$message = ''; $messageType = '';

// Colonnes réelles company_invoices : id, faction, employee_id, customer_name, amount, discount, final_amount, description, status, created_at

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_sale') {
    try {
        $stockId = (int)$_POST['stock_id']; $qty = (int)$_POST['qty'];
        $stmt = $db->prepare("SELECT item_name, item_price, stock_quantity FROM company_stock WHERE id=? AND faction=?");
        $stmt->execute([$stockId, $companyFaction]); $item = $stmt->fetch();
        if (!$item) throw new Exception("Article introuvable");
        if ($item['stock_quantity'] < $qty) throw new Exception("Stock insuffisant ({$item['stock_quantity']} dispo)");
        $total = $item['item_price'] * $qty;
        $clientName = trim($_POST['client'] ?: 'Comptoir');
        $db->prepare("UPDATE company_stock SET stock_quantity=stock_quantity-? WHERE id=?")->execute([$qty, $stockId]);
        // Insérer dans company_invoices avec employee_id
        $db->prepare("INSERT INTO company_invoices (faction, employee_id, customer_name, amount, discount, final_amount, description, status, created_at) VALUES (?,?,?,?,0,?,?,?,NOW())")
           ->execute([$companyFaction, $_SESSION['user_id'], $clientName, $total, $total, "Vente: {$item['item_name']} x{$qty}", 'paid']);
        // ── Suivi ventes — classement ──────────────────────────────────────
        $__week = date('Y-\WW');
        $db->prepare("INSERT INTO company_sales (faction, employee_id, amount, customer, week) VALUES (?,?,?,?,?)")
           ->execute([$companyFaction, $_SESSION['user_id'], $total, $clientName, $__week]);
        $message = "Vente : {$item['item_name']} x{$qty} = \$$total"; $messageType = 'success';
    } catch (Exception $e) { $message = 'Erreur: '.$e->getMessage(); $messageType = 'error'; }
}

$stockItems = $db->prepare("SELECT * FROM company_stock WHERE faction=? AND stock_quantity>0 ORDER BY item_category, item_name");
$stockItems->execute([$companyFaction]); $stockItems = $stockItems->fetchAll();

// Historique — employee_id joint à users
$recentSales = $db->prepare("
    SELECT ci.*, u.username as seller
    FROM company_invoices ci
    LEFT JOIN users u ON ci.employee_id = u.id
    WHERE ci.faction=? AND ci.status='paid' AND ci.description LIKE 'Vente:%'
    ORDER BY ci.created_at DESC LIMIT 30
"); $recentSales->execute([$companyFaction]); $recentSales = $recentSales->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<?php if ($message): ?><div class="alert alert-<?php echo $messageType==='success'?'success':'error'; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.5rem;align-items:start;">
    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-title"><i class="fas fa-cash-register"></i> Enregistrer une vente</div></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_sale">
            <div class="form-group"><label class="form-label">Article</label>
                <select name="stock_id" class="form-select" required onchange="updateSalePreview()">
                    <option value="">Sélectionner…</option>
                    <?php foreach ($stockItems as $it): ?>
                    <option value="<?php echo $it['id']; ?>" data-price="<?php echo $it['item_price']; ?>" data-stock="<?php echo $it['stock_quantity']; ?>">
                        <?php echo htmlspecialchars($it['item_name']); ?> (stock: <?php echo $it['stock_quantity']; ?>) — $<?php echo number_format($it['item_price'],0); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Quantité</label><input type="number" name="qty" id="sale-qty" class="form-input" min="1" value="1" oninput="updateSalePreview()"></div>
            <div class="form-group"><label class="form-label">Client (optionnel)</label><input type="text" name="client" class="form-input" placeholder="Nom ou 'Comptoir'"></div>
            <div id="sale-preview" style="display:none;background:var(--gold-dim);border:1px solid var(--border-2);border-radius:10px;padding:.9rem;margin-bottom:1rem;text-align:center;">
                <div style="font-size:.7rem;color:var(--text-2);margin-bottom:.25rem;">Total</div>
                <div id="sale-preview-val" style="font-size:1.4rem;font-weight:800;color:var(--gold);font-family:'DM Mono',monospace;"></div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;"><i class="fas fa-check"></i> Valider la vente</button>
        </form>
    </div>
    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-title"><i class="fas fa-history"></i> Dernières ventes</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Article</th><th>Client</th><th>Montant</th><th>Vendeur</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if (empty($recentSales)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:1.5rem;color:var(--text-2);">Aucune vente</td></tr>
                    <?php else: foreach ($recentSales as $s): ?>
                    <tr>
                        <td style="font-size:.8rem;"><?php echo htmlspecialchars(str_replace('Vente: ', '', $s['description'])); ?></td>
                        <td style="font-size:.8rem;color:var(--text-2);"><?php echo htmlspecialchars($s['customer_name']); ?></td>
                        <td style="font-weight:700;color:var(--gold);">$<?php echo number_format($s['final_amount'],0); ?></td>
                        <td style="font-size:.78rem;"><?php echo htmlspecialchars($s['seller'] ?? '—'); ?></td>
                        <td style="font-size:.72rem;font-family:'DM Mono',monospace;color:var(--text-2);"><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
function updateSalePreview() {
    const sel = document.querySelector('select[name="stock_id"]');
    const qty = parseInt(document.getElementById('sale-qty').value)||0;
    const preview = document.getElementById('sale-preview');
    if (!sel.value||qty<=0){preview.style.display='none';return;}
    const price = parseFloat(sel.options[sel.selectedIndex].dataset.price||0);
    document.getElementById('sale-preview-val').textContent = '$'+(price*qty).toLocaleString('fr-FR');
    preview.style.display='block';
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
