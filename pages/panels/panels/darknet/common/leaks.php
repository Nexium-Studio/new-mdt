<?php
/**
 * DarkNet — Leaks Center (commun).
 * Publications sensibles, indics, fréquences interceptées.
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Leaks Center';
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'post') {
                $type = in_array($_POST['type'] ?? 'leak', ['leak','indic','frequency'], true) ? $_POST['type'] : 'leak';
                $title = trim((string) ($_POST['title'] ?? ''));
                if ($title === '') { throw new RuntimeException('Titre requis.'); }
                $db->prepare("INSERT INTO darknet_leaks (type, title, content, category, extra_data, author_faction, author_user) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([
                       $type, $title,
                       trim((string) ($_POST['content'] ?? '')) ?: null,
                       trim((string) ($_POST['category'] ?? '')) ?: null,
                       trim((string) ($_POST['extra_data'] ?? '')) ?: null,
                       $dnFaction, $dnUserId,
                   ]);
                $success = 'Publication enregistrée.';
            } elseif ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $st = $db->prepare("SELECT author_user FROM darknet_leaks WHERE id = ?");
                $st->execute([$id]);
                $author = (int) $st->fetchColumn();
                if (!$dnIsStaff && $author !== $dnUserId) { throw new RuntimeException('Non autorisé.'); }
                $db->prepare("DELETE FROM darknet_leaks WHERE id = ?")->execute([$id]);
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

$typeFilter = in_array($_GET['t'] ?? '', ['leak','indic','frequency'], true) ? $_GET['t'] : '';

if ($typeFilter) {
    $st = $db->prepare("SELECT l.*, f.name AS faction_name, p.firstname, p.lastname, u.username FROM darknet_leaks l LEFT JOIN factions f ON f.faction_key = l.author_faction LEFT JOIN users u ON u.id = l.author_user LEFT JOIN profiles p ON p.id = u.profile_id WHERE l.is_active = 1 AND l.type = ? ORDER BY l.created_at DESC LIMIT 50");
    $st->execute([$typeFilter]);
    $leaks = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    $leaks = $db->query("SELECT l.*, f.name AS faction_name, p.firstname, p.lastname, u.username FROM darknet_leaks l LEFT JOIN factions f ON f.faction_key = l.author_faction LEFT JOIN users u ON u.id = l.author_user LEFT JOIN profiles p ON p.id = u.profile_id WHERE l.is_active = 1 ORDER BY l.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../includes/header.php';
?>
<style>
.lk-hero{background:linear-gradient(135deg,rgba(167,139,250,.1),rgba(13,17,33,.9));border:1px solid rgba(167,139,250,.3);border-radius:14px;padding:20px 24px;margin-bottom:20px}
.lk-hero h1{font-family:'Syne',sans-serif;font-size:1.3rem;color:#fff;letter-spacing:3px;text-transform:uppercase;display:flex;align-items:center;gap:10px}
.lk-hero .sub{color:#94a3b8;font-size:.82rem;margin-top:5px;font-family:'Share Tech Mono',monospace;letter-spacing:1px}

.lk-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.lk-tab{padding:8px 14px;border-radius:8px;background:rgba(13,17,33,.7);border:1px solid rgba(148,163,184,.18);color:#94a3b8;text-decoration:none;font-family:'Share Tech Mono',monospace;font-size:.7rem;letter-spacing:1.5px;text-transform:uppercase;display:flex;align-items:center;gap:6px}
.lk-tab.active{background:rgba(167,139,250,.14);border-color:rgba(167,139,250,.4);color:#c4b5fd}

.lk-grid{display:grid;grid-template-columns:1fr 340px;gap:22px;align-items:start}
@media(max-width:1100px){.lk-grid{grid-template-columns:1fr}}

.lk-card{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:16px 20px;margin-bottom:12px;position:relative}
.lk-card .type{position:absolute;top:12px;right:14px;font-family:'Share Tech Mono',monospace;font-size:.58rem;padding:3px 8px;border-radius:3px;letter-spacing:1.5px;font-weight:700;text-transform:uppercase}
.lk-card .type.leak{background:rgba(239,68,68,.18);color:#fca5a5}
.lk-card .type.indic{background:rgba(251,191,36,.18);color:#fbbf24}
.lk-card .type.frequency{background:rgba(34,211,238,.18);color:#67e8f9}
.lk-card h4{font-family:'Syne',sans-serif;font-size:1rem;color:#fff;margin-bottom:6px;padding-right:80px}
.lk-card .cat{display:inline-block;padding:2px 7px;font-family:'Share Tech Mono',monospace;font-size:.6rem;background:rgba(124,58,237,.15);color:#c4b5fd;border-radius:3px;letter-spacing:1px;text-transform:uppercase;margin-right:6px}
.lk-card .content{color:#cbd5e1;font-size:.83rem;line-height:1.55;margin-top:10px;padding:10px;background:rgba(0,0,0,.25);border-radius:6px;white-space:pre-wrap}
.lk-card .extra{margin-top:8px;font-family:'JetBrains Mono',monospace;color:#22d3ee;font-size:.85rem;padding:8px 12px;background:rgba(6,182,212,.08);border-left:2px solid #22d3ee;border-radius:4px}
.lk-card .meta{font-size:.72rem;color:#64748b;margin-top:10px;font-family:'Share Tech Mono',monospace;letter-spacing:.5px}

.lk-side{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:16px 18px;position:sticky;top:92px}
.lk-side h3{font-family:'Syne',sans-serif;font-size:.82rem;letter-spacing:2px;text-transform:uppercase;color:#e2e8f0;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.lk-side input,.lk-side select,.lk-side textarea{width:100%;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:8px 11px;border-radius:7px;font-size:.82rem;margin-bottom:7px;font-family:'DM Sans',sans-serif}
.lk-side button{width:100%;padding:10px;background:linear-gradient(135deg,#7c3aed,#22d3ee);border:none;border-radius:8px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;font-size:.72rem;cursor:pointer;margin-top:4px}

.lk-alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:.82rem}
.lk-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.lk-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
</style>

<div class="lk-hero">
    <h1><i class="fa-solid fa-user-ninja" style="color:#c4b5fd"></i> Leaks Center</h1>
    <div class="sub">Publications sensibles · indics · fréquences interceptées</div>
</div>

<?php if ($success): ?><div class="lk-alert ok"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="lk-alert ko"><i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($error); ?></div><?php endif; ?>

<div class="lk-tabs">
    <a href="?" class="lk-tab <?= $typeFilter === '' ? 'active' : ''; ?>"><i class="fa-solid fa-layer-group"></i> Tout</a>
    <a href="?t=leak" class="lk-tab <?= $typeFilter === 'leak' ? 'active' : ''; ?>"><i class="fa-solid fa-file-lock"></i> Leaks</a>
    <a href="?t=indic" class="lk-tab <?= $typeFilter === 'indic' ? 'active' : ''; ?>"><i class="fa-solid fa-user-secret"></i> Indics</a>
    <a href="?t=frequency" class="lk-tab <?= $typeFilter === 'frequency' ? 'active' : ''; ?>"><i class="fa-solid fa-satellite-dish"></i> Fréquences</a>
</div>

<div class="lk-grid">
    <div>
        <?php if (!$leaks): ?>
            <div style="text-align:center;padding:60px 20px;color:#64748b;background:rgba(13,17,33,.5);border:1px dashed rgba(148,163,184,.15);border-radius:12px">
                <i class="fa-solid fa-eye-slash" style="font-size:2.5rem;opacity:.3;margin-bottom:10px;display:block"></i>
                <p>Aucune publication.</p>
            </div>
        <?php else: foreach ($leaks as $l):
            $name = trim(($l['firstname'] ?? '') . ' ' . ($l['lastname'] ?? ''));
            if ($name === '') { $name = $l['username'] ?? 'Anonyme'; }
            $typeIcon = ['leak'=>'file-lock','indic'=>'user-secret','frequency'=>'satellite-dish'][$l['type']] ?? 'file';
        ?>
            <div class="lk-card">
                <span class="type <?= htmlspecialchars($l['type']); ?>"><?= htmlspecialchars($l['type']); ?></span>
                <h4><i class="fa-solid fa-<?= $typeIcon; ?>" style="color:#a78bfa"></i> <?= htmlspecialchars($l['title']); ?></h4>
                <?php if ($l['category']): ?><span class="cat"><?= htmlspecialchars($l['category']); ?></span><?php endif; ?>
                <?php if ($l['content']): ?><div class="content"><?= htmlspecialchars($l['content']); ?></div><?php endif; ?>
                <?php if ($l['extra_data']): ?><div class="extra"><i class="fa-solid fa-broadcast-tower"></i> <?= htmlspecialchars($l['extra_data']); ?></div><?php endif; ?>
                <div class="meta">Par <b style="color:#cbd5e1"><?= htmlspecialchars($name); ?></b> (<?= htmlspecialchars($l['faction_name'] ?? '—'); ?>) · <?= htmlspecialchars(date('d/m/Y H:i', strtotime($l['created_at']))); ?></div>
                <?php if ($dnIsStaff || (int) $l['author_user'] === $dnUserId): ?>
                <form method="post" style="margin-top:10px;display:inline" onsubmit="return confirm('Supprimer ?')">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $l['id']; ?>">
                    <button type="submit" class="dn-btn danger"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <aside>
        <div class="lk-side">
            <h3><i class="fa-solid fa-plus" style="color:#c4b5fd"></i> Publier</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="post">
                <select name="type">
                    <option value="leak">Leak / fuite</option>
                    <option value="indic">Indic / témoin</option>
                    <option value="frequency">Fréquence interceptée</option>
                </select>
                <input type="text" name="title" placeholder="Titre" required>
                <input type="text" name="category" placeholder="Catégorie">
                <textarea name="content" rows="4" placeholder="Contenu / détails"></textarea>
                <input type="text" name="extra_data" placeholder="Fréquence / URL / code (optionnel)">
                <button type="submit">Publier</button>
            </form>
        </div>
    </aside>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
