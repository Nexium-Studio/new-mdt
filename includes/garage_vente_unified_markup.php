<?php
/**
 * Corps HTML/JS commun — vente atelier (inclus après header).
 *
 * @var string $gvAccent
 * @var array<string, array<string, list<array<string,mixed>>>> $gvModsGrouped
 * @var list<array{value:string,label:string}> $gvCategories
 * @var list<array<string,mixed>> $stockItems
 * @var array<string, list<array<string,mixed>>> $categories
 */
$gvAccent = $gvAccent ?? '#8b5cf6';
$gvTitle = $gvTitle ?? 'Configuration véhicule & personnalisation';
?>
<style>
.vente-wrap { --vente-a: <?php echo htmlspecialchars($gvAccent); ?>; }
.vente-container {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
}
.stock-section {
    background: rgba(15, 23, 41, 0.6);
    border: 1px solid color-mix(in srgb, var(--vente-a) 22%, transparent);
    border-radius: 16px;
    padding: 2rem;
}
.category-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #e8edf5;
    margin: 1.5rem 0 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid color-mix(in srgb, var(--vente-a) 35%, transparent);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.category-title:first-child { margin-top: 0; }
.items-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.item-card {
    background: linear-gradient(135deg, color-mix(in srgb, var(--vente-a) 12%, transparent), rgba(0,0,0,0.15));
    border: 2px solid color-mix(in srgb, var(--vente-a) 22%, transparent);
    border-radius: 12px;
    padding: 1.25rem;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
}
.item-card:hover {
    border-color: color-mix(in srgb, var(--vente-a) 55%, transparent);
    transform: translateY(-3px);
}
.item-card.selected {
    border-color: var(--vente-a);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--vente-a) 22%, transparent);
}
.item-name { font-weight: 700; color: #e8edf5; margin-bottom: 0.5rem; font-size: 0.95rem; }
.item-price { font-size: 1.25rem; font-weight: 900; color: #22c55e; font-family: 'Orbitron', monospace; margin-bottom: 0.5rem; }
.item-stock { font-size: 0.8rem; color: #64748b; }
.quantity-control { display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-top: 0.75rem; }
.quantity-btn {
    width: 30px; height: 30px;
    background: color-mix(in srgb, var(--vente-a) 18%, transparent);
    border: 1px solid color-mix(in srgb, var(--vente-a) 40%, transparent);
    border-radius: 6px;
    color: var(--vente-a);
    cursor: pointer;
    font-weight: 700;
}
.quantity-display { font-weight: 700; color: #e8edf5; min-width: 30px; text-align: center; }
.cart-section {
    background: linear-gradient(135deg, color-mix(in srgb, var(--vente-a) 14%, transparent), rgba(0,0,0,0.12));
    border: 2px solid color-mix(in srgb, var(--vente-a) 30%, transparent);
    border-radius: 16px;
    padding: 2rem;
    height: fit-content;
    position: sticky;
    top: 100px;
}
.cart-title { font-size: 1.5rem; font-weight: 800; color: #e8edf5; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
.cart-empty { text-align: center; padding: 2rem 1rem; color: #64748b; }
.cart-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 1rem; background: rgba(0, 0, 0, 0.2); border-radius: 10px; margin-bottom: 0.75rem;
}
.cart-item-name { font-weight: 700; color: #e8edf5; margin-bottom: 0.25rem; }
.cart-item-details { font-size: 0.85rem; color: #94a3b8; }
.cart-item-remove {
    width: 32px; height: 32px; background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 8px; color: #ef4444; cursor: pointer;
}
.cart-summary { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid color-mix(in srgb, var(--vente-a) 28%, transparent); }
.summary-row { display: flex; justify-content: space-between; margin-bottom: 0.75rem; }
.summary-label { color: #94a3b8; font-weight: 600; }
.summary-value { font-weight: 700; color: #e8edf5; font-family: 'Orbitron', monospace; }
.summary-total { margin-top: 1rem; padding-top: 1rem; border-top: 2px solid color-mix(in srgb, var(--vente-a) 28%, transparent); }
.summary-total .summary-value { font-size: 1.65rem; color: #22c55e; }
.adjustment-control { margin: 1.5rem 0; padding: 1rem; background: rgba(0, 0, 0, 0.2); border-radius: 10px; }
.adjustment-buttons { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-bottom: 1rem; }
.adjustment-btn {
    padding: 0.6rem; background: color-mix(in srgb, var(--vente-a) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--vente-a) 28%, transparent);
    border-radius: 8px; color: #94a3b8; cursor: pointer; font-weight: 600; font-size: 0.85rem;
}
.adjustment-btn.active {
    background: color-mix(in srgb, var(--vente-a) 22%, transparent);
    border-color: var(--vente-a); color: #e8edf5;
}
.gv-line-row { font-size: 0.8rem; color: #a5b4fc; margin-bottom: 0.35rem; }
@media (max-width: 1200px) {
    .vente-container { grid-template-columns: 1fr; }
    .cart-section { position: static; }
}
</style>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType ?? 'info'); ?>">
        <i class="fas fa-<?php echo ($messageType ?? '') === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars((string) $message); ?>
    </div>
<?php endif; ?>

<div class="vente-wrap">
<form method="POST" id="workshopSaleForm" novalidate>
    <input type="hidden" name="action" value="sell_workshop">
    <input type="hidden" name="cart_data" id="cartData" value="[]">
    <input type="hidden" name="adjustment_type" id="adjustmentType" value="none">
    <input type="hidden" name="adjustment_value" id="adjustmentValueHidden" value="0">

    <?php
    $gvModsGrouped = $gvModsGrouped;
    $gvCategories = $gvCategories;
    include __DIR__ . '/partials/garage_vente_configurator.php';
    ?>

    <div class="vente-container">
        <div class="stock-section">
            <h2 style="margin-bottom: 1.5rem; font-size: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fas fa-boxes"></i> Stock & pièces
            </h2>
            <?php if (empty($stockItems)): ?>
                <div style="text-align: center; padding: 3rem 2rem; color: #64748b;">
                    <i class="fas fa-box-open" style="font-size: 3rem; opacity: 0.3; margin-bottom: 1rem;"></i>
                    <p>Aucun article en stock</p>
                </div>
            <?php else: ?>
                <?php foreach ($categories as $categoryName => $items): ?>
                    <div class="category-title">
                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars((string) $categoryName); ?>
                    </div>
                    <div class="items-grid">
                        <?php foreach ($items as $item): ?>
                            <div class="item-card"
                                 data-id="<?php echo (int) $item['id']; ?>"
                                 data-name="<?php echo htmlspecialchars((string) $item['item_name'], ENT_QUOTES); ?>"
                                 data-price="<?php echo htmlspecialchars((string) $item['item_price']); ?>"
                                 data-stock="<?php echo (int) $item['stock_quantity']; ?>"
                                 onclick="gvToggleItem(this)">
                                <div class="item-name"><?php echo htmlspecialchars((string) $item['item_name']); ?></div>
                                <div class="item-price">$<?php echo number_format((float) $item['item_price'], 2); ?></div>
                                <div class="item-stock"><i class="fas fa-box"></i> <?php echo (int) $item['stock_quantity']; ?> en stock</div>
                                <div class="quantity-control" style="display: none;">
                                    <button type="button" class="quantity-btn" onclick="event.stopPropagation(); gvChangeQty(this.parentElement.parentElement, -1)">−</button>
                                    <span class="quantity-display">1</span>
                                    <button type="button" class="quantity-btn" onclick="event.stopPropagation(); gvChangeQty(this.parentElement.parentElement, 1)">+</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="cart-section">
            <div class="cart-title"><i class="fas fa-file-invoice-dollar"></i> Récapitulatif</div>
            <div id="cartEmpty" class="cart-empty">
                <p>Ajoutez des pièces au panier et/ou configurez le véhicule ci-dessus.</p>
            </div>
            <div id="cartItems"></div>

            <div id="gvLineSummary" class="gv-line-row" style="display:none;"></div>

            <div id="cartSummary" class="cart-summary" style="display: none;">
                <div class="adjustment-control">
                    <label class="form-label" style="margin-bottom: 0.75rem;">Ajustement (sur customisation + stock)</label>
                    <div class="adjustment-buttons">
                        <button type="button" class="adjustment-btn active" data-adj="none" onclick="gvSetAdj('none', this)">Aucun</button>
                        <button type="button" class="adjustment-btn" data-adj="discount" onclick="gvSetAdj('discount', this)">Réduction</button>
                        <button type="button" class="adjustment-btn" data-adj="increase" onclick="gvSetAdj('increase', this)">Supplément</button>
                    </div>
                    <input type="number" id="adjustmentValue" class="form-input" placeholder="%" min="0" max="100" step="0.01" value="0" style="display: none;" oninput="gvUpdateCart()">
                </div>
                <div class="summary-row">
                    <span class="summary-label">Customisation</span>
                    <span class="summary-value" id="gvSumWorkshop">$0.00</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Stock / pièces</span>
                    <span class="summary-value" id="gvSumStock">$0.00</span>
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

                <div class="form-group" style="margin-top: 1.25rem;">
                    <label class="form-label">Nom du client *</label>
                    <input type="text" name="customer_name" class="form-input" required placeholder="Prénom NOM" autocomplete="name">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                    <i class="fas fa-file-invoice"></i> Générer la facture (en attente de paiement)
                </button>
            </div>
        </div>
    </div>
</form>
</div>

<script>
let gvCart = [];
let gvAdj = 'none';

function gvGarageLine() {
    const el = document.getElementById('gvGarageLineHidden');
    return el ? (parseFloat(el.value) || 0) : 0;
}

function gvStockSubtotal() {
    return gvCart.reduce(function (s, i) { return s + i.price * i.quantity; }, 0);
}

function gvToggleItem(card) {
    const id = String(card.dataset.id);
    const name = card.dataset.name;
    const price = parseFloat(card.dataset.price);
    const stock = parseInt(card.dataset.stock, 10);
    const idx = gvCart.findIndex(function (i) { return String(i.id) === id; });
    if (idx >= 0) {
        gvCart.splice(idx, 1);
        card.classList.remove('selected');
        card.querySelector('.quantity-control').style.display = 'none';
        card.querySelector('.quantity-display').textContent = '1';
    } else {
        gvCart.push({ id: id, name: name, price: price, quantity: 1, stock: stock });
        card.classList.add('selected');
        card.querySelector('.quantity-control').style.display = 'flex';
    }
    gvUpdateCart();
}

function gvChangeQty(card, delta) {
    const id = String(card.dataset.id);
    const item = gvCart.find(function (i) { return String(i.id) === id; });
    if (!item) return;
    const nq = item.quantity + delta;
    if (nq < 1) {
        gvCart = gvCart.filter(function (i) { return String(i.id) !== id; });
        card.classList.remove('selected');
        card.querySelector('.quantity-control').style.display = 'none';
        card.querySelector('.quantity-display').textContent = '1';
    } else if (nq <= item.stock) {
        item.quantity = nq;
        card.querySelector('.quantity-display').textContent = nq;
    } else {
        alert('Stock insuffisant');
        return;
    }
    gvUpdateCart();
}

function gvRemoveFromCart(id) {
    gvCart = gvCart.filter(function (i) { return String(i.id) !== String(id); });
    const card = document.querySelector('.item-card[data-id="' + id + '"]');
    if (card) {
        card.classList.remove('selected');
        const qc = card.querySelector('.quantity-control');
        const qd = card.querySelector('.quantity-display');
        if (qc) qc.style.display = 'none';
        if (qd) qd.textContent = '1';
    }
    gvUpdateCart();
}

function gvSetAdj(type, btn) {
    gvAdj = type;
    document.getElementById('adjustmentType').value = type;
    document.querySelectorAll('#workshopSaleForm .adjustment-btn').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    const inp = document.getElementById('adjustmentValue');
    inp.style.display = type === 'none' ? 'none' : 'block';
    if (type === 'none') inp.value = '0';
    gvUpdateCart();
}

function gvUpdateCart() {
    const itemsEl = document.getElementById('cartItems');
    const emptyEl = document.getElementById('cartEmpty');
    const sumEl = document.getElementById('cartSummary');
    const lineSum = document.getElementById('gvLineSummary');

    const gLine = gvGarageLine();
    const stockSub = gvStockSubtotal();
    const combined = gLine + stockSub;

    const hasWorkshop = gLine > 0 || (document.getElementById('gvPlate') && document.getElementById('gvPlate').value.trim() !== '');
    const show = gvCart.length > 0 || combined > 0 || hasWorkshop;

    if (!show) {
        itemsEl.innerHTML = '';
        emptyEl.style.display = 'block';
        sumEl.style.display = 'none';
        lineSum.style.display = 'none';
        document.getElementById('cartData').value = '[]';
        return;
    }
    emptyEl.style.display = 'none';
    sumEl.style.display = 'block';

    if (gLine > 0) {
        lineSum.style.display = 'block';
        lineSum.textContent = 'Customisation : ' + (document.getElementById('gvDispGarage') ? document.getElementById('gvDispGarage').textContent : '');
    } else {
        lineSum.style.display = 'none';
    }

    itemsEl.innerHTML = gvCart.map(function (item) {
        return '<div class="cart-item">' +
            '<div class="cart-item-info">' +
            '<div class="cart-item-name">' + item.name + '</div>' +
            '<div class="cart-item-details">' + item.quantity + ' × $' + item.price.toFixed(2) + '</div>' +
            '</div>' +
            '<button type="button" class="cart-item-remove" onclick="gvRemoveFromCart(\'' + String(item.id).replace(/'/g, "\\'") + '\')"><i class="fas fa-times"></i></button>' +
            '</div>';
    }).join('');

    let adj = 0;
    const adjPct = parseFloat(document.getElementById('adjustmentValue').value) || 0;
    document.getElementById('adjustmentValueHidden').value = adjPct;
    if (gvAdj === 'discount') adj = -(combined * adjPct / 100);
    else if (gvAdj === 'increase') adj = combined * adjPct / 100;
    const total = Math.max(0, combined + adj);

    document.getElementById('gvSumWorkshop').textContent = '$' + gLine.toFixed(2);
    document.getElementById('gvSumStock').textContent = '$' + stockSub.toFixed(2);
    document.getElementById('subtotalValue').textContent = '$' + combined.toFixed(2);
    document.getElementById('totalValue').textContent = '$' + total.toFixed(2);

    const adjRow = document.getElementById('adjustmentRow');
    if (gvAdj !== 'none' && adjPct > 0) {
        adjRow.style.display = 'flex';
        document.getElementById('adjustmentLabel').textContent = (gvAdj === 'discount' ? 'Réduction' : 'Supplément') + ' (' + adjPct + '%)';
        document.getElementById('adjustmentValue2').textContent = (adj >= 0 ? '+' : '') + '$' + Math.abs(adj).toFixed(2);
    } else {
        adjRow.style.display = 'none';
    }

    document.getElementById('cartData').value = JSON.stringify(gvCart);
}

document.getElementById('gvModIdsJson')?.addEventListener('change', gvUpdateCart);
document.getElementById('gvBasePrice')?.addEventListener('input', function () { setTimeout(gvUpdateCart, 0); });
document.getElementById('gvPlate')?.addEventListener('input', gvUpdateCart);

document.getElementById('workshopSaleForm').addEventListener('submit', function (e) {
    const plate = document.getElementById('gvPlate')?.value?.trim() || '';
    const model = document.getElementById('gvModel')?.value?.trim() || '';
    const cat = document.getElementById('gvCategoryValue')?.value || '';
    const cartRaw = document.getElementById('cartData').value;
    let hasCart = false;
    try {
        const arr = JSON.parse(cartRaw);
        hasCart = Array.isArray(arr) && arr.length > 0;
    } catch (x) {}
    const hasVeh = plate && model && cat;
    if (!hasVeh && !hasCart) {
        e.preventDefault();
        alert('Renseignez le véhicule (plaque, modèle, catégorie) ou ajoutez des articles au panier.');
        return false;
    }
    const combined = gvGarageLine() + gvStockSubtotal();
    if (combined <= 0) {
        e.preventDefault();
        alert('Le montant total doit être supérieur à 0 (prix de base, modifications ou panier stock).');
        return false;
    }
    return true;
});

gvUpdateCart();
</script>
