<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = '🐈 UwU Coffee';
$companyColor = '#ec4899';
$companyFaction = 'UWU_COFFEE';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Gestion du Stock';
$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Vérifier permissions
$stmt = $db->prepare("
    SELECT cr.can_manage_stock
    FROM company_ranks cr
    WHERE cr.faction = ? AND cr.rank_name = ?
");
$stmt->execute([$companyFaction, $_SESSION['user_faction_rank'] ?? '']);
$permissions = $stmt->fetch() ?: ['can_manage_stock' => false];
$canManage = $permissions['can_manage_stock'] || hasRole(['*', 'Administrateur']);

// AJOUTER ARTICLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add' && $canManage) {
    try {
        $stmt = $db->prepare("
            INSERT INTO company_stock (faction, item_name, item_category, item_price, stock_quantity)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                item_price = VALUES(item_price),
                stock_quantity = stock_quantity + VALUES(stock_quantity)
        ");
        $stmt->execute([
            $companyFaction,
            trim($_POST['item_name']),
            trim($_POST['item_category']),
            floatval($_POST['item_price']),
            intval($_POST['stock_quantity'])
        ]);
        
        $message = 'Article ajouté au stock';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// MODIFIER ARTICLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update' && $canManage) {
    try {
        $stmt = $db->prepare("
            UPDATE company_stock 
            SET item_name = ?, item_category = ?, item_price = ?, stock_quantity = ?
            WHERE id = ? AND faction = ?
        ");
        $stmt->execute([
            trim($_POST['item_name']),
            trim($_POST['item_category']),
            floatval($_POST['item_price']),
            intval($_POST['stock_quantity']),
            intval($_POST['item_id']),
            $companyFaction
        ]);
        
        $message = 'Article mis à jour';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// SUPPRIMER ARTICLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    try {
        $stmt = $db->prepare("DELETE FROM company_stock WHERE id = ? AND faction = ?");
        $stmt->execute([intval($_POST['item_id']), $companyFaction]);
        
        $message = 'Article supprimé';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// RÉCUPÉRER LE STOCK
$stmt = $db->prepare("SELECT * FROM company_stock WHERE faction = ? ORDER BY item_category, item_name");
$stmt->execute([$companyFaction]);
$stockItems = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
.stock-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
}

.stock-card {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.08), rgba(219, 39, 119, 0.05));
    border: 1px solid rgba(236, 72, 153, 0.15);
    border-radius: 14px;
    padding: 1.5rem;
    transition: all 0.3s;
}

.stock-card:hover {
    border-color: rgba(236, 72, 153, 0.4);
    transform: translateY(-3px);
}

.stock-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.stock-name {
    font-size: 1.1rem;
    font-weight: 800;
    color: #e8edf5;
}

.stock-category {
    padding: 0.35rem 0.75rem;
    background: rgba(236, 72, 153, 0.15);
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    color: #ec4899;
    text-transform: uppercase;
}

.stock-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-top: 1rem;
}

.stock-detail {
    text-align: center;
}

.stock-detail-label {
    font-size: 0.75rem;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}

.stock-detail-value {
    font-size: 1.25rem;
    font-weight: 900;
    font-family: 'Orbitron', monospace;
}

.stock-detail-value.price {
    color: #22c55e;
}

.stock-detail-value.quantity {
    color: #60a5fa;
}

.stock-detail-value.low {
    color: #ef4444;
}

.stock-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (!$canManage): ?>
    <div class="info-banner">
        <div class="info-icon">
            <i class="fas fa-info-circle"></i>
        </div>
        <div>
            <strong>Information:</strong> Vous ne pouvez pas gérer le stock avec votre grade actuel.
        </div>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
<div style="display: flex; gap: 1rem; margin-bottom: 2rem;">
    <button onclick="Company.openModal('addStockModal')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Ajouter un Article
    </button>
</div>
<?php endif; ?>

<?php if (empty($stockItems)): ?>
    <div class="card">
        <div style="text-align: center; padding: 4rem 2rem; color: #64748b;">
            <i class="fas fa-box-open" style="font-size: 4rem; opacity: 0.3; margin-bottom: 1rem;"></i>
            <p>Aucun article en stock</p>
        </div>
    </div>
<?php else: ?>
    <div class="stock-grid">
        <?php foreach ($stockItems as $item): ?>
            <div class="stock-card">
                <div class="stock-header">
                    <div class="stock-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                    <div class="stock-category"><?php echo htmlspecialchars($item['item_category'] ?: 'Autre'); ?></div>
                </div>
                
                <div class="stock-details">
                    <div class="stock-detail">
                        <div class="stock-detail-label">Prix</div>
                        <div class="stock-detail-value price">$<?php echo number_format($item['item_price'], 2); ?></div>
                    </div>
                    <div class="stock-detail">
                        <div class="stock-detail-label">Stock</div>
                        <div class="stock-detail-value quantity <?php echo $item['stock_quantity'] < 10 ? 'low' : ''; ?>">
                            <?php echo $item['stock_quantity']; ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($canManage): ?>
                <div class="stock-actions">
                    <button onclick="editStock(<?php echo htmlspecialchars(json_encode($item)); ?>)" class="btn btn-secondary btn-sm" style="flex: 1;">
                        <i class="fas fa-edit"></i> Modifier
                    </button>
                    <form method="POST" style="margin: 0;" data-confirm="Supprimer cet article ?">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal Ajouter -->
<?php if ($canManage): ?>
<div id="addStockModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Ajouter un Article</h2>
            <button class="modal-close" onclick="Company.closeModal('addStockModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom de l'Article *</label>
                        <input type="text" name="item_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Catégorie</label>
                        <select name="item_category" class="form-select">
                            <option value="Boisson">Boisson</option>
                            <option value="Nourriture">Nourriture</option>
                            <option value="Service">Service</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix *</label>
                        <input type="number" name="item_price" class="form-input" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Quantité *</label>
                        <input type="number" name="stock_quantity" class="form-input" min="0" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('addStockModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Ajouter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modifier -->
<div id="editStockModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Modifier l'Article</h2>
            <button class="modal-close" onclick="Company.closeModal('editStockModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="item_id" id="edit_item_id">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom de l'Article *</label>
                        <input type="text" name="item_name" id="edit_item_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Catégorie</label>
                        <select name="item_category" id="edit_item_category" class="form-select">
                            <option value="Boisson">Boisson</option>
                            <option value="Nourriture">Nourriture</option>
                            <option value="Service">Service</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix *</label>
                        <input type="number" name="item_price" id="edit_item_price" class="form-input" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Quantité *</label>
                        <input type="number" name="stock_quantity" id="edit_stock_quantity" class="form-input" min="0" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('editStockModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function editStock(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_item_category').value = item.item_category || 'Autre';
    document.getElementById('edit_item_price').value = item.item_price;
    document.getElementById('edit_stock_quantity').value = item.stock_quantity;
    Company.openModal('editStockModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>