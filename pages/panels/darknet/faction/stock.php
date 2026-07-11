<?php
/**
 * DarkNet — Stock (privé / faction).
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Stock';
$success = '';
$error = '';

$log_tx = static function (PDO $db, int $itemId, string $faction, int $userId, string $action, int $delta, ?string $notes = null): void {
    $s = $db->prepare("INSERT INTO darknet_stock_tx (item_id, faction_key, user_id, action, qty_delta, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $s->execute([$itemId, $faction, $userId, $action, $delta, $notes]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_item') {
                if (!$dnIsLeader) { throw new RuntimeException('Réservé aux chefs.'); }
                $name = trim((string) ($_POST['name'] ?? ''));
                $qty  = max(0, (int) ($_POST['quantity'] ?? 0));
                if ($name === '') { throw new RuntimeException('Nom requis.'); }
                $s = $db->prepare("INSERT INTO darknet_stock_items (faction_key, name, category, quantity, unit, notes, added_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $s->execute([
                    $dnFaction,
                    $name,
                    trim((string) ($_POST['category'] ?? '')) ?: null,
                    $qty,
                    trim((string) ($_POST['unit'] ?? 'unité')) ?: 'unité',
                    trim((string) ($_POST['notes'] ?? '')) ?: null,
                    $dnUserId,
                ]);
                $itemId = (int) $db->lastInsertId();
                if ($qty > 0) { $log_tx($db, $itemId, $dnFaction, $dnUserId, 'add', $qty, 'Création'); }
                $success = 'Article ajouté.';
            } elseif ($action === 'adjust') {
                if (!$dnIsLeader) { throw new RuntimeException('Réservé aux chefs.'); }
                $id = (int) ($_POST['id'] ?? 0);
                $delta = (int) ($_POST['delta'] ?? 0);
                if ($id < 1 || $delta === 0) { throw new RuntimeException('Paramètres invalides.'); }
                $s = $db->prepare("UPDATE darknet_stock_items SET quantity = GREATEST(0, quantity + ?) WHERE id = ? AND faction_key = ?");
                $s->execute([$delta, $id, $dnFaction]);
                $log_tx($db, $id, $dnFaction, $dnUserId, 'adjust', $delta, 'Ajustement');
                $success = 'Quantité ajustée.';
            } elseif ($action === 'take') {
                $id = (int) ($_POST['id'] ?? 0);
                $qty = max(1, (int) ($_POST['qty'] ?? 1));
                if ($id < 1) { throw new RuntimeException('Article invalide.'); }
                // Lock la ligne pour éviter les prises concurrentes
                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT quantity FROM darknet_stock_items WHERE id = ? AND faction_key = ? FOR UPDATE");
                    $st->execute([$id, $dnFaction]);
                    $curQty = (int) $st->fetchColumn();
                    if ($curQty <= 0) { throw new RuntimeException('Stock vide.'); }
                    $take = min($qty, $curQty);
                    $db->prepare("UPDATE darknet_stock_items SET quantity = quantity - ? WHERE id = ?")->execute([$take, $id]);
                    $log_tx($db, $id, $dnFaction, $dnUserId, 'take', -$take, null);
                    $db->commit();
                    $success = "Tu as pris $take unité(s).";
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } elseif ($action === 'delete') {
                if (!$dnIsLeader) { throw new RuntimeException('Réservé aux chefs.'); }
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('Paramètres invalides.'); }
                $db->prepare("DELETE FROM darknet_stock_items WHERE id = ? AND faction_key = ?")->execute([$id, $dnFaction]);
                $success = 'Article supprimé.';
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

$items = $db->prepare("SELECT * FROM darknet_stock_items WHERE faction_key = ? ORDER BY category ASC, name ASC");
$items->execute([$dnFaction]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$recent = $db->prepare("
    SELECT t.*, i.name AS item_name, p.firstname, p.lastname, u.username
    FROM darknet_stock_tx t
    LEFT JOIN darknet_stock_items i ON i.id = t.item_id
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE t.faction_key = ?
    ORDER BY t.created_at DESC LIMIT 25
");
$recent->execute([$dnFaction]);
$recent = $recent->fetchAll(PDO::FETCH_ASSOC);

$totalItems = count($items);
$totalQty   = array_sum(array_map(static fn($i) => (int) $i['quantity'], $items));
$byCategory = [];
foreach ($items as $i) {
    $c = $i['category'] ?: 'Autres';
    $byCategory[$c] = ($byCategory[$c] ?? 0) + (int) $i['quantity'];
}

include __DIR__ . '/../includes/header.php';
?>
<style>
.stk-grid{display:grid;grid-template-columns:1fr 320px;gap:22px;align-items:start}
@media(max-width:1100px){.stk-grid{grid-template-columns:1fr}}
.stk-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.stk-stat{background:rgba(13,17,33,.8);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:14px 16px}
.stk-stat .v{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:700;color:#e2e8f0}
.stk-stat .l{font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;margin-top:4px}
.stk-stat.c{border-color:rgba(34,211,238,.3);background:linear-gradient(135deg,rgba(34,211,238,.08),rgba(13,17,33,.85))}
.stk-stat.c .v{color:#67e8f9}

.stk-table{width:100%;border-collapse:collapse;background:rgba(13,17,33,.7);border:1px solid rgba(148,163,184,.1);border-radius:12px;overflow:hidden}
.stk-table thead th{background:rgba(15,23,42,.8);color:#94a3b8;font-family:'Share Tech Mono',monospace;font-size:.65rem;letter-spacing:1.5px;text-transform:uppercase;padding:12px 14px;text-align:left;font-weight:600;border-bottom:1px solid rgba(148,163,184,.12)}
.stk-table tbody td{padding:12px 14px;color:#cbd5e1;font-size:.82rem;border-bottom:1px solid rgba(148,163,184,.06)}
.stk-table tbody tr:last-child td{border-bottom:none}
.stk-table tbody tr:hover{background:rgba(167,139,250,.04)}
.stk-cat{display:inline-block;padding:3px 8px;font-family:'Share Tech Mono',monospace;font-size:.62rem;background:rgba(124,58,237,.12);color:#c4b5fd;border-radius:4px;letter-spacing:1px;text-transform:uppercase}
.stk-qty{font-family:'JetBrains Mono',monospace;font-weight:700;color:#22d3ee;font-size:.95rem}
.stk-qty.zero{color:#ef4444}
.stk-actions{display:flex;gap:5px;justify-content:flex-end}

.stk-side{display:flex;flex-direction:column;gap:16px;position:sticky;top:92px}
.stk-card{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:16px 18px}
.stk-card h3{font-family:'Syne',sans-serif;font-size:.82rem;letter-spacing:2px;text-transform:uppercase;color:#e2e8f0;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.stk-card input,.stk-card select,.stk-card textarea{width:100%;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.18);color:#e2e8f0;padding:8px 11px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.82rem;margin-bottom:8px}
.stk-card input:focus,.stk-card select:focus,.stk-card textarea:focus{outline:none;border-color:#a78bfa}
.stk-card button.pr{width:100%;padding:10px;background:linear-gradient(135deg,#7c3aed,#22d3ee);border:none;border-radius:8px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;font-size:.75rem}
.stk-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}

.stk-log{max-height:320px;overflow-y:auto}
.stk-log-item{padding:10px 0;border-bottom:1px solid rgba(148,163,184,.06);font-size:.78rem;color:#94a3b8}
.stk-log-item:last-child{border-bottom:none}
.stk-log-item b{color:#cbd5e1;font-weight:600}
.stk-log-item .d{color:#22d3ee;font-family:'JetBrains Mono',monospace;font-weight:600}
.stk-log-item .d.neg{color:#f97316}
.stk-log-item .t{color:#64748b;font-family:'Share Tech Mono',monospace;font-size:.65rem;letter-spacing:1px}

.stk-empty{text-align:center;padding:50px 20px;color:#64748b;background:rgba(13,17,33,.5);border:1px dashed rgba(148,163,184,.15);border-radius:12px}

.stk-alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:.82rem}
.stk-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.stk-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
</style>

<?php if ($success): ?><div class="stk-alert ok"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="stk-alert ko"><i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($error); ?></div><?php endif; ?>

<div class="stk-summary">
    <div class="stk-stat"><div class="v"><?= (int) $totalItems; ?></div><div class="l">Articles</div></div>
    <div class="stk-stat c"><div class="v"><?= number_format($totalQty, 0, ',', ' '); ?></div><div class="l">Quantité totale</div></div>
    <div class="stk-stat"><div class="v"><?= count($byCategory); ?></div><div class="l">Catégories</div></div>
</div>

<div class="stk-grid">
    <div>
        <?php if (!$items): ?>
            <div class="stk-empty"><i class="fa-solid fa-boxes-stacked" style="font-size:2.5rem;opacity:.3;margin-bottom:10px;display:block"></i><p>Stock vide.<?= $dnIsLeader ? ' Ajoute un article →' : ''; ?></p></div>
        <?php else: ?>
            <table class="stk-table">
                <thead><tr><th>Article</th><th>Cat.</th><th style="text-align:right">Qté</th><th style="text-align:right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td>
                            <div style="color:#fff;font-weight:600"><?= htmlspecialchars($i['name']); ?></div>
                            <?php if ($i['notes']): ?><div style="font-size:.72rem;color:#64748b;margin-top:2px"><?= htmlspecialchars($i['notes']); ?></div><?php endif; ?>
                        </td>
                        <td><?php if ($i['category']): ?><span class="stk-cat"><?= htmlspecialchars($i['category']); ?></span><?php endif; ?></td>
                        <td style="text-align:right">
                            <span class="stk-qty <?= (int) $i['quantity'] === 0 ? 'zero' : ''; ?>"><?= (int) $i['quantity']; ?></span>
                            <span style="color:#64748b;font-size:.7rem"><?= htmlspecialchars($i['unit'] ?? ''); ?></span>
                        </td>
                        <td>
                            <div class="stk-actions">
                                <?php if ((int) $i['quantity'] > 0): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                                    <input type="hidden" name="action" value="take">
                                    <input type="hidden" name="id" value="<?= (int) $i['id']; ?>">
                                    <input type="hidden" name="qty" value="1">
                                    <button type="submit" class="dn-btn cyan" title="Prendre 1"><i class="fa-solid fa-hand-grab"></i></button>
                                </form>
                                <?php endif; ?>
                                <?php if ($dnIsLeader): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                                    <input type="hidden" name="action" value="adjust">
                                    <input type="hidden" name="id" value="<?= (int) $i['id']; ?>">
                                    <input type="hidden" name="delta" value="10">
                                    <button type="submit" class="dn-btn" title="+10"><i class="fa-solid fa-plus"></i></button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cet article ?')">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $i['id']; ?>">
                                    <button type="submit" class="dn-btn danger" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <aside class="stk-side">
        <?php if ($dnIsLeader): ?>
        <div class="stk-card">
            <h3><i class="fa-solid fa-plus" style="color:#22d3ee"></i> Nouvel article</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="add_item">
                <input type="text" name="name" placeholder="Nom de l'article" required>
                <div class="stk-row2">
                    <input type="text" name="category" placeholder="Catégorie">
                    <input type="text" name="unit" placeholder="Unité" value="unité">
                </div>
                <input type="number" name="quantity" placeholder="Quantité initiale" min="0" value="0">
                <textarea name="notes" placeholder="Notes (optionnel)" rows="2"></textarea>
                <button type="submit" class="pr"><i class="fa-solid fa-bolt"></i> Ajouter</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="stk-card">
            <h3><i class="fa-solid fa-clock-rotate-left" style="color:#c4b5fd"></i> Activité récente</h3>
            <?php if (!$recent): ?>
                <p style="color:#64748b;font-size:.8rem;text-align:center;padding:15px 0">Aucune activité.</p>
            <?php else: ?>
                <div class="stk-log">
                    <?php foreach ($recent as $r):
                        $uname = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
                        if ($uname === '') { $uname = $r['username'] ?? 'Anonyme'; }
                        $dt = (int) $r['qty_delta'];
                    ?>
                        <div class="stk-log-item">
                            <b><?= htmlspecialchars($uname); ?></b>
                            <?php if ($r['action'] === 'take'): ?>a pris<?php elseif ($r['action'] === 'add'): ?>a ajouté<?php elseif ($r['action'] === 'adjust'): ?>a ajusté<?php else: ?><?= htmlspecialchars($r['action']); ?><?php endif; ?>
                            <span class="d <?= $dt < 0 ? 'neg' : ''; ?>"><?= $dt > 0 ? '+' : ''; ?><?= $dt; ?></span>
                            <b><?= htmlspecialchars($r['item_name'] ?? 'article supprimé'); ?></b>
                            <div class="t"><?= htmlspecialchars(date('d/m H:i', strtotime($r['created_at']))); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
