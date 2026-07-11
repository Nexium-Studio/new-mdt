<?php
require_once __DIR__ . '/_bootstrap.php';

$db = li_db();
$hasImg = li_has_column('li_articles', 'image_principale');
$imgSel = $hasImg ? 'a.image_principale' : 'NULL AS image_principale';

$q = trim($_GET['q'] ?? '');
$articles = [];
$total = 0;

if ($q !== '') {
    $like = '%' . $q . '%';
    $st = $db->prepare("
        SELECT a.id, a.titre, a.chapeau, a.priorite, a.a_la_une, a.exclusif, a.nb_vues,
               a.date_publication, $imgSel,
               c.nom AS cat_nom, c.couleur AS cat_col, c.icone AS cat_icone,
               u.username AS author_username,
               p.firstname AS author_firstname, p.lastname AS author_lastname
        FROM li_articles a
        LEFT JOIN li_categories c ON c.id = a.categorie_id
        JOIN users u ON u.id = a.auteur_id
        LEFT JOIN profiles p ON p.id = u.profile_id
        WHERE a.statut='publie'
          AND (a.titre LIKE ? OR a.chapeau LIKE ? OR a.contenu LIKE ? OR a.tags LIKE ?)
        ORDER BY a.date_publication DESC
        LIMIT 60");
    $st->execute([$like, $like, $like, $like]);
    $articles = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = count($articles);
}

$LI_PAGE_TITLE = $q !== '' ? "Recherche : \"$q\" — Life Invader" : "Recherche — Life Invader";
$LI_PAGE_DESC = "Résultats de recherche dans les articles de Life Invader.";

include __DIR__ . '/_header.php';
?>

<header class="li-cat-header">
    <div class="li-cat-header-icon" style="background:var(--li-softer);color:var(--li);border:1px solid var(--li-bd)"><i class="fas fa-search"></i></div>
    <h1>Recherche</h1>
    <?php if ($q !== ''): ?>
    <p>Résultats pour <strong style="color:var(--tx);font-weight:700">« <?php echo htmlspecialchars($q); ?> »</strong> — <?php echo $total; ?> article<?php echo $total>1?'s':''; ?> trouvé<?php echo $total>1?'s':''; ?></p>
    <?php else: ?>
    <p>Cherchez un article par titre, contenu, mots-clés ou tag.</p>
    <?php endif; ?>
</header>

<form method="GET" action="<?php echo li_pub_url('search.php'); ?>" style="background:var(--paper);border:1px solid var(--bd);border-radius:var(--r-md);padding:.65rem .8rem;display:flex;gap:.5rem;align-items:center;margin-bottom:2rem;box-shadow:var(--shadow-1)">
    <i class="fas fa-search" style="color:var(--tx-mute);padding-left:.4rem"></i>
    <input type="text" name="q" autofocus placeholder="Rechercher un titre, un sujet, un mot-clé…" value="<?php echo htmlspecialchars($q); ?>" style="flex:1;background:transparent;border:0;outline:none;color:var(--tx);font-size:1rem;padding:.6rem 0;font-family:'Inter',sans-serif">
    <button type="submit" style="background:var(--li);color:#fff;border:0;padding:.65rem 1.4rem;border-radius:var(--r-sm);font-weight:700;cursor:pointer;font-size:.85rem">Rechercher</button>
</form>

<?php if ($q === ''): ?>
<div class="li-side-widget" style="margin-bottom:2rem">
    <h3 class="li-side-title">Suggestions</h3>
    <div class="li-cat-chips">
        <?php foreach ($LI_NAV_CATS as $c): ?>
        <a href="<?php echo li_pub_url('category.php?slug=' . urlencode($c['slug'])); ?>" class="li-cat-chip">
            <i class="fas <?php echo htmlspecialchars($c['icone']); ?>" style="color:<?php echo htmlspecialchars($c['couleur']); ?>"></i>
            <?php echo htmlspecialchars($c['nom']); ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php elseif (empty($articles)): ?>
<div class="li-empty">
    <i class="fas fa-search"></i>
    <h3>Aucun résultat pour « <?php echo htmlspecialchars($q); ?> »</h3>
    <p>Essayez d'autres mots-clés ou parcourez nos rubriques.</p>
</div>
<?php else: ?>
<div class="li-grid li-grid-3">
    <?php foreach ($articles as $a): ?>
    <a href="<?php echo li_pub_url('article.php?id=' . $a['id']); ?>" class="li-card">
        <div class="li-card-image" <?php if (li_image_for($a)): ?>style="background-image:url('<?php echo htmlspecialchars(li_image_for($a)); ?>')"<?php endif; ?>>
            <?php if (!li_image_for($a)): ?>
            <div class="li-card-image-ph"><i class="fas <?php echo htmlspecialchars($a['cat_icone'] ?? 'fa-newspaper'); ?>"></i></div>
            <?php endif; ?>
            <div class="li-card-flags">
                <?php if ($a['a_la_une']): ?><span class="li-card-flag flag-une">Une</span><?php endif; ?>
                <?php if ($a['exclusif']): ?><span class="li-card-flag flag-excl">Excl.</span><?php endif; ?>
            </div>
        </div>
        <div class="li-card-body">
            <?php if ($a['cat_nom']): ?>
            <span class="li-card-cat" style="color:<?php echo htmlspecialchars($a['cat_col']); ?>"><i class="fas <?php echo htmlspecialchars($a['cat_icone']); ?>"></i> <?php echo htmlspecialchars($a['cat_nom']); ?></span>
            <?php endif; ?>
            <h3 class="li-card-title"><?php echo htmlspecialchars($a['titre']); ?></h3>
            <p class="li-card-excerpt"><?php echo htmlspecialchars(li_plain($a['chapeau'] ?? '')); ?></p>
            <div class="li-card-meta">
                <span><i class="fas fa-user"></i> <strong><?php echo htmlspecialchars(li_pub_author_name($a)); ?></strong></span>
                <span><i class="far fa-clock"></i> <?php echo li_time_ago($a['date_publication']); ?></span>
                <span><i class="fas fa-eye"></i> <?php echo number_format($a['nb_vues']); ?></span>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
