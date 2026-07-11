<?php
/**
 * DarkNet — Le Réseau : liste les factions illégales actives + comptes leader + stats.
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Le Réseau';

$keys = DARKNET_ALLOWED_FACTION_KEYS;
$placeholders = implode(',', array_fill(0, count($keys), '?'));

$factions = [];
try {
    $st = $db->prepare("SELECT faction_key, name FROM factions WHERE faction_key IN ($placeholders) ORDER BY name ASC");
    $st->execute($keys);
    $factions = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$leadersByFac = [];
$membersByFac = [];
$bankByFac    = [];
try {
    $st = $db->prepare("SELECT faction_key, COUNT(*) AS n FROM darknet_illegal_accounts WHERE is_active = 1 AND faction_key IN ($placeholders) GROUP BY faction_key");
    $st->execute($keys);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $leadersByFac[$r['faction_key']] = (int) $r['n']; }

    $st = $db->prepare("SELECT faction_key, COUNT(*) AS n FROM darknet_subcodes WHERE is_active = 1 AND faction_key IN ($placeholders) GROUP BY faction_key");
    $st->execute($keys);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $membersByFac[$r['faction_key']] = (int) $r['n']; }

    $st = $db->prepare("SELECT faction_key, clean_balance, dirty_balance FROM darknet_bank WHERE faction_key IN ($placeholders)");
    $st->execute($keys);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $bankByFac[$r['faction_key']] = $r; }
} catch (Throwable $e) {}

$totalLeaders = array_sum($leadersByFac);
$totalMembers = array_sum($membersByFac);

include __DIR__ . '/../includes/header.php';
?>
<style>
.rs-hero{background:linear-gradient(135deg,rgba(34,211,238,.08),rgba(167,139,250,.08),rgba(13,17,33,.9));border:1px solid rgba(167,139,250,.3);border-radius:14px;padding:22px 26px;margin-bottom:22px;position:relative;overflow:hidden}
.rs-hero h1{font-family:'Syne',sans-serif;font-size:1.4rem;color:#fff;letter-spacing:3px;text-transform:uppercase;display:flex;align-items:center;gap:10px}
.rs-hero .sub{color:#94a3b8;font-size:.85rem;margin-top:5px}
.rs-stats{display:flex;gap:22px;margin-top:14px}
.rs-stats .s .v{font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#fff}
.rs-stats .s .l{font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase}

.rs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.rs-card{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.12);border-radius:14px;padding:20px 22px;position:relative;overflow:hidden;transition:all .2s}
.rs-card:hover{transform:translateY(-2px);border-color:rgba(167,139,250,.35)}
.rs-card h3{font-family:'Syne',sans-serif;color:#fff;font-size:1.15rem;letter-spacing:2px;text-transform:uppercase;margin-bottom:6px}
.rs-card .key{font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#94a3b8;letter-spacing:1.5px;margin-bottom:14px}
.rs-card .row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(148,163,184,.06);font-size:.82rem}
.rs-card .row:last-child{border-bottom:none}
.rs-card .row .k{color:#94a3b8;font-family:'Share Tech Mono',monospace;font-size:.65rem;letter-spacing:1px;text-transform:uppercase}
.rs-card .row .v{font-family:'JetBrains Mono',monospace;font-weight:700;color:#fff}
.rs-card .row .v.c{color:#86efac}
.rs-card .row .v.d{color:#fca5a5}
.rs-card .deco{position:absolute;right:-20px;top:-20px;font-size:6rem;opacity:.05;color:#a78bfa}
</style>

<div class="rs-hero">
    <h1><i class="fa-solid fa-diagram-project" style="color:#c4b5fd"></i> Le Réseau</h1>
    <div class="sub">Cartographie des factions illégales opérant sur le DarkNet</div>
    <div class="rs-stats">
        <div class="s"><div class="v"><?= count($factions); ?></div><div class="l">Factions actives</div></div>
        <div class="s"><div class="v"><?= $totalLeaders; ?></div><div class="l">Chefs</div></div>
        <div class="s"><div class="v"><?= $totalMembers; ?></div><div class="l">Membres</div></div>
    </div>
</div>

<div class="rs-grid">
    <?php foreach ($factions as $f):
        $b = $bankByFac[$f['faction_key']] ?? null;
    ?>
        <div class="rs-card">
            <h3><?= htmlspecialchars($f['name']); ?></h3>
            <div class="key">[ <?= htmlspecialchars($f['faction_key']); ?> ]</div>
            <div class="row"><span class="k">Chefs</span><span class="v"><?= $leadersByFac[$f['faction_key']] ?? 0; ?></span></div>
            <div class="row"><span class="k">Membres</span><span class="v"><?= $membersByFac[$f['faction_key']] ?? 0; ?></span></div>
            <?php if ($b && $dnIsStaff): ?>
                <div class="row"><span class="k">Clean $</span><span class="v c"><?= number_format((int) $b['clean_balance'], 0, ',', ' '); ?></span></div>
                <div class="row"><span class="k">Dirty $</span><span class="v d"><?= number_format((int) $b['dirty_balance'], 0, ',', ' '); ?></span></div>
            <?php endif; ?>
            <i class="fa-solid fa-skull-crossbones deco"></i>
        </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
