<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = '🚕 Taxi';
$companyColor = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Vente';
$db = Database::getInstance()->getConnection();
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
$message = '';
$messageType = '';

// TRAITER UNE VENTE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sell') {
    try {
        $db->beginTransaction();
        
        $customerName = trim($_POST['customer_name']);
        $cart = json_decode($_POST['cart_data'], true);
        $adjustmentType = $_POST['adjustment_type'] ?? 'none';
        $adjustmentValue = floatval($_POST['adjustment_value'] ?? 0);
        
        if (empty($cart)) {
            throw new Exception("Panier vide");
        }
        
        // Calculer le total
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        
        // Appliquer réduction/augmentation
        $adjustment = 0;
        if ($adjustmentType === 'discount') {
            $adjustment = -($subtotal * $adjustmentValue / 100);
        } elseif ($adjustmentType === 'increase') {
            $adjustment = ($subtotal * $adjustmentValue / 100);
        }
        
        $finalAmount = $subtotal + $adjustment;
        
        // Créer la facture
        $description = "Vente de " . count($cart) . " article(s):\n";
        foreach ($cart as $item) {
            $description .= "- {$item['name']} x{$item['quantity']} @ $" . number_format($item['price'], 2) . "\n";
            
            // Vérifier et mettre à jour le stock
            $stmt = $db->prepare("
                SELECT stock_quantity FROM company_stock 
                WHERE faction = ? AND item_name = ?
            ");
            $stmt->execute([$companyFaction, $item['name']]);
            $currentStock = $stmt->fetchColumn();
            
            if ($currentStock !== false && $currentStock < $item['quantity']) {
                throw new Exception("Stock insuffisant pour: {$item['name']}");
            }
            
            // Déduire du stock
            if ($currentStock !== false) {
                $stmt = $db->prepare("
                    UPDATE company_stock 
                    SET stock_quantity = stock_quantity - ?
                    WHERE faction = ? AND item_name = ?
                ");
                $stmt->execute([$item['quantity'], $companyFaction, $item['name']]);
            }
        }
        
        if ($adjustmentType !== 'none') {
            $description .= "\n" . ($adjustmentType === 'discount' ? 'Réduction' : 'Supplément') . ": " . $adjustmentValue . "% ($" . number_format(abs($adjustment), 2) . ")";
        }
        
        // Insérer la facture
        $stmt = $db->prepare("
            INSERT INTO company_invoices (
                faction, employee_id, customer_name, amount, discount, final_amount, description, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'paid')
        ");
        $stmt->execute([
            $companyFaction,
            $_SESSION['user_id'],
            $customerName,
            $subtotal,
            $adjustment,
            $finalAmount,
            $description
        ]);
        
        // Mettre à jour le CA individuel de l'employé
        $stmt = $db->prepare("
            UPDATE company_employees 
            SET individual_ca = individual_ca + ?,
                calculated_salary = (individual_ca + ?) * salary_percentage / 100
            WHERE user_id = ? AND faction = ?
        ");
        $stmt->execute([$finalAmount, $finalAmount, $_SESSION['user_id'], $companyFaction]);
        
        // Mettre à jour le CA global
        $stmt = $db->prepare("
            UPDATE company_finances 
            SET ca_brut = ca_brut + ?
            WHERE faction = ?
        ");
        $stmt->execute([$finalAmount, $companyFaction]);
        
        // Si la table company_finances n'existe pas encore pour cette faction
        $stmt = $db->prepare("
            INSERT IGNORE INTO company_finances (faction, ca_brut) VALUES (?, ?)
        ");
        $stmt->execute([$companyFaction, 0]);
        
        
        // ── Suivi ventes — classement ──────────────────────────────────────
        $__week = date('Y-\WW');
        $db->prepare("INSERT INTO company_sales (faction, employee_id, amount, customer, week) VALUES (?,?,?,?,?)")
           ->execute([$companyFaction, $_SESSION['user_id'], $finalAmount, $customerName, $__week]);

        $db->commit();
        
        $message = "Vente enregistrée ! Total: $" . number_format($finalAmount, 2);
        $messageType = 'success';
        
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// RÉCUPÉRER LE STOCK
$stmt = $db->prepare("
    SELECT * FROM company_stock 
    WHERE faction = ? 
    AND stock_quantity > 0
    ORDER BY item_category, item_name
");
$stmt->execute([$companyFaction]);
$stockItems = $stmt->fetchAll();

// Grouper par catégorie
$categories = [];
foreach ($stockItems as $item) {
    $cat = $item['item_category'] ?: 'Autre';
    if (!isset($categories[$cat])) {
        $categories[$cat] = [];
    }
    $categories[$cat][] = $item;
}

include __DIR__ . '/includes/header.php';
?>

<style>
.vente-container {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
}

.stock-section {
    background: rgba(15, 23, 41, 0.6);
    border: 1px solid rgba(236, 72, 153, 0.15);
    border-radius: 16px;
    padding: 2rem;
}

.category-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #e8edf5;
    margin: 1.5rem 0 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid rgba(236, 72, 153, 0.3);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.category-title:first-child {
    margin-top: 0;
}

.items-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.item-card {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.08), rgba(219, 39, 119, 0.05));
    border: 2px solid rgba(236, 72, 153, 0.2);
    border-radius: 12px;
    padding: 1.25rem;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
}

.item-card:hover {
    border-color: rgba(236, 72, 153, 0.5);
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(236, 72, 153, 0.2);
}

.item-card.selected {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.25), rgba(219, 39, 119, 0.15));
    border-color: #ec4899;
    box-shadow: 0 0 0 3px rgba(236, 72, 153, 0.2);
}

.item-name {
    font-weight: 700;
    color: #e8edf5;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
}

.item-price {
    font-size: 1.25rem;
    font-weight: 900;
    color: #22c55e;
    font-family: 'Orbitron', monospace;
    margin-bottom: 0.5rem;
}

.item-stock {
    font-size: 0.8rem;
    color: #64748b;
}

.quantity-control {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 0.75rem;
}

.quantity-btn {
    width: 30px;
    height: 30px;
    background: rgba(236, 72, 153, 0.2);
    border: 1px solid rgba(236, 72, 153, 0.4);
    border-radius: 6px;
    color: #ec4899;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.quantity-btn:hover {
    background: rgba(236, 72, 153, 0.3);
}

.quantity-display {
    font-weight: 700;
    color: #e8edf5;
    min-width: 30px;
    text-align: center;
}

.cart-section {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.1), rgba(219, 39, 119, 0.05));
    border: 2px solid rgba(236, 72, 153, 0.3);
    border-radius: 16px;
    padding: 2rem;
    height: fit-content;
    position: sticky;
    top: 100px;
}

.cart-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #e8edf5;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.cart-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: #64748b;
}

.cart-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 10px;
    margin-bottom: 0.75rem;
}

.cart-item-info {
    flex: 1;
}

.cart-item-name {
    font-weight: 700;
    color: #e8edf5;
    margin-bottom: 0.25rem;
}

.cart-item-details {
    font-size: 0.85rem;
    color: #94a3b8;
}

.cart-item-remove {
    width: 32px;
    height: 32px;
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.4);
    border-radius: 8px;
    color: #ef4444;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
}

.cart-item-remove:hover {
    background: rgba(239, 68, 68, 0.3);
}

.cart-summary {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 2px solid rgba(236, 72, 153, 0.3);
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.summary-label {
    color: #94a3b8;
    font-weight: 600;
}

.summary-value {
    font-weight: 700;
    color: #e8edf5;
    font-family: 'Orbitron', monospace;
}

.summary-total {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 2px solid rgba(236, 72, 153, 0.3);
}

.summary-total .summary-label {
    font-size: 1.1rem;
    color: #e8edf5;
}

.summary-total .summary-value {
    font-size: 1.75rem;
    color: #22c55e;
}

.adjustment-control {
    margin: 1.5rem 0;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 10px;
}

.adjustment-buttons {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.adjustment-btn {
    padding: 0.6rem;
    background: rgba(236, 72, 153, 0.1);
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 8px;
    color: #94a3b8;
    cursor: pointer;
    transition: all 0.3s;
    font-weight: 600;
    font-size: 0.85rem;
}

.adjustment-btn.active {
    background: rgba(236, 72, 153, 0.25);
    border-color: #ec4899;
    color: #ec4899;
}

@media (max-width: 1200px) {
    .vente-container {
        grid-template-columns: 1fr;
    }
    
    .cart-section {
        position: static;
    }
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="vente-container">
    <!-- Stock -->
    <div class="stock-section">
        <h2 style="margin-bottom: 1.5rem; font-size: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-boxes"></i>
            Stock Disponible
        </h2>
        
        <?php if (empty($stockItems)): ?>
            <div style="text-align: center; padding: 4rem 2rem; color: #64748b;">
                <i class="fas fa-box-open" style="font-size: 4rem; opacity: 0.3; margin-bottom: 1rem;"></i>
                <p>Aucun article en stock</p>
                <a href="stock.php" class="btn btn-secondary" style="margin-top: 1rem;">
                    <i class="fas fa-plus"></i> Ajouter des articles
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($categories as $categoryName => $items): ?>
                <div class="category-title">
                    <i class="fas fa-tag"></i>
                    <?php echo htmlspecialchars($categoryName); ?>
                </div>
                
                <div class="items-grid">
                    <?php foreach ($items as $item): ?>
                        <div class="item-card" data-id="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['item_name']); ?>" data-price="<?php echo $item['item_price']; ?>" data-stock="<?php echo $item['stock_quantity']; ?>" onclick="toggleItem(this)">
                            <div class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="item-price">$<?php echo number_format($item['item_price'], 2); ?></div>
                            <div class="item-stock">
                                <i class="fas fa-box"></i> <?php echo $item['stock_quantity']; ?> en stock
                            </div>
                            <div class="quantity-control" style="display: none;">
                                <button type="button" class="quantity-btn" onclick="event.stopPropagation(); changeQuantity(this.parentElement.parentElement, -1)">-</button>
                                <span class="quantity-display">1</span>
                                <button type="button" class="quantity-btn" onclick="event.stopPropagation(); changeQuantity(this.parentElement.parentElement, 1)">+</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Panier -->
    <div class="cart-section">
        <div class="cart-title">
            <i class="fas fa-shopping-cart"></i>
            Panier
        </div>
        
        <div id="cartItems"></div>
        
        <div id="cartEmpty" class="cart-empty">
            <i class="fas fa-shopping-cart" style="font-size: 3rem; opacity: 0.3; margin-bottom: 1rem;"></i>
            <p>Panier vide</p>
        </div>
        
        <div id="cartSummary" class="cart-summary" style="display: none;">
            <div class="adjustment-control">
                <label class="form-label" style="margin-bottom: 0.75rem;">Ajustement</label>
                <div class="adjustment-buttons">
                    <button type="button" class="adjustment-btn active" onclick="setAdjustment('none')">Aucun</button>
                    <button type="button" class="adjustment-btn" onclick="setAdjustment('discount')">Réduction</button>
                    <button type="button" class="adjustment-btn" onclick="setAdjustment('increase')">Supplément</button>
                </div>
                <input type="number" id="adjustmentValue" class="form-input" placeholder="%" min="0" max="100" step="0.01" value="0" style="display: none;" oninput="updateCart()">
            </div>
            
            <div class="summary-row">
                <span class="summary-label">Sous-total</span>
                <span class="summary-value" id="subtotalValue">$0.00</span>
            </div>
            
            <div class="summary-row" id="adjustmentRow" style="display: none;">
                <span class="summary-label" id="adjustmentLabel"></span>
                <span class="summary-value" id="adjustmentValue2">$0.00</span>
            </div>
            
            <div class="summary-row summary-total">
                <span class="summary-label">TOTAL</span>
                <span class="summary-value" id="totalValue">$0.00</span>
            </div>
            
            <form method="POST" id="saleForm" style="margin-top: 1.5rem;">
                <input type="hidden" name="action" value="sell">
                <input type="hidden" name="cart_data" id="cartData">
                <input type="hidden" name="adjustment_type" id="adjustmentType" value="none">
                <input type="hidden" name="adjustment_value" id="adjustmentValueHidden" value="0">
                
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Nom du Client *</label>
                    <input type="text" name="customer_name" class="form-input" required placeholder="Prénom NOM">
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-cash-register"></i> Finaliser la Vente
                </button>
            </form>
        </div>
    </div>
</div>

<script>
let cart = [];
let adjustmentType = 'none';

function toggleItem(card) {
    const id = card.dataset.id;
    const name = card.dataset.name;
    const price = parseFloat(card.dataset.price);
    const stock = parseInt(card.dataset.stock);
    
    const existingIndex = cart.findIndex(item => item.id === id);
    
    if (existingIndex >= 0) {
        // Retirer du panier
        cart.splice(existingIndex, 1);
        card.classList.remove('selected');
        card.querySelector('.quantity-control').style.display = 'none';
        card.querySelector('.quantity-display').textContent = '1';
    } else {
        // Ajouter au panier
        cart.push({
            id: id,
            name: name,
            price: price,
            quantity: 1,
            stock: stock
        });
        card.classList.add('selected');
        card.querySelector('.quantity-control').style.display = 'flex';
    }
    
    updateCart();
}

function changeQuantity(card, delta) {
    const id = card.dataset.id;
    const item = cart.find(i => i.id === id);
    
    if (!item) return;
    
    const newQuantity = item.quantity + delta;
    
    if (newQuantity < 1) {
        // Retirer du panier
        cart = cart.filter(i => i.id !== id);
        card.classList.remove('selected');
        card.querySelector('.quantity-control').style.display = 'none';
        card.querySelector('.quantity-display').textContent = '1';
    } else if (newQuantity <= item.stock) {
        item.quantity = newQuantity;
        card.querySelector('.quantity-display').textContent = newQuantity;
    } else {
        MDTConfirm.toast('Stock insuffisant', 'warning');
        return;
    }
    
    updateCart();
}

function setAdjustment(type) {
    adjustmentType = type;
    document.getElementById('adjustmentType').value = type;
    
    // Mise à jour des boutons
    document.querySelectorAll('.adjustment-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    // Afficher/masquer l'input
    const input = document.getElementById('adjustmentValue');
    if (type === 'none') {
        input.style.display = 'none';
        input.value = '0';
    } else {
        input.style.display = 'block';
    }
    
    updateCart();
}

function updateCart() {
    const cartItemsDiv = document.getElementById('cartItems');
    const cartEmptyDiv = document.getElementById('cartEmpty');
    const cartSummaryDiv = document.getElementById('cartSummary');
    
    if (cart.length === 0) {
        cartItemsDiv.innerHTML = '';
        cartEmptyDiv.style.display = 'block';
        cartSummaryDiv.style.display = 'none';
        return;
    }
    
    cartEmptyDiv.style.display = 'none';
    cartSummaryDiv.style.display = 'block';
    
    // Afficher les articles
    cartItemsDiv.innerHTML = cart.map(item => `
        <div class="cart-item">
            <div class="cart-item-info">
                <div class="cart-item-name">${item.name}</div>
                <div class="cart-item-details">
                    ${item.quantity} × $${item.price.toFixed(2)} = $${(item.quantity * item.price).toFixed(2)}
                </div>
            </div>
            <button type="button" class="cart-item-remove" onclick="removeFromCart('${item.id}')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `).join('');
    
    // Calculer le total
    let subtotal = 0;
    cart.forEach(item => {
        subtotal += item.price * item.quantity;
    });
    
    let adjustment = 0;
    const adjustmentValue = parseFloat(document.getElementById('adjustmentValue').value) || 0;
    document.getElementById('adjustmentValueHidden').value = adjustmentValue;
    
    if (adjustmentType === 'discount') {
        adjustment = -(subtotal * adjustmentValue / 100);
    } else if (adjustmentType === 'increase') {
        adjustment = (subtotal * adjustmentValue / 100);
    }
    
    const total = subtotal + adjustment;
    
    // Afficher
    document.getElementById('subtotalValue').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('totalValue').textContent = '$' + total.toFixed(2);
    
    const adjustmentRow = document.getElementById('adjustmentRow');
    const adjustmentLabel = document.getElementById('adjustmentLabel');
    const adjustmentValue2 = document.getElementById('adjustmentValue2');
    
    if (adjustmentType !== 'none' && adjustmentValue > 0) {
        adjustmentRow.style.display = 'flex';
        adjustmentLabel.textContent = adjustmentType === 'discount' ? 'Réduction' : 'Supplément';
        adjustmentLabel.textContent += ` (${adjustmentValue}%)`;
        adjustmentValue2.textContent = (adjustment >= 0 ? '+' : '') + '$' + Math.abs(adjustment).toFixed(2);
        adjustmentValue2.style.color = adjustment >= 0 ? '#f59e0b' : '#22c55e';
    } else {
        adjustmentRow.style.display = 'none';
    }
    
    // Mettre à jour le champ caché
    document.getElementById('cartData').value = JSON.stringify(cart);
}

function removeFromCart(id) {
    cart = cart.filter(item => item.id !== id);
    
    // Réinitialiser la carte visuelle
    const card = document.querySelector(`.item-card[data-id="${id}"]`);
    if (card) {
        card.classList.remove('selected');
        card.querySelector('.quantity-control').style.display = 'none';
        card.querySelector('.quantity-display').textContent = '1';
    }
    
    updateCart();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
