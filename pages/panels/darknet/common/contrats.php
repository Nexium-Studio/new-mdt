<?php
/**
 * DarkNet — Contrats & Hitman (commun).
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Contrats';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'post') {
                if (!$dnIsLeader) { throw new RuntimeException('Publication réservée aux chefs.'); }
                $title = trim((string) ($_POST['title'] ?? ''));
                $target = trim((string) ($_POST['target_name'] ?? ''));
                $reward = max(0, (int) ($_POST['reward'] ?? 0));
                if ($title === '' || $target === '') { throw new RuntimeException('Titre et cible requis.'); }
                $db->prepare("INSERT INTO darknet_contracts (title, target_name, description, reward, currency, posted_by_faction, posted_by_user) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([
                       $title, $target,
                       trim((string) ($_POST['description'] ?? '')) ?: null,
                       $reward,
                       ($_POST['currency'] ?? 'dirty') === 'clean' ? 'clean' : 'dirty',
                       $dnFaction, $dnUserId,
                   ]);
                $success = 'Contrat publié.';
            } elseif ($action === 'accept') {
                $id = (int) ($_POST['id'] ?? 0);
                $db->prepare("UPDATE darknet_contracts SET status = 'accepted', accepted_by_user = ?, accepted_at = NOW() WHERE id = ? AND status = 'open'")
                   ->execute([$dnUserId, $id]);
                $success = 'Contrat accepté — tu as 24h pour fournir une preuve.';
            } elseif ($action === 'submit_proof') {
                $id = (int) ($_POST['id'] ?? 0);
                $url = trim((string) ($_POST['proof_url'] ?? ''));
                $note = trim((string) ($_POST['proof_note'] ?? '')) ?: null;
                if ($url === '') { throw new RuntimeException('URL de preuve requise.'); }
                $db->prepare("UPDATE darknet_contracts SET status = 'proof', proof_url = ?, proof_note = ? WHERE id = ? AND accepted_by_user = ?")
                   ->execute([$url, $note, $id, $dnUserId]);
                $success = 'Preuve soumise — en attente de validation.';
            } elseif ($action === 'validate') {
                // Validation par staff OU par le leader commanditaire
                $id = (int) ($_POST['id'] ?? 0);
                $st = $db->prepare("SELECT * FROM darknet_contracts WHERE id = ?");
                $st->execute([$id]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if (!$c) { throw new RuntimeException('Contrat introuvable.'); }
                if (!$dnIsStaff && !($dnIsLeader && $c['posted_by_faction'] === $dnFaction)) {
                    throw new RuntimeException('Seul le commanditaire ou un staff peut valider.');
                }
                if ($c['status'] !== 'proof') { throw new RuntimeException('Contrat pas en attente de preuve.'); }
                // Paiement automatique : débit banque du commanditaire → historique pour le hitman
                $db->beginTransaction();
                try {
                    $bs = $db->prepare("SELECT clean_balance, dirty_balance FROM darknet_bank WHERE faction_key = ? FOR UPDATE");
                    $bs->execute([$c['posted_by_faction']]);
                    $bank = $bs->fetch(PDO::FETCH_ASSOC) ?: ['clean_balance'=>0,'dirty_balance'=>0];
                    $col = $c['currency'] === 'clean' ? 'clean_balance' : 'dirty_balance';
                    if ((int) $bank[$col] < (int) $c['reward']) {
                        throw new RuntimeException('Fonds ' . $c['currency'] . ' insuffisants chez le commanditaire.');
                    }
                    $db->prepare("UPDATE darknet_bank SET $col = $col - ? WHERE faction_key = ?")
                       ->execute([(int) $c['reward'], $c['posted_by_faction']]);
                    $db->prepare("INSERT INTO darknet_bank_tx (faction_key, user_id, currency, type, amount, notes) VALUES (?, ?, ?, 'withdraw', ?, ?)")
                       ->execute([$c['posted_by_faction'], $dnUserId, $c['currency'], -(int) $c['reward'], 'Contrat : ' . $c['title']]);
                    $db->prepare("UPDATE darknet_contracts SET status = 'completed', completed_at = NOW() WHERE id = ?")
                       ->execute([$id]);
                    $db->commit();
                    $success = 'Contrat validé — paiement effectué.';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } elseif ($action === 'cancel') {
                $id = (int) ($_POST['id'] ?? 0);
                $st = $db->prepare("SELECT posted_by_faction FROM darknet_contracts WHERE id = ?");
                $st->execute([$id]);
                if (!$dnIsStaff && $st->fetchColumn() !== $dnFaction) {
                    throw new RuntimeException('Non autorisé.');
                }
                $db->prepare("UPDATE darknet_contracts SET status = 'canceled' WHERE id = ?")->execute([$id]);
                $success = 'Contrat annulé.';
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

$filter = $_GET['f'] ?? 'open';
$valid = ['open','accepted','proof','completed','canceled','all'];
if (!in_array($filter, $valid, true)) { $filter = 'open'; }

if ($filter === 'all') {
    $sql = "SELECT c.*, f.name AS faction_name, p.firstname, p.lastname, u.username,
                   pa.firstname AS hit_firstname, pa.lastname AS hit_lastname, ua.username AS hit_username
            FROM darknet_contracts c
            LEFT JOIN factions f ON f.faction_key = c.posted_by_faction
            LEFT JOIN users u  ON u.id = c.posted_by_user
            LEFT JOIN profiles p ON p.id = u.profile_id
            LEFT JOIN users ua ON ua.id = c.accepted_by_user
            LEFT JOIN profiles pa ON pa.id = ua.profile_id
            ORDER BY c.status = 'open' DESC, c.created_at DESC LIMIT 50";
    $contracts = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $db->prepare("SELECT c.*, f.name AS faction_name, p.firstname, p.lastname, u.username,
                              pa.firstname AS hit_firstname, pa.lastname AS hit_lastname, ua.username AS hit_username
                       FROM darknet_contracts c
                       LEFT JOIN factions f ON f.faction_key = c.posted_by_faction
                       LEFT JOIN users u  ON u.id = c.posted_by_user
                       LEFT JOIN profiles p ON p.id = u.profile_id
                       LEFT JOIN users ua ON ua.id = c.accepted_by_user
                       LEFT JOIN profiles pa ON pa.id = ua.profile_id
                       WHERE c.status = ? ORDER BY c.created_at DESC LIMIT 50");
    $st->execute([$filter]);
    $contracts = $st->fetchAll(PDO::FETCH_ASSOC);
}

$counts = [];
foreach ($db->query("SELECT status, COUNT(*) AS n FROM darknet_contracts GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}

include __DIR__ . '/../includes/header.php';
?>
<style>
.ctr-filters{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.ctr-filter{padding:8px 14px;border-radius:8px;background:rgba(13,17,33,.7);border:1px solid rgba(148,163,184,.18);color:#94a3b8;text-decoration:none;font-family:'Share Tech Mono',monospace;font-size:.72rem;letter-spacing:1px;text-transform:uppercase}
.ctr-filter.active{background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.4);color:#fca5a5}
.ctr-filter .n{margin-left:6px;opacity:.7}

.ctr-grid{display:grid;grid-template-columns:1fr 340px;gap:22px;align-items:start}
@media(max-width:1100px){.ctr-grid{grid-template-columns:1fr}}

.ctr-card{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:18px 22px;margin-bottom:12px;position:relative}
.ctr-card.s-open{border-left:3px solid #fbbf24}
.ctr-card.s-accepted{border-left:3px solid #a78bfa}
.ctr-card.s-proof{border-left:3px solid #22d3ee}
.ctr-card.s-completed{border-left:3px solid #10b981;opacity:.7}
.ctr-card.s-canceled{border-left:3px solid #ef4444;opacity:.5}
.ctr-card h4{font-family:'Syne',sans-serif;font-size:1.05rem;color:#fff;display:flex;align-items:center;gap:10px;margin-bottom:5px}
.ctr-card .target{font-family:'JetBrains Mono',monospace;color:#fca5a5;font-weight:600;font-size:.85rem}
.ctr-card .meta{display:flex;gap:14px;font-size:.72rem;color:#94a3b8;margin-top:8px;flex-wrap:wrap}
.ctr-card .meta .k{color:#64748b;margin-right:3px;font-family:'Share Tech Mono',monospace;text-transform:uppercase;font-size:.62rem;letter-spacing:1px}
.ctr-card .desc{color:#cbd5e1;font-size:.82rem;line-height:1.55;margin-top:8px;padding:10px;background:rgba(0,0,0,.25);border-radius:6px}
.ctr-card .reward{display:inline-flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;color:#fff;font-weight:700;font-size:1.1rem;padding:6px 12px;background:linear-gradient(135deg,rgba(251,191,36,.18),rgba(239,68,68,.18));border:1px solid rgba(251,191,36,.3);border-radius:20px;margin-top:10px}
.ctr-card .status-chip{position:absolute;top:14px;right:14px;font-family:'Share Tech Mono',monospace;font-size:.6rem;padding:3px 8px;border-radius:4px;letter-spacing:1.5px;text-transform:uppercase;font-weight:700}
.ctr-card .status-chip.open{background:rgba(251,191,36,.18);color:#fbbf24}
.ctr-card .status-chip.accepted{background:rgba(167,139,250,.18);color:#c4b5fd}
.ctr-card .status-chip.proof{background:rgba(34,211,238,.18);color:#67e8f9}
.ctr-card .status-chip.completed{background:rgba(16,185,129,.18);color:#6ee7b7}
.ctr-card .status-chip.canceled{background:rgba(239,68,68,.18);color:#fca5a5}
.ctr-actions{display:flex;gap:7px;margin-top:13px;flex-wrap:wrap}
.ctr-actions form,.ctr-actions .proof{display:flex;gap:7px;align-items:center}
.ctr-actions input[type=text]{background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:7px 10px;border-radius:7px;font-size:.75rem;min-width:180px}

.ctr-side{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:16px 18px;position:sticky;top:92px}
.ctr-side h3{font-family:'Syne',sans-serif;font-size:.82rem;letter-spacing:2px;text-transform:uppercase;color:#e2e8f0;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.ctr-side input,.ctr-side select,.ctr-side textarea{width:100%;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:9px 12px;border-radius:8px;font-size:.82rem;margin-bottom:8px;font-family:'DM Sans',sans-serif}
.ctr-side button{width:100%;padding:10px;background:linear-gradient(135deg,#ef4444,#fbbf24);border:none;border-radius:8px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;font-size:.72rem;cursor:pointer;margin-top:4px}

.ctr-alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:.82rem}
.ctr-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.ctr-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
</style>

<?php if ($success): ?><div class="ctr-alert ok"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="ctr-alert ko"><i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($error); ?></div><?php endif; ?>

<div class="ctr-filters">
    <?php foreach (['open' => 'Ouverts', 'accepted' => 'Acceptés', 'proof' => 'En preuve', 'completed' => 'Complétés', 'canceled' => 'Annulés', 'all' => 'Tous'] as $k => $lbl): ?>
        <a href="?f=<?= $k; ?>" class="ctr-filter <?= $filter === $k ? 'active' : ''; ?>"><?= $lbl; ?><?php if ($k !== 'all'): ?><span class="n">(<?= $counts[$k] ?? 0; ?>)</span><?php endif; ?></a>
    <?php endforeach; ?>
</div>

<div class="ctr-grid">
    <div>
        <?php if (!$contracts): ?>
            <div style="text-align:center;padding:60px 20px;color:#64748b;background:rgba(13,17,33,.5);border:1px dashed rgba(148,163,184,.15);border-radius:12px">
                <i class="fa-solid fa-crosshairs" style="font-size:2.5rem;opacity:.3;margin-bottom:10px;display:block"></i>
                <p>Aucun contrat.</p>
            </div>
        <?php else: foreach ($contracts as $c):
            $postedBy = trim(($c['firstname'] ?? '') . ' ' . ($c['lastname'] ?? ''));
            if ($postedBy === '') { $postedBy = $c['username'] ?? '—'; }
            $hitBy = trim(($c['hit_firstname'] ?? '') . ' ' . ($c['hit_lastname'] ?? ''));
            if ($hitBy === '' && !empty($c['accepted_by_user'])) { $hitBy = $c['hit_username'] ?? '—'; }
        ?>
            <div class="ctr-card s-<?= htmlspecialchars($c['status']); ?>">
                <span class="status-chip <?= htmlspecialchars($c['status']); ?>"><?= htmlspecialchars($c['status']); ?></span>
                <h4><i class="fa-solid fa-crosshairs" style="color:#fca5a5"></i> <?= htmlspecialchars($c['title']); ?></h4>
                <div class="target">Cible : <?= htmlspecialchars($c['target_name']); ?></div>
                <?php if ($c['description']): ?><div class="desc"><?= nl2br(htmlspecialchars($c['description'])); ?></div><?php endif; ?>
                <div class="meta">
                    <span><span class="k">Poste par</span><?= htmlspecialchars($postedBy); ?> (<?= htmlspecialchars($c['faction_name'] ?? $c['posted_by_faction']); ?>)</span>
                    <?php if ($hitBy): ?><span><span class="k">Hitman</span><?= htmlspecialchars($hitBy); ?></span><?php endif; ?>
                    <span><span class="k">Créé</span><?= htmlspecialchars(date('d/m/Y H:i', strtotime($c['created_at']))); ?></span>
                </div>
                <div class="reward"><i class="fa-solid fa-sack-dollar"></i> <?= number_format((int) $c['reward'], 0, ',', ' '); ?> $ <?= htmlspecialchars($c['currency']); ?></div>

                <?php if ($c['proof_url']): ?>
                    <div style="margin-top:10px;font-size:.78rem;color:#94a3b8">
                        <b style="color:#67e8f9">Preuve :</b> <a href="<?= htmlspecialchars($c['proof_url']); ?>" target="_blank" rel="noopener" style="color:#22d3ee;text-decoration:underline"><?= htmlspecialchars(substr($c['proof_url'], 0, 60)); ?>…</a>
                        <?php if ($c['proof_note']): ?><div style="color:#cbd5e1;margin-top:4px"><?= nl2br(htmlspecialchars($c['proof_note'])); ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="ctr-actions">
                    <?php if ($c['status'] === 'open' && (int) $c['posted_by_user'] !== $dnUserId): ?>
                        <form method="post" onsubmit="return confirm('Accepter ce contrat ?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="accept">
                            <input type="hidden" name="id" value="<?= (int) $c['id']; ?>">
                            <button type="submit" class="dn-btn cyan"><i class="fa-solid fa-handshake"></i> Accepter</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($c['status'] === 'accepted' && (int) $c['accepted_by_user'] === $dnUserId): ?>
                        <form method="post" class="proof" onsubmit="return confirm('Soumettre cette preuve ?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="submit_proof">
                            <input type="hidden" name="id" value="<?= (int) $c['id']; ?>">
                            <input type="text" name="proof_url" placeholder="URL image / vidéo de preuve" required>
                            <input type="text" name="proof_note" placeholder="Note (optionnel)">
                            <button type="submit" class="dn-btn cyan"><i class="fa-solid fa-camera"></i> Soumettre preuve</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($c['status'] === 'proof' && ($dnIsStaff || ($dnIsLeader && $c['posted_by_faction'] === $dnFaction))): ?>
                        <form method="post" onsubmit="return confirm('Valider et payer ?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="validate">
                            <input type="hidden" name="id" value="<?= (int) $c['id']; ?>">
                            <button type="submit" class="dn-btn" style="background:rgba(16,185,129,.18);color:#6ee7b7;border-color:rgba(16,185,129,.35)"><i class="fa-solid fa-circle-check"></i> Valider & Payer</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($c['status'], ['open','accepted','proof'], true) && ($dnIsStaff || ($dnIsLeader && $c['posted_by_faction'] === $dnFaction))): ?>
                        <form method="post" onsubmit="return confirm('Annuler le contrat ?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="id" value="<?= (int) $c['id']; ?>">
                            <button type="submit" class="dn-btn danger"><i class="fa-solid fa-ban"></i> Annuler</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <aside>
        <?php if ($dnIsLeader): ?>
        <div class="ctr-side">
            <h3><i class="fa-solid fa-plus" style="color:#ef4444"></i> Publier un contrat</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="post">
                <input type="text" name="title" placeholder="Titre du contrat" required>
                <input type="text" name="target_name" placeholder="Nom de la cible" required>
                <textarea name="description" rows="3" placeholder="Description / conditions / délais"></textarea>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                    <input type="number" name="reward" placeholder="Récompense $" min="0" required>
                    <select name="currency"><option value="dirty">Dirty</option><option value="clean">Clean</option></select>
                </div>
                <button type="submit">Publier</button>
            </form>
        </div>
        <?php else: ?>
        <div class="ctr-side">
            <h3><i class="fa-solid fa-info-circle"></i> Mur des contrats</h3>
            <p style="color:#94a3b8;font-size:.8rem;line-height:1.6">
                Accepte un contrat et soumets une preuve pour obtenir la récompense.<br><br>
                <b style="color:#fbbf24">Seuls les leaders</b> peuvent publier des contrats. Paiement auto depuis la banque du commanditaire.
            </p>
        </div>
        <?php endif; ?>
    </aside>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
