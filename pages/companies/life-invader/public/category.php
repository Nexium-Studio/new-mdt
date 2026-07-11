<?php
require_once __DIR__ . '/_bootstrap.php';

$db = li_db();
$hasImg = li_has_column('li_articles', 'image_principale');
$imgSel = $hasImg ? 'a.image_principale' : 'NULL AS image_principale';

$slug = trim($_GET['slug'] ?? '');
$cat  = null;
if ($slug !== '') {
    $st = $db->prepare("SELECT id, nom, slug, couleur, icone, description FROM li_categories WHERE slug=? AND actif=1");
    $st->execute([$slug]);
    $cat = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cat) {
        header('Location: ' . li_pub_url('category.php'));
        exit;
    }
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

$where  = ["a.statut='publie'"];
$params = [];
if ($cat) { $where[] = 'a.categorie_id=?'; $params[] = $cat['id']; }
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM li_articles a $whereSQL");
$total->execute($params);
$total = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$articlesSt = $db->prepare("
    SELECT a.id, a.titre, a.chapeau, a.priorite, a.a_la_une, a.exclusif, a.nb_vues,
           a.date_publication, $imgSel,
           c.nom AS cat_nom, c.couleur AS cat_col, c.icone AS cat_icone,
           u.username AS author_username,
           p.firstname AS author_firstname, p.lastname AS author_lastname
    FROM li_articles a
    LEFT JOIN li_categories c ON c.id = a.categorie_id
    JOIN users u ON u.id = a.auteur_id
    LEFT JOIN profiles p ON p.id = u.profile_id
    $whereSQL
    ORDER BY a.date_publication DESC
    LIMIT $perPage OFFSET $offset");
$articlesSt->execute($params);
$articles = $articlesSt->fetchAll(PDO::FETCH_ASSOC);

if ($cat) {
    $LI_PAGE_TITLE = $cat['nom'] . ' — Life Invader';
    $LI_PAGE_DESC  = $cat['description'] ?: "Toute l'actualité de la rubrique " . $cat['nom'] . " — Life Invader.";
    $LI_CURRENT_CAT = $cat['slug'];
} else {
    $LI_PAGE_TITLE = "Tous les articles — Life Invader";
    $LI_PAGE_DESC  = "L'ensemble des articles publiés par la rédaction Life Invader.";
}

include __DIR__ . '/_header.php';
?>

<header class="li-cat-header">
    <?php if ($cat): ?>
    <div class="li-cat-header-icon" style="background:<?php echo htmlspecialchars($cat['couleur']); ?>18;color:<?php echo htmlspecialchars($cat['couleur']); ?>;border:1px solid <?php echo htmlspecialchars($cat['couleur']); ?>33"><i class="fas <?php echo htmlspecialchars($cat['icone']); ?>"></i></div>
    <h1><?php echo htmlspecialchars($cat['nom']); ?></h1>
    <?php if (!empty($cat['description'])): ?><p><?php echo htmlspecialchars($cat['description']); ?></p><?php else: ?>
    <p>Tous les articles de la rubrique <strong><?php echo htmlspecialchars($cat['nom']); ?></strong>.</p>
    <?php endif; ?>
    <?php else: ?>
    <div class="li-cat-header-icon" style="background:var(--li-softer);color:var(--li);border:1px solid var(--li-bd)"><i class="fas fa-newspaper"></i></div>
    <h1>Tous les articles</h1>
    <p>Découvrez l'ensemble des articles publiés par la rédaction Life Invader, toutes rubriques confondues.</p>
    <?php endif; ?>
</header>

<div class="li-cat-chips" style="margin-bottom:2rem">
    <a href="<?php echo li_pub_url('category.php'); ?>" class="li-cat-chip<?php echo !$cat ? ' active' : ''; ?>"><i class="fas fa-globe"></i> Toutes</a>
    <?php foreach ($LI_NAV_CATS as $c): ?>
    <a href="<?php echo li_pub_url('category.php?slug=' . urlencode($c['slug'])); ?>" class="li-cat-chip<?php echo ($cat && $cat['id']==$c['id']) ? ' active' : ''; ?>">
        <i class="fas <?php echo htmlspecialchars($c['icone']); ?>" style="color:<?php echo htmlspecialchars($c['couleur']); ?>"></i>
        <?php echo htmlspecialchars($c['nom']); ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="li-filter-bar">
    <span class="li-filter-lbl">Trier par</span>
    <span style="font-size:.82rem;color:var(--tx-2);font-weight:600">Plus récents</span>
    <span class="li-filter-result"><strong><?php echo number_format($total); ?></strong> article<?php echo $total>1?'s':''; ?> trouvé<?php echo $total>1?'s':''; ?></span>
</div>

<?php if (empty($articles)): ?>
<div class="li-empty">
    <i class="far fa-newspaper"></i>
    <h3>Aucun article publié dans cette rubrique</h3>
    <p>Aucun article ne correspond à cette sélection pour l'instant.</p>
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
                <?php if ($a['priorite'] === 'urgente' || $a['priorite'] === 'flash'): ?><span class="li-card-flag flag-urg"><?php echo $a['priorite'] === 'flash' ? 'Flash' : 'Urg.'; ?></span><?php endif; ?>
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

<?php if ($totalPages > 1):
    $baseUrl = li_pub_url('category.php') . ($cat ? '?slug=' . urlencode($cat['slug']) . '&' : '?');
?>
<nav class="li-pagi">
    <?php if ($page > 1): ?>
    <a href="<?php echo $baseUrl . 'page=' . ($page - 1); ?>"><i class="fas fa-chevron-left"></i></a>
    <?php else: ?>
    <span class="disabled"><i class="fas fa-chevron-left"></i></span>
    <?php endif; ?>

    <?php
    $range = 2;
    for ($i = max(1, $page - $range); $i <= min($totalPages, $page + $range); $i++):
        if ($i == $page): ?>
            <span class="current"><?php echo $i; ?></span>
        <?php else: ?>
            <a href="<?php echo $baseUrl . 'page=' . $i; ?>"><?php echo $i; ?></a>
        <?php endif;
    endfor; ?>

    <?php if ($page < $totalPages): ?>
    <a href="<?php echo $baseUrl . 'page=' . ($page + 1); ?>"><i class="fas fa-chevron-right"></i></a>
    <?php else: ?>
    <span class="disabled"><i class="fas fa-chevron-right"></i></span>
    <?php endif; ?>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
