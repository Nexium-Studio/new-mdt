<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🔫 Ammunation';
$companyColor   = '#dc2626';
$companyFaction = 'AMMUNATION';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Point de Vente';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'vente.view')) faction_acl_deny();
$canCreate = faction_acl_can($db, $uid, $companyFaction, 'vente.create');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'vente.delete');

// Catégories centralisées (depuis staff > Catalogues & Stock)
require_once ROOT_PATH . '/includes/stock_central.php';
$_CATS      = stock_get_categories($db, $companyFaction);
$CAT_ICONS  = array_map(fn($c) => $c['icon'],  $_CATS);
$CAT_COLORS = array_map(fn($c) => $c['color'], $_CATS);
if (empty($CAT_ICONS)) { $CAT_ICONS = ['Autre' => 'fas fa-box']; $CAT_COLORS = ['Autre' => '#64748b']; }

// ── TRAITER VENTE ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sell') {
    try {
        $db->beginTransaction();
        $customerName    = trim($_POST['customer_name']);
        $cart            = json_decode($_POST['cart_data'], true);
        $adjustmentType  = $_POST['adjustment_type'] ?? 'none';
        $adjustmentValue = floatval($_POST['adjustment_value'] ?? 0);
        $licenseNumber   = trim($_POST['license_number'] ?? '');

        if (empty($cart)) throw new Exception("Panier vide");
        if (!$customerName)  throw new Exception("Nom du client requis");

        // Calculer sous-total
        $subtotal = 0;
        foreach ($cart as $it) $subtotal += $it['price'] * $it['quantity'];

        $adjustment = 0;
        if ($adjustmentType==='discount') $adjustment = -($subtotal*$adjustmentValue/100);
        if ($adjustmentType==='increase') $adjustment =  ($subtotal*$adjustmentValue/100);
        $finalAmount = $subtotal + $adjustment;

        // Description
        $lines = [];
        foreach ($cart as $it) {
            $stmt = $db->prepare("SELECT stock_quantity FROM company_stock WHERE faction=? AND item_name=?");
            $stmt->execute([$companyFaction, $it['name']]);
            $cur = $stmt->fetchColumn();
            if ($cur !== false && $cur < $it['quantity']) throw new Exception("Stock insuffisant : {$it['name']}");
            if ($cur !== false) {
                $db->prepare("UPDATE company_stock SET stock_quantity=stock_quantity-? WHERE faction=? AND item_name=?")
                   ->execute([$it['quantity'], $companyFaction, $it['name']]);
            }
            $lines[] = "- {$it['name']} x{$it['quantity']} @ $".number_format($it['price'],2);
        }
        $description = "Vente ".count($cart)." article(s):\n".implode("\n",$lines);
        if ($licenseNumber) $description .= "\nPermis/Licence : {$licenseNumber}";
        if ($adjustmentType!=='none') $description .= "\n".($adjustmentType==='discount'?'Réduction':'Supplément').": {$adjustmentValue}%";

        // Insérer facture
        $db->prepare("
            INSERT INTO company_invoices (faction, employee_id, customer_name, amount, discount, final_amount, description, status)
            VALUES (?,?,?,?,?,?,?,'paid')
        ")->execute([$companyFaction, $_SESSION['user_id'], $customerName, $subtotal, $adjustment, $finalAmount, $description]);

        // Mettre à jour CA employé
        $db->prepare("
            UPDATE company_employees
            SET individual_ca=individual_ca+?,
                calculated_salary=(individual_ca+?)*salary_percentage/100
            WHERE user_id=? AND faction=?
        ")->execute([$finalAmount,$finalAmount,$_SESSION['user_id'],$companyFaction]);

        // Mettre à jour CA global (INSERT IGNORE au cas où)
        $db->prepare("INSERT IGNORE INTO company_finances (faction,ca_brut) VALUES (?,0)")->execute([$companyFaction]);
        $db->prepare("UPDATE company_finances SET ca_brut=ca_brut+? WHERE faction=?")->execute([$finalAmount,$companyFaction]);

        
        // ── Suivi ventes — classement ──────────────────────────────────────
        $__week = date('Y-\WW');
        $db->prepare("INSERT INTO company_sales (faction, employee_id, amount, customer, week) VALUES (?,?,?,?,?)")
           ->execute([$companyFaction, $_SESSION['user_id'], $finalAmount, $customerName, $__week]);

        $db->commit();
        $msg = "✓ Vente enregistrée — Total: $".number_format($finalAmount,2)." | Client: {$customerName}";
        $msgType = 'success';

    } catch(Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $msg = 'Erreur : '.$e->getMessage(); $msgType='error';
    }
}

// ── STOCK DISPONIBLE ──────────────────────────────────────────────────────────
$stmt=$db->prepare("SELECT * FROM company_stock WHERE faction=? AND stock_quantity>0 ORDER BY item_category,item_name");
$stmt->execute([$companyFaction]);
$allItems=$stmt->fetchAll();

$categories=[];
foreach($allItems as $it) {
    $cat = $it['item_category'] ?: 'Autre';
    $categories[$cat][] = $it;
}

// ── Citoyens (sélection client) + PPA valides par citoyen ─────────────────────
$venteCitizens = [];
try {
    foreach ($db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname, firstname LIMIT 1000")->fetchAll() as $r) {
        $av = trim((string)($r['avatar'] ?? ''));
        $venteCitizens[] = [
            'id'     => (int)$r['id'],
            'name'   => trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')),
            'avatar' => $av !== '' ? (preg_match('/^https?:\/\//', $av) ? $av : rtrim((string)SITE_URL, '/') . $av) : '',
        ];
    }
} catch (Exception $e) {}
$ventePpa = [];
try {
    foreach ($db->query("SELECT citizen_id, type_arme FROM lspd_ppa_demandes WHERE statut='valide' AND type_arme IS NOT NULL AND type_arme<>'' ORDER BY created_at DESC")->fetchAll() as $r) {
        $ventePpa[(int)$r['citizen_id']][] = $r['type_arme'];
    }
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>
<style>
/* ── LAYOUT ───── */
.pos-wrap{display:grid;grid-template-columns:1fr 350px;gap:1.5rem;align-items:start}

/* ── CATALOGUE ───── */
.catalogue{background:rgba(13,21,38,.8);border:1px solid rgba(220,38,38,.14);border-radius:14px;overflow:hidden}
.cat-tabs{display:flex;overflow-x:auto;border-bottom:1px solid rgba(220,38,38,.14);scrollbar-width:none}
.cat-tabs::-webkit-scrollbar{display:none}
.cat-tab{display:flex;align-items:center;gap:.45rem;padding:.85rem 1.2rem;font-size:.82rem;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;transition:all .2s;white-space:nowrap;border:none;background:none;font-family:inherit}
.cat-tab:hover{color:#f1f5f9;background:rgba(220,38,38,.06)}
.cat-tab.active{color:#f87171;border-bottom-color:var(--cc)}

.items-panel{padding:1.5rem;display:none}
.items-panel.active{display:block}
.items-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:.85rem}

.item-card{background:rgba(8,12,23,.7);border:2px solid rgba(220,38,38,.14);border-radius:11px;padding:1.1rem;cursor:pointer;transition:all .22s;text-align:center;user-select:none}
.item-card:hover{border-color:rgba(220,38,38,.4);transform:translateY(-2px);box-shadow:0 5px 18px rgba(220,38,38,.15)}
.item-card.in-cart{background:rgba(220,38,38,.1);border-color:var(--cc);box-shadow:0 0 0 3px rgba(220,38,38,.15)}
.item-icon{font-size:1.75rem;margin-bottom:.6rem;display:block}
.item-name{font-weight:700;font-size:.82rem;color:#f1f5f9;margin-bottom:.4rem;line-height:1.3}
.item-price{font-size:1.1rem;font-weight:900;color:#4ade80;margin-bottom:.3rem}
.item-stock{font-size:.72rem;color:#64748b}
.qty-ctrl{display:none;align-items:center;justify-content:center;gap:.6rem;margin-top:.6rem}
.qty-btn{width:26px;height:26px;background:rgba(220,38,38,.2);border:1px solid rgba(220,38,38,.4);border-radius:5px;color:#f87171;cursor:pointer;font-weight:700;display:flex;align-items:center;justify-content:center;transition:background .15s}
.qty-btn:hover{background:rgba(220,38,38,.35)}
.qty-num{font-weight:700;color:#f1f5f9;min-width:24px;text-align:center}

/* ── PANIER ───── */
.cart-panel{background:rgba(13,21,38,.9);border:2px solid rgba(220,38,38,.22);border-radius:14px;padding:1.5rem;position:sticky;top:88px}
.cart-hd{font-size:1.05rem;font-weight:800;color:#f1f5f9;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between}
.cart-count{width:24px;height:24px;background:var(--cc);border-radius:50%;font-size:.75rem;font-weight:700;color:#fff;display:inline-flex;align-items:center;justify-content:center}
.cart-empty{text-align:center;padding:2.5rem 1rem;color:#475569}
.cart-empty i{font-size:2.5rem;opacity:.25;display:block;margin-bottom:.75rem}
.cart-item{display:flex;align-items:center;gap:.75rem;padding:.75rem;background:rgba(8,12,23,.5);border:1px solid rgba(220,38,38,.1);border-radius:9px;margin-bottom:.55rem}
.ci-info{flex:1}
.ci-name{font-weight:700;font-size:.82rem;color:#f1f5f9;margin-bottom:.15rem}
.ci-sub{font-size:.75rem;color:#64748b}
.ci-rm{width:28px;height:28px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);border-radius:6px;color:#f87171;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.75rem;transition:background .15s;flex-shrink:0}
.ci-rm:hover{background:rgba(239,68,68,.25)}

/* ── AJUSTEMENTS ───── */
.adj-wrap{background:rgba(8,12,23,.5);border:1px solid rgba(220,38,38,.12);border-radius:9px;padding:.9rem;margin:1rem 0}
.adj-btns{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem;margin-bottom:.7rem}
.adj-btn{padding:.5rem;background:transparent;border:1px solid rgba(220,38,38,.2);border-radius:7px;color:#64748b;cursor:pointer;font-size:.78rem;font-weight:600;transition:all .18s;font-family:inherit}
.adj-btn.active{background:rgba(220,38,38,.18);border-color:var(--cc);color:#f87171}

/* ── TOTAUX ───── */
.totals{margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(220,38,38,.14)}
.total-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;font-size:.875rem}
.total-row .lbl{color:#64748b}
.total-row .val{font-weight:700;color:#f1f5f9}
.total-row.big .lbl{font-size:1rem;color:#f1f5f9;font-weight:700}
.total-row.big .val{font-size:1.5rem;color:#4ade80;font-family:'Courier New',monospace}

.sell-form{margin-top:1.25rem}

@media(max-width:1200px){
    .pos-wrap{grid-template-columns:1fr}
    .cart-panel{position:static}
    .items-grid{grid-template-columns:repeat(auto-fill,minmax(145px,1fr))}
}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<div class="pos-wrap">
    <!-- CATALOGUE -->
    <div class="catalogue">
        <div class="cat-tabs" id="catTabs">
            <button type="button" class="cat-tab active" onclick="showTab('all',this)" data-tab="all">
                <i class="fas fa-list"></i> Tout
            </button>
            <?php foreach(array_keys($categories) as $cat): ?>
            <?php $icon = $CAT_ICONS[$cat] ?? 'fas fa-box'; $col = $CAT_COLORS[$cat] ?? '#64748b'; ?>
            <button type="button" class="cat-tab" onclick="showTab('<?php echo htmlspecialchars($cat,ENT_QUOTES); ?>',this)"
                data-tab="<?php echo htmlspecialchars($cat,ENT_QUOTES); ?>">
                <i class="<?php echo $icon; ?>" style="color:<?php echo $col; ?>"></i>
                <?php echo htmlspecialchars($cat); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- PANEL TOUT -->
        <div class="items-panel active" id="tab-all">
            <?php if(empty($allItems)): ?>
            <div style="text-align:center;padding:4rem 2rem;color:#475569">
                <i class="fas fa-box-open" style="font-size:3rem;opacity:.25;display:block;margin-bottom:.75rem"></i>
                <p>Aucun article en stock — <a href="stock.php" style="color:var(--cc)">Gérer le stock</a></p>
            </div>
            <?php else: ?>
            <div class="items-grid">
                <?php foreach($allItems as $it): ?>
                <?php $cat=$it['item_category']?:'Autre'; $icon=$CAT_ICONS[$cat]??'fas fa-box'; $col=$CAT_COLORS[$cat]??'#64748b'; ?>
                <div class="item-card"
                     data-id="<?php echo $it['id']; ?>"
                     data-name="<?php echo htmlspecialchars($it['item_name'],ENT_QUOTES); ?>"
                     data-price="<?php echo $it['item_price']; ?>"
                     data-stock="<?php echo $it['stock_quantity']; ?>"
                     onclick="toggleItem(this)">
                    <span class="item-icon"><i class="<?php echo $icon; ?>" style="color:<?php echo $col; ?>"></i></span>
                    <div class="item-name"><?php echo htmlspecialchars($it['item_name']); ?></div>
                    <div class="item-price">$<?php echo number_format($it['item_price'],2); ?></div>
                    <div class="item-stock"><i class="fas fa-box"></i> <?php echo $it['stock_quantity']; ?></div>
                    <div class="qty-ctrl">
                        <button type="button" class="qty-btn" onclick="event.stopPropagation();changeQty(this.closest('.item-card'),-1)">−</button>
                        <span class="qty-num">1</span>
                        <button type="button" class="qty-btn" onclick="event.stopPropagation();changeQty(this.closest('.item-card'),1)">+</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- PANELS PAR CAT -->
        <?php foreach($categories as $cat=>$items): ?>
        <?php $icon=$CAT_ICONS[$cat]??'fas fa-box'; $col=$CAT_COLORS[$cat]??'#64748b'; ?>
        <div class="items-panel" id="tab-<?php echo htmlspecialchars($cat,ENT_QUOTES); ?>">
            <div class="items-grid">
                <?php foreach($items as $it): ?>
                <div class="item-card"
                     data-id="<?php echo $it['id']; ?>"
                     data-name="<?php echo htmlspecialchars($it['item_name'],ENT_QUOTES); ?>"
                     data-price="<?php echo $it['item_price']; ?>"
                     data-stock="<?php echo $it['stock_quantity']; ?>"
                     onclick="toggleItem(this)">
                    <span class="item-icon"><i class="<?php echo $icon; ?>" style="color:<?php echo $col; ?>"></i></span>
                    <div class="item-name"><?php echo htmlspecialchars($it['item_name']); ?></div>
                    <div class="item-price">$<?php echo number_format($it['item_price'],2); ?></div>
                    <div class="item-stock"><i class="fas fa-box"></i> <?php echo $it['stock_quantity']; ?></div>
                    <div class="qty-ctrl">
                        <button type="button" class="qty-btn" onclick="event.stopPropagation();changeQty(this.closest('.item-card'),-1)">−</button>
                        <span class="qty-num">1</span>
                        <button type="button" class="qty-btn" onclick="event.stopPropagation();changeQty(this.closest('.item-card'),1)">+</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- PANIER -->
    <div class="cart-panel">
        <div class="cart-hd">
            <span><i class="fas fa-shopping-cart" style="color:var(--cc);margin-right:.4rem"></i> Panier</span>
            <span class="cart-count" id="cartCount">0</span>
        </div>

        <div id="cartEmpty" class="cart-empty">
            <i class="fas fa-shopping-cart"></i>
            <p>Panier vide</p>
            <p style="font-size:.78rem;margin-top:.35rem">Cliquez sur un article</p>
        </div>

        <div id="cartBody" style="display:none">
            <div id="cartItems"></div>

            <!-- Ajustement -->
            <div class="adj-wrap">
                <label class="form-label" style="margin-bottom:.5rem;display:block">Ajustement de prix</label>
                <div class="adj-btns">
                    <button type="button" class="adj-btn active" id="adjNone"     onclick="setAdj('none')">Aucun</button>
                    <button type="button" class="adj-btn"        id="adjDiscount" onclick="setAdj('discount')">Réduction</button>
                    <button type="button" class="adj-btn"        id="adjIncrease" onclick="setAdj('increase')">Supplément</button>
                </div>
                <div id="adjInput" style="display:none">
                    <input type="number" id="adjVal" class="form-input" placeholder="%" min="0" max="100" step="0.5" value="0"
                        style="font-size:.9rem" oninput="renderCart()">
                </div>
            </div>

            <!-- Totaux -->
            <div class="totals">
                <div class="total-row"><span class="lbl">Sous-total</span><span class="val" id="subtotal">$0.00</span></div>
                <div class="total-row" id="adjRow" style="display:none">
                    <span class="lbl" id="adjLabel"></span>
                    <span class="val" id="adjAmount"></span>
                </div>
                <div class="total-row big" style="margin-top:.5rem">
                    <span class="lbl">TOTAL</span>
                    <span class="val" id="totalVal">$0.00</span>
                </div>
            </div>

            <!-- Formulaire vente -->
            <form method="POST" id="saleForm" class="sell-form">
                <input type="hidden" name="action" value="sell">
                <input type="hidden" name="cart_data" id="cartData">
                <input type="hidden" name="adjustment_type" id="adjType" value="none">
                <input type="hidden" name="adjustment_value" id="adjValHidden" value="0">

                <div class="form-group" style="margin-bottom:.75rem">
                    <label class="form-label">Client *</label>
                    <button type="button" class="btn btn-secondary btn-sm" id="venteClientBtn" style="width:100%;justify-content:center;border-style:dashed"><i class="fas fa-user-plus"></i> Sélectionner un citoyen</button>
                    <input type="hidden" name="customer_name" id="venteCustomerName">
                    <div id="venteClientChip" style="margin-top:.5rem"></div>
                    <div style="margin-top:.45rem"><a href="#" id="venteManualToggle" style="font-size:.72rem;color:#64748b;text-decoration:none"><i class="fas fa-pen" style="font-size:.65rem;margin-right:.25rem"></i>Client non recensé ? Saisir manuellement</a></div>
                    <input type="text" id="venteManualName" class="form-input hidden" placeholder="Prénom NOM" style="margin-top:.45rem">
                </div>
                <div class="form-group hidden" id="ventePpaGroup" style="margin-bottom:1.1rem">
                    <label class="form-label">PPA concerné</label>
                    <select name="license_number" id="ventePpaSelect" class="form-select"><option value="">Aucun / non concerné</option></select>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                    <i class="fas fa-cash-register"></i> Finaliser la Vente
                </button>
            </form>
        </div>
    </div>
</div>

<script>
let cart = [];
let adjMode = 'none';

function showTab(name, btn) {
    document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.items-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const el = document.getElementById('tab-' + name);
    if (el) el.classList.add('active');
}

function toggleItem(card) {
    const id = card.dataset.id;
    const existing = cart.find(i => i.id === id);
    if (existing) {
        cart = cart.filter(i => i.id !== id);
        // Sync all cards with same id
        document.querySelectorAll(`.item-card[data-id="${id}"]`).forEach(c => {
            c.classList.remove('in-cart');
            c.querySelector('.qty-ctrl').style.display = 'none';
            c.querySelector('.qty-num').textContent = '1';
        });
    } else {
        cart.push({ id, name: card.dataset.name, price: parseFloat(card.dataset.price), quantity: 1, stock: parseInt(card.dataset.stock) });
        document.querySelectorAll(`.item-card[data-id="${id}"]`).forEach(c => {
            c.classList.add('in-cart');
            c.querySelector('.qty-ctrl').style.display = 'flex';
        });
    }
    renderCart();
}

function changeQty(card, delta) {
    const id = card.dataset.id;
    const item = cart.find(i => i.id === id);
    if (!item) return;
    const newQ = item.quantity + delta;
    if (newQ < 1) {
        cart = cart.filter(i => i.id !== id);
        document.querySelectorAll(`.item-card[data-id="${id}"]`).forEach(c => {
            c.classList.remove('in-cart');
            c.querySelector('.qty-ctrl').style.display = 'none';
            c.querySelector('.qty-num').textContent = '1';
        });
    } else if (newQ <= item.stock) {
        item.quantity = newQ;
        document.querySelectorAll(`.item-card[data-id="${id}"] .qty-num`).forEach(n => n.textContent = newQ);
    } else {
        MDTConfirm.toast('Stock insuffisant', 'warning');
    }
    renderCart();
}

function setAdj(mode) {
    adjMode = mode;
    document.getElementById('adjType').value = mode;
    document.querySelectorAll('.adj-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('adj'+mode.charAt(0).toUpperCase()+mode.slice(1)).classList.add('active');
    const inp = document.getElementById('adjInput');
    if (mode === 'none') { inp.style.display='none'; document.getElementById('adjVal').value=0; }
    else inp.style.display='block';
    renderCart();
}

function renderCart() {
    const empty = document.getElementById('cartEmpty');
    const body  = document.getElementById('cartBody');
    const count = document.getElementById('cartCount');
    count.textContent = cart.reduce((s,i)=>s+i.quantity, 0);

    if (cart.length === 0) { empty.style.display='block'; body.style.display='none'; return; }
    empty.style.display='none'; body.style.display='block';

    document.getElementById('cartItems').innerHTML = cart.map(it => `
        <div class="cart-item">
            <div class="ci-info">
                <div class="ci-name">${it.name}</div>
                <div class="ci-sub">${it.quantity} × $${it.price.toFixed(2)} = <strong style="color:#4ade80">$${(it.quantity*it.price).toFixed(2)}</strong></div>
            </div>
            <button type="button" class="ci-rm" onclick="removeItem('${it.id}')"><i class="fas fa-times"></i></button>
        </div>
    `).join('');

    let sub = cart.reduce((s,i)=>s+i.price*i.quantity,0);
    const adjPct = parseFloat(document.getElementById('adjVal').value)||0;
    document.getElementById('adjValHidden').value = adjPct;

    let adj = 0;
    if (adjMode==='discount') adj = -(sub*adjPct/100);
    if (adjMode==='increase') adj = (sub*adjPct/100);
    const total = sub + adj;

    document.getElementById('subtotal').textContent = '$'+sub.toFixed(2);
    document.getElementById('totalVal').textContent = '$'+total.toFixed(2);

    const adjRow = document.getElementById('adjRow');
    if (adjMode!=='none' && adjPct>0) {
        adjRow.style.display='flex';
        document.getElementById('adjLabel').textContent = (adjMode==='discount'?'Réduction':'Supplément')+' ('+adjPct+'%)';
        document.getElementById('adjAmount').textContent = (adj>=0?'+':'')+'$'+Math.abs(adj).toFixed(2);
        document.getElementById('adjAmount').style.color = adj<0?'#4ade80':'#fb923c';
    } else { adjRow.style.display='none'; }

    document.getElementById('cartData').value = JSON.stringify(cart);
}

function removeItem(id) {
    cart = cart.filter(i => i.id !== id);
    document.querySelectorAll(`.item-card[data-id="${id}"]`).forEach(c => {
        c.classList.remove('in-cart');
        c.querySelector('.qty-ctrl').style.display='none';
        c.querySelector('.qty-num').textContent='1';
    });
    renderCart();
}
</script>

<!-- ── Sélecteur de citoyen ── -->
<style>
.vente-cli{display:flex;align-items:center;gap:.65rem;padding:.5rem .6rem;border-radius:9px;border:1px solid transparent;cursor:pointer;transition:background .12s,border-color .12s}
.vente-cli:hover{background:rgba(220,38,38,.08);border-color:rgba(220,38,38,.2)}
.vente-cli-name{font-size:.86rem;font-weight:600;color:#e2e8f0}
</style>
<div id="venteClientModal" class="modal-overlay hidden">
    <div class="modal modal-sm">
        <div class="modal-header"><h2><i class="fas fa-user"></i> Sélectionner un citoyen</h2><button class="modal-close" onclick="Company.closeModal('venteClientModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <input type="text" id="venteClientSearch" class="form-input" placeholder="Rechercher un citoyen…" style="margin-bottom:.85rem">
            <div id="venteClientList" style="display:flex;flex-direction:column;gap:.3rem;max-height:55vh;overflow-y:auto"></div>
        </div>
    </div>
</div>
<script>
const VENTE_CITIZENS = <?php echo json_encode($venteCitizens, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const VENTE_PPA = <?php echo json_encode($ventePpa, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
(function(){
  var btn=document.getElementById('venteClientBtn'), hid=document.getElementById('venteCustomerName'),
      chip=document.getElementById('venteClientChip'), listEl=document.getElementById('venteClientList'),
      searchEl=document.getElementById('venteClientSearch'), ppaGroup=document.getElementById('ventePpaGroup'),
      ppaSel=document.getElementById('ventePpaSelect'), manualToggle=document.getElementById('venteManualToggle'),
      manualInput=document.getElementById('venteManualName'), form=document.getElementById('saleForm');
  if(!btn) return;
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  function avatarHtml(c,sz){sz=sz||34;return c.avatar?('<img src="'+esc(c.avatar)+'" referrerpolicy="no-referrer" style="width:'+sz+'px;height:'+sz+'px;border-radius:50%;object-fit:cover;flex-shrink:0">'):('<span style="width:'+sz+'px;height:'+sz+'px;border-radius:50%;background:linear-gradient(135deg,var(--cc),var(--cc-d));display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.75rem;flex-shrink:0">'+esc((c.name||'?').charAt(0).toUpperCase())+'</span>');}
  function renderList(q){
    q=(q||'').toLowerCase().trim();
    var rows=VENTE_CITIZENS.filter(function(c){return !q||(c.name||'').toLowerCase().indexOf(q)>=0;}).slice(0,300);
    listEl.innerHTML=rows.length?rows.map(function(c){return '<div class="vente-cli" data-id="'+esc(c.id)+'">'+avatarHtml(c)+'<span class="vente-cli-name">'+esc(c.name)+'</span></div>';}).join(''):'<div style="text-align:center;color:#64748b;padding:1.3rem;font-size:.82rem">Aucun résultat</div>';
    listEl.querySelectorAll('.vente-cli').forEach(function(el){el.addEventListener('click',function(){pick(el.dataset.id);});});
  }
  function pick(id){
    var c=VENTE_CITIZENS.find(function(x){return String(x.id)===String(id);}); if(!c)return;
    hid.value=c.name;
    chip.innerHTML='<span style="display:inline-flex;align-items:center;gap:.5rem;padding:.35rem .7rem;background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.3);border-radius:30px;font-size:.82rem;color:#f1f5f9">'+avatarHtml(c,22)+'<span>'+esc(c.name)+'</span><span class="vente-chip-x" style="cursor:pointer;color:#94a3b8;display:inline-flex"><i class="fas fa-times"></i></span></span>';
    var x=chip.querySelector('.vente-chip-x'); if(x)x.addEventListener('click',clearClient);
    var ppas=VENTE_PPA[c.id]||[];
    ppaSel.innerHTML='<option value="">Aucun / non concerné</option>'+ppas.map(function(t){return '<option value="'+esc('PPA '+t)+'">'+esc(t)+'</option>';}).join('');
    ppaGroup.classList.remove('hidden');
    if(manualInput){manualInput.classList.add('hidden');manualInput.value='';}
    Company.closeModal('venteClientModal');
  }
  function clearClient(e){if(e)e.stopPropagation();hid.value='';chip.innerHTML='';ppaGroup.classList.add('hidden');ppaSel.innerHTML='<option value="">Aucun / non concerné</option>';}
  window.venteClearClient=clearClient;
  btn.addEventListener('click',function(){renderList('');Company.openModal('venteClientModal');setTimeout(function(){searchEl.focus();},60);});
  searchEl.addEventListener('input',function(){renderList(searchEl.value);});
  if(manualToggle){manualToggle.addEventListener('click',function(e){e.preventDefault();manualInput.classList.toggle('hidden');if(!manualInput.classList.contains('hidden')){manualInput.focus();chip.innerHTML='';ppaGroup.classList.add('hidden');}else if(!chip.innerHTML){hid.value='';}});}
  if(manualInput){manualInput.addEventListener('input',function(){hid.value=manualInput.value.trim();});}
  if(form){form.addEventListener('submit',function(e){if(!hid.value.trim()){e.preventDefault();if(window.MDTConfirm&&MDTConfirm.toast)MDTConfirm.toast('Veuillez sélectionner un client','warning');else alert('Veuillez sélectionner un client');}});}
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>