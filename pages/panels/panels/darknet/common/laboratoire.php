<?php
/**
 * DarkNet — Laboratoire & R&D (commun).
 * Tracking activité police + notes de recherche.
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Laboratoire';
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'log') {
                $type = in_array($_POST['type'] ?? 'note', ['police_raid','arrest','patrol','research','note'], true) ? $_POST['type'] : 'note';
                $title = trim((string) ($_POST['title'] ?? ''));
                if ($title === '') { throw new RuntimeException('Titre requis.'); }
                $db->prepare("INSERT INTO darknet_lab_logs (type, title, details, faction_key, author_user) VALUES (?, ?, ?, ?, ?)")
                   ->execute([
                       $type, $title,
                       trim((string) ($_POST['details'] ?? '')) ?: null,
                       $dnFaction, $dnUserId,
                   ]);
                $success = 'Entrée enregistrée.';
            } elseif ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $st = $db->prepare("SELECT author_user FROM darknet_lab_logs WHERE id = ?");
                $st->execute([$id]);
                if (!$dnIsStaff && (int) $st->fetchColumn() !== $dnUserId) { throw new RuntimeException('Non autorisé.'); }
                $db->prepare("DELETE FROM darknet_lab_logs WHERE id = ?")->execute([$id]);
                $success = 'Supprimé.';
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

// Stats des 7 derniers jours par type (pour un mini-graph)
$stats7 = $db->query("SELECT DATE(logged_at) AS d, type, COUNT(*) AS n FROM darknet_lab_logs WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(logged_at), type ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);

$typeCounts = [];
foreach ($db->query("SELECT type, COUNT(*) AS n FROM darknet_lab_logs GROUP BY type")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $typeCounts[$r['type']] = (int) $r['n'];
}

$filter = in_array($_GET['t'] ?? '', ['police_raid','arrest','patrol','research','note'], true) ? $_GET['t'] : '';

if ($filter) {
    $st = $db->prepare("SELECT l.*, f.name AS faction_name, p.firstname, p.lastname, u.username FROM darknet_lab_logs l LEFT JOIN factions f ON f.faction_key = l.faction_key LEFT JOIN users u ON u.id = l.author_user LEFT JOIN profiles p ON p.id = u.profile_id WHERE l.type = ? ORDER BY l.logged_at DESC LIMIT 50");
    $st->execute([$filter]);
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    $logs = $db->query("SELECT l.*, f.name AS faction_name, p.firstname, p.lastname, u.username FROM darknet_lab_logs l LEFT JOIN factions f ON f.faction_key = l.faction_key LEFT JOIN users u ON u.id = l.author_user LEFT JOIN profiles p ON p.id = u.profile_id ORDER BY l.logged_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
}

// Sparkline : total par jour (7j)
$byDay = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $byDay[$d] = 0;
}
foreach ($stats7 as $r) { $byDay[$r['d']] = ($byDay[$r['d']] ?? 0) + (int) $r['n']; }
$maxDay = max($byDay) ?: 1;

include __DIR__ . '/../includes/header.php';
?>
<style>
.lab-hero{background:linear-gradient(135deg,rgba(34,211,238,.08),rgba(13,17,33,.9));border:1px solid rgba(34,211,238,.3);border-radius:14px;padding:20px 24px;margin-bottom:20px}
.lab-hero h1{font-family:'Syne',sans-serif;font-size:1.3rem;color:#fff;letter-spacing:3px;text-transform:uppercase;display:flex;align-items:center;gap:10px}
.lab-hero .sub{color:#94a3b8;font-size:.82rem;margin-top:5px}

.lab-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px}
@media(max-width:900px){.lab-stats{grid-template-columns:repeat(3,1fr)}}
.lab-stat{background:rgba(13,17,33,.7);border:1px solid rgba(148,163,184,.1);border-radius:10px;padding:12px}
.lab-stat .v{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:700;color:#fff}
.lab-stat .l{font-family:'Share Tech Mono',monospace;font-size:.58rem;letter-spacing:1.2px;text-transform:uppercase;color:#94a3b8;margin-top:3px}
.lab-stat.police_raid{border-color:rgba(239,68,68,.3);background:linear-gradient(135deg,rgba(239,68,68,.06),rgba(13,17,33,.85))}
.lab-stat.arrest{border-color:rgba(251,191,36,.3)}
.lab-stat.patrol{border-color:rgba(34,211,238,.3)}
.lab-stat.research{border-color:rgba(167,139,250,.3)}

.lab-spark{background:rgba(13,17,33,.7);border:1px solid rgba(148,163,184,.1);border-radius:10px;padding:16px 18px;margin-bottom:18px}
.lab-spark h4{font-family:'Share Tech Mono',monospace;color:#94a3b8;font-size:.65rem;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:12px}
.lab-spark-bars{display:flex;align-items:flex-end;gap:4px;height:80px}
.lab-spark-bar{flex:1;background:linear-gradient(180deg,#22d3ee,#7c3aed);border-radius:3px 3px 0 0;position:relative;min-height:4px;transition:all .2s}
.lab-spark-bar:hover{opacity:.7}
.lab-spark-bar .v{position:absolute;top:-18px;left:50%;transform:translateX(-50%);font-family:'JetBrains Mono',monospace;font-size:.7rem;color:#cbd5e1}
.lab-spark-labels{display:flex;gap:4px;margin-top:6px}
.lab-spark-labels span{flex:1;text-align:center;font-family:'Share Tech Mono',monospace;font-size:.58rem;color:#64748b;letter-spacing:1px}

.lab-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.lab-tab{padding:7px 12px;border-radius:8px;background:rgba(13,17,33,.7);border:1px solid rgba(148,163,184,.18);color:#94a3b8;text-decoration:none;font-family:'Share Tech Mono',monospace;font-size:.68rem;letter-spacing:1.2px;text-transform:uppercase}
.lab-tab.active{background:rgba(34,211,238,.14);border-color:rgba(34,211,238,.4);color:#67e8f9}

.lab-grid{display:grid;grid-template-columns:1fr 340px;gap:22px;align-items:start}
@media(max-width:1100px){.lab-grid{grid-template-columns:1fr}}

.lab-card{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:10px;padding:14px 18px;margin-bottom:10px;display:flex;gap:12px}
.lab-card .tcol{width:38px;flex-shrink:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;border-right:1px dashed rgba(148,163,184,.1);padding-right:12px}
.lab-card .tcol .d{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:700;color:#fff;line-height:1}
.lab-card .tcol .m{font-family:'Share Tech Mono',monospace;font-size:.58rem;color:#94a3b8;letter-spacing:1px;text-transform:uppercase}
.lab-card .tcol .h{font-family:'JetBrains Mono',monospace;font-size:.7rem;color:#22d3ee;margin-top:3px}
.lab-card .ccol{flex:1;min-width:0}
.lab-card .chip{display:inline-block;padding:2px 7px;font-family:'Share Tech Mono',monospace;font-size:.58rem;border-radius:3px;letter-spacing:1.2px;text-transform:uppercase;font-weight:700;margin-right:6px}
.lab-card .chip.police_raid{background:rgba(239,68,68,.18);color:#fca5a5}
.lab-card .chip.arrest{background:rgba(251,191,36,.18);color:#fbbf24}
.lab-card .chip.patrol{background:rgba(34,211,238,.18);color:#67e8f9}
.lab-card .chip.research{background:rgba(167,139,250,.18);color:#c4b5fd}
.lab-card .chip.note{background:rgba(100,116,139,.18);color:#cbd5e1}
.lab-card .t{font-family:'Syne',sans-serif;color:#fff;font-weight:600;font-size:.93rem;margin-top:4px}
.lab-card .d{color:#cbd5e1;font-size:.8rem;line-height:1.55;margin-top:4px}
.lab-card .m{color:#64748b;font-size:.7rem;margin-top:6px;font-family:'Share Tech Mono',monospace;letter-spacing:.5px}

.lab-side{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:16px 18px;position:sticky;top:92px}
.lab-side h3{font-family:'Syne',sans-serif;font-size:.82rem;letter-spacing:2px;text-transform:uppercase;color:#e2e8f0;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.lab-side input,.lab-side select,.lab-side textarea{width:100%;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:8px 11px;border-radius:7px;font-size:.82rem;margin-bottom:7px;font-family:'DM Sans',sans-serif}
.lab-side button{width:100%;padding:10px;background:linear-gradient(135deg,#22d3ee,#7c3aed);border:none;border-radius:8px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;font-size:.72rem;cursor:pointer;margin-top:4px}

.lab-alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:.82rem}
.lab-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.lab-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
</style>

<div class="lab-hero">
    <h1><i class="fa-solid fa-flask" style="color:#67e8f9"></i> Laboratoire & R&D</h1>
    <div class="sub">Tracking activité police · analyses opérationnelles · notes de recherche</div>
</div>

<?php if ($success): ?><div class="lab-alert ok"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="lab-alert ko"><i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($error); ?></div><?php endif; ?>

<div class="lab-stats">
    <div class="lab-stat police_raid"><div class="v"><?= $typeCounts['police_raid'] ?? 0; ?></div><div class="l">Descentes</div></div>
    <div class="lab-stat arrest"><div class="v"><?= $typeCounts['arrest'] ?? 0; ?></div><div class="l">Arrestations</div></div>
    <div class="lab-stat patrol"><div class="v"><?= $typeCounts['patrol'] ?? 0; ?></div><div class="l">Patrouilles</div></div>
    <div class="lab-stat research"><div class="v"><?= $typeCounts['research'] ?? 0; ?></div><div class="l">Recherches</div></div>
    <div class="lab-stat"><div class="v"><?= $typeCounts['note'] ?? 0; ?></div><div class="l">Notes</div></div>
</div>

<div class="lab-spark">
    <h4><i class="fa-solid fa-chart-line"></i> Activité des 7 derniers jours</h4>
    <div class="lab-spark-bars">
        <?php foreach ($byDay as $d => $n): ?>
            <div class="lab-spark-bar" style="height: <?= max(4, (int) round($n / $maxDay * 80)); ?>px" title="<?= htmlspecialchars($d); ?> — <?= $n; ?>">
                <?php if ($n > 0): ?><span class="v"><?= $n; ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="lab-spark-labels">
        <?php foreach ($byDay as $d => $n): ?><span><?= htmlspecialchars(date('d/m', strtotime($d))); ?></span><?php endforeach; ?>
    </div>
</div>

<div class="lab-tabs">
    <a href="?" class="lab-tab <?= $filter === '' ? 'active' : ''; ?>">Tout</a>
    <a href="?t=police_raid" class="lab-tab <?= $filter === 'police_raid' ? 'active' : ''; ?>"><i class="fa-solid fa-car-burst"></i> Descentes</a>
    <a href="?t=arrest" class="lab-tab <?= $filter === 'arrest' ? 'active' : ''; ?>"><i class="fa-solid fa-handcuffs"></i> Arrestations</a>
    <a href="?t=patrol" class="lab-tab <?= $filter === 'patrol' ? 'active' : ''; ?>"><i class="fa-solid fa-route"></i> Patrouilles</a>
    <a href="?t=research" class="lab-tab <?= $filter === 'research' ? 'active' : ''; ?>"><i class="fa-solid fa-microscope"></i> Recherches</a>
    <a href="?t=note" class="lab-tab <?= $filter === 'note' ? 'active' : ''; ?>"><i class="fa-solid fa-note-sticky"></i> Notes</a>
</div>

<div class="lab-grid">
    <div>
        <?php if (!$logs): ?>
            <div style="text-align:center;padding:60px 20px;color:#64748b;background:rgba(13,17,33,.5);border:1px dashed rgba(148,163,184,.15);border-radius:12px">
                <i class="fa-solid fa-vial-circle-check" style="font-size:2.5rem;opacity:.3;margin-bottom:10px;display:block"></i>
                <p>Aucune entrée.</p>
            </div>
        <?php else: foreach ($logs as $l):
            $name = trim(($l['firstname'] ?? '') . ' ' . ($l['lastname'] ?? ''));
            if ($name === '') { $name = $l['username'] ?? '—'; }
            $ts = strtotime($l['logged_at']);
        ?>
            <div class="lab-card">
                <div class="tcol">
                    <div class="d"><?= date('d', $ts); ?></div>
                    <div class="m"><?= date('M', $ts); ?></div>
                    <div class="h"><?= date('H:i', $ts); ?></div>
                </div>
                <div class="ccol">
                    <span class="chip <?= htmlspecialchars($l['type']); ?>"><?= htmlspecialchars($l['type']); ?></span>
                    <div class="t"><?= htmlspecialchars($l['title']); ?></div>
                    <?php if ($l['details']): ?><div class="d"><?= nl2br(htmlspecialchars($l['details'])); ?></div><?php endif; ?>
                    <div class="m">Par <?= htmlspecialchars($name); ?> · <?= htmlspecialchars($l['faction_name'] ?? '—'); ?></div>
                </div>
                <?php if ($dnIsStaff || (int) $l['author_user'] === $dnUserId): ?>
                <form method="post" onsubmit="return confirm('Supprimer ?')">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $l['id']; ?>">
                    <button type="submit" class="dn-btn danger" style="align-self:flex-start"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <aside>
        <div class="lab-side">
            <h3><i class="fa-solid fa-plus" style="color:#22d3ee"></i> Log une activité</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="log">
                <select name="type">
                    <option value="police_raid">Descente / raid</option>
                    <option value="arrest">Arrestation</option>
                    <option value="patrol">Patrouille repérée</option>
                    <option value="research">Recherche / analyse</option>
                    <option value="note">Note libre</option>
                </select>
                <input type="text" name="title" placeholder="Titre / résumé" required>
                <textarea name="details" rows="4" placeholder="Détails (lieu, effectifs, véhicules…)"></textarea>
                <button type="submit">Enregistrer</button>
            </form>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(148,163,184,.08);font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#64748b;letter-spacing:1px">[ intel ] data retained 90d · pattern analysis</div>
        </div>
    </aside>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
