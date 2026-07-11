<?php
/**
 * DarkNet — Bank (privé / faction).
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Banque';
$success = '';
$error = '';

$launderFeePct = 15; // 15% de perte lors du blanchiment

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        $amount = (int) ($_POST['amount'] ?? 0);
        $notes  = trim((string) ($_POST['notes'] ?? '')) ?: null;
        try {
            if ($amount < 1 && $action !== 'adjust') { throw new RuntimeException('Montant invalide.'); }
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT clean_balance, dirty_balance FROM darknet_bank WHERE faction_key = ? FOR UPDATE");
                $st->execute([$dnFaction]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['clean_balance' => 0, 'dirty_balance' => 0];
                $clean = (int) $row['clean_balance'];
                $dirty = (int) $row['dirty_balance'];

                if ($action === 'deposit') {
                    $currency = ($_POST['currency'] ?? 'dirty') === 'clean' ? 'clean' : 'dirty';
                    if ($currency === 'clean') $clean += $amount; else $dirty += $amount;
                    $db->prepare("UPDATE darknet_bank SET clean_balance = ?, dirty_balance = ? WHERE faction_key = ?")->execute([$clean, $dirty, $dnFaction]);
                    $db->prepare("INSERT INTO darknet_bank_tx (faction_key, user_id, currency, type, amount, notes) VALUES (?, ?, ?, 'deposit', ?, ?)")
                       ->execute([$dnFaction, $dnUserId, $currency, $amount, $notes]);
                    $success = "Dépôt de $amount $ ($currency) enregistré.";
                } elseif ($action === 'withdraw') {
                    if (!$dnIsLeader) { throw new RuntimeException('Retrait réservé aux chefs.'); }
                    $currency = ($_POST['currency'] ?? 'clean') === 'dirty' ? 'dirty' : 'clean';
                    $bal = $currency === 'clean' ? $clean : $dirty;
                    if ($amount > $bal) { throw new RuntimeException('Solde insuffisant.'); }
                    if ($currency === 'clean') $clean -= $amount; else $dirty -= $amount;
                    $db->prepare("UPDATE darknet_bank SET clean_balance = ?, dirty_balance = ? WHERE faction_key = ?")->execute([$clean, $dirty, $dnFaction]);
                    $db->prepare("INSERT INTO darknet_bank_tx (faction_key, user_id, currency, type, amount, notes) VALUES (?, ?, ?, 'withdraw', ?, ?)")
                       ->execute([$dnFaction, $dnUserId, $currency, -$amount, $notes]);
                    $success = "Retrait de $amount $ ($currency) enregistré.";
                } elseif ($action === 'launder') {
                    if (!$dnIsLeader) { throw new RuntimeException('Blanchiment réservé aux chefs.'); }
                    if ($amount > $dirty) { throw new RuntimeException('Dirty insuffisant.'); }
                    $fee = (int) floor($amount * $launderFeePct / 100);
                    $net = $amount - $fee;
                    $dirty -= $amount;
                    $clean += $net;
                    $db->prepare("UPDATE darknet_bank SET clean_balance = ?, dirty_balance = ? WHERE faction_key = ?")->execute([$clean, $dirty, $dnFaction]);
                    $db->prepare("INSERT INTO darknet_bank_tx (faction_key, user_id, currency, type, amount, notes) VALUES (?, ?, 'dirty', 'launder', ?, ?)")
                       ->execute([$dnFaction, $dnUserId, -$amount, sprintf('→ +%d clean (fee %d %%: -%d)', $net, $launderFeePct, $fee)]);
                    $db->prepare("INSERT INTO darknet_bank_tx (faction_key, user_id, currency, type, amount, notes) VALUES (?, ?, 'clean', 'launder', ?, ?)")
                       ->execute([$dnFaction, $dnUserId, $net, "Blanchiment depuis dirty ($amount brut)"]);
                    $success = "Blanchiment : -$amount dirty → +$net clean (fee $fee $).";
                } elseif ($action === 'adjust') {
                    if (!$dnIsLeader) { throw new RuntimeException('Ajustement réservé aux chefs.'); }
                    $currency = ($_POST['currency'] ?? 'clean') === 'dirty' ? 'dirty' : 'clean';
                    if ($currency === 'clean') $clean = max(0, $clean + $amount); else $dirty = max(0, $dirty + $amount);
                    $db->prepare("UPDATE darknet_bank SET clean_balance = ?, dirty_balance = ? WHERE faction_key = ?")->execute([$clean, $dirty, $dnFaction]);
                    $db->prepare("INSERT INTO darknet_bank_tx (faction_key, user_id, currency, type, amount, notes) VALUES (?, ?, ?, 'adjust', ?, ?)")
                       ->execute([$dnFaction, $dnUserId, $currency, $amount, $notes]);
                    $success = 'Ajustement enregistré.';
                } else {
                    throw new RuntimeException('Action inconnue.');
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
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

// Données
$st = $db->prepare("SELECT clean_balance, dirty_balance FROM darknet_bank WHERE faction_key = ?");
$st->execute([$dnFaction]);
$bank = $st->fetch(PDO::FETCH_ASSOC) ?: ['clean_balance' => 0, 'dirty_balance' => 0];

$tx = $db->prepare("
    SELECT t.*, p.firstname, p.lastname, u.username
    FROM darknet_bank_tx t
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE t.faction_key = ? ORDER BY t.created_at DESC LIMIT 30
");
$tx->execute([$dnFaction]);
$tx = $tx->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>
<style>
.bnk-balances{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px}
.bnk-bal{padding:26px 28px;border-radius:16px;position:relative;overflow:hidden}
.bnk-bal.clean{background:linear-gradient(135deg,rgba(34,197,94,.1),rgba(13,17,33,.85));border:1px solid rgba(34,197,94,.35)}
.bnk-bal.dirty{background:linear-gradient(135deg,rgba(239,68,68,.1),rgba(13,17,33,.85));border:1px solid rgba(239,68,68,.35)}
.bnk-bal .lbl{font-family:'Share Tech Mono',monospace;font-size:.7rem;letter-spacing:2px;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.bnk-bal.clean .lbl{color:#86efac}
.bnk-bal.dirty .lbl{color:#fca5a5}
.bnk-bal .amt{font-family:'JetBrains Mono',monospace;font-size:2.2rem;font-weight:700;color:#fff;letter-spacing:-1px}
.bnk-bal .cur{font-family:'Share Tech Mono',monospace;font-size:.7rem;color:#94a3b8;letter-spacing:1.5px}
.bnk-bal .deco{position:absolute;right:-20px;top:-20px;font-size:8rem;opacity:.04}

.bnk-grid{display:grid;grid-template-columns:1fr 340px;gap:22px;align-items:start}
@media(max-width:1100px){.bnk-grid{grid-template-columns:1fr}}

.bnk-card{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:18px 20px;margin-bottom:14px}
.bnk-card h3{font-family:'Syne',sans-serif;font-size:.82rem;letter-spacing:2px;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:8px;color:#e2e8f0}
.bnk-card label{display:block;font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px;margin-top:10px}
.bnk-card input,.bnk-card select{width:100%;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:9px 12px;border-radius:8px;font-family:'JetBrains Mono',monospace;font-size:.88rem}
.bnk-card input:focus,.bnk-card select:focus{outline:none;border-color:#a78bfa;box-shadow:0 0 0 3px rgba(167,139,250,.15)}
.bnk-card button{width:100%;padding:10px;border:none;border-radius:8px;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;font-size:.75rem;color:#fff;margin-top:12px}
.bnk-b-deposit{background:linear-gradient(135deg,#10b981,#22d3ee)}
.bnk-b-withdraw{background:linear-gradient(135deg,#7c3aed,#3b82f6)}
.bnk-b-launder{background:linear-gradient(135deg,#ef4444,#f59e0b)}
.bnk-b-adjust{background:linear-gradient(135deg,#64748b,#475569)}

.bnk-log{max-height:520px;overflow-y:auto}
.bnk-tx{display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-bottom:1px solid rgba(148,163,184,.06);font-size:.78rem}
.bnk-tx:last-child{border-bottom:none}
.bnk-tx .who{color:#cbd5e1;font-weight:600}
.bnk-tx .what{color:#94a3b8;font-size:.72rem;margin-top:2px}
.bnk-tx .t{color:#64748b;font-family:'Share Tech Mono',monospace;font-size:.62rem;letter-spacing:1px}
.bnk-tx .amt{font-family:'JetBrains Mono',monospace;font-weight:700;font-size:.92rem}
.bnk-tx .amt.pos{color:#22d3ee}
.bnk-tx .amt.neg{color:#f97316}
.bnk-tx .cur{display:inline-block;font-size:.55rem;padding:2px 6px;border-radius:3px;margin-left:5px;letter-spacing:1px;text-transform:uppercase}
.bnk-tx .cur.clean{background:rgba(34,197,94,.18);color:#86efac}
.bnk-tx .cur.dirty{background:rgba(239,68,68,.18);color:#fca5a5}

.bnk-alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:.82rem}
.bnk-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.bnk-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
</style>

<?php if ($success): ?><div class="bnk-alert ok"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="bnk-alert ko"><i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($error); ?></div><?php endif; ?>

<div class="bnk-balances">
    <div class="bnk-bal clean">
        <div class="lbl"><i class="fa-solid fa-wallet"></i> Fonds propres</div>
        <div class="amt"><?= number_format((int) $bank['clean_balance'], 0, ',', ' '); ?> <span style="color:#86efac;font-size:1.1rem">$</span></div>
        <div class="cur">CLEAN · Utilisable directement</div>
        <i class="fa-solid fa-dollar-sign deco"></i>
    </div>
    <div class="bnk-bal dirty">
        <div class="lbl"><i class="fa-solid fa-mask"></i> Fonds illicites</div>
        <div class="amt"><?= number_format((int) $bank['dirty_balance'], 0, ',', ' '); ?> <span style="color:#fca5a5;font-size:1.1rem">$</span></div>
        <div class="cur">DIRTY · À blanchir (fee <?= $launderFeePct; ?>%)</div>
        <i class="fa-solid fa-skull deco"></i>
    </div>
</div>

<div class="bnk-grid">
    <div>
        <div class="bnk-card">
            <h3><i class="fa-solid fa-clock-rotate-left" style="color:#c4b5fd"></i> Journal des transactions</h3>
            <?php if (!$tx): ?>
                <p style="color:#64748b;text-align:center;padding:30px 0">Aucune transaction.</p>
            <?php else: ?>
                <div class="bnk-log">
                    <?php foreach ($tx as $t):
                        $uname = trim(($t['firstname'] ?? '') . ' ' . ($t['lastname'] ?? ''));
                        if ($uname === '') { $uname = $t['username'] ?? 'Anonyme'; }
                        $amt = (int) $t['amount'];
                        $typeLabel = ['deposit'=>'Dépôt','withdraw'=>'Retrait','launder'=>'Blanchiment','transfer'=>'Transfert','adjust'=>'Ajustement'][$t['type']] ?? $t['type'];
                    ?>
                        <div class="bnk-tx">
                            <div style="flex:1;min-width:0">
                                <div class="who"><?= htmlspecialchars($uname); ?> — <?= htmlspecialchars($typeLabel); ?></div>
                                <?php if ($t['notes']): ?><div class="what"><?= htmlspecialchars($t['notes']); ?></div><?php endif; ?>
                                <div class="t"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($t['created_at']))); ?></div>
                            </div>
                            <div style="text-align:right">
                                <span class="amt <?= $amt >= 0 ? 'pos' : 'neg'; ?>"><?= $amt > 0 ? '+' : ''; ?><?= number_format($amt, 0, ',', ' '); ?> $</span>
                                <span class="cur <?= htmlspecialchars($t['currency']); ?>"><?= htmlspecialchars($t['currency']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <aside style="position:sticky;top:92px">
        <!-- Dépôt (tout le monde) -->
        <div class="bnk-card">
            <h3><i class="fa-solid fa-arrow-down" style="color:#10b981"></i> Déposer</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="deposit">
                <label>Type</label>
                <select name="currency">
                    <option value="clean">Clean ($)</option>
                    <option value="dirty" selected>Dirty ($ illicite)</option>
                </select>
                <label>Montant</label>
                <input type="number" name="amount" min="1" placeholder="0" required>
                <label>Notes</label>
                <input type="text" name="notes" maxlength="250" placeholder="(optionnel)">
                <button type="submit" class="bnk-b-deposit">Déposer</button>
            </form>
        </div>

        <?php if ($dnIsLeader): ?>
        <!-- Retrait -->
        <div class="bnk-card">
            <h3><i class="fa-solid fa-arrow-up" style="color:#7c3aed"></i> Retirer</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="withdraw">
                <label>Type</label>
                <select name="currency">
                    <option value="clean">Clean ($)</option>
                    <option value="dirty">Dirty ($)</option>
                </select>
                <label>Montant</label>
                <input type="number" name="amount" min="1" placeholder="0" required>
                <label>Notes</label>
                <input type="text" name="notes" maxlength="250" placeholder="Motif">
                <button type="submit" class="bnk-b-withdraw">Retirer</button>
            </form>
        </div>

        <!-- Blanchir -->
        <div class="bnk-card">
            <h3><i class="fa-solid fa-soap" style="color:#ef4444"></i> Blanchir (fee <?= $launderFeePct; ?>%)</h3>
            <form method="post" onsubmit="return confirm('Confirmer le blanchiment ?')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="launder">
                <label>Montant dirty à convertir</label>
                <input type="number" name="amount" min="1" placeholder="0" required>
                <button type="submit" class="bnk-b-launder">Blanchir</button>
            </form>
        </div>

        <!-- Ajuster -->
        <div class="bnk-card">
            <h3><i class="fa-solid fa-wrench" style="color:#64748b"></i> Ajuster (admin)</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="adjust">
                <label>Type</label>
                <select name="currency">
                    <option value="clean">Clean</option>
                    <option value="dirty">Dirty</option>
                </select>
                <label>Delta (positif ou négatif)</label>
                <input type="number" name="amount" placeholder="+ ou -" required>
                <label>Motif</label>
                <input type="text" name="notes" maxlength="250">
                <button type="submit" class="bnk-b-adjust">Ajuster</button>
            </form>
        </div>
        <?php endif; ?>
    </aside>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
