<?php
/**
 * DarkNet — Black Market (commun).
 * Flash sale le samedi à partir de 21h.
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Black Market';
$success = '';
$error = '';

// Flash sale : samedi (6) de 21h à 23h59 (timezone serveur)
$now = new DateTime('now');
$dow = (int) $now->format('w'); // 0=dim, 6=sam
$hour = (int) $now->format('H');
$flashActive = ($dow === 6 && $hour >= 21);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_item') {
                if (!$dnIsStaff) { throw new RuntimeException('Création réservée au staff.'); }
                $name = trim((string) ($_POST['name'] ?? ''));
                $price = max(0, (int) ($_POST['price'] ?? 0));
                if ($name === '') { throw new RuntimeException('Nom requis.'); }
                $s = $db->prepare("INSERT INTO darknet_market_items (name, description, category, price, currency, stock, is_flash, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $s->execute([
                    $name,
                    trim((string) ($_POST['description'] ?? '')) ?: null,
                    trim((string) ($_POST['category'] ?? '')) ?: null,
                    $price,
                    ($_POST['currency'] ?? 'dirty') === 'clean' ? 'clean' : 'dirty',
                    max(1, (int) ($_POST['stock'] ?? 1)),
                    !empty($_POST['is_flash']) ? 1 : 0,
                    $dnUserId,
                ]);
                $success = 'Produit ajouté.';
            } elseif ($action === 'toggle_active') {
                if (!$dnIsStaff) { throw new RuntimeException('Réservé staff.'); }
                $id = (int) ($_POST['id'] ?? 0);
                $db->prepare("UPDATE darknet_market_items SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                $success = 'Statut modifié.';
            } elseif ($action === 'delete_item') {
                if (!$dnIsStaff) { throw new RuntimeException('Réservé staff.'); }
                $id = (int) ($_POST['id'] ?? 0);
                $db->prepare("DELETE FROM darknet_market_items WHERE id = ?")->execute([$id]);
                $success = 'Produit supprimé.';
            } elseif ($action === 'purchase') {
                if (!$dnIsLeader) { throw new RuntimeException('Achat réservé aux chefs de gang.'); }
                $id = (int) ($_POST['id'] ?? 0);
                $qty = max(1, (int) ($_POST['qty'] ?? 1));
                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT * FROM darknet_market_items WHERE id = ? AND is_active = 1 FOR UPDATE");
                    $st->execute([$id]);
                    $item = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$item) { throw new RuntimeException('Produit indisponible.'); }
                    if ((int) $item['stock'] < $qty) { throw new RuntimeException('Stock insuffisant.'); }
                    if ((int) $item['is_flash'] && !$flashActive) { throw new RuntimeException('Flash sale inactive (dispo samedi 21h).'); }
                    $total = (int) $item['price'] * $qty;
                    $cur = $item['currency'];

                    $bs = $db->prepare("SELECT clean_balance, dirty_balance FROM darknet_bank WHERE faction_key = ? FOR UPDATE");
                    $bs->execute([$dnFaction]);
                    $bank = $bs->fetch(PDO::FETCH_ASSOC) ?: ['clean_balance'=>0,'dirty_balance'=>0];
                    $bal = $cur === 'clean' ? (int) $bank['clean_balance'] : (int) $bank['dirty_balance'];
                    if ($bal < $total) { throw new RuntimeException('Fonds ' . $cur . ' insuffisants dans la banque de faction.'); }

                    // Débit banque faction
                    if ($cur === 'clean') {
                        $db->prepare("UPDATE darknet_bank SET clean_balance = clean_balance - ? WHERE faction_key = ?")->execute([$total, $dnFaction]);
                    } else {
                        $db->prepare("UPDATE darknet_bank SET dirty_balance = dirty_balance - ? WHERE faction_key = ?")->execute([$total, $dnFaction]);
                    }
                    // Décrément stock
                    $db->prepare("UPDATE darknet_market_items SET stock = stock - ? WHERE id = ?")->execute([$qty, $id]);
                    // Log bank_tx + achat
                    $db->prepare("INSERT INTO darknet_bank_tx (faction_key, user_id, currency, type, amount, notes) VALUES (?, ?, ?, 'withdraw', ?, ?)")
                       ->execute([$dnFaction, $dnUserId, $cur, -$total, 'Black Market — ' . $item['name'] . ' ×' . $qty]);
                    $db->prepare("INSERT INTO darknet_market_purchases (item_id, buyer_faction, buyer_user, amount_paid, currency, qty) VALUES (?, ?, ?, ?, ?, ?)")
                       ->execute([$id, $dnFaction, $dnUserId, $total, $cur, $qty]);

                    $db->commit();
                    $success = "Achat effectué : $qty × " . $item['name'] . " pour $total $ ($cur).";
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($success !== '') {
            $_SESSION['darknet_csrf'] = bin2hex(random_bytes(16));
            $dnCsrf = $_SESSION['darknet_csrf'];
        }
    }
}

// Filtre
$showFlash = !empty($_GET['flash']);
$where = 'is_active = 1';
if ($showFlash) { $where .= ' AND is_flash = 1'; }

$items = $db->query("SELECT * FROM darknet_market_items WHERE $where ORDER BY is_flash DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$purchases = $db->prepare("
    SELECT p.*, i.name AS item_name, f.name AS faction_name, pr.firstname, pr.lastname, u.username
    FROM darknet_market_purchases p
    LEFT JOIN darknet_market_items i ON i.id = p.item_id
    LEFT JOIN factions f ON f.faction_key = p.buyer_faction
    LEFT JOIN users u ON u.id = p.buyer_user
    LEFT JOIN profiles pr ON pr.id = u.profile_id
    ORDER BY p.created_at DESC LIMIT 15
");
$purchases->execute();
$purchases = $purchases->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>
<style>
.bm-hero{background:linear-gradient(135deg,rgba(244,114,182,.12),rgba(13,17,33,.9));border:1px solid rgba(244,114,182,.3);border-radius:14px;padding:22px 26px;margin-bottom:22px;position:relative;overflow:hidden}
.bm-hero h1{font-family:'Syne',sans-serif;font-size:1.4rem;color:#fff;letter-spacing:3px;text-transform:uppercase;display:flex;align-items:center;gap:10px}
.bm-hero .sub{color:#94a3b8;font-size:.85rem;margin-top:6px;font-family:'Share Tech Mono',monospace;letter-spacing:1px}
.bm-hero .bg{position:absolute;right:-40px;top:-40px;font-size:10rem;opacity:.04;color:#f472b6}
.bm-flash{display:inline-flex;align-items:center;gap:8px;margin-top:12px;padding:6px 14px;border-radius:20px;font-family:'Share Tech Mono',monospace;font-size:.72rem;letter-spacing:1.5px;text-transform:uppercase}
.bm-flash.on{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);color:#fca5a5;animation:bmpulse 2s infinite}
.bm-flash.off{background:rgba(148,163,184,.08);border:1px solid rgba(148,163,184,.2);color:#64748b}
@keyframes bmpulse{0%,100%{box-shadow:0 0 10px rgba(239,68,68,.3)}50%{box-shadow:0 0 20px rgba(239,68,68,.55)}}

.bm-filters{display:flex;gap:10px;margin-bottom:20px;align-items:center}
.bm-filter{padding:8px 14px;border-radius:8px;background:rgba(13,17,33,.7);border:1px solid rgba(148,163,184,.18);color:#94a3b8;text-decoration:none;font-family:'Share Tech Mono',monospace;font-size:.72rem;letter-spacing:1px;text-transform:uppercase;transition:all .15s}
.bm-filter.active{background:rgba(244,114,182,.14);border-color:rgba(244,114,182,.4);color:#f9a8d4}
.bm-filter:hover{border-color:rgba(167,139,250,.35);color:#c4b5fd}

.bm-grid{display:grid;grid-template-columns:1fr 340px;gap:22px;align-items:start}
@media(max-width:1100px){.bm-grid{grid-template-columns:1fr}}

.bm-items{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.bm-item{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:14px;padding:16px 18px;position:relative;transition:all .2s}
.bm-item:hover{border-color:rgba(244,114,182,.35);transform:translateY(-2px)}
.bm-item.flash{border-color:rgba(239,68,68,.35);background:linear-gradient(135deg,rgba(239,68,68,.08),rgba(13,17,33,.9))}
.bm-item.flash::before{content:'FLASH';position:absolute;top:10px;right:10px;background:#ef4444;color:#fff;font-family:'Share Tech Mono',monospace;font-size:.58rem;padding:3px 8px;border-radius:4px;letter-spacing:1.5px;font-weight:700}
.bm-item .cat{font-family:'Share Tech Mono',monospace;font-size:.6rem;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px}
.bm-item .nm{font-family:'Syne',sans-serif;font-weight:700;color:#fff;font-size:1.05rem;margin-bottom:6px}
.bm-item .ds{color:#94a3b8;font-size:.78rem;line-height:1.5;margin-bottom:12px;min-height:40px}
.bm-item .pr{display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid rgba(148,163,184,.1)}
.bm-item .pr .amt{font-family:'JetBrains Mono',monospace;font-size:1.2rem;font-weight:700;color:#fff}
.bm-item .pr .cur{display:inline-block;font-size:.55rem;padding:2px 6px;border-radius:3px;margin-left:5px;letter-spacing:1px;text-transform:uppercase}
.bm-item .pr .cur.clean{background:rgba(34,197,94,.18);color:#86efac}
.bm-item .pr .cur.dirty{background:rgba(239,68,68,.18);color:#fca5a5}
.bm-item .stock{font-family:'Share Tech Mono',monospace;font-size:.65rem;color:#64748b;letter-spacing:1px;margin-top:8px}
.bm-item .buy{display:block;width:100%;margin-top:12px;padding:9px;background:linear-gradient(135deg,#f472b6,#a78bfa);border:none;border-radius:8px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;font-size:.72rem;cursor:pointer}
.bm-item .buy:disabled{opacity:.4;cursor:not-allowed}

.bm-side-card{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:16px 18px;margin-bottom:14px;position:sticky;top:92px}
.bm-side-card h3{font-family:'Syne',sans-serif;font-size:.82rem;letter-spacing:2px;text-transform:uppercase;color:#e2e8f0;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.bm-side-card input,.bm-side-card select,.bm-side-card textarea{width:100%;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:8px 11px;border-radius:7px;font-size:.82rem;margin-bottom:7px;font-family:'DM Sans',sans-serif}
.bm-side-card button{width:100%;padding:10px;background:linear-gradient(135deg,#f472b6,#7c3aed);border:none;border-radius:8px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;font-size:.72rem;cursor:pointer;margin-top:4px}

.bm-log-item{padding:10px 0;border-bottom:1px solid rgba(148,163,184,.06);font-size:.76rem;color:#94a3b8}
.bm-log-item:last-child{border-bottom:none}
.bm-log-item b{color:#cbd5e1;font-weight:600}
.bm-log-item .t{color:#64748b;font-family:'Share Tech Mono',monospace;font-size:.62rem;letter-spacing:1px}

.bm-alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:.82rem}
.bm-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.bm-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
</style>

<div class="bm-hero">
    <h1><i class="fa-solid fa-basket-shopping" style="color:#f472b6"></i> Black Market</h1>
    <div class="sub">Armement · drogue · faveurs · documents. Paiement depuis la banque de ta faction.</div>
    <?php if ($flashActive): ?>
        <span class="bm-flash on"><i class="fa-solid fa-bolt"></i> Flash sale active — jusqu'à 23:59</span>
    <?php else: ?>
        <span class="bm-flash off"><i class="fa-regular fa-clock"></i> Flash sale : Samedi 21h → 23h59</span>
    <?php endif; ?>
    <i class="fa-solid fa-sack-dollar bg"></i>
</div>

<?php if ($success): ?><div class="bm-alert ok"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="bm-alert ko"><i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($error); ?></div><?php endif; ?>

<div class="bm-filters">
    <a href="?" class="bm-filter <?= !$showFlash ? 'active' : ''; ?>">Tous les produits</a>
    <a href="?flash=1" class="bm-filter <?= $showFlash ? 'active' : ''; ?>"><i class="fa-solid fa-bolt"></i> Flash uniquement</a>
</div>

<div class="bm-grid">
    <div>
        <?php if (!$items): ?>
            <div style="text-align:center;padding:60px 20px;color:#64748b;background:rgba(13,17,33,.5);border:1px dashed rgba(148,163,184,.15);border-radius:12px">
                <i class="fa-solid fa-store-slash" style="font-size:2.5rem;opacity:.3;margin-bottom:10px;display:block"></i>
                <p>Aucun produit disponible.</p>
                <?php if ($dnIsStaff): ?><p style="font-size:.8rem;margin-top:6px">Utilise le formulaire pour ajouter des produits.</p><?php endif; ?>
            </div>
        <?php else: ?>
        <div class="bm-items">
            <?php foreach ($items as $it):
                $disabled = ((int) $it['stock'] < 1) || ((int) $it['is_flash'] && !$flashActive);
            ?>
                <div class="bm-item <?= (int) $it['is_flash'] ? 'flash' : ''; ?>">
                    <?php if ($it['category']): ?><div class="cat"><?= htmlspecialchars($it['category']); ?></div><?php endif; ?>
                    <div class="nm"><?= htmlspecialchars($it['name']); ?></div>
                    <div class="ds"><?= htmlspecialchars($it['description'] ?? ''); ?></div>
                    <div class="pr">
                        <div><span class="amt"><?= number_format((int) $it['price'], 0, ',', ' '); ?> $</span><span class="cur <?= htmlspecialchars($it['currency']); ?>"><?= htmlspecialchars($it['currency']); ?></span></div>
                        <div style="color:#64748b;font-size:.72rem">Stock: <?= (int) $it['stock']; ?></div>
                    </div>
                    <form method="post" onsubmit="return confirm('Confirmer l\u0027achat ?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                        <input type="hidden" name="action" value="purchase">
                        <input type="hidden" name="id" value="<?= (int) $it['id']; ?>">
                        <input type="hidden" name="qty" value="1">
                        <button type="submit" class="buy" <?= $disabled ? 'disabled' : ''; ?>>
                            <?php if ((int) $it['stock'] < 1): ?>Épuisé
                            <?php elseif ((int) $it['is_flash'] && !$flashActive): ?><i class="fa-solid fa-lock"></i> Flash inactive
                            <?php elseif (!$dnIsLeader): ?><i class="fa-solid fa-ban"></i> Leader requis
                            <?php else: ?>Acheter<?php endif; ?>
                        </button>
                    </form>
                    <?php if ($dnIsStaff): ?>
                    <div style="display:flex;gap:6px;margin-top:7px">
                        <form method="post" style="flex:1">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int) $it['id']; ?>">
                            <button type="submit" class="dn-btn" style="width:100%"><i class="fa-solid fa-power-off"></i></button>
                        </form>
                        <form method="post" style="flex:1" onsubmit="return confirm('Supprimer ?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="id" value="<?= (int) $it['id']; ?>">
                            <button type="submit" class="dn-btn danger" style="width:100%"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <aside>
        <?php if ($dnIsStaff): ?>
        <div class="bm-side-card">
            <h3><i class="fa-solid fa-plus" style="color:#f472b6"></i> Ajouter un produit</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="create_item">
                <input type="text" name="name" placeholder="Nom" required>
                <input type="text" name="category" placeholder="Catégorie">
                <textarea name="description" rows="2" placeholder="Description"></textarea>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                    <input type="number" name="price" placeholder="Prix" min="0" required>
                    <select name="currency"><option value="dirty">Dirty</option><option value="clean">Clean</option></select>
                </div>
                <input type="number" name="stock" min="1" placeholder="Stock" value="1">
                <label style="display:flex;align-items:center;gap:8px;color:#94a3b8;font-size:.78rem;margin:8px 0">
                    <input type="checkbox" name="is_flash" value="1" style="width:auto;margin:0"> Flash sale uniquement
                </label>
                <button type="submit">Créer</button>
            </form>
        </div>
        <?php else: ?>
        <div class="bm-side-card">
            <h3><i class="fa-solid fa-info-circle" style="color:#22d3ee"></i> Comment ça marche</h3>
            <p style="color:#94a3b8;font-size:.8rem;line-height:1.6">
                Paiement débité directement sur la banque de ta faction.<br>
                Achats réservés aux <b style="color:#fbbf24">leaders</b>.<br>
                Produits flagués <b style="color:#fca5a5">FLASH</b> uniquement le samedi 21h-24h.
            </p>
        </div>
        <?php endif; ?>

        <div class="bm-side-card" style="position:static">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Dernières ventes</h3>
            <?php if (!$purchases): ?>
                <p style="color:#64748b;font-size:.8rem;text-align:center;padding:15px 0">Aucun achat.</p>
            <?php else: foreach ($purchases as $p):
                $name = trim(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? ''));
                if ($name === '') { $name = $p['username'] ?? '—'; }
            ?>
                <div class="bm-log-item">
                    <b><?= htmlspecialchars($p['faction_name'] ?? $p['buyer_faction']); ?></b> · <?= htmlspecialchars($p['item_name'] ?? '—'); ?> × <?= (int) $p['qty']; ?>
                    <div style="color:#f472b6;font-family:'JetBrains Mono',monospace;font-weight:600;margin-top:2px"><?= number_format((int) $p['amount_paid'], 0, ',', ' '); ?> $ <?= htmlspecialchars($p['currency']); ?></div>
                    <div class="t"><?= htmlspecialchars(date('d/m H:i', strtotime($p['created_at']))); ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </aside>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
