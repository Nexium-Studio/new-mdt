<?php
/**
 * DarkNet — Wiki Criminel (commun, wiki léger).
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Wiki Criminel';
$success = ''; $error = '';

$slugify = static function (string $s): string {
    $s = preg_replace('~[^\pL\d]+~u', '-', $s) ?? '';
    $s = trim($s, '-');
    $s = iconv('utf-8', 'us-ascii//TRANSLIT', $s);
    $s = preg_replace('~[^-\w]+~', '', $s) ?? '';
    return strtolower(trim($s, '-')) ?: 'page-' . bin2hex(random_bytes(3));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $title = trim((string) ($_POST['title'] ?? ''));
                $content = (string) ($_POST['content'] ?? '');
                $category = trim((string) ($_POST['category'] ?? '')) ?: null;
                if ($title === '' || $content === '') { throw new RuntimeException('Titre et contenu requis.'); }
                if ($id > 0) {
                    // Update : vérifier droit (auteur ou staff)
                    $st = $db->prepare("SELECT author_user FROM darknet_wiki_pages WHERE id = ?");
                    $st->execute([$id]);
                    $aut = (int) $st->fetchColumn();
                    if (!$dnIsStaff && $aut !== $dnUserId) { throw new RuntimeException('Non autorisé.'); }
                    $db->prepare("UPDATE darknet_wiki_pages SET title = ?, content = ?, category = ? WHERE id = ?")
                       ->execute([$title, $content, $category, $id]);
                    $success = 'Page mise à jour.';
                } else {
                    $slug = $slugify($title);
                    // Evite collision slug
                    $check = $db->prepare("SELECT COUNT(*) FROM darknet_wiki_pages WHERE slug = ?");
                    $check->execute([$slug]);
                    if ((int) $check->fetchColumn() > 0) { $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4); }
                    $db->prepare("INSERT INTO darknet_wiki_pages (title, slug, content, category, author_user) VALUES (?, ?, ?, ?, ?)")
                       ->execute([$title, $slug, $content, $category, $dnUserId]);
                    $success = 'Page créée.';
                }
            } elseif ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $st = $db->prepare("SELECT author_user FROM darknet_wiki_pages WHERE id = ?");
                $st->execute([$id]);
                $aut = (int) $st->fetchColumn();
                if (!$dnIsStaff && $aut !== $dnUserId) { throw new RuntimeException('Non autorisé.'); }
                $db->prepare("DELETE FROM darknet_wiki_pages WHERE id = ?")->execute([$id]);
                $success = 'Page supprimée.';
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

$slug = (string) ($_GET['p'] ?? '');
$editing = isset($_GET['edit']);
$creating = isset($_GET['new']);

$pages = $db->query("SELECT id, title, slug, category FROM darknet_wiki_pages ORDER BY category ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);

$current = null;
if ($slug !== '') {
    $st = $db->prepare("SELECT w.*, p.firstname, p.lastname, u.username FROM darknet_wiki_pages w LEFT JOIN users u ON u.id = w.author_user LEFT JOIN profiles p ON p.id = u.profile_id WHERE w.slug = ?");
    $st->execute([$slug]);
    $current = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

include __DIR__ . '/../includes/header.php';
?>
<style>
.wk-app{display:grid;grid-template-columns:260px 1fr;gap:22px;align-items:start}
@media(max-width:900px){.wk-app{grid-template-columns:1fr}}
.wk-side{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:14px 12px;position:sticky;top:92px}
.wk-side h3{font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#94a3b8;letter-spacing:2px;text-transform:uppercase;padding:0 8px 10px}
.wk-side a{display:flex;align-items:center;gap:8px;padding:8px 12px;color:#94a3b8;text-decoration:none;font-size:.82rem;border-radius:7px;margin-bottom:2px}
.wk-side a:hover{background:rgba(255,255,255,.04);color:#e2e8f0}
.wk-side a.active{background:rgba(167,139,250,.15);color:#c4b5fd;border-left:3px solid #a78bfa;padding-left:9px}
.wk-side .cat{font-family:'Share Tech Mono',monospace;font-size:.6rem;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;padding:10px 12px 4px;margin-top:6px}
.wk-new{margin-top:10px;padding:8px 12px;background:linear-gradient(135deg,#7c3aed,#22d3ee);color:#fff;border-radius:8px;text-decoration:none;font-family:'Syne',sans-serif;font-weight:700;text-transform:uppercase;font-size:.7rem;letter-spacing:1.5px;display:flex;align-items:center;justify-content:center;gap:6px}

.wk-main{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:26px 30px}
.wk-main h1{font-family:'Syne',sans-serif;color:#fff;font-size:1.5rem;letter-spacing:2px;margin-bottom:6px}
.wk-main .cat{font-family:'Share Tech Mono',monospace;font-size:.62rem;padding:3px 10px;background:rgba(167,139,250,.15);color:#c4b5fd;border-radius:4px;letter-spacing:1.5px;text-transform:uppercase;display:inline-block}
.wk-main .meta{font-family:'Share Tech Mono',monospace;font-size:.65rem;color:#64748b;letter-spacing:1px;margin:8px 0 20px}
.wk-main .body{color:#cbd5e1;font-size:.88rem;line-height:1.7;white-space:pre-wrap}
.wk-main .body h2,.wk-main .body h3{font-family:'Syne',sans-serif;color:#fff;margin:14px 0 8px}
.wk-main .actions{margin-top:20px;padding-top:14px;border-top:1px solid rgba(148,163,184,.08);display:flex;gap:8px}

.wk-form input,.wk-form select,.wk-form textarea{width:100%;background:rgba(2,4,12,.8);border:1px solid rgba(148,163,184,.2);color:#e2e8f0;padding:10px 14px;border-radius:8px;font-size:.88rem;margin-bottom:10px;font-family:'DM Sans',sans-serif}
.wk-form textarea{min-height:320px;font-family:'JetBrains Mono',monospace;font-size:.82rem;line-height:1.6}
.wk-form button{padding:10px 20px;background:linear-gradient(135deg,#7c3aed,#22d3ee);border:none;border-radius:8px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;font-size:.75rem;cursor:pointer}
.wk-empty{text-align:center;padding:60px 20px;color:#64748b}

.wk-alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:.82rem}
.wk-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.wk-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
</style>

<?php if ($success): ?><div class="wk-alert ok"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="wk-alert ko"><i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($error); ?></div><?php endif; ?>

<div class="wk-app">
    <aside class="wk-side">
        <h3>Sommaire</h3>
        <?php
        $grouped = [];
        foreach ($pages as $p) { $grouped[$p['category'] ?: 'Général'][] = $p; }
        foreach ($grouped as $cat => $list):
        ?>
            <div class="cat"><?= htmlspecialchars($cat); ?></div>
            <?php foreach ($list as $p): ?>
                <a href="?p=<?= urlencode($p['slug']); ?>" class="<?= $slug === $p['slug'] ? 'active' : ''; ?>"><i class="fa-solid fa-file-lines"></i> <?= htmlspecialchars($p['title']); ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <a href="?new=1" class="wk-new"><i class="fa-solid fa-plus"></i> Nouvelle page</a>
    </aside>

    <main class="wk-main">
        <?php if ($creating || ($editing && $current)): ?>
            <h1><?= $creating ? 'Nouvelle page' : 'Éditer : ' . htmlspecialchars($current['title']); ?></h1>
            <form method="post" class="wk-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                <input type="hidden" name="action" value="save">
                <?php if ($editing && $current): ?><input type="hidden" name="id" value="<?= (int) $current['id']; ?>"><?php endif; ?>
                <input type="text" name="title" placeholder="Titre" value="<?= htmlspecialchars($current['title'] ?? ''); ?>" required>
                <input type="text" name="category" placeholder="Catégorie" value="<?= htmlspecialchars($current['category'] ?? ''); ?>">
                <textarea name="content" placeholder="Contenu (markdown basique supporté par ton rendu)" required><?= htmlspecialchars($current['content'] ?? ''); ?></textarea>
                <button type="submit"><i class="fa-solid fa-save"></i> Enregistrer</button>
                <a href="<?= SITE_URL; ?>/pages/panels/darknet/common/wiki.php" class="dn-btn" style="margin-left:8px">Annuler</a>
            </form>
        <?php elseif ($current): ?>
            <?php if ($current['category']): ?><span class="cat"><?= htmlspecialchars($current['category']); ?></span><?php endif; ?>
            <h1><?= htmlspecialchars($current['title']); ?></h1>
            <div class="meta">
                Par <?= htmlspecialchars(trim(($current['firstname'] ?? '') . ' ' . ($current['lastname'] ?? '')) ?: ($current['username'] ?? '—')); ?> ·
                Créé <?= htmlspecialchars(date('d/m/Y', strtotime($current['created_at']))); ?> ·
                Maj <?= htmlspecialchars(date('d/m/Y H:i', strtotime($current['updated_at']))); ?>
            </div>
            <div class="body"><?= nl2br(htmlspecialchars($current['content'])); ?></div>
            <?php if ($dnIsStaff || (int) $current['author_user'] === $dnUserId): ?>
                <div class="actions">
                    <a href="?p=<?= urlencode($current['slug']); ?>&edit=1" class="dn-btn cyan"><i class="fa-solid fa-pen"></i> Éditer</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette page ?')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $current['id']; ?>">
                        <button type="submit" class="dn-btn danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="wk-empty">
                <i class="fa-solid fa-book-skull" style="font-size:3rem;opacity:.3;margin-bottom:12px;display:block;color:#a78bfa"></i>
                <h2 style="font-family:'Syne',sans-serif;color:#e2e8f0;margin-bottom:8px">Wiki Criminel</h2>
                <p>Sélectionne une page dans le sommaire ou crée-en une nouvelle.</p>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
